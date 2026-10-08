$ErrorActionPreference = 'Stop'

$requiredVariables = @(
    'MDM_ANDROID_KEYSTORE_FILE',
    'MDM_ANDROID_KEYSTORE_PASSWORD',
    'MDM_ANDROID_KEY_ALIAS',
    'MDM_ANDROID_KEY_PASSWORD'
)

foreach ($variableName in $requiredVariables) {
    if ([string]::IsNullOrWhiteSpace([Environment]::GetEnvironmentVariable($variableName))) {
        throw "La variable d'environnement $variableName est obligatoire."
    }
}

$keystorePath = [Environment]::GetEnvironmentVariable('MDM_ANDROID_KEYSTORE_FILE')
if (-not (Test-Path -LiteralPath $keystorePath -PathType Leaf)) {
    throw "Le fichier de signature est introuvable : $keystorePath"
}

$projectDirectory = Split-Path -Parent $MyInvocation.MyCommand.Path
$repositoryDirectory = Split-Path -Parent $projectDirectory
$releaseOutputDirectory = Join-Path $projectDirectory 'app\build\outputs\apk\release'
$outputMetadata = Join-Path $releaseOutputDirectory 'output-metadata.json'
$publishedApk = Join-Path $repositoryDirectory 'mdm-2isy-api\public\apk\mdm-agent.apk'
$publishedDirectory = Split-Path -Parent $publishedApk
$releaseDirectory = Join-Path $publishedDirectory 'releases'
$manifestFile = Join-Path $publishedDirectory 'mdm-agent.json'

Push-Location $projectDirectory
try {
    # Le bloc de validation de signature du build utilise des références de
    # script que Gradle ne peut pas sérialiser dans son cache de configuration.
    # Une release doit rester reproductible et ne pas échouer après la création
    # de l'APK à cause de cette optimisation facultative.
    & .\gradlew.bat --no-configuration-cache clean test assembleRelease
    if ($LASTEXITCODE -ne 0) {
        throw 'La compilation Android a échoué.'
    }
} finally {
    Pop-Location
}

if (-not (Test-Path -LiteralPath $outputMetadata -PathType Leaf)) {
    throw "Les métadonnées de la release sont introuvables : $outputMetadata"
}

$metadata = Get-Content -LiteralPath $outputMetadata -Raw | ConvertFrom-Json
$release = @($metadata.elements) | Select-Object -First 1
if (
    $metadata.applicationId -ne 'com.mdm2isy.agent' -or
    -not $release -or
    -not $release.versionCode -or
    [string]::IsNullOrWhiteSpace($release.versionName) -or
    [string]::IsNullOrWhiteSpace($release.outputFile)
) {
    throw 'Les métadonnées Android de la release sont incomplètes ou invalides.'
}

$outputApk = Join-Path $releaseOutputDirectory $release.outputFile
if (-not (Test-Path -LiteralPath $outputApk -PathType Leaf)) {
    throw "L'APK signé attendu est introuvable : $outputApk"
}

Copy-Item -LiteralPath $outputApk -Destination $publishedApk -Force
$hash = Get-FileHash -LiteralPath $publishedApk -Algorithm SHA256
$normalizedHash = $hash.Hash.ToLowerInvariant()
$size = (Get-Item -LiteralPath $publishedApk).Length
if ($size -gt (100 * 1024 * 1024)) {
    throw "L'APK dépasse 100 Mio et ne peut pas amorcer une mise à jour depuis l'agent 0.1.9."
}

New-Item -ItemType Directory -Path $releaseDirectory -Force | Out-Null
$immutableApk = Join-Path $releaseDirectory "$normalizedHash.apk"
if (Test-Path -LiteralPath $immutableApk -PathType Leaf) {
    $existingHash = (Get-FileHash -LiteralPath $immutableApk -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($existingHash -ne $normalizedHash) {
        throw "Collision d'artefact : $immutableApk ne correspond pas à son nom SHA-256."
    }
} else {
    Copy-Item -LiteralPath $publishedApk -Destination $immutableApk
}

$manifest = [ordered]@{
    package_name = $metadata.applicationId
    version_code = [int]$release.versionCode
    version_name = [string]$release.versionName
    sha256 = $normalizedHash
    size_bytes = $size
    generated_at = [DateTimeOffset]::UtcNow.ToString('o')
}
$manifest | ConvertTo-Json | Set-Content -LiteralPath $manifestFile -Encoding utf8

Write-Host "APK signé publié : $publishedApk"
Write-Host "SHA-256 : $($hash.Hash)"
Write-Host "Artefact immuable : $immutableApk"
Write-Host "Manifeste : $manifestFile"
