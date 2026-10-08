param(
    [string]$RepositoryRoot = $PSScriptRoot
)

$ErrorActionPreference = 'Stop'

$releaseOutputDirectory = Join-Path $RepositoryRoot 'mdm-2isy-android\app\build\outputs\apk\release'
$outputMetadata = Join-Path $releaseOutputDirectory 'output-metadata.json'
$apkFile = Join-Path $RepositoryRoot 'mdm-2isy-api\public\apk\mdm-agent.apk'
$apkDirectory = Split-Path -Parent $apkFile
$releaseDirectory = Join-Path $apkDirectory 'releases'
$manifestFile = Join-Path $apkDirectory 'mdm-agent.json'

if (-not (Test-Path -LiteralPath $outputMetadata -PathType Leaf)) {
    throw "Métadonnées de la release introuvables : $outputMetadata"
}
if (-not (Test-Path -LiteralPath $apkFile -PathType Leaf)) {
    throw "APK publié introuvable : $apkFile"
}

$metadata = Get-Content -LiteralPath $outputMetadata -Raw | ConvertFrom-Json
$release = @($metadata.elements) | Select-Object -First 1
if ($metadata.applicationId -ne 'com.mdm2isy.agent' -or -not $release -or -not $release.versionCode -or [string]::IsNullOrWhiteSpace($release.versionName)) {
    throw 'Les métadonnées Android de la release sont incomplètes ou invalides.'
}

$hash = (Get-FileHash -LiteralPath $apkFile -Algorithm SHA256).Hash.ToLowerInvariant()
$size = (Get-Item -LiteralPath $apkFile).Length
if ($size -gt (100 * 1024 * 1024)) {
    throw "L'APK dépasse 100 Mio et ne peut pas amorcer une mise à jour depuis l'agent 0.1.9."
}

New-Item -ItemType Directory -Path $releaseDirectory -Force | Out-Null
$immutableApk = Join-Path $releaseDirectory "$hash.apk"
if (Test-Path -LiteralPath $immutableApk -PathType Leaf) {
    $existingHash = (Get-FileHash -LiteralPath $immutableApk -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($existingHash -ne $hash) {
        throw "Collision d'artefact : $immutableApk ne correspond pas à son nom SHA-256."
    }
} else {
    Copy-Item -LiteralPath $apkFile -Destination $immutableApk
}

$manifest = [ordered]@{
    package_name = $metadata.applicationId
    version_code = [int]$release.versionCode
    version_name = [string]$release.versionName
    sha256 = $hash
    size_bytes = $size
    generated_at = [DateTimeOffset]::UtcNow.ToString('o')
}

$manifest | ConvertTo-Json | Set-Content -LiteralPath $manifestFile -Encoding utf8

Write-Host "Manifeste agent : $manifestFile"
Write-Host "Artefact immuable : $immutableApk"
Write-Host "Version : $($manifest.version_name) ($($manifest.version_code))"
Write-Host "SHA-256 : $hash"
