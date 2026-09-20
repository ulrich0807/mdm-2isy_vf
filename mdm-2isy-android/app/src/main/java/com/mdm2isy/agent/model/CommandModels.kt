package com.mdm2isy.agent.model

data class DeviceCommand(
    val publicId: String,
    val type: String,
    val payload: CommandPayload,
    val queuedAt: String,
    val sentAt: String?,
    val expiresAt: String,
) {
    init {
        require(isCanonicalUuid(publicId)) { "The command public ID must be a UUID." }
        require(type.isNotBlank()) { "The command type cannot be blank." }
        require(queuedAt.isNotBlank()) { "queuedAt cannot be blank." }
        require(expiresAt.isNotBlank()) { "expiresAt cannot be blank." }
    }

    val knownType: KnownCommandType?
        get() = KnownCommandType.fromWireValue(type)
}

enum class KnownCommandType(val wireValue: String) {
    LOCATE("locate"),
    LOCK("lock"),
    WIPE("wipe"),
    INSTALL_APP("install_app"),
    UNINSTALL_APP("uninstall_app"),
    INVENTORY("inventory");

    companion object {
        fun fromWireValue(value: String): KnownCommandType? = entries.firstOrNull {
            it.wireValue == value
        }
    }
}

/**
 * The backend currently serializes an empty PHP payload as `[]`. The network
 * codec normalizes that representation to this same empty value.
 */
data class CommandPayload(
    val message: String? = null,
    val timeoutSeconds: Int? = null,
    val highAccuracy: Boolean? = null,
    val url: String? = null,
    val packageName: String? = null,
) {
    init {
        require(message == null || message.length <= 500) {
            "A command message cannot exceed 500 characters."
        }
        require(timeoutSeconds == null || timeoutSeconds in 5..300) {
            "timeoutSeconds must be between 5 and 300."
        }
    }
}

data class CommandTransition(
    val publicId: String,
    val type: String,
    val status: String,
    val acknowledgedAt: String?,
    val completedAt: String?,
) {
    init {
        require(isCanonicalUuid(publicId)) { "The transition public ID must be a UUID." }
        require(type.isNotBlank()) { "The transition command type cannot be blank." }
        require(status in TRANSITION_STATUSES) {
            "The server returned an unsupported command transition state."
        }
    }

    private companion object {
        val TRANSITION_STATUSES = setOf("acknowledged", "succeeded", "failed")
    }
}

data class CommandProof private constructor(
    val lat: Double? = null,
    val lng: Double? = null,
    val accuracyM: Double? = null,
    val message: String? = null,
    val executedAt: String? = null,
    val locked: Boolean? = null,
    val wipeStarted: Boolean? = null,
    val installedApps: List<String>? = null,
) {
    companion object {
        fun locate(
            lat: Double,
            lng: Double,
            accuracyM: Double? = null,
            message: String? = null,
            executedAt: String? = null,
        ): CommandProof {
            require(lat.isFinite() && lat in -90.0..90.0) {
                "Latitude is outside its supported range."
            }
            require(lng.isFinite() && lng in -180.0..180.0) {
                "Longitude is outside its supported range."
            }
            require(accuracyM == null || (accuracyM.isFinite() && accuracyM in 0.0..100_000.0)) {
                "Location accuracy is outside its supported range."
            }
            requireMessage(message)
            return CommandProof(
                lat = lat,
                lng = lng,
                accuracyM = accuracyM,
                message = message,
                executedAt = executedAt,
            )
        }

        fun lock(
            message: String? = null,
            executedAt: String? = null,
        ): CommandProof {
            requireMessage(message)
            return CommandProof(
                message = message,
                executedAt = executedAt,
                locked = true,
            )
        }

        fun wipe(
            message: String? = null,
            executedAt: String? = null,
        ): CommandProof {
            requireMessage(message)
            return CommandProof(
                message = message,
                executedAt = executedAt,
                wipeStarted = true,
            )
        }

        fun installApp(
            message: String? = null,
            executedAt: String? = null,
        ): CommandProof {
            requireMessage(message)
            return CommandProof(
                message = message,
                executedAt = executedAt,
            )
        }

        fun uninstallApp(
            message: String? = null,
            executedAt: String? = null,
        ): CommandProof {
            requireMessage(message)
            return CommandProof(
                message = message,
                executedAt = executedAt,
            )
        }

        fun inventory(
            apps: List<String>,
            message: String? = null,
            executedAt: String? = null,
        ): CommandProof {
            requireMessage(message)
            return CommandProof(
                installedApps = apps,
                message = message,
                executedAt = executedAt,
            )
        }

        private fun requireMessage(message: String?) {
            require(message == null || message.length <= 500) {
                "A result message cannot exceed 500 characters."
            }
        }
    }
}

enum class CommandResultStatus(val wireValue: String) {
    SUCCEEDED("succeeded"),
    FAILED("failed"),
}

data class CommandResultRequest private constructor(
    val status: CommandResultStatus,
    val proof: CommandProof?,
    val errorCode: String?,
    val errorMessage: String?,
) {
    companion object {
        private val errorCodePattern = Regex("[A-Z0-9][A-Z0-9_.-]*")

        fun succeeded(proof: CommandProof): CommandResultRequest = CommandResultRequest(
            status = CommandResultStatus.SUCCEEDED,
            proof = proof,
            errorCode = null,
            errorMessage = null,
        )

        fun failed(errorCode: String, errorMessage: String? = null): CommandResultRequest {
            require(errorCode.length in 1..100 && errorCodePattern.matches(errorCode)) {
                "errorCode does not match the backend command contract."
            }
            require(errorMessage == null || errorMessage.length <= 1_000) {
                "errorMessage cannot exceed 1000 characters."
            }
            return CommandResultRequest(
                status = CommandResultStatus.FAILED,
                proof = null,
                errorCode = errorCode,
                errorMessage = errorMessage,
            )
        }
    }
}
