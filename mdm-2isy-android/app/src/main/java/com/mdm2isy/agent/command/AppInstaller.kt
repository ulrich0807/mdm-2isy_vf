package com.mdm2isy.agent.command

import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInfo
import android.content.pm.PackageInstaller
import android.content.pm.PackageManager
import android.os.Build
import com.mdm2isy.agent.admin.MdmDeviceAdminReceiver
import com.mdm2isy.agent.receiver.AppInstallResultReceiver
import com.mdm2isy.agent.receiver.AppOperationCallbacks
import com.mdm2isy.agent.storage.InstallOperation
import com.mdm2isy.agent.storage.InstallOperationState
import com.mdm2isy.agent.storage.InstallOperationStore
import java.io.File
import java.io.FileOutputStream
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest
import java.util.UUID
import java.util.zip.ZipInputStream
import kotlin.concurrent.thread

data class AppInstallRequest(
    val commandPublicId: String,
    val apkUrl: String,
    val expectedPackageName: String,
    val expectedVersionCode: Long? = null,
    val expectedVersionName: String? = null,
    val expectedSha256: String? = null,
    val artifactType: String = "apk",
    val timeoutSeconds: Long = DEFAULT_INSTALL_TIMEOUT_SECONDS,
) {
    init {
        require(artifactType == "apk" || artifactType == "apks") {
            "artifactType must be apk or apks."
        }
    }
}

data class InstalledPackageVersion(
    val versionCode: Long,
    val versionName: String?,
    val sha256: String? = null,
)

internal sealed interface InstallRecoveryDecision {
    data object StartInstall : InstallRecoveryDecision
    data object AlreadyInstalled : InstallRecoveryDecision
    data class AwaitPlatformResult(val operationId: String) : InstallRecoveryDecision
    data class Failed(val message: String) : InstallRecoveryDecision
}

internal object InstallRecoveryPolicy {
    fun decide(
        request: AppInstallRequest,
        operation: InstallOperation?,
        installed: InstalledPackageVersion?,
        nowEpochMs: Long,
    ): InstallRecoveryDecision {
        installed.matchFailure(request)?.let { return InstallRecoveryDecision.Failed(it) }
        if (installed.matches(request)) {
            return InstallRecoveryDecision.AlreadyInstalled
        }
        if (operation?.state == InstallOperationState.SUCCEEDED) {
            return InstallRecoveryDecision.AlreadyInstalled
        }
        if (operation?.state == InstallOperationState.FAILED) {
            return InstallRecoveryDecision.Failed(
                operation.errorMessage ?: "Android a refusé l'installation.",
            )
        }
        if (operation?.state == InstallOperationState.COMMITTING) {
            val deadline = operation.createdAtEpochMs +
                (request.timeoutSeconds.coerceAtLeast(5L) + RESULT_GRACE_SECONDS) * 1_000L
            return if (nowEpochMs <= deadline) {
                InstallRecoveryDecision.AwaitPlatformResult(operation.operationId)
            } else {
                InstallRecoveryDecision.Failed(
                    "Le résultat de l'installation Android est resté indéterminé après le redémarrage.",
                )
            }
        }
        return InstallRecoveryDecision.StartInstall
    }

    private fun InstalledPackageVersion?.matches(request: AppInstallRequest): Boolean {
        val current = this ?: return false
        val expectedCode = request.expectedVersionCode ?: return false
        if (current.versionCode < expectedCode) return false
        if (current.versionCode > expectedCode) return true
        if (request.expectedVersionName != null && request.expectedVersionName != current.versionName) {
            return false
        }
        return request.expectedSha256 == null ||
            request.expectedSha256.equals(current.sha256, ignoreCase = true)
    }

    private fun InstalledPackageVersion?.matchFailure(request: AppInstallRequest): String? {
        val current = this ?: return null
        val expectedCode = request.expectedVersionCode ?: return null
        if (current.versionCode != expectedCode) return null
        if (request.expectedVersionName != null && request.expectedVersionName != current.versionName) {
            return "La version installée porte le versionCode attendu mais un versionName différent."
        }
        if (request.expectedSha256 != null && !request.expectedSha256.equals(current.sha256, true)) {
            return "La version installée porte le versionCode attendu mais son empreinte SHA-256 diffère."
        }
        return null
    }

    private const val RESULT_GRACE_SECONDS = 10L
}

interface AppInstaller {
    fun installSilently(
        request: AppInstallRequest,
        callback: (Boolean, String?) -> Unit,
    )

    fun uninstallSilently(packageName: String, callback: (Boolean, String?) -> Unit)
}

internal const val MAX_APK_MEBIBYTES = 250L
internal const val MAX_APK_BYTES = MAX_APK_MEBIBYTES * 1024L * 1024L
internal const val DEFAULT_INSTALL_TIMEOUT_SECONDS = 55L * 60L

class AndroidAppInstaller(private val context: Context) : AppInstaller {
    private val appContext = context.applicationContext
    private val operationStore = InstallOperationStore(appContext)

    override fun installSilently(
        request: AppInstallRequest,
        callback: (Boolean, String?) -> Unit,
    ) {
        thread(name = "mdm-package-installer") {
            val installed = installedPackage(
                request.expectedPackageName,
                includeSha256 = request.expectedSha256 != null,
            )
            when (
                val recovery = InstallRecoveryPolicy.decide(
                    request = request,
                    operation = operationStore.findByCommand(request.commandPublicId),
                    installed = installed,
                    nowEpochMs = System.currentTimeMillis(),
                )
            ) {
                InstallRecoveryDecision.AlreadyInstalled -> {
                    callback(true, null)
                    return@thread
                }

                is InstallRecoveryDecision.Failed -> {
                    operationStore.findByCommand(request.commandPublicId)
                        ?.takeIf { it.state == InstallOperationState.COMMITTING }
                        ?.let { operationStore.recordFailure(it.operationId, recovery.message) }
                    callback(false, recovery.message)
                    return@thread
                }

                is InstallRecoveryDecision.AwaitPlatformResult -> {
                    AppOperationCallbacks.register(recovery.operationId, callback)
                    return@thread
                }

                InstallRecoveryDecision.StartInstall -> Unit
            }

            installNewSession(request, callback)
        }
    }

    private fun installNewSession(
        request: AppInstallRequest,
        callback: (Boolean, String?) -> Unit,
    ) {
        var temporaryApk: File? = null
        var session: PackageInstaller.Session? = null
        val childSessions = mutableListOf<PackageInstaller.Session>()
        val temporarySplitFiles = mutableListOf<File>()
        var sessionId = -1
        var committed = false
        var operationId: String? = null

        try {
            val downloaded = downloadApk(request.apkUrl)
            temporaryApk = downloaded.file
            verifyDownload(request, downloaded)

            val packageInstaller = appContext.packageManager.packageInstaller
            if (request.artifactType == "apk") {
                val archive = inspectArchive(downloaded.file)
                verifyArchive(request, archive)
                val params = installSessionParams().apply {
                    setAppPackageName(request.expectedPackageName)
                    setSize(downloaded.sizeBytes)
                }
                sessionId = packageInstaller.createSession(params)
                session = packageInstaller.openSession(sessionId)
                writeApk(session!!, "base.apk", downloaded.file, downloaded.sizeBytes)
            } else {
                val splitFiles = extractSplitApks(downloaded.file)
                require(splitFiles.isNotEmpty()) { "Le paquet .apks/.zip ne contient aucun APK." }
                temporarySplitFiles += splitFiles.map { it.file }
                val archives = splitFiles.map { it.file to inspectArchive(it.file) }
                archives.forEach { (_, archive) -> verifySplitArchive(request, archive) }
                require(splitFiles.any { it.entryName.substringAfterLast('/').startsWith("base") }) {
                    "Le paquet de splits ne contient pas de base.apk."
                }
                verifySplitSignatures(archives.map { it.second })

                val parentParams = installSessionParams().apply { setMultiPackage() }
                sessionId = packageInstaller.createSession(parentParams)
                session = packageInstaller.openSession(sessionId)
                archives.forEachIndexed { index, (file, archive) ->
                    val childParams = installSessionParams().apply {
                        setAppPackageName(archive.packageName)
                        setSize(file.length())
                    }
                    val childId = packageInstaller.createSession(childParams)
                    val child = packageInstaller.openSession(childId)
                    childSessions += child
                    writeApk(child, "split-$index.apk", file, file.length())
                    session!!.addChildSessionId(childId)
                }
            }

            operationId = UUID.randomUUID().toString()
            // Persist synchronously before commit: replacing this package may
            // kill this process before its in-memory callback can run.
            operationStore.prepare(
                InstallOperation(
                    operationId = operationId,
                    commandPublicId = request.commandPublicId,
                    packageName = request.expectedPackageName,
                    expectedVersionCode = request.expectedVersionCode,
                    expectedVersionName = request.expectedVersionName,
                    expectedSha256 = request.expectedSha256,
                    sessionId = sessionId,
                    state = InstallOperationState.COMMITTING,
                ),
            )

            AppOperationCallbacks.register(operationId, callback)
            val resultIntent = Intent(appContext, AppInstallResultReceiver::class.java).apply {
                putExtra(AppInstallResultReceiver.EXTRA_OPERATION_ID, operationId)
            }
            val pendingIntent = PendingIntent.getBroadcast(
                appContext,
                sessionId,
                resultIntent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE,
            )
            session!!.commit(pendingIntent.intentSender)
            committed = true
        } catch (exception: Exception) {
            operationId?.let {
                AppOperationCallbacks.remove(it)
                runCatching {
                    operationStore.recordFailure(
                        it,
                        exception.message ?: "Échec avant la validation de l'installation.",
                    )
                }
            }
            if (!committed) {
                childSessions.forEach { child -> runCatching { child.abandon() } }
                runCatching { session?.abandon() }
            }
            callback(
                false,
                exception.message ?: "Échec de préparation de l'installation Android.",
            )
        } finally {
            childSessions.forEach { child -> runCatching { child.close() } }
            runCatching { session?.close() }
            temporarySplitFiles.forEach { it.delete() }
            temporaryApk?.delete()
        }
    }

    private fun installSessionParams(): PackageInstaller.SessionParams =
        PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL).apply {
            setInstallReason(PackageManager.INSTALL_REASON_POLICY)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                setRequireUserAction(PackageInstaller.SessionParams.USER_ACTION_NOT_REQUIRED)
            }
        }

    private fun writeApk(
        session: PackageInstaller.Session,
        name: String,
        file: File,
        sizeBytes: Long,
    ) {
        session.openWrite(name, 0, sizeBytes).use { output ->
            file.inputStream().use { input -> input.copyTo(output, COPY_BUFFER_SIZE) }
            session.fsync(output)
        }
    }

    private fun extractSplitApks(archive: File): List<ExtractedSplit> {
        val extracted = mutableListOf<ExtractedSplit>()
        var totalBytes = 0L
        try {
            ZipInputStream(archive.inputStream().buffered()).use { input ->
                while (true) {
                    val entry = input.nextEntry ?: break
                    if (entry.isDirectory || !entry.name.lowercase().endsWith(".apk")) {
                        input.closeEntry()
                        continue
                    }
                    require(extracted.size < MAX_SPLIT_COUNT) {
                        "Le paquet de splits contient trop de fichiers APK."
                    }
                    val output = File.createTempFile("mdm-split-", ".apk", appContext.cacheDir)
                    var entryBytes = 0L
                    try {
                        output.outputStream().use { out ->
                            val buffer = ByteArray(COPY_BUFFER_SIZE)
                            while (true) {
                                val count = input.read(buffer)
                                if (count < 0) break
                                entryBytes += count
                                totalBytes += count
                                require(entryBytes <= MAX_APK_BYTES && totalBytes <= MAX_APK_BYTES) {
                                    "Le paquet de splits dépasse la limite de $MAX_APK_MEBIBYTES Mio."
                                }
                                out.write(buffer, 0, count)
                            }
                        }
                        require(entryBytes > 0L) { "Un fichier APK du paquet est vide." }
                        extracted += ExtractedSplit(entry.name, output)
                    } catch (exception: Exception) {
                        output.delete()
                        throw exception
                    } finally {
                        input.closeEntry()
                    }
                }
            }
            return extracted
        } catch (exception: Exception) {
            extracted.forEach { it.file.delete() }
            throw exception
        }
    }

    private fun downloadApk(apkUrl: String): DownloadedApk {
        val connection = URL(apkUrl).openConnection() as HttpURLConnection
        val file = File.createTempFile("mdm-install-", ".apk", appContext.cacheDir)
        try {
            connection.requestMethod = "GET"
            connection.connectTimeout = 30_000
            connection.readTimeout = 60_000
            connection.instanceFollowRedirects = false
            connection.connect()

            if (connection.responseCode != HttpURLConnection.HTTP_OK) {
                error("Échec du téléchargement (HTTP ${connection.responseCode}).")
            }
            if (connection.contentLengthLong > MAX_APK_BYTES) {
                error("Le fichier APK dépasse la limite de $MAX_APK_MEBIBYTES Mio.")
            }

            val digest = MessageDigest.getInstance("SHA-256")
            var totalBytes = 0L
            connection.inputStream.use { input ->
                FileOutputStream(file).use { output ->
                    val buffer = ByteArray(COPY_BUFFER_SIZE)
                    while (true) {
                        val count = input.read(buffer)
                        if (count < 0) break
                        totalBytes += count
                        if (totalBytes > MAX_APK_BYTES) {
                            error("Le fichier APK dépasse la limite de $MAX_APK_MEBIBYTES Mio.")
                        }
                        digest.update(buffer, 0, count)
                        output.write(buffer, 0, count)
                    }
                    output.fd.sync()
                }
            }
            check(totalBytes > 0L) { "Le serveur a retourné un fichier APK vide." }
            return DownloadedApk(
                file = file,
                sizeBytes = totalBytes,
                sha256 = digest.digest().joinToString("") { "%02x".format(it) },
            )
        } catch (exception: Exception) {
            file.delete()
            throw exception
        } finally {
            connection.disconnect()
        }
    }

    private fun verifyDownload(request: AppInstallRequest, downloaded: DownloadedApk) {
        val expected = request.expectedSha256 ?: return
        check(expected.matches(SHA256_PATTERN)) { "L'empreinte SHA-256 attendue est invalide." }
        check(downloaded.sha256.equals(expected, ignoreCase = true)) {
            "L'empreinte SHA-256 de l'APK téléchargé ne correspond pas à la version publiée."
        }
    }

    private fun inspectArchive(file: File): PackageInfo {
        val packageManager = appContext.packageManager
        val archive = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            packageManager.getPackageArchiveInfo(
                file.absolutePath,
                PackageManager.PackageInfoFlags.of(
                    PackageManager.GET_SIGNING_CERTIFICATES.toLong(),
                ),
            )
        } else {
            @Suppress("DEPRECATION")
            packageManager.getPackageArchiveInfo(
                file.absolutePath,
                PackageManager.GET_SIGNING_CERTIFICATES,
            )
        }
        return requireNotNull(archive) { "Le fichier téléchargé n'est pas un APK Android valide." }
    }

    private fun verifyArchive(request: AppInstallRequest, archive: PackageInfo) {
        check(archive.packageName == request.expectedPackageName) {
            "Le package APK '${archive.packageName}' ne correspond pas à '${request.expectedPackageName}'."
        }
        request.expectedVersionCode?.let { expected ->
            check(archive.longVersionCode == expected) {
                "Le versionCode APK ${archive.longVersionCode} ne correspond pas à $expected."
            }
        }
        request.expectedVersionName?.let { expected ->
            check(archive.versionName == expected) {
                "La version APK '${archive.versionName}' ne correspond pas à '$expected'."
            }
        }
        if (request.expectedPackageName == appContext.packageName) {
            verifyAgentSignature(archive)
        }
    }

    private fun verifySplitArchive(request: AppInstallRequest, archive: PackageInfo) {
        check(archive.packageName == request.expectedPackageName) {
            "Un split APK cible '${archive.packageName}' au lieu de '${request.expectedPackageName}'."
        }
    }

    private fun verifySplitSignatures(archives: List<PackageInfo>) {
        val signerSets = archives.map { archive ->
            val signing = requireNotNull(archive.signingInfo) {
                "La signature d'un split APK est indisponible."
            }
            signing.apkContentsSigners.map(::certificateSha256).toSet()
        }
        val first = signerSets.firstOrNull() ?: return
        require(signerSets.all { it == first }) {
            "Les APK du paquet de splits ne sont pas signés avec le même certificat."
        }
    }

    private fun verifyAgentSignature(archive: PackageInfo) {
        val installed = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            appContext.packageManager.getPackageInfo(
                appContext.packageName,
                PackageManager.PackageInfoFlags.of(
                    PackageManager.GET_SIGNING_CERTIFICATES.toLong(),
                ),
            )
        } else {
            @Suppress("DEPRECATION")
            appContext.packageManager.getPackageInfo(
                appContext.packageName,
                PackageManager.GET_SIGNING_CERTIFICATES,
            )
        }
        val installedSigning = requireNotNull(installed.signingInfo) {
            "La signature de l'agent installé est indisponible."
        }
        val archiveSigning = requireNotNull(archive.signingInfo) {
            "La signature de l'APK de mise à jour est indisponible."
        }
        val installedCurrent = installedSigning.apkContentsSigners.map(::certificateSha256).toSet()
        val archiveCurrent = archiveSigning.apkContentsSigners.map(::certificateSha256).toSet()
        val installedLineage = installedSigning.signingCertificateHistory
            ?.map(::certificateSha256)
            ?.toSet()
            ?: installedCurrent
        val archiveLineage = archiveSigning.signingCertificateHistory
            ?.map(::certificateSha256)
            ?.toSet()
            ?: archiveCurrent
        check(
            archiveCurrent.any { it in installedLineage } ||
                installedCurrent.any { it in archiveLineage },
        ) {
            "La mise à jour de l'agent n'est pas signée par une clé compatible."
        }
    }

    private fun certificateSha256(signature: android.content.pm.Signature): String =
        MessageDigest.getInstance("SHA-256")
            .digest(signature.toByteArray())
            .joinToString("") { "%02x".format(it) }

    private fun installedPackage(
        packageName: String,
        includeSha256: Boolean,
    ): InstalledPackageVersion? {
        val packageInfo = runCatching {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
                appContext.packageManager.getPackageInfo(
                    packageName,
                    PackageManager.PackageInfoFlags.of(0L),
                )
            } else {
                @Suppress("DEPRECATION")
                appContext.packageManager.getPackageInfo(packageName, 0)
            }
        }.getOrNull() ?: return null
        return InstalledPackageVersion(
            versionCode = packageInfo.longVersionCode,
            versionName = packageInfo.versionName,
            sha256 = if (includeSha256) {
                runCatching {
                    sha256Of(File(requireNotNull(packageInfo.applicationInfo).sourceDir))
                }.getOrNull()
            } else {
                null
            },
        )
    }

    private fun sha256Of(file: File): String {
        val digest = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { input ->
            val buffer = ByteArray(COPY_BUFFER_SIZE)
            while (true) {
                val count = input.read(buffer)
                if (count < 0) break
                digest.update(buffer, 0, count)
            }
        }
        return digest.digest().joinToString("") { "%02x".format(it) }
    }

    override fun uninstallSilently(packageName: String, callback: (Boolean, String?) -> Unit) {
        try {
            val packageManager = appContext.packageManager
            val appInfo = packageManager.getApplicationInfo(packageName, 0)
            if ((appInfo.flags and android.content.pm.ApplicationInfo.FLAG_SYSTEM) != 0) {
                val devicePolicyManager = appContext.getSystemService(
                    Context.DEVICE_POLICY_SERVICE,
                ) as android.app.admin.DevicePolicyManager
                val componentName = android.content.ComponentName(
                    appContext,
                    MdmDeviceAdminReceiver::class.java,
                )
                devicePolicyManager.setApplicationHidden(componentName, packageName, true)
                callback(true, null)
                return
            }

            val packageInstaller = packageManager.packageInstaller
            val operationId = UUID.randomUUID().toString()
            AppOperationCallbacks.register(operationId, callback)
            val intent = Intent(appContext, AppInstallResultReceiver::class.java).apply {
                putExtra(AppInstallResultReceiver.EXTRA_OPERATION_ID, operationId)
            }
            val pendingIntent = PendingIntent.getBroadcast(
                appContext,
                System.currentTimeMillis().toInt(),
                intent,
                PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE,
            )
            try {
                packageInstaller.uninstall(packageName, pendingIntent.intentSender)
            } catch (exception: Exception) {
                AppOperationCallbacks.remove(operationId)
                throw exception
            }
        } catch (exception: Exception) {
            callback(false, exception.message)
        }
    }

    private data class DownloadedApk(
        val file: File,
        val sizeBytes: Long,
        val sha256: String,
    )

    private data class ExtractedSplit(
        val entryName: String,
        val file: File,
    )

    private companion object {
        const val COPY_BUFFER_SIZE = 64 * 1024
        const val MAX_SPLIT_COUNT = 32
        val SHA256_PATTERN = Regex("[0-9a-fA-F]{64}")
    }
}
