<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ApplicationDeploymentController;
use App\Http\Controllers\TerminalCommandController;
use App\Models\Log;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditAdministrativeAction
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();
        $controller = $request->route()?->getControllerClass();

        if (
            ! $user
            || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || $response->getStatusCode() >= 400
            || in_array($controller, [TerminalCommandController::class, ApplicationDeploymentController::class], true)
        ) {
            return $response;
        }

        $organizationId = $user->organization_id
            ?: $request->input('organization_id')
            ?: $request->query('organization_id');
        $action = match ($request->method()) {
            'POST' => 'Création ou action',
            'PUT', 'PATCH' => 'Modification',
            'DELETE' => 'Suppression',
        };

        Log::create([
            'organization_id' => $organizationId ?: null,
            'usr' => $user->name.' ('.ucfirst($user->role).')',
            'act' => $action,
            'cible' => '/'.$request->path(),
            'typ' => $request->method() === 'DELETE' ? 'warning' : 'primary',
        ]);

        return $response;
    }
}
