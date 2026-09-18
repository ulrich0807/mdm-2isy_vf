package com.mdm2isy.agent.command

import android.content.Context
import com.mdm2isy.agent.device.DeviceAdminController
import com.mdm2isy.agent.device.DeviceAdminFailureReason
import com.mdm2isy.agent.device.DeviceAdminOperationResult
import com.mdm2isy.agent.location.CancellableLocationRequest
import com.mdm2isy.agent.location.DeviceLocationProvider
import com.mdm2isy.agent.location.DeviceLocationResult
import com.mdm2isy.agent.model.DeviceCommand
import com.mdm2isy.agent.model.KnownCommandType
import java.time.Clock
import java.time.OffsetDateTime
import java.time.format.DateTimeFormatter

object CommandExecutionErrorCodes {
    const val UNSUPPORTED_COMMAND = "UNSUPPORTED_COMMAND"
    const val INVALID_COMMAND_PAYLOAD = "INVALID_COMMAND_PAYLOAD"
    const val DEVICE_ADMIN_INACTIVE = "DEVICE_ADMIN_INACTIVE"
    const val DEVICE_OWNER_REQUIRED = "DEVICE_OWNER_REQUIRED"
    const val DEVICE_POLICY_REJECTED = "DEVICE_POLICY_REJECTED"
    const val DEVICE_OPERATION_FAILED = "DEVICE_OPERATION_FAILED"
}

data class CommandExecutionProof(
    val lat: Double? = null,
    val lng: Double? = null,
    val accuracyM: Double? = null,
    val message: String? = null,
    val executedAt: String,
    val locked: Boolean? = null,
    val wipeStarted: Boolean? = null,
)

sealed interface CommandExecutionResult {
    data class Success(
        val proof: CommandExecutionProof,
    ) : CommandExecutionResult

    data class Failure(
        val errorCode: String,
        val errorMessage: String,
    ) : CommandExecutionResult
}

fun interface CommandExecutionHandle {
    fun cancel()
}

class DeviceCommandExecutor(
    private val adminController: DeviceAdminController,
    private val locationProvider: DeviceLocationProvider,
    private val appInstaller: AppInstaller,
    private val clock: Clock = Clock.systemUTC(),
) {
    constructor(context: Context) : this(
        adminController = DeviceAdminController(context),
        locationProvider = DeviceLocationProvider(context),
        appInstaller = AppInstaller(context),
    )

    fun execute(
        command: DeviceCommand,
        callback: (CommandExecutionResult) -> Unit,
    ): CommandExecutionHandle = when (command.knownType) {
        KnownCommandType.LOCATE -> executeLocate(command, callback)
        KnownCommandType.LOCK -> executeLock(callback)
        KnownCommandType.WIPE -> executeWipe(callback)
        KnownCommandType.INSTALL_APP -> executeInstallApp(command, callback)
        KnownCommandType.UNINSTALL_APP -> executeUninstallApp(command, callback)
        null -> immediate(
            callback,
            CommandExecutionResult.Failure(
                CommandExecutionErrorCodes.UNSUPPORTED_COMMAND,
                "Le type de commande '${command.type}' n'est pas pris en charge par cet agent.",
            ),
        )
    }

    private fun executeLocate(
        command: DeviceCommand,
        callback: (CommandExecutionResult) -> Unit,
    ): CommandExecutionHandle {
        val timeoutSeconds = command.payload.timeoutSeconds?.toLong()
            ?: DEFAULT_LOCATION_TIMEOUT_SECONDS
        if (timeoutSeconds !in MIN_LOCATION_TIMEOUT_SECONDS..MAX_LOCATION_TIMEOUT_SECONDS) {
            return immediate(
                callback,
                CommandExecutionResult.Failure(
                    CommandExecutionErrorCodes.INVALID_COMMAND_PAYLOAD,
                    "Le delai de localisation doit etre compris entre 5 et 300 secondes.",
                ),
            )
        }

        val request = locationProvider.locate(
            timeoutMillis = timeoutSeconds * 1_000L,
            highAccuracy = command.payload.highAccuracy ?: true,
        ) { outcome ->
            val result = when (outcome) {
                is DeviceLocationResult.Success -> CommandExecutionResult.Success(
                    CommandExecutionProof(
                        lat = outcome.location.latitude,
                        lng = outcome.location.longitude,
                        accuracyM = outcome.location.accuracyMeters,
                        executedAt = executedAt(),
                    ),
                )

                is DeviceLocationResult.Failure -> CommandExecutionResult.Failure(
                    errorCode = outcome.errorCode,
                    errorMessage = outcome.errorMessage,
                )
            }
            callback(result)
        }

        return CommandExecutionHandle(request::cancel)
    }

    private fun executeLock(
        callback: (CommandExecutionResult) -> Unit,
    ): CommandExecutionHandle {
        val result = when (val operation = adminController.lockDevice()) {
            DeviceAdminOperationResult.Completed -> CommandExecutionResult.Success(
                CommandExecutionProof(
                    executedAt = executedAt(),
                    // This proof is emitted only after lockNow() returned without an exception.
                    locked = true,
                ),
            )

            is DeviceAdminOperationResult.Failed -> operation.toCommandFailure()
        }

        return immediate(callback, result)
    }

    private fun executeWipe(
        callback: (CommandExecutionResult) -> Unit,
    ): CommandExecutionHandle {
        // Never publish wipeStarted before the real platform wipe call.
        val result = when (val operation = adminController.wipeDevice()) {
            DeviceAdminOperationResult.Completed -> CommandExecutionResult.Success(
                CommandExecutionProof(
                    executedAt = executedAt(),
                    wipeStarted = true,
                ),
            )

            is DeviceAdminOperationResult.Failed -> operation.toCommandFailure()
        }

        return immediate(callback, result)
    }

    private fun executeInstallApp(
        command: DeviceCommand,
        callback: (CommandExecutionResult) -> Unit,
    ): CommandExecutionHandle {
        val apkUrl = command.payload.url
        if (apkUrl.isNullOrBlank()) {
            return immediate(
                callback,
                CommandExecutionResult.Failure(
                    CommandExecutionErrorCodes.INVALID_COMMAND_PAYLOAD,
                    "L'URL de l'application est manquante.",
                ),
            )
        }

        if (!adminController.status().isDeviceOwner) {
            return immediate(
                callback,
                CommandExecutionResult.Failure(
                    CommandExecutionErrorCodes.DEVICE_OWNER_REQUIRED,
                    "L'installation silencieuse nécessite d'être Device Owner.",
                ),
            )
        }

        appInstaller.installSilently(apkUrl) { success, error ->
            val result = if (success) {
                CommandExecutionResult.Success(
                    CommandExecutionProof(
                        executedAt = executedAt(),
                        message = "Installation de l'application lancée avec succès.",
                    ),
                )
            } else {
                CommandExecutionResult.Failure(
                    CommandExecutionErrorCodes.DEVICE_OPERATION_FAILED,
                    error ?: "Erreur d'installation.",
                )
            }
            callback(result)
        }

        return CommandExecutionHandle { /* Cannot easily cancel download in this simplified version */ }
    }

    private fun executeUninstallApp(
        command: DeviceCommand,
        callback: (CommandExecutionResult) -> Unit,
    ): CommandExecutionHandle {
        val packageName = command.payload.packageName
        if (packageName.isNullOrBlank()) {
            return immediate(
                callback,
                CommandExecutionResult.Failure(
                    CommandExecutionErrorCodes.INVALID_COMMAND_PAYLOAD,
                    "Le nom du package est manquant.",
                ),
            )
        }

        if (!adminController.status().isDeviceOwner) {
            return immediate(
                callback,
                CommandExecutionResult.Failure(
                    CommandExecutionErrorCodes.DEVICE_OWNER_REQUIRED,
                    "La désinstallation silencieuse nécessite d'être Device Owner.",
                ),
            )
        }

        appInstaller.uninstallSilently(packageName) { success, error ->
            val result = if (success) {
                CommandExecutionResult.Success(
                    CommandExecutionProof(
                        executedAt = executedAt(),
                        message = "Désinstallation de l'application lancée avec succès.",
                    ),
                )
            } else {
                CommandExecutionResult.Failure(
                    CommandExecutionErrorCodes.DEVICE_OPERATION_FAILED,
                    error ?: "Erreur de désinstallation.",
                )
            }
            callback(result)
        }

        return CommandExecutionHandle { }
    }

    private fun DeviceAdminOperationResult.Failed.toCommandFailure(): CommandExecutionResult.Failure {
        val errorCode = when (reason) {
            DeviceAdminFailureReason.ADMIN_INACTIVE ->
                CommandExecutionErrorCodes.DEVICE_ADMIN_INACTIVE

            DeviceAdminFailureReason.DEVICE_OWNER_REQUIRED ->
                CommandExecutionErrorCodes.DEVICE_OWNER_REQUIRED

            DeviceAdminFailureReason.POLICY_REJECTED ->
                CommandExecutionErrorCodes.DEVICE_POLICY_REJECTED

            DeviceAdminFailureReason.PLATFORM_FAILURE ->
                CommandExecutionErrorCodes.DEVICE_OPERATION_FAILED
        }
        return CommandExecutionResult.Failure(errorCode, message)
    }

    private fun immediate(
        callback: (CommandExecutionResult) -> Unit,
        result: CommandExecutionResult,
    ): CommandExecutionHandle {
        callback(result)
        return CommandExecutionHandle { }
    }

    private fun executedAt(): String =
        OffsetDateTime.now(clock).format(EXECUTED_AT_FORMATTER)

    private companion object {
        const val DEFAULT_LOCATION_TIMEOUT_SECONDS = 20L
        const val MIN_LOCATION_TIMEOUT_SECONDS = 5L
        const val MAX_LOCATION_TIMEOUT_SECONDS = 300L

        val EXECUTED_AT_FORMATTER: DateTimeFormatter =
            // Laravel validates `Y-m-d\TH:i:sP`; lowercase xxx always emits
            // an explicit offset such as +00:00 instead of the shorthand Z.
            DateTimeFormatter.ofPattern("uuuu-MM-dd'T'HH:mm:ssxxx")
    }
}
