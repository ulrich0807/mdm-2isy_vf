<?php

namespace App\Http\Middleware;

use App\Models\DeviceCredential;
use App\Models\Terminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();

        if (! is_string($plainTextToken) || $plainTextToken === '') {
            return $this->unauthenticated();
        }

        $credential = DeviceCredential::query()
            ->with('terminal.organization')
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->whereNull('revoked_at')
            ->first();

        if (
            ! $credential
            || ! $credential->terminal instanceof Terminal
            || ! $credential->terminal->organization?->active
        ) {
            return $this->unauthenticated();
        }

        $credential->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('device', $credential->terminal);
        $request->attributes->set('device_credential', $credential);

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json([
            'message' => 'Unauthenticated device.',
        ], Response::HTTP_UNAUTHORIZED);
    }
}
