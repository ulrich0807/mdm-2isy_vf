package com.mdm2isy.agent.receiver

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
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

        AppOperationCallbacks.complete(
            operationId,
            status == PackageInstaller.STATUS_SUCCESS,
            if (status == PackageInstaller.STATUS_SUCCESS) null else message ?: "Échec Android ($status)",
        )
    }

    companion object {
        const val EXTRA_OPERATION_ID = "operation_id"
    }
}
