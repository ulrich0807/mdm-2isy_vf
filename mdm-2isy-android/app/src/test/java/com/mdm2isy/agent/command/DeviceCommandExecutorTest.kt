package com.mdm2isy.agent.command

import com.mdm2isy.agent.device.DeviceAdminController
import com.mdm2isy.agent.device.DeviceAdminFailureReason
import com.mdm2isy.agent.device.DeviceAdminOperationResult
import com.mdm2isy.agent.device.DevicePolicyGateway
import com.mdm2isy.agent.location.CancellableLocationRequest
import com.mdm2isy.agent.location.CurrentLocationSource
import com.mdm2isy.agent.location.DeviceLocation
import com.mdm2isy.agent.location.DeviceLocationProvider
import com.mdm2isy.agent.location.LocationRequestStart
import com.mdm2isy.agent.location.LocationTimeoutScheduler
import com.mdm2isy.agent.model.CommandPayload
import com.mdm2isy.agent.model.DeviceCommand
import java.time.Clock
import java.time.Instant
import java.time.ZoneOffset
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class DeviceCommandExecutorTest {
    @Test
    fun `locate returns backend-compatible proof with explicit UTC offset`() {
        val locationSource = object : CurrentLocationSource {
            override fun request(
                highAccuracy: Boolean,
                callback: (DeviceLocation?) -> Unit,
            ): LocationRequestStart {
                assertTrue(highAccuracy)
                callback(DeviceLocation(5.3599, -4.0083, 8.5, 1L))
                return LocationRequestStart.Started(CancellableLocationRequest { })
            }
        }
        val executor = DeviceCommandExecutor(
            adminController = DeviceAdminController(FakePolicyGateway()),
            locationProvider = DeviceLocationProvider(
                source = locationSource,
                timeoutScheduler = LocationTimeoutScheduler { _, _ ->
                    CancellableLocationRequest { }
                },
            ),
            appInstaller = FakeAppInstaller(),
            clock = FIXED_CLOCK,
        )

        var result: CommandExecutionResult? = null
        executor.execute(command("locate", CommandPayload(timeoutSeconds = 20))) {
            result = it
        }

        val proof = (result as CommandExecutionResult.Success).proof
        assertEquals(5.3599, proof.lat ?: 0.0, 0.0)
        assertEquals(-4.0083, proof.lng ?: 0.0, 0.0)
        assertEquals("2026-08-08T12:00:00+00:00", proof.executedAt)
    }

    @Test
    fun `wipe requires device owner and never calls the gateway otherwise`() {
        val gateway = FakePolicyGateway(adminActive = true, deviceOwner = false)
        val controller = DeviceAdminController(gateway)

        val result = controller.wipeDevice()

        val failure = result as DeviceAdminOperationResult.Failed
        assertEquals(DeviceAdminFailureReason.DEVICE_OWNER_REQUIRED, failure.reason)
        assertEquals(0, gateway.wipeCalls)
    }

    @Test
    fun `wipe proof is emitted only after the policy gateway returned`() {
        val gateway = FakePolicyGateway(adminActive = true, deviceOwner = true)
        val executor = DeviceCommandExecutor(
            adminController = DeviceAdminController(gateway),
            locationProvider = unusedLocationProvider(),
            appInstaller = FakeAppInstaller(),
            clock = FIXED_CLOCK,
        )

        var result: CommandExecutionResult? = null
        executor.execute(command("wipe")) { result = it }

        assertEquals(1, gateway.wipeCalls)
        assertEquals(0, gateway.lastWipeFlags)
        assertTrue((result as CommandExecutionResult.Success).proof.wipeStarted == true)
    }

    @Test
    fun `unknown command produces a stable failure code`() {
        val executor = DeviceCommandExecutor(
            adminController = DeviceAdminController(FakePolicyGateway()),
            locationProvider = unusedLocationProvider(),
            appInstaller = FakeAppInstaller(),
            clock = FIXED_CLOCK,
        )

        var result: CommandExecutionResult? = null
        executor.execute(command("future-command")) { result = it }

        assertEquals(
            CommandExecutionErrorCodes.UNSUPPORTED_COMMAND,
            (result as CommandExecutionResult.Failure).errorCode,
        )
    }

    private fun unusedLocationProvider(): DeviceLocationProvider = DeviceLocationProvider(
        source = object : CurrentLocationSource {
            override fun request(
                highAccuracy: Boolean,
                callback: (DeviceLocation?) -> Unit,
            ): LocationRequestStart = error("Location must not be requested in this test.")
        },
        timeoutScheduler = LocationTimeoutScheduler { _, _ ->
            CancellableLocationRequest { }
        },
    )

    private fun command(
        type: String,
        payload: CommandPayload = CommandPayload(),
    ): DeviceCommand = DeviceCommand(
        publicId = "11111111-1111-4111-8111-111111111111",
        type = type,
        payload = payload,
        queuedAt = "2026-08-08T11:59:00+00:00",
        sentAt = "2026-08-08T11:59:01+00:00",
        expiresAt = "2026-08-08T12:05:00+00:00",
    )

    private class FakePolicyGateway(
        private val adminActive: Boolean = false,
        private val deviceOwner: Boolean = false,
    ) : DevicePolicyGateway {
        var wipeCalls: Int = 0
            private set
        var lastWipeFlags: Int? = null
            private set

        override fun isAdminActive(): Boolean = adminActive

        override fun isDeviceOwner(): Boolean = deviceOwner

        override fun lockNow() = Unit

        override fun wipeDeviceData(flags: Int) {
            wipeCalls += 1
            lastWipeFlags = flags
        }

        override fun setLockTaskPackages(packages: Array<String>) = Unit
        override fun setCameraDisabled(disabled: Boolean) = Unit
        override fun addUserRestriction(restriction: String) = Unit
        override fun clearUserRestriction(restriction: String) = Unit
        override fun setApplicationHidden(packageName: String, hidden: Boolean): Boolean = true
        override fun resetPassword(password: String, flags: Int): Boolean = true
        override fun setLocationEnabled(enabled: Boolean) = Unit
    }

    private class FakeAppInstaller : AppInstaller {
        override fun installSilently(apkUrl: String, callback: (Boolean, String?) -> Unit) {
            callback(true, null)
        }

        override fun uninstallSilently(packageName: String, callback: (Boolean, String?) -> Unit) {
            callback(true, null)
        }
    }

    private companion object {
        val FIXED_CLOCK: Clock = Clock.fixed(
            Instant.parse("2026-08-08T12:00:00Z"),
            ZoneOffset.UTC,
        )
    }
}
