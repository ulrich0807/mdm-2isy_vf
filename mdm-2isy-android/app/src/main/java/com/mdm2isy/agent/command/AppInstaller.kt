package com.mdm2isy.agent.command

import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import com.mdm2isy.agent.receiver.AppInstallResultReceiver
import com.mdm2isy.agent.receiver.AppOperationCallbacks
import java.io.InputStream
import java.net.HttpURLConnection
import java.net.URL
import java.util.UUID
import kotlin.concurrent.thread

interface AppInstaller {
    fun installSilently(
        apkUrl: String,
        expectedPackageName: String,
        callback: (Boolean, String?) -> Unit,
    )
    fun uninstallSilently(packageName: String, callback: (Boolean, String?) -> Unit)
}

class AndroidAppInstaller(private val context: Context) : AppInstaller {

    private companion object {
        const val MAX_APK_BYTES = 100L * 1024L * 1024L
    }

    override fun installSilently(
        apkUrl: String,
        expectedPackageName: String,
        callback: (Boolean, String?) -> Unit,
    ) {
        thread {
            try {
                val url = URL(apkUrl)
                val connection = url.openConnection() as HttpURLConnection
                connection.requestMethod = "GET"
                connection.connectTimeout = 30_000
                connection.readTimeout = 60_000
                connection.instanceFollowRedirects = false
                connection.connect()

                if (connection.responseCode != HttpURLConnection.HTTP_OK) {
                    callback(false, "Échec du téléchargement (HTTP ${connection.responseCode})")
                    return@thread
                }

                if (connection.contentLengthLong > MAX_APK_BYTES) {
                    connection.disconnect()
                    callback(false, "Le fichier APK dépasse la limite de 100 Mo.")
                    return@thread
                }

                val packageInstaller = context.packageManager.packageInstaller
                val params = PackageInstaller.SessionParams(
                    PackageInstaller.SessionParams.MODE_FULL_INSTALL,
                ).apply {
                    setAppPackageName(expectedPackageName)
                }
                val sessionId = packageInstaller.createSession(params)
                val session = packageInstaller.openSession(sessionId)

                var totalBytes = 0L
                session.openWrite("mdm_install", 0, connection.contentLengthLong).use { out ->
                    connection.inputStream.use { input: InputStream ->
                        val buffer = ByteArray(65536)
                        var bytesRead: Int
                        while (input.read(buffer).also { bytesRead = it } != -1) {
                            totalBytes += bytesRead
                            if (totalBytes > MAX_APK_BYTES) {
                                throw IllegalArgumentException("Le fichier APK dépasse la limite de 100 Mo.")
                            }
                            out.write(buffer, 0, bytesRead)
                        }
                        session.fsync(out)
                    }
                }

                val operationId = UUID.randomUUID().toString()
                AppOperationCallbacks.register(operationId, callback)
                val intent = Intent(context, AppInstallResultReceiver::class.java).apply {
                    putExtra(AppInstallResultReceiver.EXTRA_OPERATION_ID, operationId)
                }
                val pendingIntent = PendingIntent.getBroadcast(
                    context,
                    sessionId,
                    intent,
                    PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE
                )
                try {
                    session.commit(pendingIntent.intentSender)
                } catch (exception: Exception) {
                    AppOperationCallbacks.remove(operationId)
                    throw exception
                } finally {
                    session.close()
                    connection.disconnect()
                }

            } catch (e: Exception) {
                callback(false, e.message)
            }
        }
    }

    override fun uninstallSilently(packageName: String, callback: (Boolean, String?) -> Unit) {
        try {
            val pm = context.packageManager
            val appInfo = pm.getApplicationInfo(packageName, 0)
            if ((appInfo.flags and android.content.pm.ApplicationInfo.FLAG_SYSTEM) != 0) {
                // Pour les applications système, on ne peut pas les désinstaller, on les masque.
                val dpm = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as android.app.admin.DevicePolicyManager
                val componentName = android.content.ComponentName(context, com.mdm2isy.agent.admin.MdmDeviceAdminReceiver::class.java)
                dpm.setApplicationHidden(componentName, packageName, true)
                callback(true, null)
                return
            }

            val packageInstaller = pm.packageInstaller
            val operationId = UUID.randomUUID().toString()
            AppOperationCallbacks.register(operationId, callback)
            val intent = Intent(context, AppInstallResultReceiver::class.java).apply {
                putExtra(AppInstallResultReceiver.EXTRA_OPERATION_ID, operationId)
            }
            val pendingIntent = PendingIntent.getBroadcast(
                context,
                System.currentTimeMillis().toInt(),
                intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE
            )
            try {
                packageInstaller.uninstall(packageName, pendingIntent.intentSender)
            } catch (exception: Exception) {
                AppOperationCallbacks.remove(operationId)
                throw exception
            }
        } catch (e: Exception) {
            callback(false, e.message)
        }
    }
}
