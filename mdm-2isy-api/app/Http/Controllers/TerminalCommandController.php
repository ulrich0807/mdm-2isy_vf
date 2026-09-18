<?php

namespace App\Http\Controllers;

use App\Exceptions\DeviceCommandException;
use App\Models\DeviceCommand;
use App\Models\Terminal;
use App\Models\User;
use App\Services\DeviceCommandService;
use App\Support\OrganizationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class TerminalCommandController extends Controller
{
    private const TYPES = ['locate', 'lock', 'wipe', 'install_app', 'uninstall_app'];

    public function index(
        Request $request,
        string $terminal,
        DeviceCommandService $commands,
    ): JsonResponse {
        $actor = $this->administrator($request);
        $device = $this->accessibleTerminalByPublicId($actor, $terminal);

        $commands->expirePending();

        $history = DeviceCommand::query()
            ->with('events')
            ->where('terminal_id', $device->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (DeviceCommand $command): array => $this->forAdministrator($command))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    public function store(
        Request $request,
        string $terminal,
        DeviceCommandService $commands,
    ): JsonResponse {
        $actor = $this->administrator($request);
        $device = $this->accessibleTerminalByPublicId($actor, $terminal);

        return $this->queue($request, $device, $actor, $commands, null, true);
    }

    public function locate(
        Request $request,
        int $id,
        DeviceCommandService $commands,
    ): JsonResponse {
        $actor = $this->administrator($request);
        $device = $this->accessibleTerminalById($actor, $id);

        return $this->queue($request, $device, $actor, $commands, 'locate');
    }

    public function lock(
        Request $request,
        int $id,
        DeviceCommandService $commands,
    ): JsonResponse {
        $actor = $this->administrator($request);
        $device = $this->accessibleTerminalById($actor, $id);

        return $this->queue($request, $device, $actor, $commands, 'lock');
    }

    public function wipe(
        Request $request,
        int $id,
        DeviceCommandService $commands,
    ): JsonResponse {
        $actor = $this->administrator($request);
        $device = $this->accessibleTerminalById($actor, $id);

        return $this->queue($request, $device, $actor, $commands, 'wipe');
    }

    public function install(
        Request $request,
        int $id,
        DeviceCommandService $commands,
    ): JsonResponse {
        $actor = $this->administrator($request);
        $device = $this->accessibleTerminalById($actor, $id);

        $request->merge(['type' => 'install_app']);

        return $this->queue($request, $device, $actor, $commands, 'install_app');
    }

    public function uninstallApp(
        Request $request,
        int $id,
        DeviceCommandService $commands,
    ): JsonResponse {
        $actor = $this->administrator($request);
        $device = $this->accessibleTerminalById($actor, $id);

        $request->merge(['type' => 'uninstall_app']);

        return $this->queue($request, $device, $actor, $commands, 'uninstall_app');
    }

    private function queue(
        Request $request,
        Terminal $terminal,
        User $actor,
        DeviceCommandService $commands,
        ?string $forcedType = null,
        bool $idempotencyKeyRequired = false,
    ): JsonResponse {
        if (!$terminal->lic || $terminal->lic->statut !== 'Active') {
            throw ValidationException::withMessages([
                'terminal' => 'Le terminal doit avoir une licence active pour recevoir des commandes.',
            ]);
        }

        $input = $request->all();

        if ($forcedType !== null) {
            $input['type'] = $forcedType;
        }

        $data = Validator::make($input, [
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'payload' => [
                'sometimes',
                'nullable',
                'array:message,timeout_seconds,high_accuracy,url,packageName',
                'max:5',
            ],
            'payload.message' => ['sometimes', 'string', 'max:500'],
            'payload.timeout_seconds' => ['sometimes', 'integer', 'between:5,300'],
            'payload.high_accuracy' => ['sometimes', 'boolean'],
            'payload.url' => ['sometimes', 'url'],
            'payload.packageName' => ['sometimes', 'string', 'max:255'],
            'confirmation' => [
                'required_if:type,wipe',
                'prohibited_unless:type,wipe',
                'string',
                'max:255',
            ],
            'current_password' => [
                'required_if:type,wipe',
                'prohibited_unless:type,wipe',
                'string',
                'max:1024',
            ],
        ])->validate();

        $payload = $this->validatedPayload($data['type'], $data['payload'] ?? []);

        if ($data['type'] === 'wipe') {
            $this->validateWipe($terminal, $actor, $data);
        }

        // Security fields are deliberately excluded from every service call.
        unset($data['confirmation'], $data['current_password']);

        $idempotencyKey = $this->idempotencyKey($request, $idempotencyKeyRequired);

        try {
            $issued = $commands->issue(
                $terminal,
                $actor,
                $data['type'],
                $payload,
                $idempotencyKey,
            );
        } catch (DeviceCommandException $exception) {
            return $this->commandError($exception);
        }

        $command = $issued['command'];
        $replayed = $issued['replayed'];

        if (!$replayed && $terminal->fcm_token) {
            app(\App\Services\FcmService::class)->sendCommand($terminal->fcm_token, $command->type, $command->payload ?? []);
        }

        if (!$replayed) {
            \App\Models\Log::create([
                'usr' => $actor->name . ' (' . ucfirst($actor->role) . ')',
                'act' => 'Commande ' . ucfirst($data['type']) . ' envoyée',
                'cible' => 'Terminal ' . ($terminal->livreur ?: $terminal->modele ?: $terminal->id),
                'typ' => 'primary'
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $replayed
                ? 'Cette commande avait déjà été mise en file.'
                : 'Commande mise en file.',
            'data' => $this->forAdministrator($command),
        ], $replayed ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatedPayload(string $type, array $payload): array
    {
        $allowedKeys = match ($type) {
            'locate' => ['timeout_seconds', 'high_accuracy'],
            'lock' => ['message'],
            'wipe' => [],
            'install_app' => ['url', 'packageName'],
            'uninstall_app' => ['packageName'],
        };

        $unexpectedKeys = array_diff(array_keys($payload), $allowedKeys);

        if ($unexpectedKeys !== []) {
            throw ValidationException::withMessages([
                'payload' => "Le contenu payload n'est pas compatible avec ce type de commande.",
            ]);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateWipe(Terminal $terminal, User $actor, array $data): void
    {
        if (! hash_equals((string) $terminal->public_id, (string) $data['confirmation'])) {
            throw ValidationException::withMessages([
                'confirmation' => "La confirmation doit correspondre exactement à l'identifiant public du terminal.",
            ]);
        }

        if (! Hash::check((string) $data['current_password'], $actor->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'Le mot de passe courant est incorrect.',
            ]);
        }
    }

    private function idempotencyKey(Request $request, bool $required): string
    {
        $key = $request->header('Idempotency-Key');
        $validated = Validator::make([
            'idempotency_key' => $key,
        ], [
            'idempotency_key' => [
                $required ? 'required' : 'nullable',
                'string',
                'min:8',
                'max:128',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/',
            ],
        ])->validate();

        return $validated['idempotency_key'] ?? 'server_'.Str::uuid()->toString();
    }

    private function administrator(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User || ! in_array($user->role, ['admin', 'super_admin'], true)) {
            throw new AuthorizationException('Cette action est réservée aux administrateurs.');
        }

        return $user;
    }

    private function accessibleTerminalByPublicId(User $actor, string $publicId): Terminal
    {
        $organization = OrganizationAccess::resolve($actor);

        return Terminal::query()
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    private function accessibleTerminalById(User $actor, int $id): Terminal
    {
        $organization = OrganizationAccess::resolve($actor);

        return Terminal::query()
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function forAdministrator(DeviceCommand $command): array
    {
        $command->loadMissing('events');

        return [
            'public_id' => $command->public_id,
            'type' => $command->type,
            'status' => $command->status,
            'payload' => $command->payload,
            'result' => $command->result,
            'error_code' => $command->error_code,
            'error_message' => $command->error_message,
            'queued_at' => $command->queued_at?->toIso8601String(),
            'sent_at' => $command->sent_at?->toIso8601String(),
            'acknowledged_at' => $command->acknowledged_at?->toIso8601String(),
            'completed_at' => $command->completed_at?->toIso8601String(),
            'failed_at' => $command->status === DeviceCommand::STATUS_FAILED
                ? $command->completed_at?->toIso8601String()
                : null,
            'expires_at' => $command->expires_at?->toIso8601String(),
            'created_at' => $command->created_at?->toIso8601String(),
            'updated_at' => $command->updated_at?->toIso8601String(),
            'events' => $command->events
                ->map(fn ($event): array => [
                    'from_status' => $event->from_status,
                    'to_status' => $event->to_status,
                    'actor_type' => $event->actor_type,
                    'metadata' => $event->metadata,
                    'created_at' => $event->created_at?->toIso8601String(),
                ])
                ->values(),
        ];
    }

    private function commandError(DeviceCommandException $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
            'reason' => $exception->reason,
        ], $exception->httpStatus);
    }
}
