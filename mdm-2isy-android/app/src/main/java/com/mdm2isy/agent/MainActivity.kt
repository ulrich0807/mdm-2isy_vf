package com.mdm2isy.agent

import android.Manifest
import android.app.Activity
import android.app.admin.DevicePolicyManager
import android.content.pm.PackageManager
import android.content.Intent
import android.net.Uri
import android.os.PowerManager
import android.provider.Settings
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.view.View
import android.view.WindowManager
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import com.mdm2isy.agent.admin.MdmDeviceAdminReceiver
import com.mdm2isy.agent.device.DeviceAdminController
import com.mdm2isy.agent.device.InventoryCollector
import com.mdm2isy.agent.identity.DeviceIdentity
import com.mdm2isy.agent.network.DeviceApiBaseUrl
import com.mdm2isy.agent.network.MdmApiClient
import com.mdm2isy.agent.network.MdmHttpException
import com.mdm2isy.agent.network.MdmTransportException
import com.mdm2isy.agent.network.MdmValidationException
import com.mdm2isy.agent.service.AgentStatusStore
import com.mdm2isy.agent.service.MdmAgentService
import com.mdm2isy.agent.service.toEnrollmentRequest
import com.mdm2isy.agent.storage.EnrollmentStore
import java.text.DateFormat
import java.util.Date
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors

class MainActivity : Activity() {
    private lateinit var apiUrlInput: EditText
    private lateinit var enrollmentTokenInput: EditText
    private lateinit var enrollButton: Button
    private lateinit var enrollmentPanel: View
    private lateinit var enrollmentStatus: TextView
    private lateinit var deviceUidValue: TextView
    private lateinit var deviceOwnerValue: TextView
    private lateinit var agentStateValue: TextView
    private lateinit var lastSyncValue: TextView
    private lateinit var startAgentButton: Button
    private lateinit var kioskButton: Button
    private lateinit var messageValue: TextView

    private lateinit var enrollmentStore: EnrollmentStore
    private lateinit var statusStore: AgentStatusStore
    private lateinit var identity: DeviceIdentity
    private lateinit var backgroundWorker: ExecutorService
    private val mainHandler = Handler(Looper.getMainLooper())
    private var enrollmentInProgress = false

    private val refreshStatus = object : Runnable {
        override fun run() {
            renderState()
            mainHandler.postDelayed(this, STATUS_REFRESH_MILLIS)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
        setContentView(R.layout.activity_main)

        enrollmentStore = EnrollmentStore(this)
        statusStore = AgentStatusStore(this)
        identity = DeviceIdentity(this)
        backgroundWorker = Executors.newSingleThreadExecutor { runnable ->
            Thread(runnable, "mdm-enrollment-worker").apply { isDaemon = true }
        }

        bindViews()
        if (apiUrlInput.text.isBlank() && BuildConfig.DEFAULT_API_URL.isNotBlank()) {
            apiUrlInput.setText(BuildConfig.DEFAULT_API_URL)
        }
        
        val providedApiUrl = intent?.getStringExtra("api_url")
        val providedToken = intent?.getStringExtra("token")
        if (providedApiUrl != null) apiUrlInput.setText(providedApiUrl)
        if (providedToken != null) enrollmentTokenInput.setText(providedToken)
        enrollButton.setOnClickListener { enrollDevice() }
        startAgentButton.setOnClickListener { ensurePermissionsAndStartAgent() }
        kioskButton.setOnClickListener { toggleKioskMode() }

        ensureDeviceOwnerPermissionGrants()
        renderState()
        if (enrollmentStore.isEnrolled()) {
            startAgent(showSuccess = false)
        } else if (providedApiUrl != null && providedToken != null) {
            enrollDevice()
        }
    }

    override fun onResume() {
        super.onResume()
        mainHandler.removeCallbacks(refreshStatus)
        mainHandler.post(refreshStatus)
    }

    override fun onPause() {
        mainHandler.removeCallbacks(refreshStatus)
        super.onPause()
    }

    override fun onDestroy() {
        if (::backgroundWorker.isInitialized) backgroundWorker.shutdownNow()
        super.onDestroy()
    }

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray,
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode == PERMISSIONS_REQUEST_CODE) {
            startAgent(showSuccess = true)
        }
    }

    private fun bindViews() {
        apiUrlInput = findViewById(R.id.apiUrlInput)
        enrollmentTokenInput = findViewById(R.id.enrollmentTokenInput)
        enrollButton = findViewById(R.id.enrollButton)
        enrollmentPanel = findViewById(R.id.enrollmentPanel)
        enrollmentStatus = findViewById(R.id.enrollmentStatus)
        deviceUidValue = findViewById(R.id.deviceUidValue)
        deviceOwnerValue = findViewById(R.id.deviceOwnerValue)
        agentStateValue = findViewById(R.id.agentStateValue)
        lastSyncValue = findViewById(R.id.lastSyncValue)
        startAgentButton = findViewById(R.id.startAgentButton)
        kioskButton = findViewById(R.id.kioskButton)
        messageValue = findViewById(R.id.messageValue)
    }

    private fun enrollDevice() {
        if (enrollmentInProgress || enrollmentStore.isEnrolled()) return
        val rawUrl = apiUrlInput.text.toString().trim()
        val token = enrollmentTokenInput.text.toString().trim()
        if (rawUrl.isBlank()) {
            apiUrlInput.error = getString(R.string.api_url_required)
            return
        }
        if (token.isBlank()) {
            enrollmentTokenInput.error = getString(R.string.enrollment_token_required)
            return
        }

        enrollmentInProgress = true
        setEnrollmentFormEnabled(false)
        messageValue.text = getString(R.string.enrollment_in_progress)

        backgroundWorker.execute {
            try {
                val canonicalUrl = DeviceApiBaseUrl.from(rawUrl).value
                val request = InventoryCollector(this)
                    .collect()
                    .toEnrollmentRequest(
                        enrollmentToken = token,
                        deviceUid = identity.stableUuid(),
                    )
                val result = MdmApiClient(canonicalUrl).enroll(request)
                enrollmentStore.save(
                    apiUrl = canonicalUrl,
                    deviceId = result.deviceId,
                    deviceToken = result.deviceToken,
                )

                runOnUiThread {
                    enrollmentTokenInput.text.clear()
                    enrollmentInProgress = false
                    messageValue.text = getString(R.string.enrollment_success)
                    renderState()
                    ensurePermissionsAndStartAgent()
                }
            } catch (exception: Exception) {
                runOnUiThread {
                    enrollmentInProgress = false
                    setEnrollmentFormEnabled(true)
                    messageValue.text = enrollmentErrorMessage(exception)
                    renderState()
                }
            }
        }
    }

    private fun ensurePermissionsAndStartAgent() {
        if (!enrollmentStore.isEnrolled()) return
        ensureDeviceOwnerPermissionGrants()

        val missing = FOREGROUND_RUNTIME_PERMISSIONS.filter {
            checkSelfPermission(it) != PackageManager.PERMISSION_GRANTED
        }
        
        val pm = getSystemService(android.content.Context.POWER_SERVICE) as? PowerManager
        if (pm != null && !pm.isIgnoringBatteryOptimizations(packageName)) {
            try {
                val intent = Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS)
                intent.data = Uri.parse("package:$packageName")
                startActivity(intent)
            } catch (e: Exception) {
                // Ignore si l'intent n'est pas géré
            }
        }

        if (missing.isNotEmpty()) {
            requestPermissions(missing.toTypedArray(), PERMISSIONS_REQUEST_CODE)
        } else {
            startAgent(showSuccess = true)
        }
    }

    private fun ensureDeviceOwnerPermissionGrants() {
        val policyManager = getSystemService(DevicePolicyManager::class.java) ?: return
        if (!policyManager.isDeviceOwnerApp(packageName)) return
        val admin = MdmDeviceAdminReceiver.componentName(this)

        DEVICE_OWNER_RUNTIME_PERMISSIONS.forEach { permission ->
            runCatching {
                policyManager.setPermissionGrantState(
                    admin,
                    packageName,
                    permission,
                    DevicePolicyManager.PERMISSION_GRANT_STATE_GRANTED,
                )
            }
        }
    }

    private fun startAgent(showSuccess: Boolean) {
        try {
            MdmAgentService.start(this)
            if (showSuccess) {
                messageValue.text = if (
                    checkSelfPermission(Manifest.permission.ACCESS_BACKGROUND_LOCATION) ==
                    PackageManager.PERMISSION_GRANTED
                ) {
                    getString(R.string.agent_started_success)
                } else {
                    getString(R.string.background_location_missing)
                }
            }
        } catch (exception: RuntimeException) {
            messageValue.text = exception.message?.take(300)
                ?: getString(R.string.agent_start_failed)
        }
        renderState()
    }

    private var isKioskModeActive = false

    private fun toggleKioskMode() {
        val adminController = DeviceAdminController(this)
        if (!adminController.status().isDeviceOwner) {
            messageValue.text = "Mode Kiosque nécessite d'être Device Owner."
            return
        }
        try {
            if (isKioskModeActive) {
                stopLockTask()
                adminController.setKioskMode(emptyArray())
                isKioskModeActive = false
                kioskButton.setText(R.string.kiosk_enable_action)
            } else {
                adminController.setKioskMode(arrayOf(packageName))
                startLockTask()
                isKioskModeActive = true
                kioskButton.setText(R.string.kiosk_disable_action)
            }
        } catch (e: Exception) {
            messageValue.text = "Erreur Kiosque: ${e.message}"
        }
    }

    private fun renderState() {
        val session = enrollmentStore.load()
        val enrolled = session != null
        enrollmentStatus.text = getString(
            if (enrolled) R.string.status_enrolled else R.string.status_not_enrolled,
        )
        enrollmentStatus.setBackgroundResource(
            if (enrolled) R.drawable.status_success_background else R.drawable.status_background,
        )
        enrollmentStatus.setTextColor(
            getColor(if (enrolled) R.color.mdm_success else R.color.mdm_blue_dark),
        )
        enrollmentPanel.visibility = if (enrolled) View.GONE else View.VISIBLE
        setEnrollmentFormEnabled(!enrollmentInProgress && !enrolled)
        if (!enrolled && apiUrlInput.text.isBlank() && BuildConfig.DEFAULT_API_URL.isNotBlank()) {
            apiUrlInput.setText(BuildConfig.DEFAULT_API_URL)
        }

        deviceUidValue.text = getString(R.string.device_uid_format, identity.stableUuid())
        val owner = DeviceAdminController(this).status().isDeviceOwner
        deviceOwnerValue.text = getString(
            if (owner) R.string.device_owner_enabled else R.string.device_owner_disabled,
        )

        val status = statusStore.load()
        agentStateValue.text = getString(
            if (status.running) R.string.agent_running else R.string.agent_stopped,
        )
        lastSyncValue.text = if (status.lastSyncEpochMs > 0L) {
            getString(
                R.string.last_sync_format,
                DateFormat.getDateTimeInstance().format(Date(status.lastSyncEpochMs)),
            )
        } else {
            getString(R.string.last_sync_never)
        }
        if (!enrollmentInProgress && enrolled && status.lastMessage.isNotBlank()) {
            messageValue.text = status.lastMessage
        }
        messageValue.visibility = if (messageValue.text.isNullOrBlank()) View.GONE else View.VISIBLE
        startAgentButton.isEnabled = enrolled && !status.running
    }

    private fun setEnrollmentFormEnabled(enabled: Boolean) {
        apiUrlInput.isEnabled = enabled
        enrollmentTokenInput.isEnabled = enabled
        enrollButton.isEnabled = enabled
    }

    private fun enrollmentErrorMessage(exception: Exception): String = when (exception) {
        is MdmValidationException -> exception.validationErrors.values
            .flatten()
            .firstOrNull()
            ?: exception.serverMessage
            ?: getString(R.string.enrollment_rejected)

        is MdmTransportException -> getString(R.string.server_unreachable)
        is MdmHttpException -> exception.serverMessage
            ?: getString(R.string.server_http_error, exception.status)

        is IllegalArgumentException -> exception.message ?: getString(R.string.invalid_configuration)
        else -> exception.message?.take(300) ?: getString(R.string.enrollment_failed)
    }

    private companion object {
        const val PERMISSIONS_REQUEST_CODE = 2102
        const val STATUS_REFRESH_MILLIS = 2_000L

        val FOREGROUND_RUNTIME_PERMISSIONS = listOf(
            Manifest.permission.POST_NOTIFICATIONS,
            Manifest.permission.ACCESS_COARSE_LOCATION,
            Manifest.permission.ACCESS_FINE_LOCATION,
        )

        val DEVICE_OWNER_RUNTIME_PERMISSIONS = FOREGROUND_RUNTIME_PERMISSIONS +
            listOf(
                Manifest.permission.ACCESS_BACKGROUND_LOCATION,
                Manifest.permission.READ_PHONE_STATE,
            )
    }
}
