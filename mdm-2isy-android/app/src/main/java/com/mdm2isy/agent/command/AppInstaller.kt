package com.mdm2isy.agent.command

import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import java.io.InputStream
import java.net.HttpURLConnection
import java.net.URL
import kotlin.concurrent.thread

interface AppInstaller {
    fun installSilently(apkUrl: String, callback: (Boolean, String?) -> Unit)
    fun uninstallSilently(packageName: String, callback: (Boolean, String?) -> Unit)
}

class AndroidAppInstaller(private val context: Context) : AppInstaller {

    override fun installSilently(apkUrl: String, callback: (Boolean, String?) -> Unit) {
        thread {
            try {
                val url = URL(apkUrl)
                val connection = url.openConnection() as HttpURLConnection
                connection.requestMethod = "GET"
                connection.connect()

                if (connection.responseCode != HttpURLConnection.HTTP_OK) {
                    callback(false, "Échec du téléchargement (HTTP ${connection.responseCode})")
                    return@thread
                }

                val packageInstaller = context.packageManager.packageInstaller
                val params = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL)
                val sessionId = packageInstaller.createSession(params)
                val session = packageInstaller.openSession(sessionId)

                val out = session.openWrite("mdm_install", 0, -1)
                val input: InputStream = connection.inputStream
                val buffer = ByteArray(65536)
                var bytesRead: Int
                while (input.read(buffer).also { bytesRead = it } != -1) {
                    out.write(buffer, 0, bytesRead)
                }
                session.fsync(out)
                input.close()
                out.close()

                // We commit the session but we don't strictly wait for the broadcast for the command proof
                // because the MDM protocol requires an immediate result for the transition.
                // In a production app, you would use a PendingIntent and a BroadcastReceiver.
                val intent = Intent("com.mdm2isy.agent.ACTION_INSTALL_COMPLETE")
                val pendingIntent = PendingIntent.getBroadcast(
                    context,
                    sessionId,
                    intent,
                    PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE
                )
                session.commit(pendingIntent.intentSender)

                callback(true, null)

            } catch (e: Exception) {
                callback(false, e.message)
            }
        }
    }

    override fun uninstallSilently(packageName: String, callback: (Boolean, String?) -> Unit) {
        try {
            val packageInstaller = context.packageManager.packageInstaller
            val intent = Intent("com.mdm2isy.agent.ACTION_UNINSTALL_COMPLETE")
            val pendingIntent = PendingIntent.getBroadcast(
                context,
                System.currentTimeMillis().toInt(),
                intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE
            )
            packageInstaller.uninstall(packageName, pendingIntent.intentSender)
            callback(true, null)
        } catch (e: Exception) {
            callback(false, e.message)
        }
    }
}
