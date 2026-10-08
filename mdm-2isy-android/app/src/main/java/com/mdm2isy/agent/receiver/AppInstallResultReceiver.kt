package com.mdm2isy.agent.receiver

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import com.mdm2isy.agent.service.MdmAgentService
import com.mdm2isy.agent.storage.EnrollmentStore
import com.mdm2isy.agent.storage.InstallOperationStore
import java.util.concurrent.ConcurrentHashMap

object AppOperationCallbacks {
    private val callbacks = ConcurrentHashMap<String, (Boolean, String?) -> Unit>()

    fun register(operationId: String, callback: (Boolean, String?) -> Unit) {
        callbacks[operationId] = callback
    }

    fun complete(operationId: String, success: Boolean, error: String?) {
        callbacks.remove(operationId)?.invoke(success, error)
    }

    fun remove(operationId: String) {
        callbacks.remove(operationId)
    }
}

class AppInstallResultReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val operationId = intent.getStringExtra(EXTRA_OPERATION_ID) ?: return
        val status = intent.getIntExtra(
            PackageInstaller.EXTRA_STATUS,
            PackageInstaller.STATUS_FAILURE,
        )
        val message = intent.getStringExtra(PackageInstaller.EXTRA_STATUS_MESSAGE)
        val success = status == PackageInstaller.STATUS_SUCCESS
        val error = if (success) null else message ?: "Échec Android ($status)"

        // Persist first. A self-update can recreate this receiver in a fresh
        // process where the original callback map no longer exists.
        InstallOperationStore(context).recordPlatformResult(
            operationId = operationId,
            success = success,
            errorMessage = error,
        )

        AppOperationCallbacks.complete(
            operationId,
            success,
            error,
        )
        // Also wake legacy 0.1.9 handoffs: they have a durable command journal
        // but predate InstallOperationStore, so no operation record exists.
        if (EnrollmentStore(context).isEnrolled()) {
            MdmAgentService.triggerImmediateSync(context)
        }
    }

    companion object {
        const val EXTRA_OPERATION_ID = "operation_id"
    }
}
