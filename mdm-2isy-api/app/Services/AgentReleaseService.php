<?php

namespace App\Services;

use RuntimeException;

class AgentReleaseService
{
    private const LEGACY_AGENT_MAX_APK_BYTES = 100 * 1024 * 1024;

    public const PACKAGE_NAME = 'com.mdm2isy.agent';

    public const UPDATE_MARKER_PREFIX = 'MDM_AGENT_UPDATE_V1';

    /**
     * @return array{
     *     package_name: string,
     *     version_code: int,
     *     version_name: string,
     *     sha256: string,
     *     size_bytes: int,
     *     download_url: string,
     *     marker: string
     * }
     */
    public function metadata(): array
    {
        $latestApkPath = public_path('apk/mdm-agent.apk');
        $manifestPath = public_path('apk/mdm-agent.json');

        if (! is_file($latestApkPath) || ! is_readable($latestApkPath)) {
            throw new RuntimeException("L'APK de l'agent MDM est indisponible.");
        }

        if (! is_file($manifestPath) || ! is_readable($manifestPath)) {
            throw new RuntimeException("Le manifeste de l'agent MDM est indisponible.");
        }

        try {
            $manifest = json_decode(
                (string) file_get_contents($manifestPath),
                true,
                16,
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException("Le manifeste de l'agent MDM est invalide.", 0, $exception);
        }

        if (! is_array($manifest)) {
            throw new RuntimeException("Le manifeste de l'agent MDM est invalide.");
        }

        $packageName = $manifest['package_name'] ?? null;
        $versionCode = filter_var($manifest['version_code'] ?? null, FILTER_VALIDATE_INT);
        $versionName = $manifest['version_name'] ?? null;
        $expectedHash = strtolower((string) ($manifest['sha256'] ?? ''));
        $expectedSize = filter_var($manifest['size_bytes'] ?? null, FILTER_VALIDATE_INT);

        if (
            $packageName !== self::PACKAGE_NAME
            || $versionCode === false
            || $versionCode < 1
            || ! is_string($versionName)
            || ! preg_match('/\A[0-9A-Za-z][0-9A-Za-z._-]{0,99}\z/', $versionName)
            || ! preg_match('/\A[a-f0-9]{64}\z/', $expectedHash)
            || $expectedSize === false
            || $expectedSize < 1
        ) {
            throw new RuntimeException("Le manifeste de l'agent MDM est incomplet ou invalide.");
        }

        $releaseApkPath = public_path('apk/releases/'.$expectedHash.'.apk');
        if (! is_file($releaseApkPath) || ! is_readable($releaseApkPath)) {
            throw new RuntimeException(
                "L'artefact immuable de l'agent MDM est indisponible.",
            );
        }

        $actualHash = hash_file('sha256', $releaseApkPath);
        $actualSize = filesize($releaseApkPath);
        $latestHash = hash_file('sha256', $latestApkPath);
        if (
            ! is_string($actualHash)
            || ! hash_equals($expectedHash, strtolower($actualHash))
            || $actualSize === false
            || $actualSize !== $expectedSize
            || ! is_string($latestHash)
            || ! hash_equals($expectedHash, strtolower($latestHash))
        ) {
            throw new RuntimeException(
                "L'APK publié ne correspond pas à son manifeste d'intégrité.",
            );
        }

        if ($expectedSize > self::LEGACY_AGENT_MAX_APK_BYTES) {
            throw new RuntimeException(
                "L'APK de l'agent dépasse la limite de 100 Mio des agents 0.1.9.",
            );
        }

        $marker = implode('|', [
            self::UPDATE_MARKER_PREFIX,
            'version_code='.$versionCode,
            'version_name='.$versionName,
            'sha256='.$expectedHash,
        ]);

        return [
            'package_name' => self::PACKAGE_NAME,
            'version_code' => $versionCode,
            'version_name' => $versionName,
            'sha256' => $expectedHash,
            'size_bytes' => $expectedSize,
            'download_url' => route('agent.release.download', ['sha256' => $expectedHash]),
            'marker' => $marker,
        ];
    }

    /**
     * Payload deliberately uses only fields understood by agent 0.1.9.
     * The strict marker lives in the existing `message` field, so newer
     * agents can add hash/version checks without breaking the bootstrap path.
     *
     * @param  array{package_name: string, version_code: int, version_name: string, sha256: string, size_bytes: int, download_url: string, marker: string}  $release
     * @return array{url: string, packageName: string, timeout_seconds: int, message: string}
     */
    public function commandPayload(array $release): array
    {
        return [
            'url' => $release['download_url'],
            'packageName' => self::PACKAGE_NAME,
            'timeout_seconds' => 300,
            'message' => $release['marker'],
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function isAgentUpdatePayload(array $payload): bool
    {
        return ($payload['packageName'] ?? null) === self::PACKAGE_NAME
            && is_string($payload['message'] ?? null)
            && preg_match(
                '/\A'.self::UPDATE_MARKER_PREFIX.'\|version_code=[1-9][0-9]*\|version_name=[0-9A-Za-z][0-9A-Za-z._-]{0,99}\|sha256=[a-f0-9]{64}\z/',
                $payload['message'],
            ) === 1;
    }
}
