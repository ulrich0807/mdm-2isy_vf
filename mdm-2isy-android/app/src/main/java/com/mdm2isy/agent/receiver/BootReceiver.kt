package com.mdm2isy.agent.receiver

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import com.mdm2isy.agent.device.DeviceAdminController
import com.mdm2isy.agent.service.AgentStatusStore
import com.mdm2isy.agent.service.MdmAgentService
import com.mdm2isy.agent.storage.EnrollmentStore
import com.mdm2isy.agent.storage.InstallOperationStore
import java.io.File
import java.security.MessageDigest

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action !in SUPPORTED_ACTIONS) return
        if (!EnrollmentStore(context).isEnrolled()) return

        if (intent.action == Intent.ACTION_MY_PACKAGE_REPLACED) {
            reconcileAgentReplacement(context)
        }

        // Android 15+ forbids a BOOT_COMPLETED receiver from starting a
        // dataSync FGS. A provisioned Device Owner uses systemExempted instead.
        if (!DeviceAdminController(context).status().isDeviceOwner) {
            AgentStatusStore(context).update(
                running = false,
                message = "Ouvrez l'application pour relancer l'agent non provisionné.",
            )
            return
        }

        try {
            MdmAgentService.start(context)
        } catch (exception: RuntimeException) {
            AgentStatusStore(context).update(
                running = false,
                message = exception.message?.take(200)
                    ?: "Android a refusé le redémarrage automatique de l'agent.",
            )
        }
    }

    private fun reconcileAgentReplacement(context: Context) {
        runCatching {
            val packageInfo = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                context.packageManager.getPackageInfo(
                    context.packageName,
                    PackageManager.PackageInfoFlags.of(0L),
                )
            } else {
                @Suppress("DEPRECATION")
                context.packageManager.getPackageInfo(context.packageName, 0)
            }
            val sourceApk = File(requireNotNull(packageInfo.applicationInfo).sourceDir)
            InstallOperationStore(context).markSuccessfulReplacement(
                packageName = context.packageName,
                installedVersionCode = packageInfo.longVersionCode,
                installedVersionName = packageInfo.versionName,
                installedSha256 = sha256(sourceApk),
            )
        }
    }

    private fun sha256(file: File): String {
        val digest = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { input ->
            val buffer = ByteArray(64 * 1024)
            while (true) {
                val count = input.read(buffer)
                if (count < 0) break
                digest.update(buffer, 0, count)
            }
        }
        return digest.digest().joinToString("") { "%02x".format(it) }
    }

    private companion object {
        val SUPPORTED_ACTIONS = setOf(
            Intent.ACTION_BOOT_COMPLETED,
            Intent.ACTION_MY_PACKAGE_REPLACED,
        )
    }
}
