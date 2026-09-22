<?php

namespace App\Http\Controllers;

use App\Exceptions\DeviceCommandException;
use App\Models\App;
use App\Models\Log;
use App\Models\Terminal;
use App\Models\User;
use App\Services\DeviceCommandService;
use App\Support\OrganizationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ApplicationDeploymentController extends Controller
{
    public function store(
        Request $request,
        int $id,
        DeviceCommandService $commands,
    ): JsonResponse {
        $actor = $request->user();
        if (! $actor instanceof User || ! in_array($actor->role, ['operator', 'admin', 'super_admin'], true)) {
            throw new AuthorizationException('Cette action est réservée aux administrateurs.');
        }

        $organization = OrganizationAccess::resolve($actor);
        $app = App::query()
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->where('type', 'blanche')
            ->findOrFail($id);
        abort_unless($app->chemin_apk, 422, "Aucun fichier APK n'est disponible pour cette application.");

        $data = $request->validate([
            'terminal_ids' => ['required', 'array', 'min:1', 'max:100'],
            'terminal_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $terminalIds = array_values($data['terminal_ids']);
        $terminals = Terminal::query()
            ->where('organization_id', $app->organization_id)
            ->whereIn('id', $terminalIds)
            ->with('lic')
            ->get()
            ->keyBy('id');

        $downloadUrl = URL::temporarySignedRoute(
            'apps.download',
            now()->addMinutes(65),
            ['app' => $app->id],
        );
        $accepted = [];
        $rejected = [];

        foreach ($terminalIds as $terminalId) {
            $terminal = $terminals->get($terminalId);
            if (! $terminal) {
                $rejected[] = ['terminal_id' => $terminalId, 'message' => 'Terminal introuvable dans cette organisation.'];
                continue;
            }

            if (! $terminal->lic || $terminal->lic->statut !== 'Active' || $terminal->lic->exp_le?->isPast()) {
                $rejected[] = ['terminal_id' => $terminalId, 'message' => 'Licence active requise.'];
                continue;
            }

            try {
                $issued = $commands->issue(
                    $terminal,
                    $actor,
                    'install_app',
                    ['url' => $downloadUrl, 'packageName' => $app->pkg],
                    'app-deploy-'.$app->id.'-'.$terminal->id.'-'.Str::uuid(),
                );
            } catch (DeviceCommandException $exception) {
                $rejected[] = ['terminal_id' => $terminalId, 'message' => $exception->getMessage()];
                continue;
            }

            $command = $issued['command'];
            if ($terminal->fcm_token) {
                app(\App\Services\FcmService::class)->sendCommand(
                    $terminal->fcm_token,
                    $command->type,
                    $command->payload ?? [],
                );
            }
            $accepted[] = [
                'terminal_id' => $terminal->id,
                'command_id' => $command->public_id,
                'status' => $command->status,
            ];
        }

        if ($accepted !== []) {
            Log::create([
                'organization_id' => $app->organization_id,
                'usr' => $actor->name.' ('.ucfirst($actor->role).')',
                'act' => 'Déploiement de '.$app->nom.' vers '.count($accepted).' terminal(aux)',
                'cible' => 'Application '.$app->pkg,
                'typ' => $rejected === [] ? 'primary' : 'warning',
            ]);
        }

        return response()->json([
            'success' => $accepted !== [],
            'message' => count($accepted).' commande(s) mise(s) en file, '.count($rejected).' rejetée(s).',
            'data' => ['accepted' => $accepted, 'rejected' => $rejected],
        ], $rejected === [] ? Response::HTTP_CREATED : Response::HTTP_MULTI_STATUS);
    }
}
