package com.mdm2isy.agent.service

import com.mdm2isy.agent.device.InventorySnapshot
import com.mdm2isy.agent.model.EnrollmentRequest
import com.mdm2isy.agent.model.HeartbeatRequest

internal fun InventorySnapshot.toEnrollmentRequest(
    enrollmentToken: String,
    deviceUid: String,
): EnrollmentRequest = EnrollmentRequest(
    enrollmentToken = enrollmentToken,
    deviceUid = deviceUid,
    imei = imei,
    serialNumber = serialNumber,
    manufacturer = manufacturer,
    model = model,
    androidVersion = androidVersion,
    androidBuild = androidBuild,
    agentVersion = agentVersion,
    batteryLevel = batteryLevel,
    storageTotalMb = storageTotalMb,
    storageFreeMb = storageFreeMb,
)

internal fun InventorySnapshot.toHeartbeatRequest(): HeartbeatRequest = HeartbeatRequest(
    imei = imei,
    serialNumber = serialNumber,
    manufacturer = manufacturer,
    model = model,
    androidVersion = androidVersion,
    androidBuild = androidBuild,
    agentVersion = agentVersion,
    batteryLevel = batteryLevel,
    storageTotalMb = storageTotalMb,
    storageFreeMb = storageFreeMb,
    installedApps = installedApps,
)
