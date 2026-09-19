package com.mdm2isy.agent.model

import java.util.UUID

data class EnrollmentRequest(
    val enrollmentToken: String,
    val deviceUid: String,
    val imei: String? = null,
    val serialNumber: String? = null,
    val manufacturer: String? = null,
    val model: String? = null,
    val androidVersion: String? = null,
    val androidBuild: String? = null,
    val agentVersion: String? = null,
    val batteryLevel: Int? = null,
    val storageTotalMb: Long? = null,
    val storageFreeMb: Long? = null,
) {
    init {
        require(enrollmentToken.isNotBlank() && enrollmentToken.length <= 512) {
            "A valid enrollment token is required."
        }
        require(isCanonicalUuid(deviceUid)) { "deviceUid must be a canonical UUID." }
        requireLength(imei, 64, "imei")
        requireLength(serialNumber, 255, "serialNumber")
        requireLength(manufacturer, 255, "manufacturer")
        requireLength(model, 255, "model")
        requireLength(androidVersion, 100, "androidVersion")
        requireLength(androidBuild, 255, "androidBuild")
        requireLength(agentVersion, 100, "agentVersion")
        require(batteryLevel == null || batteryLevel in 0..100) {
            "batteryLevel must be between 0 and 100."
        }
        require(storageTotalMb == null || storageTotalMb >= 0) {
            "storageTotalMb cannot be negative."
        }
        require(storageFreeMb == null || storageFreeMb >= 0) {
            "storageFreeMb cannot be negative."
        }
        require(
            storageTotalMb == null ||
                storageFreeMb == null ||
                storageFreeMb <= storageTotalMb,
        ) { "Free storage cannot exceed total storage." }
    }
}

data class EnrollmentResult(
    val deviceId: String,
    val deviceToken: String,
) {
    init {
        require(isCanonicalUuid(deviceId)) { "The server returned an invalid device ID." }
        require(deviceToken.startsWith("mdm_device_") && deviceToken.length > 11) {
            "The server returned an invalid device credential."
        }
    }
}

data class HeartbeatRequest(
    val imei: String? = null,
    val serialNumber: String? = null,
    val manufacturer: String? = null,
    val model: String? = null,
    val androidVersion: String? = null,
    val androidBuild: String? = null,
    val agentVersion: String? = null,
    val batteryLevel: Int? = null,
    val storageTotalMb: Long? = null,
    val storageFreeMb: Long? = null,
    val latitude: Double? = null,
    val longitude: Double? = null,
) {
    init {
        requireLength(imei, 64, "imei")
        requireLength(serialNumber, 255, "serialNumber")
        requireLength(manufacturer, 255, "manufacturer")
        requireLength(model, 255, "model")
        requireLength(androidVersion, 100, "androidVersion")
        requireLength(androidBuild, 255, "androidBuild")
        requireLength(agentVersion, 100, "agentVersion")
        require(batteryLevel == null || batteryLevel in 0..100) {
            "batteryLevel must be between 0 and 100."
        }
        require(storageTotalMb == null || storageTotalMb >= 0) {
            "storageTotalMb cannot be negative."
        }
        require(storageFreeMb == null || storageFreeMb >= 0) {
            "storageFreeMb cannot be negative."
        }
        require(
            storageTotalMb == null ||
                storageFreeMb == null ||
                storageFreeMb <= storageTotalMb,
        ) { "Free storage cannot exceed total storage." }
        require((latitude == null) == (longitude == null)) {
            "Latitude and longitude must be sent together."
        }
        require(latitude == null || (latitude.isFinite() && latitude in -90.0..90.0)) {
            "Latitude is outside its supported range."
        }
        require(longitude == null || (longitude.isFinite() && longitude in -180.0..180.0)) {
            "Longitude is outside its supported range."
        }
    }
}

data class SecurityPolicy(
    val kiosk: Boolean = false,
    val appKiosk: String? = null,
    val kioskApps: List<String> = emptyList(),
    val noCam: Boolean = false,
    val noUsb: Boolean = false,
    val noBt: Boolean = false,
    val pinFort: Boolean = false,
    val blacklistApps: List<String> = emptyList(),
)

data class HeartbeatReceipt(
    val deviceId: String,
    val serverTime: String,
    val policy: SecurityPolicy? = null,
) {
    init {
        require(isCanonicalUuid(deviceId)) { "The server returned an invalid device ID." }
        require(serverTime.isNotBlank()) { "The server did not return its current time." }
    }
}

internal fun isCanonicalUuid(value: String): Boolean = runCatching {
    UUID.fromString(value).toString().equals(value, ignoreCase = true)
}.getOrDefault(false)

private fun requireLength(value: String?, maximum: Int, field: String) {
    require(value == null || value.length <= maximum) {
        "$field exceeds its maximum length of $maximum characters."
    }
}
