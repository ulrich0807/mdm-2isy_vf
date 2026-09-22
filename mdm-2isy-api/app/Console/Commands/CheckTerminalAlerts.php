<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\DeviceCommand;
use App\Models\Terminal;
use Illuminate\Console\Command;

class CheckTerminalAlerts extends Command
{
    protected $signature = 'mdm:check-alerts';

    protected $description = 'Génère et résout les alertes de supervision du parc MDM';

    public function handle(): int
    {
        $terminals = Terminal::query()
            ->where('enrollment_status', 'enrolled')
            ->with('lic')
            ->get();

        foreach ($terminals as $terminal) {
            $this->checkConnectivity($terminal);
            $this->checkBattery($terminal);
            $this->checkStorage($terminal);
            $this->checkLicence($terminal);
        }

        $this->checkFailedCommands();
        $this->info('Vérification des alertes terminée.');

        return self::SUCCESS;
    }

    private function checkConnectivity(Terminal $terminal): void
    {
        $offline = $terminal->last_seen_at === null
            || $terminal->last_seen_at->lt(now()->subHour());

        $this->syncCondition(
            $terminal,
            'offline',
            'critical',
            $offline,
            "Le terminal n'a pas communiqué depuis plus de 60 minutes.",
        );
    }

    private function checkBattery(Terminal $terminal): void
    {
        $level = $terminal->batterie;
        $this->syncCondition(
            $terminal,
            'battery',
            $level !== null && $level <= 15 ? 'critical' : 'warning',
            $level !== null && $level < 20,
            "Le niveau de batterie est faible ({$level}%).",
        );
    }

    private function checkStorage(Terminal $terminal): void
    {
        $total = $terminal->storage_total_mb;
        $free = $terminal->storage_free_mb;
        $ratio = $total && $free !== null ? $free / $total : null;
        $message = $ratio === null
            ? 'Stockage indisponible.'
            : sprintf('Stockage faible : %s Mo libres sur %s Mo.', number_format($free, 0, ',', ' '), number_format($total, 0, ',', ' '));

        $this->syncCondition(
            $terminal,
            'storage',
            $ratio !== null && $ratio < 0.05 ? 'critical' : 'warning',
            $ratio !== null && $ratio < 0.10,
            $message,
        );
    }

    private function checkLicence(Terminal $terminal): void
    {
        $licence = $terminal->lic;
        $expiresAt = $licence?->exp_le;
        $expired = $expiresAt?->isPast() ?? false;
        $expiring = ! $expired && $expiresAt !== null && $expiresAt->lte(now()->addDays(30));

        $this->syncCondition(
            $terminal,
            'licence_expired',
            'critical',
            $expired,
            'La licence du terminal est expirée.',
        );
        $this->syncCondition(
            $terminal,
            'licence_expiring',
            'warning',
            $expiring,
            $expiresAt ? 'La licence expire le '.$expiresAt->format('d/m/Y').'.' : '',
        );
    }

    private function checkFailedCommands(): void
    {
        DeviceCommand::query()
            ->with('terminal:id,organization_id')
            ->whereIn('status', [DeviceCommand::STATUS_FAILED, DeviceCommand::STATUS_EXPIRED])
            ->where('updated_at', '>=', now()->subDays(7))
            ->each(function (DeviceCommand $command): void {
                if (! $command->terminal) {
                    return;
                }

                Alert::query()->firstOrCreate(
                    ['source_key' => 'command:'.$command->public_id],
                    [
                        'organization_id' => $command->organization_id,
                        'terminal_id' => $command->terminal_id,
                        'type' => 'command_failure',
                        'severity' => $command->type === DeviceCommand::TYPE_WIPE ? 'critical' : 'warning',
                        'message' => sprintf(
                            'La commande %s a échoué%s.',
                            $command->type,
                            $command->error_message ? ' : '.$command->error_message : '',
                        ),
                    ],
                );
            });
    }

    private function syncCondition(
        Terminal $terminal,
        string $type,
        string $severity,
        bool $active,
        string $message,
    ): void {
        $sourceKey = 'terminal:'.$terminal->id.':'.$type;

        if ($active) {
            Alert::query()->updateOrCreate(
                ['source_key' => $sourceKey],
                [
                    'organization_id' => $terminal->organization_id,
                    'terminal_id' => $terminal->id,
                    'type' => $type,
                    'severity' => $severity,
                    'message' => $message,
                    'resolved_at' => null,
                ],
            );

            return;
        }

        Alert::query()
            ->where('source_key', $sourceKey)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);
    }
}
