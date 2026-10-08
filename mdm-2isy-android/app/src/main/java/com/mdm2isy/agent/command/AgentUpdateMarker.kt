package com.mdm2isy.agent.command

data class AgentUpdateMetadata(
    val versionCode: Long,
    val versionName: String,
    val sha256: String,
)

/**
 * Compatibility contract used to move devices from agent 0.1.9 without adding
 * a new command type that the old binary would reject.
 */
internal object AgentUpdateMarker {
    const val PREFIX = "MDM_AGENT_UPDATE_V1"

    private val pattern = Regex(
        "^$PREFIX\\|version_code=([1-9][0-9]*)" +
            "\\|version_name=([A-Za-z0-9][A-Za-z0-9._+-]{0,63})" +
            "\\|sha256=([0-9A-Fa-f]{64})$",
    )

    fun parse(message: String?): AgentUpdateMetadata? {
        val match = message?.let(pattern::matchEntire) ?: return null
        val versionCode = match.groupValues[1].toLongOrNull()
            ?.takeIf { it > 0L }
            ?: return null

        return AgentUpdateMetadata(
            versionCode = versionCode,
            versionName = match.groupValues[2],
            sha256 = match.groupValues[3].lowercase(),
        )
    }
}
