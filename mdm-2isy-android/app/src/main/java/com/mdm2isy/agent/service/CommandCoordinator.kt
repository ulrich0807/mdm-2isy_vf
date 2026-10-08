package com.mdm2isy.agent.service

import android.content.Context
import com.mdm2isy.agent.command.CommandExecutionHandle
import com.mdm2isy.agent.command.CommandExecutionResult
import com.mdm2isy.agent.command.DeviceCommandExecutor
import com.mdm2isy.agent.model.CommandPayload
import com.mdm2isy.agent.model.DeviceCommand
import com.mdm2isy.agent.model.KnownCommandType
import com.mdm2isy.agent.network.MdmConflictException
import com.mdm2isy.agent.network.MdmApiException
import com.mdm2isy.agent.network.MdmAuthenticationException
import com.mdm2isy.agent.network.MdmDeviceApi
import com.mdm2isy.agent.network.MdmNotFoundException
import com.mdm2isy.agent.network.MdmProtocolException
import com.mdm2isy.agent.network.MdmValidationException
import com.mdm2isy.agent.storage.CommandJournal
import com.mdm2isy.agent.storage.JournalEntry
import com.mdm2isy.agent.storage.LocalCommandState
import java.time.Clock
import java.time.Instant
import java.time.OffsetDateTime
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicReference
import org.json.JSONObject

data class CommandSyncReport(
    val received: Int,
    val finalized: Int,
)

internal object CommandExecutionTimeoutPolicy {
    private const val DEFAULT_EXECUTION_TIMEOUT_SECONDS = 30L

    // The server keeps install commands valid for 60 minutes and their signed
    // download URL for 65 minutes. Leave five minutes for result publication.
    const val APP_INSTALL_TIMEOUT_SECONDS = 55L * 60L

    fun timeoutSeconds(command: DeviceCommand): Long {
        val defaultSeconds = if (command.knownType == KnownCommandType.INSTALL_APP) {
            APP_INSTALL_TIMEOUT_SECONDS
        } else {
            DEFAULT_EXECUTION_TIMEOUT_SECONDS
        }

        return command.payload.timeoutSeconds?.toLong() ?: defaultSeconds
    }
}

/**
 * Implements the persist -> ACK -> execute -> persist result -> publish sequence.
 * Blocking network and command waits are intentional: the service owns a single
 * worker so a destructive command cannot race another command on the device.
 */
class CommandCoordinator(
    private val journal: CommandJournal,
    private val executor: DeviceCommandExecutor,
    private val clock: Clock = Clock.systemUTC(),
) {
    constructor(context: Context) : this(
        journal = CommandJournal(context),
        executor = DeviceCommandExecutor(context),
    )

    fun synchronize(
        api: MdmDeviceApi,
        deviceToken: String,
    ): CommandSyncReport {
        var finalized = 0
        var firstTransientFailure: MdmApiException? = null
        var networkAvailable = true
        // Poll first so a newly queued wipe/lock cannot be hidden indefinitely
        // by stale local work or a failing inventory heartbeat.
        val delivered = try {
            api.poll(deviceToken, POLL_LIMIT)
        } catch (exception: MdmAuthenticationException) {
            throw exception
        } catch (exception: MdmApiException) {
            firstTransientFailure = exception
            networkAvailable = false
            emptyList()
        }
        delivered.forEach { command ->
            journal.recordReceived(
                publicId = command.publicId,
                type = command.type,
                payloadJson = encodePayload(command.payload),
                expiresAt = command.expiresAt,
            )
        }

        journal.actionable()
            .sortedWith(
                compareBy<JournalEntry> { processingPriority(it) }
                    .thenBy(JournalEntry::createdAtEpochMs),
            )
            .forEach { entry ->
                try {
                    if (processEntry(api, deviceToken, entry, networkAvailable)) finalized += 1
                } catch (exception: MdmAuthenticationException) {
                    throw exception
                } catch (exception: MdmApiException) {
                    // A stale result must not starve an already acknowledged
                    // wipe or lock. Preserve the first retry signal and keep
                    // walking the durable queue in command-safety order.
                    if (firstTransientFailure == null) firstTransientFailure = exception
                    networkAvailable = false
                }
            }

        firstTransientFailure?.let { throw it }
        return CommandSyncReport(received = delivered.size, finalized = finalized)
    }

    private fun processingPriority(entry: JournalEntry): Int = when {
        entry.type == com.mdm2isy.agent.model.KnownCommandType.WIPE.wireValue -> 0
        entry.type == com.mdm2isy.agent.model.KnownCommandType.LOCK.wireValue -> 1
        // Publishing an already durable result does not repeat the device effect.
        entry.state == LocalCommandState.RESULT_PENDING -> 2
        else -> 3
    }

    private fun processEntry(
        api: MdmDeviceApi,
        deviceToken: String,
        initialEntry: JournalEntry,
        allowNetwork: Boolean,
    ): Boolean {
        var entry = journal.find(initialEntry.publicId) ?: initialEntry
        if (entry.state in TERMINAL_STATES) return false

        // Once an effect has a durable result, always let the server decide
        // whether it is still acceptable. The device clock is not authoritative.
        if (entry.state == LocalCommandState.RESULT_PENDING) {
            return if (allowNetwork) publishStoredResult(api, deviceToken, entry) else false
        }

        if (
            entry.state == LocalCommandState.EXECUTING &&
            entry.type == com.mdm2isy.agent.model.KnownCommandType.WIPE.wireValue
        ) {
            // After a process crash, Android gives us no reliable way to know
            // whether the destructive platform call already started. Prefer a
            // visible failure over issuing a factory reset a second time.
            val unknown = com.mdm2isy.agent.model.CommandResultRequest.failed(
                "EXECUTION_OUTCOME_UNKNOWN",
                "L'agent a redémarré pendant l'effacement ; la commande n'est pas répétée.",
            )
            journal.storeFinalRequest(entry.publicId, CommandResultCodec.encode(unknown))
            if (!allowNetwork) return false
            return publishStoredResult(api, deviceToken, journal.find(entry.publicId) ?: return false)
        }

        val expired = runCatching { isExpired(entry.expiresAt) }.getOrElse {
            journal.quarantine(entry.publicId)
            return false
        }
        if (expired) {
            journal.markExpired(entry.publicId)
            return false
        }

        if (entry.state == LocalCommandState.RECEIVED) {
            if (!allowNetwork) return false
            try {
                val transition = api.ack(deviceToken, entry.publicId)
                verifyTransition(entry, transition.publicId, transition.type)
                if (transition.status in FINAL_SERVER_STATUSES) {
                    journal.markFinal(entry.publicId)
                    return true
                }
                if (transition.status != ACKNOWLEDGED_SERVER_STATUS) {
                    quarantineProtocol(
                        entry,
                        "The ACK response returned an invalid command status.",
                    )
                }
                entry = journal.markAcknowledged(entry.publicId) ?: return false
            } catch (exception: MdmConflictException) {
                finishConflict(entry.publicId, exception)
                return false
            } catch (_: MdmNotFoundException) {
                journal.quarantine(entry.publicId)
                return false
            } catch (_: MdmValidationException) {
                journal.quarantine(entry.publicId)
                return false
            }
        }

        if (entry.state !in setOf(
                LocalCommandState.ACKNOWLEDGED,
                LocalCommandState.EXECUTING,
            )
        ) {
            return false
        }

        journal.markExecuting(entry.publicId)
        val command = runCatching { entry.toDeviceCommand() }.getOrElse {
            val invalid = com.mdm2isy.agent.model.CommandResultRequest.failed(
                "INVALID_LOCAL_COMMAND",
                "La commande persistée localement est invalide.",
            )
            journal.storeFinalRequest(entry.publicId, CommandResultCodec.encode(invalid))
            if (!allowNetwork) return false
            return publishStoredResult(
                api,
                deviceToken,
                journal.find(entry.publicId) ?: return false,
            )
        }

        val execution = awaitExecution(command) ?: return false
        val resultRequest = CommandResultCodec.fromExecution(command.type, execution)
        journal.storeFinalRequest(entry.publicId, CommandResultCodec.encode(resultRequest))
        if (!allowNetwork) return false
        return publishStoredResult(
            api,
            deviceToken,
            journal.find(entry.publicId) ?: return false,
        )
    }

    private fun publishStoredResult(
        api: MdmDeviceApi,
        deviceToken: String,
        entry: JournalEntry,
    ): Boolean {
        val rawResult = entry.finalRequestJson ?: run {
            journal.quarantine(entry.publicId)
            return false
        }
        val request = runCatching {
            CommandResultCodec.decode(entry.type, rawResult)
        }.getOrElse {
            journal.quarantine(entry.publicId)
            return false
        }

        return try {
            val transition = api.result(deviceToken, entry.publicId, request)
            verifyTransition(entry, transition.publicId, transition.type)
            if (transition.status != request.status.wireValue) {
                quarantineProtocol(
                    entry,
                    "The result response returned another final command status.",
                )
            }
            journal.markFinal(entry.publicId)
            true
        } catch (exception: MdmConflictException) {
            finishConflict(entry.publicId, exception)
            false
        } catch (_: MdmNotFoundException) {
            journal.quarantine(entry.publicId)
            false
        } catch (_: MdmValidationException) {
            journal.quarantine(entry.publicId)
            false
        }
    }

    /** Null means the worker was interrupted and the durable EXECUTING state must be retried. */
    private fun awaitExecution(command: DeviceCommand): CommandExecutionResult? {
        val completed = CountDownLatch(1)
        val result = AtomicReference<CommandExecutionResult?>()
        var handle: CommandExecutionHandle? = null

        return try {
            handle = executor.execute(command) { execution ->
                if (result.compareAndSet(null, execution)) completed.countDown()
            }
            val timeoutSeconds = CommandExecutionTimeoutPolicy.timeoutSeconds(command)
            if (completed.await(timeoutSeconds + EXECUTION_GRACE_SECONDS, TimeUnit.SECONDS)) {
                result.get()
            } else {
                handle?.cancel()
                CommandExecutionResult.Failure(
                    "COMMAND_EXECUTION_TIMEOUT",
                    "La commande n'a pas produit de résultat avant l'expiration du délai local.",
                )
            }
        } catch (_: InterruptedException) {
            handle?.cancel()
            Thread.currentThread().interrupt()
            null
        }
    }

    private fun finishConflict(publicId: String, exception: MdmConflictException) {
        if (exception.reason == "command_expired") {
            journal.markExpired(publicId)
        } else {
            journal.quarantine(publicId)
        }
    }

    private fun verifyTransition(
        entry: JournalEntry,
        responsePublicId: String,
        responseType: String,
    ) {
        if (
            !responsePublicId.equals(entry.publicId, ignoreCase = true) ||
            responseType != entry.type
        ) {
            quarantineProtocol(
                entry,
                "The server command transition does not match the local command.",
            )
        }
    }

    private fun quarantineProtocol(entry: JournalEntry, message: String): Nothing {
        journal.quarantine(entry.publicId)
        throw MdmProtocolException(message)
    }

    private fun isExpired(expiresAt: String): Boolean {
        val instant = runCatching { OffsetDateTime.parse(expiresAt).toInstant() }
            .recoverCatching { Instant.parse(expiresAt) }
            .getOrThrow()
        return !instant.isAfter(clock.instant())
    }

    private fun JournalEntry.toDeviceCommand(): DeviceCommand = DeviceCommand(
        publicId = publicId,
        type = type,
        payload = decodePayload(payloadJson),
        queuedAt = Instant.ofEpochMilli(createdAtEpochMs.coerceAtLeast(0L)).toString(),
        sentAt = null,
        expiresAt = expiresAt,
    )

    private fun encodePayload(payload: CommandPayload): String = JSONObject().apply {
        payload.message?.let { put("message", it) }
        payload.timeoutSeconds?.let { put("timeout_seconds", it) }
        payload.highAccuracy?.let { put("high_accuracy", it) }
        payload.url?.let { put("url", it) }
        payload.packageName?.let { put("package_name", it) }
    }.toString()

    private fun decodePayload(rawJson: String): CommandPayload {
        val payload = JSONObject(rawJson)
        return CommandPayload(
            message = payload.optString("message").takeIf { it.isNotBlank() },
            timeoutSeconds = if (payload.has("timeout_seconds")) {
                payload.getInt("timeout_seconds")
            } else {
                null
            },
            highAccuracy = if (payload.has("high_accuracy")) {
                payload.getBoolean("high_accuracy")
            } else {
                null
            },
            url = payload.optString("url").takeIf { it.isNotBlank() },
            packageName = payload.optString("package_name").takeIf { it.isNotBlank() },
        )
    }

    private companion object {
        const val POLL_LIMIT = 10
        const val EXECUTION_GRACE_SECONDS = 5L
        const val ACKNOWLEDGED_SERVER_STATUS = "acknowledged"

        val FINAL_SERVER_STATUSES = setOf("succeeded", "failed")

        val TERMINAL_STATES = setOf(
            LocalCommandState.FINAL,
            LocalCommandState.EXPIRED,
            LocalCommandState.QUARANTINED,
        )
    }
}
