package com.mdm2isy.agent.command

import com.mdm2isy.agent.storage.InstallOperation
import com.mdm2isy.agent.storage.InstallOperationState
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class AgentUpdateRecoveryTest {
    private val sha256 = "a1".repeat(32)

    @Test
    fun `strict compatibility marker decodes the published agent identity`() {
        val metadata = AgentUpdateMarker.parse(
            "MDM_AGENT_UPDATE_V1|version_code=12|version_name=0.1.11|sha256=${sha256.uppercase()}",
        )

        requireNotNull(metadata)
        assertEquals(12L, metadata.versionCode)
        assertEquals("0.1.11", metadata.versionName)
        assertEquals(sha256, metadata.sha256)
    }

    @Test
    fun `marker rejects missing reordered and extended fields`() {
        assertNull(AgentUpdateMarker.parse(null))
        assertNull(
            AgentUpdateMarker.parse(
                "MDM_AGENT_UPDATE_V1|version_name=0.1.11|version_code=12|sha256=$sha256",
            ),
        )
        assertNull(
            AgentUpdateMarker.parse(
                "MDM_AGENT_UPDATE_V1|version_code=12|version_name=0.1.11|sha256=$sha256|extra=1",
            ),
        )
    }

    @Test
    fun `legacy 0_1_9 handoff recognizes installed target by version and source hash`() {
        val decision = InstallRecoveryPolicy.decide(
            request = request(),
            operation = null,
            installed = InstalledPackageVersion(12L, "0.1.11", sha256),
            nowEpochMs = 20_000L,
        )

        assertTrue(decision is InstallRecoveryDecision.AlreadyInstalled)
    }

    @Test
    fun `same target version with another installed hash fails without reinstall loop`() {
        val decision = InstallRecoveryPolicy.decide(
            request = request(),
            operation = null,
            installed = InstalledPackageVersion(12L, "0.1.11", "ff".repeat(32)),
            nowEpochMs = 20_000L,
        )

        assertTrue(decision is InstallRecoveryDecision.Failed)
    }

    @Test
    fun `durable commit waits for PackageInstaller result instead of starting twice`() {
        val decision = InstallRecoveryPolicy.decide(
            request = request(),
            operation = pendingOperation(createdAt = 10_000L),
            installed = InstalledPackageVersion(11L, "0.1.10", "bb".repeat(32)),
            nowEpochMs = 20_000L,
        )

        assertTrue(decision is InstallRecoveryDecision.AwaitPlatformResult)
        assertEquals(
            "operation-1",
            (decision as InstallRecoveryDecision.AwaitPlatformResult).operationId,
        )
    }

    @Test
    fun `stale indeterminate commit fails closed instead of downloading again`() {
        val decision = InstallRecoveryPolicy.decide(
            request = request(),
            operation = pendingOperation(createdAt = 10_000L),
            installed = InstalledPackageVersion(11L, "0.1.10", "bb".repeat(32)),
            nowEpochMs = 400_001L,
        )

        assertTrue(decision is InstallRecoveryDecision.Failed)
    }

    @Test
    fun `newer installed agent is never downgraded`() {
        val decision = InstallRecoveryPolicy.decide(
            request = request(),
            operation = null,
            installed = InstalledPackageVersion(13L, "0.1.12", "cc".repeat(32)),
            nowEpochMs = 20_000L,
        )

        assertTrue(decision is InstallRecoveryDecision.AlreadyInstalled)
    }

    private fun request() = AppInstallRequest(
        commandPublicId = "11111111-1111-4111-8111-111111111111",
        apkUrl = "https://api.example.test/mdm-agent.apk",
        expectedPackageName = "com.mdm2isy.agent",
        expectedVersionCode = 12L,
        expectedVersionName = "0.1.11",
        expectedSha256 = sha256,
        timeoutSeconds = 300L,
    )

    private fun pendingOperation(createdAt: Long) = InstallOperation(
        operationId = "operation-1",
        commandPublicId = "11111111-1111-4111-8111-111111111111",
        packageName = "com.mdm2isy.agent",
        expectedVersionCode = 12L,
        expectedVersionName = "0.1.11",
        expectedSha256 = sha256,
        sessionId = 42,
        state = InstallOperationState.COMMITTING,
        createdAtEpochMs = createdAt,
    )
}
