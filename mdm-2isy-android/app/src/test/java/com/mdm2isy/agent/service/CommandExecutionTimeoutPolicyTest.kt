package com.mdm2isy.agent.service

import com.mdm2isy.agent.model.CommandPayload
import com.mdm2isy.agent.model.DeviceCommand
import org.junit.Assert.assertEquals
import org.junit.Test

class CommandExecutionTimeoutPolicyTest {
    @Test
    fun `install command gets enough time for a large APK download`() {
        assertEquals(
            55L * 60L,
            CommandExecutionTimeoutPolicy.timeoutSeconds(command("install_app")),
        )
    }

    @Test
    fun `ordinary command keeps the short default timeout`() {
        assertEquals(30L, CommandExecutionTimeoutPolicy.timeoutSeconds(command("lock")))
    }

    @Test
    fun `explicit command timeout remains authoritative`() {
        assertEquals(
            120L,
            CommandExecutionTimeoutPolicy.timeoutSeconds(
                command("install_app", CommandPayload(timeoutSeconds = 120)),
            ),
        )
    }

    private fun command(
        type: String,
        payload: CommandPayload = CommandPayload(),
    ): DeviceCommand = DeviceCommand(
        publicId = "11111111-1111-4111-8111-111111111111",
        type = type,
        payload = payload,
        queuedAt = "2026-08-08T11:59:00+00:00",
        sentAt = "2026-08-08T11:59:01+00:00",
        expiresAt = "2026-08-08T13:00:00+00:00",
    )
}
