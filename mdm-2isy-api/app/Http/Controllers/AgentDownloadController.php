<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AgentDownloadController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $apkPath = public_path('apk/mdm-agent.apk');

        abort_unless(is_file($apkPath), 404, "L'agent Android n'est pas disponible.");

        $response = response()->download(
            $apkPath,
            '2ISY-MDM-Agent.apk',
            [
                'Content-Type' => 'application/vnd.android.package-archive',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );

        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('no-cache');
        $response->headers->addCacheControlDirective('must-revalidate');

        return $response;
    }
}
