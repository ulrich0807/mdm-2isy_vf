package com.mdm2isy.agent.storage

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject

enum class InstallOperationState {
    COMMITTING,
    SUCCEEDED,
    FAILED,
}

data class InstallOperation(
    val operationId: String,
    val commandPublicId: String,
    val packageName: String,
    val expectedVersionCode: Long?,
    val expectedVersionName: String?,
    val expectedSha256: String?,
    val sessionId: Int,
    val state: InstallOperationState,
    val errorMessage: String? = null,
    val createdAtEpochMs: Long = System.currentTimeMillis(),
)

/**
 * Durable bridge between PackageInstaller and the command journal.
 *
 * Package replacement kills the process that called Session.commit(). The
 * platform result therefore cannot rely on an in-memory callback, especially
 * when the package being replaced is this agent itself.
 */
class InstallOperationStore(context: Context) {
    private val preferences = context.applicationContext.getSharedPreferences(
        PREFERENCES,
        Context.MODE_PRIVATE,
    )

    @Synchronized
    fun prepare(operation: InstallOperation) {
        require(operation.state == InstallOperationState.COMMITTING) {
            "A new install operation must start in COMMITTING state."
        }
        val operations = readOperations().toMutableList()
        operations.removeAll {
            it.operationId == operation.operationId ||
                it.commandPublicId.equals(operation.commandPublicId, ignoreCase = true)
        }
        operations += operation
        writeOperations(prune(operations))
    }

    @Synchronized
    fun findByCommand(commandPublicId: String): InstallOperation? =
        readOperations().lastOrNull {
            it.commandPublicId.equals(commandPublicId, ignoreCase = true)
        }

    @Synchronized
    fun recordPlatformResult(
        operationId: String,
        success: Boolean,
        errorMessage: String?,
    ): InstallOperation? = updateByOperation(operationId) { operation ->
        operation.copy(
            state = if (success) {
                InstallOperationState.SUCCEEDED
            } else {
                InstallOperationState.FAILED
            },
            errorMessage = if (success) null else errorMessage?.take(MAX_ERROR_LENGTH),
        )
    }

    @Synchronized
    fun recordFailure(operationId: String, errorMessage: String): InstallOperation? =
        recordPlatformResult(operationId, false, errorMessage)

    /** Reconciles the durable commit before the service resumes after replacement. */
    @Synchronized
    fun markSuccessfulReplacement(
        packageName: String,
        installedVersionCode: Long,
        installedVersionName: String?,
        installedSha256: String?,
    ) {
        val operations = readOperations().toMutableList()
        var changed = false
        operations.indices.forEach { index ->
            val operation = operations[index]
            if (
                operation.state == InstallOperationState.COMMITTING &&
                operation.packageName == packageName &&
                operation.matchesInstalledVersion(
                    installedVersionCode,
                    installedVersionName,
                    installedSha256,
                )
            ) {
                operations[index] = operation.copy(
                    state = InstallOperationState.SUCCEEDED,
                    errorMessage = null,
                )
                changed = true
            }
        }
        if (changed) writeOperations(operations)
    }

    @Synchronized
    private fun updateByOperation(
        operationId: String,
        transform: (InstallOperation) -> InstallOperation,
    ): InstallOperation? {
        val operations = readOperations().toMutableList()
        val index = operations.indexOfLast { it.operationId == operationId }
        if (index < 0) return null
        val updated = transform(operations[index])
        operations[index] = updated
        writeOperations(operations)
        return updated
    }

    private fun readOperations(): List<InstallOperation> {
        val raw = preferences.getString(KEY_OPERATIONS, null) ?: return emptyList()
        return try {
            val array = JSONArray(raw)
            buildList(array.length()) {
                for (index in 0 until array.length()) {
                    val json = array.getJSONObject(index)
                    add(
                        InstallOperation(
                            operationId = json.getString("operation_id"),
                            commandPublicId = json.getString("command_public_id"),
                            packageName = json.getString("package_name"),
                            expectedVersionCode = json.optionalLong("expected_version_code"),
                            expectedVersionName = json.optionalString("expected_version_name"),
                            expectedSha256 = json.optionalString("expected_sha256"),
                            sessionId = json.getInt("session_id"),
                            state = InstallOperationState.valueOf(json.getString("state")),
                            errorMessage = json.optionalString("error_message"),
                            createdAtEpochMs = json.getLong("created_at_epoch_ms"),
                        ),
                    )
                }
            }
        } catch (_: Exception) {
            // A damaged auxiliary callback store must not put the Device Owner
            // into a boot crash loop. Keep a bounded diagnostic copy and let
            // self-update recovery fall back to version + installed APK hash.
            preferences.edit()
                .putString(KEY_CORRUPTED_OPERATIONS, raw.take(MAX_CORRUPTED_BACKUP_LENGTH))
                .remove(KEY_OPERATIONS)
                .commit()
            emptyList()
        }
    }

    private fun writeOperations(operations: List<InstallOperation>) {
        val array = JSONArray()
        operations.forEach { operation ->
            array.put(
                JSONObject()
                    .put("operation_id", operation.operationId)
                    .put("command_public_id", operation.commandPublicId)
                    .put("package_name", operation.packageName)
                    .putOptional("expected_version_code", operation.expectedVersionCode)
                    .putOptional("expected_version_name", operation.expectedVersionName)
                    .putOptional("expected_sha256", operation.expectedSha256)
                    .put("session_id", operation.sessionId)
                    .put("state", operation.state.name)
                    .putOptional("error_message", operation.errorMessage)
                    .put("created_at_epoch_ms", operation.createdAtEpochMs),
            )
        }
        check(preferences.edit().putString(KEY_OPERATIONS, array.toString()).commit()) {
            "Unable to persist the PackageInstaller operation before commit."
        }
    }

    private fun prune(operations: List<InstallOperation>): List<InstallOperation> {
        if (operations.size <= MAX_OPERATIONS) return operations
        val removable = operations
            .filter { it.state != InstallOperationState.COMMITTING }
            .sortedBy(InstallOperation::createdAtEpochMs)
            .take(operations.size - MAX_OPERATIONS)
            .map(InstallOperation::operationId)
            .toSet()
        return operations.filterNot { it.operationId in removable }
    }

    private fun InstallOperation.matchesInstalledVersion(
        installedVersionCode: Long,
        installedVersionName: String?,
        installedSha256: String?,
    ): Boolean {
        val expectedCode = expectedVersionCode ?: return false
        if (installedVersionCode < expectedCode) return false
        if (installedVersionCode > expectedCode) return true
        if (expectedVersionName != null && expectedVersionName != installedVersionName) return false
        return expectedSha256 == null || expectedSha256.equals(installedSha256, ignoreCase = true)
    }

    private fun JSONObject.optionalString(name: String): String? =
        if (!has(name) || isNull(name)) null else getString(name)

    private fun JSONObject.optionalLong(name: String): Long? =
        if (!has(name) || isNull(name)) null else getLong(name)

    private fun JSONObject.putOptional(name: String, value: Any?): JSONObject {
        if (value != null) put(name, value)
        return this
    }

    private companion object {
        const val PREFERENCES = "mdm_install_operations"
        const val KEY_OPERATIONS = "operations"
        const val KEY_CORRUPTED_OPERATIONS = "corrupted_operations"
        const val MAX_OPERATIONS = 32
        const val MAX_ERROR_LENGTH = 1_000
        const val MAX_CORRUPTED_BACKUP_LENGTH = 8_192
    }
}
