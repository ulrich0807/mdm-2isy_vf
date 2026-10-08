<?php

namespace App\Http\Controllers;

use App\Services\AgentReleaseService;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AgentDownloadController extends Controller
{
    public function __invoke(AgentReleaseService $releases): BinaryFileResponse
    {
        $apkPath = public_path('apk/mdm-agent.apk');

        abort_unless(is_file($apkPath), 404, "L'agent Android n'est pas disponible.");

        try {
            $release = $releases->metadata();
        } catch (RuntimeException $exception) {
            report($exception);
            abort(503, "La publication de l'agent Android n'a pas passé le contrôle d'intégrité.");
        }

        $response = response()->download(
            $apkPath,
            '2ISY-MDM-Agent.apk',
            [
                'Content-Type' => 'application/vnd.android.package-archive',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'X-Agent-Package' => $release['package_name'],
                'X-Agent-Version' => $release['version_name'],
                'X-Agent-Version-Code' => (string) $release['version_code'],
                'X-Checksum-Sha256' => $release['sha256'],
            ],
        );

        $response->setEtag($release['sha256']);

        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('no-cache');
        $response->headers->addCacheControlDirective('must-revalidate');

        return $response;
    }

    public function release(string $sha256): BinaryFileResponse
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $sha256) === 1, 404);

        $apkPath = public_path('apk/releases/'.$sha256.'.apk');
        abort_unless(is_file($apkPath) && is_readable($apkPath), 404);

        $actualHash = hash_file('sha256', $apkPath);
        abort_unless(is_string($actualHash) && hash_equals($sha256, strtolower($actualHash)), 503);

        $response = response()->download(
            $apkPath,
            '2ISY-MDM-Agent-'.$sha256.'.apk',
            [
                'Content-Type' => 'application/vnd.android.package-archive',
                'X-Content-Type-Options' => 'nosniff',
                'X-Checksum-Sha256' => $sha256,
            ],
        );
        $response->setEtag($sha256);
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setImmutable();

        return $response;
    }
}
