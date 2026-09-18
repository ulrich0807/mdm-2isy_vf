<?php

namespace App\Http\Controllers;

use App\Exceptions\DeviceCommandException;
use App\Models\DeviceCommand;
use App\Models\Terminal;
use App\Services\DeviceCommandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeviceCommandController extends Controller
{
    public function index(Request $request, DeviceCommandService $commands): JsonResponse
    {
        $device = $this->device($request);
        $data = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        try {
            $pending = $commands->deliverPending($device, (int) ($data['limit'] ?? 10));
        } catch (DeviceCommandException $exception) {
            return $this->commandError($exception);
        }

        return response()->json([
            'success' => true,
            'data' => $pending
                ->map(fn (DeviceCommand $command): array => $this->forDevice($command))
                ->values(),
        ]);
    }

    public function acknowledge(
        Request $request,
        string $command,
        DeviceCommandService $commands,
    ): JsonResponse {
        $device = $this->device($request);

        try {
            $deviceCommand = $commands->acknowledge($device, $command);
        } catch (DeviceCommandException $exception) {
            return $this->commandError($exception);
        }

        return response()->json([
            'success' => true,
            'data' => $this->transitionForDevice($deviceCommand),
        ]);
    }

    public function result(
        Request $request,
        string $command,
        DeviceCommandService $commands,
    ): JsonResponse {
        $device = $this->device($request);
        $deviceCommand = $this->accessibleCommand($device, $command);
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(['succeeded', 'failed'])],
            'result' => [
                'sometimes',
                'nullable',
                'array:lat,lng,accuracy_m,message,executed_at,locked,wipe_started',
                'max:7',
            ],
            'result.lat' => ['required_with:result.lng', 'numeric', 'between:-90,90'],
            'result.lng' => ['required_with:result.lat', 'numeric', 'between:-180,180'],
            'result.accuracy_m' => ['sometimes', 'numeric', 'between:0,100000'],
            'result.message' => ['sometimes', 'string', 'max:500'],
            'result.executed_at' => ['sometimes', 'date_format:Y-m-d\TH:i:sP'],
            'result.locked' => ['sometimes', 'boolean'],
            'result.wipe_started' => ['sometimes', 'boolean'],
            'error_code' => [
                'required_if:status,failed',
                'prohibited_unless:status,failed',
                'string',
                'max:100',
                'regex:/\A[A-Z0-9][A-Z0-9_.-]*\z/',
            ],
            'error_message' => [
                'sometimes',
                'nullable',
                'prohibited_unless:status,failed',
                'string',
                'max:1000',
            ],
        ]);

        $result = $data['result'] ?? [];
        $this->validateResultForType($deviceCommand, $data['status'], $result);

        try {
            $deviceCommand = $commands->complete(
                $device,
                $command,
                $data['status'],
                $data['result'] ?? null,
                $data['error_code'] ?? null,
                $data['error_message'] ?? null,
            );
        } catch (DeviceCommandException $exception) {
            return $this->commandError($exception);
        }

        return response()->json([
            'success' => true,
            'data' => $this->transitionForDevice($deviceCommand),
        ]);
    }

    private function device(Request $request): Terminal
    {
        $device = $request->attributes->get('device');

        if (! $device instanceof Terminal) {
            abort(401, 'Unauthenticated device.');
        }

        return $device;
    }

    private function accessibleCommand(Terminal $device, string $publicId): DeviceCommand
    {
        return DeviceCommand::query()
            ->where('terminal_id', $device->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function validateResultForType(
        DeviceCommand $command,
        string $status,
        array $result,
    ): void {
        if ($status !== DeviceCommand::STATUS_SUCCEEDED) {
            return;
        }

        if ($command->type === DeviceCommand::TYPE_LOCATE) {
            $errors = [];

            if (! array_key_exists('lat', $result)) {
                $errors['result.lat'] = 'Une localisation réussie doit inclure la latitude.';
            }

            if (! array_key_exists('lng', $result)) {
                $errors['result.lng'] = 'Une localisation réussie doit inclure la longitude.';
            }

            if (array_key_exists('locked', $result) || array_key_exists('wipe_started', $result)) {
                $errors['result'] = 'Le résultat locate contient une preuve incompatible.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return;
        }

        if ($command->type !== 'locate' && (array_key_exists('lat', $result) || array_key_exists('lng', $result))) {
            throw ValidationException::withMessages([
                'result' => 'Seule une commande locate peut publier des coordonnées.',
            ]);
        }

        if ($command->type === DeviceCommand::TYPE_LOCK) {
            if (($result['locked'] ?? null) !== true) {
                throw ValidationException::withMessages([
                    'result.locked' => 'Une commande lock réussie doit confirmer locked=true.',
                ]);
            }

            if (array_key_exists('wipe_started', $result)) {
                throw ValidationException::withMessages([
                    'result.wipe_started' => 'Cette preuve est réservée aux commandes wipe.',
                ]);
            }

            return;
        }

        if (($result['wipe_started'] ?? null) !== true) {
            throw ValidationException::withMessages([
                'result.wipe_started' => 'Une commande wipe réussie doit confirmer wipe_started=true.',
            ]);
        }

        if (array_key_exists('locked', $result)) {
            throw ValidationException::withMessages([
                'result.locked' => 'Cette preuve est réservée aux commandes lock.',
            ]);
        }
    }

    /**
     * Minimal delivery representation: no tenant, actor, internal identifier or audit data.
     *
     * @return array<string, mixed>
     */
    private function forDevice(DeviceCommand $command): array
    {
        return [
            'public_id' => $command->public_id,
            'type' => $command->type,
            'payload' => $command->payload,
            'queued_at' => $command->queued_at?->toIso8601String(),
            'sent_at' => $command->sent_at?->toIso8601String(),
            'expires_at' => $command->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transitionForDevice(DeviceCommand $command): array
    {
        return [
            'public_id' => $command->public_id,
            'type' => $command->type,
            'status' => $command->status,
            'acknowledged_at' => $command->acknowledged_at?->toIso8601String(),
            'completed_at' => $command->completed_at?->toIso8601String(),
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
