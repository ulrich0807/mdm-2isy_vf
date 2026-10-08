package com.mdm2isy.agent.command

import org.junit.Assert.assertEquals
import org.junit.Test

class AppInstallerPolicyTest {
    @Test
    fun `APK size limit is 250 mebibytes`() {
        assertEquals(250L, MAX_APK_MEBIBYTES)
        assertEquals(262_144_000L, MAX_APK_BYTES)
    }
}
