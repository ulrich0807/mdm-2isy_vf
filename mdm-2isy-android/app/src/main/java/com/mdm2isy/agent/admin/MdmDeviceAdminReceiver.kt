package com.mdm2isy.agent.admin

import android.app.admin.DeviceAdminReceiver
import android.content.ComponentName
import android.content.Context

/**
 * Android entry point that grants the agent access to device-policy APIs.
 *
 * Provisioning the application as Device Owner is deliberately handled outside
 * this receiver. Merely installing the APK or activating a legacy device admin
 * does not turn the terminal into a fully managed device.
 */
class MdmDeviceAdminReceiver : DeviceAdminReceiver() {
    override fun onProfileProvisioningComplete(context: Context, intent: android.content.Intent) {
        val manager = context.getSystemService(Context.DEVICE_POLICY_SERVICE) as android.app.admin.DevicePolicyManager
        val componentName = componentName(context)
        // Some OEM ROMs reject setProfileName() for a fully-managed device.
        // A cosmetic label must never abort the critical provisioning callback.
        runCatching { manager.setProfileName(componentName, "2ISY MDM") }

        // Récupérer le bundle envoyé via le QR Code généré par l'API Laravel
        val bundle = intent.getParcelableExtra<android.os.PersistableBundle>(
            android.app.admin.DevicePolicyManager.EXTRA_PROVISIONING_ADMIN_EXTRAS_BUNDLE
        )
        val apiUrl = bundle?.getString("api_url")
        val token = bundle?.getString("token")

        // Démarrer l'agent MDM pour réaliser l'enrôlement final
        val launchIntent = android.content.Intent(context, com.mdm2isy.agent.MainActivity::class.java).apply {
            addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK)
            if (apiUrl != null) putExtra("api_url", apiUrl)
            if (token != null) putExtra("token", token)
        }
        context.startActivity(launchIntent)
    }

    companion object {
        @JvmStatic
        fun componentName(context: Context): ComponentName =
            ComponentName(context.applicationContext, MdmDeviceAdminReceiver::class.java)
    }
}
