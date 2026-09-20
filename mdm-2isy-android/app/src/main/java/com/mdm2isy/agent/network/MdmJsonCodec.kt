package com.mdm2isy.agent.network

import com.mdm2isy.agent.model.CommandPayload
import com.mdm2isy.agent.model.CommandProof
import com.mdm2isy.agent.model.CommandResultRequest
import com.mdm2isy.agent.model.CommandTransition
import com.mdm2isy.agent.model.DeviceCommand
import com.mdm2isy.agent.model.EnrollmentRequest
import com.mdm2isy.agent.model.EnrollmentResult
import com.mdm2isy.agent.model.HeartbeatReceipt
import com.mdm2isy.agent.model.HeartbeatRequest
import org.json.JSONArray
import org.json.JSONException
import org.json.JSONObject

internal object MdmJsonCodec {
    fun encodeEnrollment(request: EnrollmentRequest): String = JSONObject()
        .put("enrollment_token", request.enrollmentToken)
        .put("device_uid", request.deviceUid.lowercase())
        .putOptional("imei", request.imei)
        .putOptional("serial_number", request.serialNumber)
        .putOptional("manufacturer", request.manufacturer)
        .putOptional("model", request.model)
        .putOptional("android_version", request.androidVersion)
        .putOptional("android_build", request.androidBuild)
        .putOptional("agent_version", request.agentVersion)
        .putOptional("battery_level", request.batteryLevel)
        .putOptional("storage_total_mb", request.storageTotalMb)
        .putOptional("storage_free_mb", request.storageFreeMb)
        .toString()

    fun parseEnrollment(body: String): EnrollmentResult = constructModel {
        val data = successDataObject(body)
        EnrollmentResult(
            deviceId = data.requiredString("device_id"),
            deviceToken = data.requiredString("device_token"),
        )
    }

    fun encodeHeartbeat(request: HeartbeatRequest): String = JSONObject()
        .putOptional("imei", request.imei)
        .putOptional("serial_number", request.serialNumber)
        .putOptional("manufacturer", request.manufacturer)
        .putOptional("model", request.model)
        .putOptional("android_version", request.androidVersion)
        .putOptional("android_build", request.androidBuild)
        .putOptional("agent_version", request.agentVersion)
        .putOptional("battery_level", request.batteryLevel)
        .putOptional("storage_total_mb", request.storageTotalMb)
        .putOptional("storage_free_mb", request.storageFreeMb)
        .putOptional("lat", request.latitude)
        .putOptional("lng", request.longitude)
        .apply {
            if (request.installedApps.isNotEmpty()) {
                put("installed_apps", JSONArray(request.installedApps))
            }
        }
        .toString()

    fun parseHeartbeat(body: String): HeartbeatReceipt = constructModel {
        val data = successDataObject(body)
        val policyObj = data.opt("policy") as? JSONObject
        val policy = policyObj?.let {
            com.mdm2isy.agent.model.SecurityPolicy(
                kiosk = it.optBoolean("kiosk", false),
                appKiosk = it.optionalString("app_kiosk"),
                kioskApps = it.optJSONArray("kiosk_apps")?.let { arr ->
                    List(arr.length()) { i -> arr.getString(i) }
                } ?: emptyList(),
                noCam = it.optBoolean("no_cam", false),
                noUsb = it.optBoolean("no_usb", false),
                noBt = it.optBoolean("no_bt", false),
                pinFort = it.optBoolean("pin_fort", false),
                blacklistApps = it.optJSONArray("blacklist_apps")?.let { arr ->
                    buildList(arr.length()) {
                        for (i in 0 until arr.length()) {
                            val str = arr.optString(i)
                            if (str.isNotBlank()) add(str)
                        }
                    }
                } ?: emptyList()
            )
        }
        HeartbeatReceipt(
            deviceId = data.requiredString("device_id"),
            serverTime = data.requiredString("server_time"),
            policy = policy,
        )
    }

    fun parseCommands(body: String): List<DeviceCommand> = constructModel {
        val root = successRoot(body)
        val data = root.opt("data") as? JSONArray
            ?: throw MdmProtocolException("The MDM command list is missing.")

        buildList(data.length()) {
            for (index in 0 until data.length()) {
                val command = data.opt(index) as? JSONObject
                    ?: throw MdmProtocolException("The MDM command list contains an invalid entry.")
                add(parseCommand(command))
            }
        }
    }

    fun parseTransition(body: String): CommandTransition = constructModel {
        val data = successDataObject(body)
        CommandTransition(
            publicId = data.requiredString("public_id"),
            type = data.requiredString("type"),
            status = data.requiredString("status"),
            acknowledgedAt = data.optionalString("acknowledged_at"),
            completedAt = data.optionalString("completed_at"),
        )
    }

    fun encodeResult(request: CommandResultRequest): String {
        val root = JSONObject().put("status", request.status.wireValue)
        request.proof?.let { root.put("result", encodeProof(it)) }
        root.putOptional("error_code", request.errorCode)
        root.putOptional("error_message", request.errorMessage)
        return root.toString()
    }

    fun parseHttpError(body: String): ParsedHttpError {
        if (body.isBlank()) return ParsedHttpError()

        return runCatching {
            val root = JSONObject(body)
            val errors = linkedMapOf<String, List<String>>()
            val rawErrors = root.opt("errors") as? JSONObject
            if (rawErrors != null) {
                for (key in rawErrors.keys()) {
                    val messages = when (val raw = rawErrors.opt(key)) {
                        is JSONArray -> buildList {
                            for (index in 0 until raw.length()) {
                                val message = raw.opt(index)
                                if (message is String) add(message.take(MAX_SERVER_MESSAGE_LENGTH))
                            }
                        }
                        is String -> listOf(raw.take(MAX_SERVER_MESSAGE_LENGTH))
                        else -> emptyList()
                    }
                    if (messages.isNotEmpty()) errors[key] = messages
                }
            }

            ParsedHttpError(
                reason = root.optionalString("reason")?.take(MAX_REASON_LENGTH),
                message = root.optionalString("message")?.take(MAX_SERVER_MESSAGE_LENGTH),
                validationErrors = errors,
            )
        }.getOrDefault(ParsedHttpError())
    }

    private fun parseCommand(json: JSONObject): DeviceCommand {
        val payload = when (val rawPayload = json.opt("payload")) {
            null, JSONObject.NULL -> JSONObject()
            is JSONObject -> rawPayload
            is JSONArray -> {
                if (rawPayload.length() != 0) {
                    throw MdmProtocolException("A command payload array must be empty.")
                }
                JSONObject()
            }
            else -> throw MdmProtocolException("A command payload has an invalid JSON shape.")
        }

        return DeviceCommand(
            publicId = json.requiredString("public_id"),
            type = json.requiredString("type"),
            payload = CommandPayload(
                message = payload.optionalString("message"),
                timeoutSeconds = payload.optionalInteger("timeout_seconds"),
                highAccuracy = payload.optionalBoolean("high_accuracy"),
                url = payload.optionalString("url"),
                packageName = payload.optionalString("packageName"),
            ),
            queuedAt = json.requiredString("queued_at"),
            sentAt = json.optionalString("sent_at"),
            expiresAt = json.requiredString("expires_at"),
        )
    }

    private fun encodeProof(proof: CommandProof): JSONObject = JSONObject()
        .putOptional("lat", proof.lat)
        .putOptional("lng", proof.lng)
        .putOptional("accuracy_m", proof.accuracyM)
        .putOptional("message", proof.message)
        .putOptional("executed_at", proof.executedAt)
        .putOptional("locked", proof.locked)
        .putOptional("wipe_started", proof.wipeStarted)
        .apply {
            if (proof.installedApps != null) {
                put("installed_apps", JSONArray(proof.installedApps))
            }
        }

    private fun successDataObject(body: String): JSONObject {
        val root = successRoot(body)
        return root.opt("data") as? JSONObject
            ?: throw MdmProtocolException("The MDM response data object is missing.")
    }

    private fun successRoot(body: String): JSONObject {
        if (body.isBlank()) throw MdmProtocolException("The MDM server returned an empty response.")
        val root = try {
            JSONObject(body)
        } catch (exception: JSONException) {
            throw MdmProtocolException("The MDM server returned malformed JSON.", exception)
        }
        if (root.opt("success") != true) {
            throw MdmProtocolException("The MDM server returned an unsuccessful 2xx response.")
        }
        return root
    }

    private inline fun <T> constructModel(block: () -> T): T = try {
        block()
    } catch (exception: MdmApiException) {
        throw exception
    } catch (exception: JSONException) {
        throw MdmProtocolException("The MDM server returned malformed JSON.", exception)
    } catch (exception: IllegalArgumentException) {
        throw MdmProtocolException("The MDM server returned data outside the API contract.", exception)
    }

    private fun JSONObject.requiredString(name: String): String = optionalString(name)
        ?.takeIf(String::isNotBlank)
        ?: throw MdmProtocolException("The MDM response is missing '$name'.")

    private fun JSONObject.optionalString(name: String): String? {
        if (!has(name) || isNull(name)) return null
        return opt(name) as? String
            ?: throw MdmProtocolException("The MDM response field '$name' must be a string.")
    }

    private fun JSONObject.optionalInteger(name: String): Int? {
        if (!has(name) || isNull(name)) return null
        val number = opt(name) as? Number
            ?: throw MdmProtocolException("The MDM response field '$name' must be an integer.")
        val doubleValue = number.toDouble()
        if (!doubleValue.isFinite() || doubleValue % 1.0 != 0.0 || doubleValue !in Int.MIN_VALUE.toDouble()..Int.MAX_VALUE.toDouble()) {
            throw MdmProtocolException("The MDM response field '$name' must be an integer.")
        }
        return number.toInt()
    }

    private fun JSONObject.optionalBoolean(name: String): Boolean? {
        if (!has(name) || isNull(name)) return null
        return opt(name) as? Boolean
            ?: throw MdmProtocolException("The MDM response field '$name' must be a boolean.")
    }

    private fun JSONObject.putOptional(name: String, value: Any?): JSONObject {
        if (value != null) put(name, value)
        return this
    }

    data class ParsedHttpError(
        val reason: String? = null,
        val message: String? = null,
        val validationErrors: Map<String, List<String>> = emptyMap(),
    )

    private const val MAX_REASON_LENGTH = 128
    private const val MAX_SERVER_MESSAGE_LENGTH = 1_000
}
