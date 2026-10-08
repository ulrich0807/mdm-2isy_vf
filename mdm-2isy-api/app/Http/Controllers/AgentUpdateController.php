<?php

namespace App\Http\Controllers;

use App\Exceptions\DeviceCommandException;
use App\Models\DeviceCommand;
use App\Models\Log;
use App\Models\Terminal;
use App\Models\User;
use App\Services\AgentReleaseService;
use App\Services\DeviceCommandService;
use App\Services\FcmService;
use App\Support\OrganizationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class AgentUpdateController extends Controller
{
    private const PILOT_MAX_TERMINALS = 1;

    private const BATCH_MAX_TERMINALS = 100;

    public function show(Request $request, AgentReleaseService $releases): JsonResponse
    {
        $this->administrator($request);

        try {
            $release = $releases->metadata();
        } catch (RuntimeException $exception) {
            return $this->releaseUnavailable($exception);
        }

        unset($release['marker']);

        return response()->json([
            'success' => true,
            'data' => [
                'release' => $release,
                'rollout' => [
                    'pilot_max_terminals' => self::PILOT_MAX_TERMINALS,
                    'batch_max_terminals' => self::BATCH_MAX_TERMINALS,
                    'offline_queue_days' => (int) config('mdm.agent_update_queue_days', 7),
                ],
            ],
        ]);
    }

    public function store(
        Request $request,
        AgentReleaseService $releases,
        DeviceCommandService $commands,
        FcmService $fcm,
    ): JsonResponse {
        $actor = $this->administrator($request);
        $data = $request->validate([
            'organization_id' => ['nullable', 'integer'],
            'mode' => ['required', 'string', Rule::in(['pilot', 'batch'])],
            'terminal_ids' => ['required', 'array', 'min:1', 'max:'.self::BATCH_MAX_TERMINALS],
            'terminal_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        if ($data['mode'] === 'pilot' && count($data['terminal_ids']) > self::PILOT_MAX_TERMINALS) {
            throw ValidationException::withMessages([
                'terminal_ids' => 'Le déploiement pilote doit cibler un seul terminal.',
            ]);
        }

        $organization = OrganizationAccess::resolve(
            $actor,
            $data['organization_id'] ?? null,
            true,
        );

        try {
            $release = $releases->metadata();
        } catch (RuntimeException $exception) {
            return $this->releaseUnavailable($exception);
        }

        $terminalIds = array_values($data['terminal_ids']);
        $terminals = Terminal::query()
            ->whereBelongsTo($organization)
            ->whereIn('id', $terminalIds)
            ->with('lic')
            ->get()
            ->keyBy('id');
        $payload = $releases->commandPayload($release);
        $accepted = [];
        $rejected = [];

        foreach ($terminalIds as $terminalId) {
            /** @var Terminal|null $terminal */
            $terminal = $terminals->get($terminalId);

            if (! $terminal) {
                $rejected[] = $this->rejection($terminalId, 'terminal_not_found', 'Terminal introuvable dans cette organisation.');
                continue;
            }

            $ineligible = $this->ineligibility($terminal, $release['version_name'], $release['marker']);
            if ($ineligible !== null) {
                $rejected[] = $ineligible;
                continue;
            }

            try {
                $issued = $commands->issue(
                    $terminal,
                    $actor,
                    DeviceCommand::TYPE_INSTALL_APP,
                    $payload,
                    'agent-update-v1-'.$release['version_code'].'-'.$terminal->public_id,
                );
            } catch (DeviceCommandException $exception) {
                $rejected[] = $this->rejection(
                    $terminal->id,
                    $exception->reason,
                    $exception->getMessage(),
                );
                continue;
            }

            $command = $issued['command'];
            if (! $issued['replayed'] && $terminal->fcm_token) {
                $fcm->sendCommand($terminal->fcm_token, $command->type, $command->payload ?? []);
            }

            $accepted[] = [
                'terminal_id' => $terminal->id,
                'command_id' => $command->public_id,
                'status' => $command->status,
                'replayed' => $issued['replayed'],
            ];
        }

        if ($accepted !== []) {
            Log::create([
                'organization_id' => $organization->id,
                'usr' => $actor->name.' ('.ucfirst($actor->role).')',
                'act' => 'Mise à jour agent '.$release['version_name'].' ('.$data['mode'].') vers '.count($accepted).' terminal(aux)',
                'cible' => 'Agent '.AgentReleaseService::PACKAGE_NAME,
                'typ' => $rejected === [] ? 'primary' : 'warning',
            ]);
        }

        return response()->json([
            'success' => $accepted !== [],
            'message' => count($accepted).' mise(s) à jour en file, '.count($rejected).' terminal(aux) rejeté(s).',
            'data' => [
                'release' => [
                    'version_code' => $release['version_code'],
                    'version_name' => $release['version_name'],
                    'sha256' => $release['sha256'],
                ],
                'mode' => $data['mode'],
                'accepted' => $accepted,
                'rejected' => $rejected,
            ],
        ], $rejected === [] ? Response::HTTP_CREATED : Response::HTTP_MULTI_STATUS);
    }

    /** @return array{terminal_id: int, reason: string, message: string}|null */
    private function ineligibility(
        Terminal $terminal,
        string $targetVersion,
        string $marker,
    ): ?array {
        if (! $terminal->lic || $terminal->lic->statut !== 'Active' || $terminal->lic->exp_le?->isPast()) {
            return $this->rejection($terminal->id, 'active_license_required', 'Licence active requise.');
        }

        if ($terminal->enrollment_status !== 'enrolled') {
            return $this->rejection($terminal->id, 'device_not_enrolled', 'Le terminal doit être enrôlé.');
        }

        if ($terminal->management_state === 'wiped') {
            return $this->rejection($terminal->id, 'device_wiped', 'Le terminal a été effacé.');
        }

        $currentVersion = $this->normalizedVersion($terminal->agent_version);
        if ($currentVersion !== null && version_compare($currentVersion, $targetVersion, '>=')) {
            return $this->rejection(
                $terminal->id,
                'already_current',
                "L'agent {$terminal->agent_version} est déjà à jour.",
            );
        }

        $hasPendingUpdate = DeviceCommand::query()
            ->where('terminal_id', $terminal->id)
            ->where('type', DeviceCommand::TYPE_INSTALL_APP)
            ->whereIn('status', DeviceCommand::PENDING_STATUSES)
            ->get(['payload'])
            ->contains(fn (DeviceCommand $command): bool => ($command->payload['message'] ?? null) === $marker);

        if ($hasPendingUpdate) {
            return $this->rejection(
                $terminal->id,
                'update_already_pending',
                'Cette version est déjà en attente sur ce terminal.',
            );
        }

        return null;
    }

    private function normalizedVersion(?string $version): ?string
    {
        if ($version === null || trim($version) === '') {
            return null;
        }

        if (preg_match('/\d+(?:\.\d+)+/', $version, $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }

    /** @return array{terminal_id: int, reason: string, message: string} */
    private function rejection(int $terminalId, string $reason, string $message): array
    {
        return [
            'terminal_id' => $terminalId,
            'reason' => $reason,
            'message' => $message,
        ];
    }

    private function administrator(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User || $user->role !== 'super_admin') {
            throw new AuthorizationException("La mise à jour de l'agent est réservée au super administrateur.");
        }

        return $user;
    }

    private function releaseUnavailable(RuntimeException $exception): JsonResponse
    {
        report($exception);

        return response()->json([
            'success' => false,
            'message' => "La version publiée de l'agent n'est pas exploitable. Vérifiez son manifeste d'intégrité.",
            'reason' => 'agent_release_unavailable',
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
