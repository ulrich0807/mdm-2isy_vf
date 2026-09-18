package com.mdm2isy.agent.device

import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.os.Build
import com.mdm2isy.agent.admin.MdmDeviceAdminReceiver

data class DeviceAdminStatus(
    val isAdminActive: Boolean,
    val isDeviceOwner: Boolean,
)

enum class DeviceAdminFailureReason {
    ADMIN_INACTIVE,
    DEVICE_OWNER_REQUIRED,
    POLICY_REJECTED,
    PLATFORM_FAILURE,
}

sealed interface DeviceAdminOperationResult {
    data object Completed : DeviceAdminOperationResult

    data class Failed(
        val reason: DeviceAdminFailureReason,
        val message: String,
    ) : DeviceAdminOperationResult
}

/**
 * Small seam around DevicePolicyManager so command behavior can be unit tested
 * without a provisioned emulator.
 */
interface DevicePolicyGateway {
    fun isAdminActive(): Boolean

    fun isDeviceOwner(): Boolean

    fun lockNow()

    fun wipeDeviceData(flags: Int)

    fun setLockTaskPackages(packages: Array<String>)
    
    fun setCameraDisabled(disabled: Boolean)
    fun addUserRestriction(restriction: String)
    fun clearUserRestriction(restriction: String)
    fun resetPassword(password: String, flags: Int): Boolean
}

class AndroidDevicePolicyGateway(
    context: Context,
    private val adminComponent: ComponentName = MdmDeviceAdminReceiver.componentName(context),
) : DevicePolicyGateway {
    private val applicationContext = context.applicationContext
    private val policyManager =
        requireNotNull(applicationContext.getSystemService(DevicePolicyManager::class.java)) {
            "DevicePolicyManager is unavailable on this device."
        }

    override fun isAdminActive(): Boolean = policyManager.isAdminActive(adminComponent)

    override fun isDeviceOwner(): Boolean =
        policyManager.isDeviceOwnerApp(applicationContext.packageName)

    override fun lockNow() {
        policyManager.lockNow()
    }

    @Suppress("DEPRECATION")
    override fun wipeDeviceData(flags: Int) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            policyManager.wipeDevice(flags)
        } else {
            policyManager.wipeData(flags)
        }
    }

    override fun setLockTaskPackages(packages: Array<String>) {
        policyManager.setLockTaskPackages(adminComponent, packages)
    }

    override fun setCameraDisabled(disabled: Boolean) {
        policyManager.setCameraDisabled(adminComponent, disabled)
    }

    override fun addUserRestriction(restriction: String) {
        policyManager.addUserRestriction(adminComponent, restriction)
    }

    override fun clearUserRestriction(restriction: String) {
        policyManager.clearUserRestriction(adminComponent, restriction)
    }

    override fun resetPassword(password: String, flags: Int): Boolean {
        return policyManager.resetPassword(password, flags)
    }
}

/**
 * Enforces the privilege checks that must happen before destructive policy calls.
 */
class DeviceAdminController(
    private val gateway: DevicePolicyGateway,
) {
    constructor(context: Context) : this(AndroidDevicePolicyGateway(context))

    fun status(): DeviceAdminStatus = DeviceAdminStatus(
        isAdminActive = safelyCheck { gateway.isAdminActive() },
        isDeviceOwner = safelyCheck { gateway.isDeviceOwner() },
    )

    fun lockDevice(): DeviceAdminOperationResult {
        val precondition = requireActiveAdmin(requireDeviceOwner = false)
        if (precondition != null) {
            return precondition
        }

        return invokePolicy("Le verrouillage a ete refuse par Android.") {
            gateway.lockNow()
        }
    }

    /**
     * Invokes the real platform wipe. Completed is returned only if wipeDevice(0)
     * on Android 14+, or wipeData(0) on Android 13, returned without throwing;
     * no success is fabricated beforehand.
     * On many devices the process is terminated before this method can return.
     */
    fun wipeDevice(): DeviceAdminOperationResult {
        val precondition = requireActiveAdmin(requireDeviceOwner = true)
        if (precondition != null) {
            return precondition
        }

        return invokePolicy("L'effacement a ete refuse par Android.") {
            gateway.wipeDeviceData(0)
        }
    }

    fun setKioskMode(packages: Array<String>): DeviceAdminOperationResult {
        val precondition = requireActiveAdmin(requireDeviceOwner = true)
        if (precondition != null) {
            return precondition
        }

        return invokePolicy("Le mode kiosque a été refusé par Android.") {
            gateway.setLockTaskPackages(packages)
        }
    }

    fun applyPolicy(policy: com.mdm2isy.agent.model.SecurityPolicy): DeviceAdminOperationResult {
        val precondition = requireActiveAdmin(requireDeviceOwner = true)
        if (precondition != null) {
            return precondition
        }
        
        return invokePolicy("L'application de la politique a été refusée.") {
            gateway.setCameraDisabled(policy.noCam)
            
            if (policy.noUsb) {
                gateway.addUserRestriction(android.os.UserManager.DISALLOW_USB_FILE_TRANSFER)
            } else {
                gateway.clearUserRestriction(android.os.UserManager.DISALLOW_USB_FILE_TRANSFER)
            }
            
            if (policy.noBt) {
                gateway.addUserRestriction(android.os.UserManager.DISALLOW_BLUETOOTH)
            } else {
                gateway.clearUserRestriction(android.os.UserManager.DISALLOW_BLUETOOTH)
            }
            
            if (policy.pinFort) {
                // PIN setup requires more interaction, but we can set quality
                // gateway.policyManager.setPasswordQuality(adminComponent, DevicePolicyManager.PASSWORD_QUALITY_NUMERIC_COMPLEX)
            }
            
            if (policy.kiosk && !policy.appKiosk.isNullOrBlank()) {
                gateway.setLockTaskPackages(arrayOf(policy.appKiosk))
            } else {
                gateway.setLockTaskPackages(emptyArray())
            }
        }
    }

    private fun requireActiveAdmin(
        requireDeviceOwner: Boolean,
    ): DeviceAdminOperationResult.Failed? {
        val active = try {
            gateway.isAdminActive()
        } catch (_: SecurityException) {
            return DeviceAdminOperationResult.Failed(
                DeviceAdminFailureReason.POLICY_REJECTED,
                "Android interdit la verification de l'administrateur MDM.",
            )
        } catch (_: RuntimeException) {
            return DeviceAdminOperationResult.Failed(
                DeviceAdminFailureReason.PLATFORM_FAILURE,
                "Impossible de verifier l'etat de l'administrateur MDM.",
            )
        }

        if (!active) {
            return DeviceAdminOperationResult.Failed(
                DeviceAdminFailureReason.ADMIN_INACTIVE,
                "L'administrateur MDM n'est pas actif sur ce terminal.",
            )
        }

        if (!requireDeviceOwner) {
            return null
        }

        val owner = try {
            gateway.isDeviceOwner()
        } catch (_: SecurityException) {
            return DeviceAdminOperationResult.Failed(
                DeviceAdminFailureReason.POLICY_REJECTED,
                "Android interdit la verification du mode Device Owner.",
            )
        } catch (_: RuntimeException) {
            return DeviceAdminOperationResult.Failed(
                DeviceAdminFailureReason.PLATFORM_FAILURE,
                "Impossible de verifier le mode Device Owner.",
            )
        }

        return if (owner) {
            null
        } else {
            DeviceAdminOperationResult.Failed(
                DeviceAdminFailureReason.DEVICE_OWNER_REQUIRED,
                "L'effacement exige un terminal provisionne en Device Owner.",
            )
        }
    }

    private inline fun invokePolicy(
        rejectedMessage: String,
        operation: () -> Unit,
    ): DeviceAdminOperationResult = try {
        operation()
        DeviceAdminOperationResult.Completed
    } catch (_: SecurityException) {
        DeviceAdminOperationResult.Failed(
            DeviceAdminFailureReason.POLICY_REJECTED,
            rejectedMessage,
        )
    } catch (_: RuntimeException) {
        DeviceAdminOperationResult.Failed(
            DeviceAdminFailureReason.PLATFORM_FAILURE,
            "Android n'a pas pu executer l'operation MDM.",
        )
    }

    private inline fun safelyCheck(check: () -> Boolean): Boolean = try {
        check()
    } catch (_: RuntimeException) {
        false
    }
}
