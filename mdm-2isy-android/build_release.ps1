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
$outputApk = Join-Path $projectDirectory 'app\build\outputs\apk\release\app-release.apk'
$publishedApk = Join-Path $repositoryDirectory 'mdm-2isy-api\public\apk\mdm-agent.apk'

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

if (-not (Test-Path -LiteralPath $outputApk -PathType Leaf)) {
    throw "L'APK signé attendu est introuvable : $outputApk"
}

Copy-Item -LiteralPath $outputApk -Destination $publishedApk -Force
$hash = Get-FileHash -LiteralPath $publishedApk -Algorithm SHA256
Write-Host "APK signé publié : $publishedApk"
Write-Host "SHA-256 : $($hash.Hash)"
