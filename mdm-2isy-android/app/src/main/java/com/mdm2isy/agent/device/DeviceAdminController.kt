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
    fun setApplicationHidden(packageName: String, hidden: Boolean): Boolean
    fun resetPassword(password: String, flags: Int): Boolean
    fun setPasswordQuality(quality: Int)
    fun setPasswordMinimumLength(length: Int)
    
    fun setLocationEnabled(enabled: Boolean)
    fun setStatusBarDisabled(disabled: Boolean): Boolean
    fun getAllInstalledPackages(): List<String>
}

class AndroidDevicePolicyGateway(
    private val context: Context,
    private val adminComponent: ComponentName = MdmDeviceAdminReceiver.componentName(context),
) : DevicePolicyGateway {
    private val applicationContext = context.applicationContext
    private val policyManager =
        requireNotNull(applicationContext.getSystemService(DevicePolicyManager::class.java)) {
            "DevicePolicyManager is unavailable on this device."
        }

    override fun getAllInstalledPackages(): List<String> {
        val pm = applicationContext.packageManager
        return pm.getInstalledPackages(0)
            .filter { ((it.applicationInfo?.flags ?: 0) and android.content.pm.ApplicationInfo.FLAG_SYSTEM) == 0 }
            .map { it.packageName }
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

    override fun setApplicationHidden(packageName: String, hidden: Boolean): Boolean {
        return policyManager.setApplicationHidden(adminComponent, packageName, hidden)
    }

    override fun resetPassword(password: String, flags: Int): Boolean {
        return policyManager.resetPassword(password, flags)
    }

    override fun setPasswordQuality(quality: Int) {
        policyManager.setPasswordQuality(adminComponent, quality)
    }

    override fun setPasswordMinimumLength(length: Int) {
        policyManager.setPasswordMinimumLength(adminComponent, length)
    }

    override fun setLocationEnabled(enabled: Boolean) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            policyManager.setLocationEnabled(adminComponent, enabled)
        } else {
            @Suppress("DEPRECATION")
            policyManager.setSecureSetting(
                adminComponent,
                android.provider.Settings.Secure.LOCATION_MODE,
                if (enabled) "3" else "0" // 3 = LOCATION_MODE_HIGH_ACCURACY, 0 = OFF
            )
        }
    }

    override fun setStatusBarDisabled(disabled: Boolean): Boolean =
        policyManager.setStatusBarDisabled(adminComponent, disabled)
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

    fun forceLocationEnabled(): DeviceAdminOperationResult {
        val precondition = requireActiveAdmin(requireDeviceOwner = true)
        if (precondition != null) {
            return precondition
        }

        return invokePolicy("L'activation du GPS a ete refusee par Android.") {
            gateway.setLocationEnabled(true)
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
            val blacklist = policy.blacklistApps
                .map(String::trim)
                .filter(String::isNotEmpty)
                .toSet()
            val whitelist = policy.whitelistApps
                .map(String::trim)
                .filter(String::isNotEmpty)
                .toSet()

            // The explicit deny-list is the highest priority. Apply it before every
            // other OEM-dependent policy so an unrelated rejection cannot skip it.
            blacklist
                .filterNot(::isAgentPackage)
                .forEach { pkg -> safelyApplyApplicationVisibility(pkg, hidden = true) }

            safelyApplyPolicy { gateway.setCameraDisabled(policy.noCam) }
            
            if (policy.noUsb) {
                safelyApplyPolicy {
                    gateway.addUserRestriction(android.os.UserManager.DISALLOW_USB_FILE_TRANSFER)
                }
            } else {
                safelyApplyPolicy {
                    gateway.clearUserRestriction(android.os.UserManager.DISALLOW_USB_FILE_TRANSFER)
                }
            }
            
            if (policy.noBt) {
                safelyApplyPolicy {
                    gateway.addUserRestriction(android.os.UserManager.DISALLOW_BLUETOOTH)
                }
                safelyApplyPolicy {
                    gateway.addUserRestriction(android.os.UserManager.DISALLOW_CONFIG_BLUETOOTH)
                }
            } else {
                safelyApplyPolicy {
                    gateway.clearUserRestriction(android.os.UserManager.DISALLOW_BLUETOOTH)
                }
                safelyApplyPolicy {
                    gateway.clearUserRestriction(android.os.UserManager.DISALLOW_CONFIG_BLUETOOTH)
                }
            }
            
            if (policy.noWifi) {
                safelyApplyPolicy {
                    gateway.addUserRestriction(android.os.UserManager.DISALLOW_CONFIG_WIFI)
                }
                safelyApplyPolicy {
                    gateway.addUserRestriction(android.os.UserManager.DISALLOW_CHANGE_WIFI_STATE)
                }
                safelyApplyPolicy {
                    gateway.addUserRestriction(android.os.UserManager.DISALLOW_ADD_WIFI_CONFIG)
                }
                safelyApplyPolicy {
                    gateway.addUserRestriction(android.os.UserManager.DISALLOW_WIFI_DIRECT)
                }
                safelyApplyPolicy {
                    gateway.addUserRestriction(android.os.UserManager.DISALLOW_WIFI_TETHERING)
                }
            } else {
                safelyApplyPolicy {
                    gateway.clearUserRestriction(android.os.UserManager.DISALLOW_CONFIG_WIFI)
                }
                safelyApplyPolicy {
                    gateway.clearUserRestriction(android.os.UserManager.DISALLOW_CHANGE_WIFI_STATE)
                }
                safelyApplyPolicy {
                    gateway.clearUserRestriction(android.os.UserManager.DISALLOW_ADD_WIFI_CONFIG)
                }
                safelyApplyPolicy {
                    gateway.clearUserRestriction(android.os.UserManager.DISALLOW_WIFI_DIRECT)
                }
                safelyApplyPolicy {
                    gateway.clearUserRestriction(android.os.UserManager.DISALLOW_WIFI_TETHERING)
                }
            }

            if (policy.noData) {
                safelyApplyPolicy {
                    gateway.addUserRestriction(android.os.UserManager.DISALLOW_CONFIG_MOBILE_NETWORKS)
                }
                safelyApplyPolicy {
                    gateway.addUserRestriction(android.os.UserManager.DISALLOW_DATA_ROAMING)
                }
                safelyApplyPolicy {
                    gateway.addUserRestriction(android.os.UserManager.DISALLOW_CONFIG_TETHERING)
                }
            } else {
                safelyApplyPolicy {
                    gateway.clearUserRestriction(android.os.UserManager.DISALLOW_CONFIG_MOBILE_NETWORKS)
                }
                safelyApplyPolicy {
                    gateway.clearUserRestriction(android.os.UserManager.DISALLOW_DATA_ROAMING)
                }
                safelyApplyPolicy {
                    gateway.clearUserRestriction(android.os.UserManager.DISALLOW_CONFIG_TETHERING)
                }
            }

            if (policy.noAirplane) {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                    gateway.addUserRestriction(android.os.UserManager.DISALLOW_AIRPLANE_MODE)
                }
            } else {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                    gateway.clearUserRestriction(android.os.UserManager.DISALLOW_AIRPLANE_MODE)
                }
            }
            
            gateway.setPasswordQuality(
                if (policy.pinFort) DevicePolicyManager.PASSWORD_QUALITY_NUMERIC_COMPLEX
                else DevicePolicyManager.PASSWORD_QUALITY_UNSPECIFIED,
            )
            gateway.setPasswordMinimumLength(if (policy.pinFort) 6 else 0)
            
            if (policy.kiosk) {
                if (policy.kioskApps.isNotEmpty()) {
                    gateway.setLockTaskPackages(policy.kioskApps.toTypedArray())
                } else if (!policy.appKiosk.isNullOrBlank()) {
                    // Fallback for older profiles
                    gateway.setLockTaskPackages(arrayOf(policy.appKiosk))
                } else {
                    gateway.setLockTaskPackages(emptyArray())
                }
            } else {
                gateway.setLockTaskPackages(emptyArray())
            }
            
            // Recalculate every known application state so removing an old blacklist
            // or disabling a whitelist restores applications that were hidden before.
            runCatching { gateway.getAllInstalledPackages() }
                .getOrDefault(emptyList())
                .asSequence()
                .map(String::trim)
                .filter(String::isNotEmpty)
                .filterNot(::isAgentPackage)
                .filterNot(blacklist::contains)
                .forEach { pkg ->
                    val hidden = whitelist.isNotEmpty() && !whitelist.contains(pkg)
                    safelyApplyApplicationVisibility(pkg, hidden)
                }

            // Tecno and similar devices may keep network toggles in Quick Settings.
            // This call is intentionally last and isolated: an OEM rejection must not
            // cancel camera, network, password, kiosk or application restrictions.
            runCatching {
                val accepted = gateway.setStatusBarDisabled(
                    policy.noWifi || policy.noData || policy.noAirplane || policy.kiosk,
                )
                check(accepted) { "Android/OEM rejected the status-bar policy." }
            }.onFailure { error ->
                // android.util.Log is unavailable in local JVM tests. Logging is
                // diagnostic only and must never make the full policy fail.
                runCatching {
                    android.util.Log.w("MdmPolicy", "Status-bar policy was not applied.", error)
                }
            }
        }
    }

    private fun isAgentPackage(packageName: String): Boolean =
        packageName == "com.mdm2isy.agent"

    private fun safelyApplyApplicationVisibility(packageName: String, hidden: Boolean) {
        runCatching { gateway.setApplicationHidden(packageName, hidden) }
    }

    private inline fun safelyApplyPolicy(operation: () -> Unit) {
        runCatching(operation)
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
