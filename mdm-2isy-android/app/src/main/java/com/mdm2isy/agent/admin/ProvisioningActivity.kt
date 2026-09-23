package com.mdm2isy.agent.admin

import android.app.Activity
import android.app.admin.DevicePolicyManager
import android.content.Intent
import android.os.Bundle
import android.os.PersistableBundle
import com.mdm2isy.agent.MainActivity

/**
 * Completes Android 12+ admin-integrated provisioning.
 *
 * Setup Wizard invokes this activity before it finalizes a fully-managed
 * device. Returning RESULT_OK is mandatory; otherwise Android rolls the
 * provisioning back and asks the user to factory-reset the terminal.
 */
class ProvisioningActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        when (intent?.action) {
            DevicePolicyManager.ACTION_GET_PROVISIONING_MODE -> returnProvisioningMode()
            DevicePolicyManager.ACTION_ADMIN_POLICY_COMPLIANCE -> acknowledgeCompliance()
            DevicePolicyManager.ACTION_PROVISIONING_SUCCESSFUL -> finishProvisioning()
            else -> {
                setResult(RESULT_CANCELED)
                finish()
            }
        }
    }

    private fun returnProvisioningMode() {
        val allowedModes = intent.getIntegerArrayListExtra(
            DevicePolicyManager.EXTRA_PROVISIONING_ALLOWED_PROVISIONING_MODES,
        )
        val fullyManaged = DevicePolicyManager.PROVISIONING_MODE_FULLY_MANAGED_DEVICE

        if (allowedModes != null && fullyManaged !in allowedModes) {
            setResult(RESULT_CANCELED)
            finish()
            return
        }

        val result = Intent().apply {
            putExtra(DevicePolicyManager.EXTRA_PROVISIONING_MODE, fullyManaged)
            putExtra(DevicePolicyManager.EXTRA_PROVISIONING_SKIP_EDUCATION_SCREENS, true)
            provisioningExtras()?.let {
                putExtra(DevicePolicyManager.EXTRA_PROVISIONING_ADMIN_EXTRAS_BUNDLE, it)
            }
        }
        setResult(RESULT_OK, result)
        finish()
    }

    private fun acknowledgeCompliance() {
        // Policies are applied by the agent after API enrollment. At this point
        // the DPC only has to acknowledge Setup Wizard's compliance checkpoint.
        setResult(RESULT_OK)
        finish()
    }

    private fun finishProvisioning() {
        val extras = provisioningExtras()
        startActivity(Intent(this, MainActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
            extras?.getString("api_url")?.let { putExtra("api_url", it) }
            extras?.getString("token")?.let { putExtra("token", it) }
        })
        setResult(RESULT_OK)
        finish()
    }

    @Suppress("DEPRECATION")
    private fun provisioningExtras(): PersistableBundle? =
        intent.getParcelableExtra(
            DevicePolicyManager.EXTRA_PROVISIONING_ADMIN_EXTRAS_BUNDLE,
        )
}
