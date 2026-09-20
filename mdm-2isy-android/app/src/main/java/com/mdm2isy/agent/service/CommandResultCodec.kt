package com.mdm2isy.agent.service

import com.mdm2isy.agent.command.CommandExecutionProof
import com.mdm2isy.agent.command.CommandExecutionResult
import com.mdm2isy.agent.model.CommandProof
import com.mdm2isy.agent.model.CommandResultRequest
import com.mdm2isy.agent.model.CommandResultStatus
import com.mdm2isy.agent.model.KnownCommandType
import com.mdm2isy.agent.network.MdmJsonCodec
import org.json.JSONObject

internal object CommandResultCodec {
    private const val INVALID_PROOF = "INVALID_EXECUTION_PROOF"

    fun fromExecution(
        commandType: String,
        execution: CommandExecutionResult,
    ): CommandResultRequest = when (execution) {
        is CommandExecutionResult.Failure -> CommandResultRequest.failed(
            errorCode = execution.errorCode,
            errorMessage = execution.errorMessage,
        )

        is CommandExecutionResult.Success -> successRequest(commandType, execution.proof)
    }

    // Persist exactly the same JSON representation used by the HTTP client so
    // retries replay the original final result without semantic drift.
    fun encode(request: CommandResultRequest): String = MdmJsonCodec.encodeResult(request)

    fun decode(commandType: String, rawJson: String): CommandResultRequest {
        val root = JSONObject(rawJson)
        return when (root.getString("status")) {
            CommandResultStatus.FAILED.wireValue -> CommandResultRequest.failed(
                errorCode = root.getString("error_code"),
                errorMessage = root.optString("error_message").takeIf { it.isNotBlank() },
            )

            CommandResultStatus.SUCCEEDED.wireValue -> {
                val proof = root.getJSONObject("result")
                when (KnownCommandType.fromWireValue(commandType)) {
                    KnownCommandType.LOCATE -> CommandResultRequest.succeeded(
                        CommandProof.locate(
                            lat = proof.getDouble("lat"),
                            lng = proof.getDouble("lng"),
                            accuracyM = proof.optionalDouble("accuracy_m"),
                            message = proof.optionalString("message"),
                            executedAt = proof.optionalString("executed_at"),
                        ),
                    )

                    KnownCommandType.LOCK -> {
                        require(proof.opt("locked") == true) {
                            "The persisted lock proof is missing locked=true."
                        }
                        CommandResultRequest.succeeded(
                            CommandProof.lock(
                                message = proof.optionalString("message"),
                                executedAt = proof.optionalString("executed_at"),
                            ),
                        )
                    }

                    KnownCommandType.WIPE -> {
                        require(proof.opt("wipe_started") == true) {
                            "The persisted wipe proof is missing wipe_started=true."
                        }
                        CommandResultRequest.succeeded(
                            CommandProof.wipe(
                                message = proof.optionalString("message"),
                                executedAt = proof.optionalString("executed_at"),
                            ),
                        )
                    }

                    KnownCommandType.INSTALL_APP -> {
                        CommandResultRequest.succeeded(
                            CommandProof.installApp(
                                message = proof.optionalString("message"),
                                executedAt = proof.optionalString("executed_at"),
                            ),
                        )
                    }

                    KnownCommandType.UNINSTALL_APP -> {
                        CommandResultRequest.succeeded(
                            CommandProof.uninstallApp(
                                message = proof.optionalString("message"),
                                executedAt = proof.optionalString("executed_at"),
                            ),
                        )
                    }

                    KnownCommandType.INVENTORY -> {
                        val appsArray = proof.optJSONArray("installed_apps")
                        val apps = buildList {
                            if (appsArray != null) {
                                for (i in 0 until appsArray.length()) {
                                    val obj = appsArray.optJSONObject(i)
                                    if (obj != null) {
                                        add(com.mdm2isy.agent.model.AppInfo(
                                            name = obj.optString("name", "Unknown"),
                                            packageName = obj.optString("packageName")
                                        ))
                                    } else {
                                        // Fallback for older versions sending strings
                                        val pkg = appsArray.optString(i)
                                        if (pkg.isNotBlank()) add(com.mdm2isy.agent.model.AppInfo(name = pkg, packageName = pkg))
                                    }
                                }
                            }
                        }
                        CommandResultRequest.succeeded(
                            CommandProof.inventory(
                                apps = apps,
                                message = proof.optionalString("message"),
                                executedAt = proof.optionalString("executed_at"),
                            ),
                        )
                    }

                    null -> CommandResultRequest.failed(
                        INVALID_PROOF,
                        "Le résultat local concerne un type de commande inconnu.",
                    )
                }
            }

            else -> throw IllegalArgumentException("Unknown persisted command result status.")
        }
    }

    private fun successRequest(
        commandType: String,
        proof: CommandExecutionProof,
    ): CommandResultRequest = when (KnownCommandType.fromWireValue(commandType)) {
        KnownCommandType.LOCATE -> {
            val lat = proof.lat
            val lng = proof.lng
            if (lat == null || lng == null) {
                invalidProof("La localisation n'a produit aucune coordonnée.")
            } else {
                CommandResultRequest.succeeded(
                    CommandProof.locate(
                        lat = lat,
                        lng = lng,
                        accuracyM = proof.accuracyM,
                        message = proof.message,
                        executedAt = proof.executedAt,
                    ),
                )
            }
        }

        KnownCommandType.LOCK -> if (proof.locked == true) {
            CommandResultRequest.succeeded(
                CommandProof.lock(proof.message, proof.executedAt),
            )
        } else {
            invalidProof("Android n'a pas confirmé le verrouillage.")
        }

        KnownCommandType.WIPE -> if (proof.wipeStarted == true) {
            CommandResultRequest.succeeded(
                CommandProof.wipe(proof.message, proof.executedAt),
            )
        } else {
            invalidProof("Android n'a pas confirmé le démarrage de l'effacement.")
        }

        KnownCommandType.INSTALL_APP -> {
            CommandResultRequest.succeeded(
                CommandProof.installApp(proof.message, proof.executedAt),
            )
        }

        KnownCommandType.UNINSTALL_APP -> {
            CommandResultRequest.succeeded(
                CommandProof.uninstallApp(proof.message, proof.executedAt),
            )
        }

        KnownCommandType.INVENTORY -> {
            CommandResultRequest.succeeded(
                CommandProof.inventory(
                    apps = proof.installedApps ?: emptyList(),
                    message = proof.message,
                    executedAt = proof.executedAt,
                ),
            )
        }

        null -> CommandResultRequest.failed(
            "UNSUPPORTED_COMMAND",
            "Le type de commande '$commandType' n'est pas pris en charge.",
        )
    }

    private fun invalidProof(message: String): CommandResultRequest =
        CommandResultRequest.failed(INVALID_PROOF, message)

    private fun JSONObject.optionalString(name: String): String? =
        optString(name).takeIf { it.isNotBlank() }

    private fun JSONObject.optionalDouble(name: String): Double? =
        if (has(name) && !isNull(name)) getDouble(name) else null
}
