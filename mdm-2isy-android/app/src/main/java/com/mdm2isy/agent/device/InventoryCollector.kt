package com.mdm2isy.agent.device

import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.os.BatteryManager
import android.os.Build
import android.os.Environment
import android.os.StatFs
import android.telephony.TelephonyManager

private const val BYTES_PER_MEBIBYTE = 1024L * 1024L

data class InventorySnapshot(
    val manufacturer: String,
    val model: String,
    val androidVersion: String,
    val androidBuild: String,
    val serialNumber: String?,
    val imei: String?,
    val batteryLevel: Int?,
    val storageTotalMb: Long,
    val storageFreeMb: Long,
    val agentVersion: String,
    val installedApps: List<String>,
)

fun interface InventorySource {
    fun collect(): InventorySnapshot
}

class InventoryCollector(
    private val source: InventorySource,
) {
    constructor(context: Context) : this(AndroidInventorySource(context))

    fun collect(): InventorySnapshot = source.collect()
}

class AndroidInventorySource(
    context: Context,
) : InventorySource {
    private val applicationContext = context.applicationContext

    override fun collect(): InventorySnapshot {
        val storage = StatFs(Environment.getDataDirectory().absolutePath)

        return InventorySnapshot(
            manufacturer = Build.MANUFACTURER.cleanRequired("unknown"),
            model = Build.MODEL.cleanRequired("unknown"),
            androidVersion = Build.VERSION.RELEASE.cleanRequired(Build.VERSION.SDK_INT.toString()),
            androidBuild = Build.ID.cleanRequired(Build.DISPLAY.cleanRequired("unknown")),
            serialNumber = readSerialNumber(),
            imei = readImei(),
            batteryLevel = readBatteryLevel(),
            storageTotalMb = storage.totalBytes.coerceAtLeast(0L) / BYTES_PER_MEBIBYTE,
            storageFreeMb = storage.availableBytes.coerceAtLeast(0L) / BYTES_PER_MEBIBYTE,
            agentVersion = readAgentVersion(),
            installedApps = readInstalledApps(),
        )
    }

    private fun readInstalledApps(): List<String> {
        val pm = applicationContext.packageManager
        val flags = PackageManager.GET_META_DATA
        val applications = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            pm.getInstalledApplications(PackageManager.ApplicationInfoFlags.of(flags.toLong()))
        } else {
            @Suppress("DEPRECATION")
            pm.getInstalledApplications(flags)
        }
        
        return applications.map { it.packageName }
    }

    private fun readBatteryLevel(): Int? {
        val manager = applicationContext.getSystemService(BatteryManager::class.java)
            ?: return null
        return manager
            .getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
            .takeIf { it in 0..100 }
    }

    @SuppressLint("HardwareIds", "MissingPermission")
    private fun readSerialNumber(): String? = try {
        Build.getSerial().cleanOptional()
    } catch (_: SecurityException) {
        null
    } catch (_: RuntimeException) {
        null
    }

    @SuppressLint("HardwareIds", "MissingPermission")
    private fun readImei(): String? {
        if (!applicationContext.packageManager.hasSystemFeature(PackageManager.FEATURE_TELEPHONY)) {
            return null
        }

        val telephonyManager = applicationContext.getSystemService(TelephonyManager::class.java)
            ?: return null

        return try {
            telephonyManager.imei.cleanOptional()
        } catch (_: SecurityException) {
            null
        } catch (_: RuntimeException) {
            null
        }
    }

    private fun readAgentVersion(): String {
        val packageInfo = applicationContext.packageManager.getPackageInfo(
            applicationContext.packageName,
            PackageManager.PackageInfoFlags.of(0L),
        )
        return packageInfo.versionName.cleanRequired(packageInfo.longVersionCode.toString())
    }

    private fun String?.cleanRequired(fallback: String): String =
        cleanOptional() ?: fallback

    private fun String?.cleanOptional(): String? =
        this?.trim()?.takeIf { it.isNotEmpty() && it != Build.UNKNOWN }
}
