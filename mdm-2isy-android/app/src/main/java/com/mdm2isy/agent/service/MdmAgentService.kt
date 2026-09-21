package com.mdm2isy.agent.service

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import com.mdm2isy.agent.MainActivity
import com.mdm2isy.agent.R
import com.mdm2isy.agent.device.DeviceAdminController
import com.mdm2isy.agent.device.InventoryCollector
import com.mdm2isy.agent.network.MdmApiClient
import com.mdm2isy.agent.network.MdmAuthenticationException
import com.mdm2isy.agent.network.MdmHttpException
import com.mdm2isy.agent.network.MdmProtocolException
import com.mdm2isy.agent.network.MdmRateLimitException
import com.mdm2isy.agent.network.MdmTransportException
import com.mdm2isy.agent.storage.CommandJournal
import com.mdm2isy.agent.storage.EnrollmentStore
import java.util.concurrent.Executors
import java.util.concurrent.ScheduledExecutorService
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean
import kotlin.math.min

class MdmAgentService : Service() {
    private lateinit var enrollmentStore: EnrollmentStore
    private lateinit var commandJournal: CommandJournal
    private lateinit var statusStore: AgentStatusStore
    private lateinit var inventoryCollector: InventoryCollector
    private lateinit var commandCoordinator: CommandCoordinator
    private lateinit var worker: ScheduledExecutorService

    @Volatile
    private var nextHeartbeatAtEpochMs = 0L

    @Volatile
    private var nextAttemptAtEpochMs = 0L

    @Volatile
    private var consecutiveFailures = 0

    @Volatile
    private var stopReason: String? = null

    private val recurringTaskStarted = AtomicBoolean(false)

    override fun onCreate() {
        super.onCreate()
        enrollmentStore = EnrollmentStore(this)
        commandJournal = CommandJournal(this)
        statusStore = AgentStatusStore(this)
        inventoryCollector = InventoryCollector(this)
        commandCoordinator = CommandCoordinator(this)
        worker = Executors.newSingleThreadScheduledExecutor { runnable ->
            Thread(runnable, "mdm-agent-worker").apply { isDaemon = true }
        }

        createNotificationChannel()
        promoteToForeground()
        statusStore.update(running = true, message = "Supervision démarrée.")
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        promoteToForeground()
        
        if (!enrollmentStore.isEnrolled()) {
            stopReason = "Le terminal n'est pas enrôlé."
            stopSelf(startId)
            return START_NOT_STICKY
        }

        if (worker.isShutdown) return START_NOT_STICKY
        if (intent?.getBooleanExtra("FORCE_SYNC", false) == true) {
            nextAttemptAtEpochMs = 0L
            worker.execute(::runCycleSafely)
        }
        if (recurringTaskStarted.compareAndSet(false, true)) {
            worker.scheduleWithFixedDelay(
                ::runCycleSafely,
                0L,
                POLL_INTERVAL_SECONDS,
                TimeUnit.SECONDS,
            )
        }
        return START_STICKY
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onDestroy() {
        if (::worker.isInitialized) worker.shutdownNow()
        if (::statusStore.isInitialized) {
            statusStore.update(
                running = false,
                message = stopReason ?: "Supervision arrêtée.",
            )
        }
        super.onDestroy()
    }

    override fun onTimeout(startId: Int, fgsType: Int) {
        stopReason = "Android a arrêté la supervision après le délai du service."
        stopSelf()
    }

    private fun runCycleSafely() {
        val now = System.currentTimeMillis()
        if (now < nextAttemptAtEpochMs) return

        val session = enrollmentStore.load()
        if (session == null) {
            stopReason = "L'identité d'enrôlement est absente ou illisible."
            stopSelf()
            return
        }

        try {
            val api = MdmApiClient(session.apiUrl)
            val report = commandCoordinator.synchronize(api, session.deviceToken)
            if (now >= nextHeartbeatAtEpochMs) {
                val prefs = getSharedPreferences("mdm_prefs", Context.MODE_PRIVATE)
                val fcmToken = prefs.getString("fcm_token", null)
                
                // Fetch location synchronously for the heartbeat
                var currentLat: Double? = null
                var currentLng: Double? = null
                val latch = java.util.concurrent.CountDownLatch(1)
                val locationProvider = com.mdm2isy.agent.location.DeviceLocationProvider(this@MdmAgentService)
                locationProvider.locate(timeoutMillis = 5000L, highAccuracy = false) { result ->
                    if (result is com.mdm2isy.agent.location.DeviceLocationResult.Success) {
                        currentLat = result.location.latitude
                        currentLng = result.location.longitude
                    }
                    latch.countDown()
                }
                try {
                    latch.await(6, java.util.concurrent.TimeUnit.SECONDS)
                } catch (e: Exception) {}

                val request = inventoryCollector.collect().toHeartbeatRequest().copy(
                    fcmToken = fcmToken,
                    latitude = currentLat,
                    longitude = currentLng
                )
                
                val receipt = api.heartbeat(
                    deviceToken = session.deviceToken,
                    request = request,
                )
                if (!receipt.deviceId.equals(session.deviceId, ignoreCase = true)) {
                    throw MdmProtocolException("The heartbeat returned another device identity.")
                }
                nextHeartbeatAtEpochMs = System.currentTimeMillis() +
                    HEARTBEAT_INTERVAL_SECONDS * 1_000L
                
                receipt.policy?.let { policy ->
                    DeviceAdminController(this@MdmAgentService).applyPolicy(policy)
                }
            }

            consecutiveFailures = 0
            nextAttemptAtEpochMs = System.currentTimeMillis() + POLL_INTERVAL_SECONDS * 1_000L
            statusStore.update(
                running = true,
                synced = true,
                message = if (report.received == 0) {
                    "Synchronisation réussie, aucune commande en attente."
                } else {
                    "${report.received} commande(s) reçue(s), ${report.finalized} finalisée(s)."
                },
            )
        } catch (_: MdmAuthenticationException) {
            enrollmentStore.clear()
            commandJournal.clear()
            stopReason = "L'identité du terminal a été révoquée par le serveur."
            stopSelf()
        } catch (exception: MdmRateLimitException) {
            val delay = (exception.retryAfterSeconds ?: DEFAULT_RATE_LIMIT_SECONDS)
                .coerceIn(1L, MAX_RATE_LIMIT_SECONDS)
            nextAttemptAtEpochMs = System.currentTimeMillis() + delay * 1_000L
            statusStore.update(
                running = true,
                message = "Serveur temporairement limité ; nouvel essai dans ${delay}s.",
            )
        } catch (_: MdmTransportException) {
            scheduleNetworkRetry("Serveur MDM injoignable.")
        } catch (exception: MdmHttpException) {
            scheduleNetworkRetry(
                "Erreur serveur HTTP ${exception.status}${exception.reason?.let { " ($it)" } ?: ""}.",
            )
        } catch (_: MdmProtocolException) {
            scheduleNetworkRetry("Réponse du serveur incompatible avec le protocole MDM.")
        } catch (exception: IllegalArgumentException) {
            stopReason = exception.message ?: "Configuration locale invalide."
            stopSelf()
        } catch (exception: RuntimeException) {
            scheduleNetworkRetry(
                exception.message?.take(200) ?: "Erreur Android pendant la synchronisation.",
            )
        }
    }

    private fun scheduleNetworkRetry(message: String) {
        consecutiveFailures += 1
        val exponent = min(consecutiveFailures - 1, MAX_BACKOFF_EXPONENT)
        val delay = (INITIAL_RETRY_SECONDS shl exponent).coerceAtMost(MAX_RETRY_SECONDS)
        nextAttemptAtEpochMs = System.currentTimeMillis() + delay * 1_000L
        statusStore.update(
            running = true,
            message = "$message Nouvel essai dans ${delay}s.",
        )
    }

    private fun createNotificationChannel() {
        val manager = getSystemService(NotificationManager::class.java) ?: return
        manager.createNotificationChannel(
            NotificationChannel(
                NOTIFICATION_CHANNEL_ID,
                getString(R.string.agent_channel_name),
                NotificationManager.IMPORTANCE_LOW,
            ).apply {
                description = getString(R.string.agent_channel_description)
                setShowBadge(false)
            },
        )
    }

    private fun promoteToForeground() {
        val notification = buildNotification()
        val isDeviceOwner = DeviceAdminController(this).status().isDeviceOwner
        var serviceType = if (
            Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE && isDeviceOwner
        ) {
            ServiceInfo.FOREGROUND_SERVICE_TYPE_SYSTEM_EXEMPTED
        } else {
            ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC
        }

        if (canUseBackgroundLocationType()) {
            serviceType = serviceType or ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION
        }

        try {
            startForeground(NOTIFICATION_ID, notification, serviceType)
        } catch (_: SecurityException) {
            // A debug device may have foreground location but not background
            // location yet. Keep the network agent alive and let locate fail safely.
            val fallback = if (
                Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE && isDeviceOwner
            ) {
                ServiceInfo.FOREGROUND_SERVICE_TYPE_SYSTEM_EXEMPTED
            } else {
                ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC
            }
            startForeground(NOTIFICATION_ID, notification, fallback)
        }
    }

    private fun canUseBackgroundLocationType(): Boolean {
        val foregroundGranted = checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED ||
            checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
        val backgroundGranted = checkSelfPermission(Manifest.permission.ACCESS_BACKGROUND_LOCATION) ==
            PackageManager.PERMISSION_GRANTED
        return foregroundGranted && backgroundGranted
    }

    private fun buildNotification(): Notification {
        val openApp = PendingIntent.getActivity(
            this,
            0,
            Intent(this, MainActivity::class.java),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )
        return Notification.Builder(this, NOTIFICATION_CHANNEL_ID)
            .setSmallIcon(android.R.drawable.stat_notify_sync_noanim)
            .setContentTitle(getString(R.string.agent_notification_title))
            .setContentText(getString(R.string.agent_notification_text))
            .setContentIntent(openApp)
            .setCategory(Notification.CATEGORY_SERVICE)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .build()
    }

    companion object {
        private const val NOTIFICATION_CHANNEL_ID = "mdm_agent_supervision"
        private const val NOTIFICATION_ID = 2102
        private const val POLL_INTERVAL_SECONDS = 30L
        private const val HEARTBEAT_INTERVAL_SECONDS = 60L
        private const val INITIAL_RETRY_SECONDS = 15L
        private const val MAX_RETRY_SECONDS = 300L
        private const val MAX_BACKOFF_EXPONENT = 5
        private const val DEFAULT_RATE_LIMIT_SECONDS = 60L
        private const val MAX_RATE_LIMIT_SECONDS = 3_600L

        fun start(context: Context) {
            context.applicationContext.startForegroundService(
                Intent(context.applicationContext, MdmAgentService::class.java),
            )
        }

        fun triggerImmediateSync(context: Context) {
            val intent = Intent(context.applicationContext, MdmAgentService::class.java)
            intent.putExtra("FORCE_SYNC", true)
            try {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    context.applicationContext.startForegroundService(intent)
                } else {
                    context.applicationContext.startService(intent)
                }
            } catch (e: Exception) {
                // Ignore exception, Device Owner should be exempt.
            }
        }
    }
}
