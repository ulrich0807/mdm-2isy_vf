package com.mdm2isy.agent.network

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class MdmJsonCodecTest {
    @Test
    fun `agent update compatibility marker survives command decoding`() {
        val sha256 = "ab".repeat(32)
        val marker =
            "MDM_AGENT_UPDATE_V1|version_code=12|version_name=0.1.11|sha256=$sha256"
        val command = MdmJsonCodec.parseCommands(
            """
            {
              "success": true,
              "data": [{
                "public_id": "11111111-1111-4111-8111-111111111111",
                "type": "install_app",
                "payload": {
                  "message": "$marker",
                  "timeout_seconds": 300,
                  "url": "https://api.example.test/mdm-agent.apk",
                  "packageName": "com.mdm2isy.agent"
                },
                "queued_at": "2026-10-08T16:00:00+00:00",
                "sent_at": "2026-10-08T16:00:01+00:00",
                "expires_at": "2026-10-08T17:00:00+00:00"
              }]
            }
            """.trimIndent(),
        ).single()

        assertEquals(marker, command.payload.message)
        assertEquals(300, command.payload.timeoutSeconds)
        assertEquals("com.mdm2isy.agent", command.payload.packageName)
    }

    @Test
    fun `heartbeat decodes every security restriction`() {
        val receipt = MdmJsonCodec.parseHeartbeat(
            """
            {
              "success": true,
              "data": {
                "device_id": "11111111-1111-4111-8111-111111111111",
                "server_time": "2026-09-22T04:00:00+00:00",
                "policy": {
                  "kiosk": false,
                  "no_cam": true,
                  "no_usb": false,
                  "no_bt": true,
                  "no_wifi": true,
                  "no_data": true,
                  "no_airplane": true,
                  "pin_fort": false,
                  "blacklist_apps": ["com.whatsapp", "com.waze"],
                  "whitelist_apps": ["com.example.allowed"]
                }
              }
            }
            """.trimIndent(),
        )

        val policy = requireNotNull(receipt.policy)
        assertTrue(policy.noCam)
        assertFalse(policy.noUsb)
        assertTrue(policy.noBt)
        assertTrue(policy.noWifi)
        assertTrue(policy.noData)
        assertTrue(policy.noAirplane)
        assertEquals(listOf("com.whatsapp", "com.waze"), policy.blacklistApps)
        assertEquals(listOf("com.example.allowed"), policy.whitelistApps)
    }
}
