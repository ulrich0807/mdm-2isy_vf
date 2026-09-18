<?php

namespace App\Services;

use App\Exceptions\DeviceCommandException;
use App\Models\DeviceCommand;
use App\Models\DeviceCommandEvent;
use App\Models\DeviceCredential;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class DeviceCommandService
{
    private const MAX_STRUCTURED_DATA_BYTES = 16_384;

    private const MAX_DELIVERY_ATTEMPTS = 20;

    private const REDELIVERY_DELAY_SECONDS = 15;

    /** @var array<string, int> */
    private const EXPIRATION_MINUTES = [
        DeviceCommand::TYPE_LOCATE => 5,
        DeviceCommand::TYPE_LOCK => 15,
        DeviceCommand::TYPE_WIPE => 30,
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array{command: DeviceCommand, replayed: bool}
     */
    public function issue(
        Terminal $terminal,
        User $actor,
        string $type,
        array $payload,
        string $idempotencyKey,
    ): array {
        if (! in_array($type, DeviceCommand::TYPES, true)) {
            throw $this->unprocessable('Ce type de commande MDM est inconnu.', 'unsupported_type');
        }

        $this->assertSafeStructuredData($payload, 'payload');
        $idempotencyHash = $this->idempotencyHash($actor, $idempotencyKey);

        try {
            return DB::transaction(function () use (
                $terminal,
                $actor,
                $type,
                $payload,
                $idempotencyHash,
            ): array {
                $existing = DeviceCommand::query()
                    ->where('idempotency_key', $idempotencyHash)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    $sameRequest = (int) $existing->terminal_id === (int) $terminal->id
                        && (int) $existing->created_by === (int) $actor->id
                        && $existing->type === $type
                        && $this->canonicalJson($existing->payload ?? []) === $this->canonicalJson($payload);

                    if (! $sameRequest) {
                        throw new DeviceCommandException(
                            "Cette clé d'idempotence appartient à une autre requête.",
                            'idempotency_key_conflict',
                        );
                    }

                    return [
                        'command' => $existing->fresh(),
                        'replayed' => true,
                    ];
                }

                $lockedTerminal = Terminal::query()->lockForUpdate()->findOrFail($terminal->id);
                $activeCredential = DeviceCredential::query()
                    ->where('terminal_id', $lockedTerminal->id)
                    ->whereNull('revoked_at')
                    ->lockForUpdate()
                    ->exists();

                if ($lockedTerminal->enrollment_status !== 'enrolled' || ! $activeCredential) {
                    throw $this->unprocessable(
                        'Le terminal doit être enrôlé avec une identité appareil active.',
                        'device_not_enrolled',
                    );
                }

                if ($lockedTerminal->management_state === 'wiped') {
                    throw $this->unprocessable(
                        'Aucune commande ne peut être envoyée à un terminal effacé.',
                        'device_wiped',
                    );
                }

                $timestamp = now();
                $command = DeviceCommand::query()->create([
                    'organization_id' => $lockedTerminal->organization_id,
                    'terminal_id' => $lockedTerminal->id,
                    'created_by' => $actor->id,
                    'type' => $type,
                    'payload' => $payload,
                    'status' => DeviceCommand::STATUS_QUEUED,
                    'idempotency_key' => $idempotencyHash,
                    'delivery_attempts' => 0,
                    'queued_at' => $timestamp,
                    'expires_at' => $timestamp->copy()->addMinutes(self::EXPIRATION_MINUTES[$type]),
                ]);

                $this->recordEvent(
                    $command,
                    null,
                    DeviceCommand::STATUS_QUEUED,
                    'admin',
                    $actor->id,
                );

                return [
                    'command' => $command->fresh(),
                    'replayed' => false,
                ];
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Concurrent retries can both miss the first lookup. The unique
            // database index selects the winner, which is then safely replayed.
            $existing = DeviceCommand::query()
                ->where('idempotency_key', $idempotencyHash)
                ->first();

            if (! $existing) {
                throw $exception;
            }

            $sameRequest = (int) $existing->terminal_id === (int) $terminal->id
                && (int) $existing->created_by === (int) $actor->id
                && $existing->type === $type
                && $this->canonicalJson($existing->payload ?? []) === $this->canonicalJson($payload);

            if (! $sameRequest) {
                throw new DeviceCommandException(
                    'This idempotency key belongs to a different request.',
                    'idempotency_key_conflict',
                );
            }

            return [
                'command' => $existing->fresh(),
                'replayed' => true,
            ];
        }
    }

    /**
     * Returns queued and previously sent commands. Redelivery is deliberate;
     * the Android agent must deduplicate execution with the public command UUID.
     *
     * @return EloquentCollection<int, DeviceCommand>
     */
    public function deliverPending(Terminal $terminal, int $limit = 10): EloquentCollection
    {
        $this->assertDeviceIdentityIsActive($terminal);
        $limit = max(1, min(50, $limit));
        $this->expirePendingForTerminal($terminal->id);
        $redeliveryBefore = now()->subSeconds(self::REDELIVERY_DELAY_SECONDS);

        $ids = DeviceCommand::query()
            ->where('terminal_id', $terminal->id)
            ->where('delivery_attempts', '<', self::MAX_DELIVERY_ATTEMPTS)
            ->where(function ($query) use ($redeliveryBefore): void {
                $query
                    ->where('status', DeviceCommand::STATUS_QUEUED)
                    ->orWhere(function ($query) use ($redeliveryBefore): void {
                        $query
                            ->where('status', DeviceCommand::STATUS_SENT)
                            ->where(function ($query) use ($redeliveryBefore): void {
                                $query
                                    ->whereNull('last_delivery_at')
                                    ->orWhere('last_delivery_at', '<=', $redeliveryBefore);
                            });
                    });
            })
            ->where('expires_at', '>', now())
            ->orderByRaw(
                'CASE status WHEN ? THEN 0 ELSE 1 END',
                [DeviceCommand::STATUS_QUEUED],
            )
            ->orderByRaw(
                'CASE type WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END',
                [
                    DeviceCommand::TYPE_WIPE,
                    DeviceCommand::TYPE_LOCK,
                    DeviceCommand::TYPE_LOCATE,
                ],
            )
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $delivered = new EloquentCollection;

        foreach ($ids as $id) {
            $command = DB::transaction(function () use ($id, $terminal): ?DeviceCommand {
                $command = DeviceCommand::query()->lockForUpdate()->find($id);

                if (! $command || (int) $command->terminal_id !== (int) $terminal->id) {
                    return null;
                }

                if (! in_array($command->status, [
                    DeviceCommand::STATUS_QUEUED,
                    DeviceCommand::STATUS_SENT,
                ], true)) {
                    return null;
                }

                if ($command->delivery_attempts >= self::MAX_DELIVERY_ATTEMPTS) {
                    return null;
                }

                if ($command->expires_at->lte(now())) {
                    $this->transitionToExpired($command);

                    return null;
                }

                $fromStatus = $command->status;
                $timestamp = now();

                if (
                    $fromStatus === DeviceCommand::STATUS_SENT
                    && $command->last_delivery_at?->gt(
                        $timestamp->copy()->subSeconds(self::REDELIVERY_DELAY_SECONDS),
                    )
                ) {
                    return null;
                }

                $command->forceFill([
                    'status' => DeviceCommand::STATUS_SENT,
                    'delivery_attempts' => $command->delivery_attempts + 1,
                    'sent_at' => $command->sent_at ?? $timestamp,
                    'last_delivery_at' => $timestamp,
                ])->save();

                if ($fromStatus === DeviceCommand::STATUS_QUEUED) {
                    $this->recordEvent(
                        $command,
                        $fromStatus,
                        DeviceCommand::STATUS_SENT,
                        'device',
                        $terminal->id,
                        ['attempt' => $command->delivery_attempts],
                    );
                }

                return $command->fresh();
            }, attempts: 3);

            if ($command) {
                $delivered->push($command);
            }
        }

        return $delivered;
    }

    public function acknowledge(Terminal $terminal, string $publicId): DeviceCommand
    {
        $this->assertDeviceIdentityIsActive($terminal);

        $outcome = DB::transaction(function () use ($terminal, $publicId): array {
            $command = $this->commandForDevice($terminal, $publicId);

            if (
                $command->status === DeviceCommand::STATUS_EXPIRED
                || $this->expireIfDue($command)
            ) {
                return ['command' => $command->fresh(), 'expired' => true];
            }

            if (in_array($command->status, [
                DeviceCommand::STATUS_ACKNOWLEDGED,
                DeviceCommand::STATUS_SUCCEEDED,
                DeviceCommand::STATUS_FAILED,
            ], true)) {
                return ['command' => $command, 'expired' => false];
            }

            if ($command->status !== DeviceCommand::STATUS_SENT) {
                throw new DeviceCommandException(
                    'La commande ne peut pas être acquittée dans son état actuel.',
                    'invalid_transition',
                );
            }

            $command->forceFill([
                'status' => DeviceCommand::STATUS_ACKNOWLEDGED,
                'acknowledged_at' => now(),
            ])->save();

            $this->recordEvent(
                $command,
                DeviceCommand::STATUS_SENT,
                DeviceCommand::STATUS_ACKNOWLEDGED,
                'device',
                $terminal->id,
            );

            return ['command' => $command->fresh(), 'expired' => false];
        }, attempts: 3);

        if ($outcome['expired']) {
            throw new DeviceCommandException(
                'La commande a expiré avant son acquittement.',
                'command_expired',
            );
        }

        return $outcome['command'];
    }

    /**
     * @param  array<string, mixed>|null  $result
     */
    public function complete(
        Terminal $terminal,
        string $publicId,
        string $status,
        ?array $result = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): DeviceCommand {
        $this->assertDeviceIdentityIsActive($terminal);

        if (! in_array($status, [
            DeviceCommand::STATUS_SUCCEEDED,
            DeviceCommand::STATUS_FAILED,
        ], true)) {
            throw $this->unprocessable('Le résultat final est invalide.', 'invalid_result_status');
        }

        $this->assertSafeStructuredData($result ?? [], 'result');

        $outcome = DB::transaction(function () use (
            $terminal,
            $publicId,
            $status,
            $result,
            $errorCode,
            $errorMessage,
        ): array {
            // Every result for a terminal follows the same lock order. This
            // serializes lock/wipe completions and makes `wiped` monotone.
            $lockedTerminal = Terminal::query()
                ->lockForUpdate()
                ->findOrFail($terminal->id);
            $command = $this->commandForDevice($lockedTerminal, $publicId);

            if ($command->status === DeviceCommand::STATUS_EXPIRED) {
                return ['command' => $command, 'expired' => true];
            }

            if ($command->isFinal()) {
                $sameResult = $command->status === $status
                    && $this->canonicalJson($command->result) === $this->canonicalJson($result)
                    && $command->error_code === $errorCode
                    && $command->error_message === $errorMessage;

                if ($sameResult) {
                    return ['command' => $command, 'expired' => false];
                }

                throw new DeviceCommandException(
                    'Un résultat final différent existe déjà pour cette commande.',
                    'conflicting_final_result',
                );
            }

            if ($this->expireIfDue($command)) {
                return ['command' => $command->fresh(), 'expired' => true];
            }

            if (
                $status === DeviceCommand::STATUS_SUCCEEDED
                && $lockedTerminal->management_state === 'wiped'
                && $command->type !== DeviceCommand::TYPE_WIPE
            ) {
                $this->transitionToExpired($command, [
                    'reason' => 'device_already_wiped',
                ]);

                return ['command' => $command->fresh(), 'expired' => true];
            }

            if (! in_array($command->status, [
                DeviceCommand::STATUS_SENT,
                DeviceCommand::STATUS_ACKNOWLEDGED,
            ], true)) {
                throw new DeviceCommandException(
                    'La commande ne peut pas être terminée dans son état actuel.',
                    'invalid_transition',
                );
            }

            $fromStatus = $command->status;
            $command->forceFill([
                'status' => $status,
                'result' => $result,
                'error_code' => $status === DeviceCommand::STATUS_FAILED ? $errorCode : null,
                'error_message' => $status === DeviceCommand::STATUS_FAILED ? $errorMessage : null,
                'completed_at' => now(),
            ])->save();

            if ($status === DeviceCommand::STATUS_SUCCEEDED) {
                $this->applySuccessfulResult($lockedTerminal, $command, $result ?? []);
            }

            $this->recordEvent(
                $command,
                $fromStatus,
                $status,
                'device',
                $lockedTerminal->id,
                $errorCode ? ['error_code' => $errorCode] : null,
            );

            return ['command' => $command->fresh(), 'expired' => false];
        }, attempts: 3);

        if ($outcome['expired']) {
            throw new DeviceCommandException(
                'La commande a expiré avant la réception du résultat.',
                'command_expired',
            );
        }

        return $outcome['command'];
    }

    public function expirePending(): int
    {
        $expired = 0;

        DeviceCommand::query()
            ->whereIn('status', DeviceCommand::PENDING_STATUSES)
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $id) use (&$expired): void {
                $didExpire = DB::transaction(function () use ($id): bool {
                    $command = DeviceCommand::query()->lockForUpdate()->find($id);

                    if (! $command || ! $this->isDueAndPending($command)) {
                        return false;
                    }

                    $this->transitionToExpired($command);

                    return true;
                }, attempts: 3);

                if ($didExpire) {
                    $expired++;
                }
            });

        return $expired;
    }

    private function expirePendingForTerminal(int $terminalId): void
    {
        $ids = DeviceCommand::query()
            ->where('terminal_id', $terminalId)
            ->whereIn('status', DeviceCommand::PENDING_STATUSES)
            ->where('expires_at', '<=', now())
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id): void {
                $command = DeviceCommand::query()->lockForUpdate()->find($id);

                if ($command && $this->isDueAndPending($command)) {
                    $this->transitionToExpired($command);
                }
            }, attempts: 3);
        }
    }

    private function commandForDevice(Terminal $terminal, string $publicId): DeviceCommand
    {
        return DeviceCommand::query()
            ->where('terminal_id', $terminal->id)
            ->where('public_id', $publicId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertDeviceIdentityIsActive(Terminal $terminal): void
    {
        $active = $terminal->enrollment_status === 'enrolled'
            && DeviceCredential::query()
                ->where('terminal_id', $terminal->id)
                ->whereNull('revoked_at')
                ->exists();

        if (! $active) {
            throw new DeviceCommandException(
                "L'identité de cet appareil n'est plus active.",
                'device_identity_inactive',
                401,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function applySuccessfulResult(
        Terminal $terminal,
        DeviceCommand $command,
        array $result,
    ): void {
        if ($command->type === DeviceCommand::TYPE_LOCATE) {
            if (isset($result['lat'], $result['lng'])) {
                $latitude = (float) $result['lat'];
                $longitude = (float) $result['lng'];

                if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
                    throw $this->unprocessable(
                        'Les coordonnées du résultat sont hors limites.',
                        'invalid_coordinates',
                    );
                }

                $terminal->forceFill([
                    'lat' => $latitude,
                    'lng' => $longitude,
                ])->save();
            }

            return;
        }

        if ($command->type === DeviceCommand::TYPE_LOCK) {
            if ($terminal->management_state !== 'wiped') {
                $terminal->forceFill(['management_state' => 'locked'])->save();
            }

            return;
        }

        if ($command->type === DeviceCommand::TYPE_WIPE) {
            $terminal->forceFill([
                'management_state' => 'wiped',
                'enrollment_status' => 'revoked',
                'statut' => 'Hors ligne',
            ])->save();
            DeviceCredential::query()
                ->where('terminal_id', $terminal->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $this->expireOtherPendingAfterWipe($terminal, $command);
        }
    }

    private function expireOtherPendingAfterWipe(
        Terminal $terminal,
        DeviceCommand $wipeCommand,
    ): void {
        $pendingCommands = DeviceCommand::query()
            ->where('terminal_id', $terminal->id)
            ->where('id', '!=', $wipeCommand->id)
            ->whereIn('status', DeviceCommand::PENDING_STATUSES)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($pendingCommands as $pendingCommand) {
            $this->transitionToExpired($pendingCommand, [
                'reason' => 'device_wiped',
                'superseded_by' => $wipeCommand->public_id,
            ]);
        }
    }

    private function expireIfDue(DeviceCommand $command): bool
    {
        if (! $this->isDueAndPending($command)) {
            return false;
        }

        $this->transitionToExpired($command);

        return true;
    }

    private function isDueAndPending(DeviceCommand $command): bool
    {
        return in_array($command->status, DeviceCommand::PENDING_STATUSES, true)
            && $command->expires_at->lte(now());
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function transitionToExpired(
        DeviceCommand $command,
        ?array $metadata = null,
    ): void {
        $fromStatus = $command->status;
        $command->forceFill([
            'status' => DeviceCommand::STATUS_EXPIRED,
            'completed_at' => now(),
        ])->save();

        $this->recordEvent(
            $command,
            $fromStatus,
            DeviceCommand::STATUS_EXPIRED,
            'system',
            null,
            $metadata,
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function recordEvent(
        DeviceCommand $command,
        ?string $fromStatus,
        string $toStatus,
        string $actorType,
        ?int $actorId,
        ?array $metadata = null,
    ): void {
        DeviceCommandEvent::query()->create([
            'device_command_id' => $command->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function assertSafeStructuredData(array $value, string $field): void
    {
        $encoded = json_encode($value, JSON_THROW_ON_ERROR);

        if (strlen($encoded) > self::MAX_STRUCTURED_DATA_BYTES) {
            throw $this->unprocessable(
                "Le champ {$field} est trop volumineux.",
                'structured_data_too_large',
            );
        }

        $forbiddenKeys = ['password', 'current_password', 'token', 'enrollment_token', 'secret'];
        $walk = function (array $items) use (&$walk, $forbiddenKeys, $field): void {
            foreach ($items as $key => $item) {
                if (is_string($key) && in_array(strtolower($key), $forbiddenKeys, true)) {
                    throw $this->unprocessable(
                        "Le champ {$field} contient une donnée sensible interdite.",
                        'sensitive_data_rejected',
                    );
                }

                if (is_array($item)) {
                    $walk($item);
                }
            }
        };
        $walk($value);
    }

    private function canonicalJson(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }

            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($normalize, $item);
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR);
    }

    private function idempotencyHash(User $actor, string $rawKey): string
    {
        return hash(
            'sha256',
            "device-command:v1\0{$actor->id}\0{$rawKey}",
        );
    }

    private function unprocessable(string $message, string $reason): DeviceCommandException
    {
        return new DeviceCommandException($message, $reason, 422);
    }
}
