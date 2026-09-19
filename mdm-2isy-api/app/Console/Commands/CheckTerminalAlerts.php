<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\Terminal;
use Illuminate\Console\Command;

class CheckTerminalAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mdm:check-alerts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Vérifie les terminaux pour générer ou résoudre des alertes (batterie, stockage, hors ligne)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $terminals = Terminal::query()->where('enrollment_status', 'enrolled')->get();

        foreach ($terminals as $terminal) {
            $this->checkConnectivity($terminal);
            $this->checkBattery($terminal);
            $this->checkStorage($terminal);
        }

        $this->info('Vérification des alertes terminée.');
    }

    private function checkConnectivity(Terminal $terminal)
    {
        $thresholdMinutes = 60; // 1 hour offline

        $isOffline = $terminal->last_seen_at === null || $terminal->last_seen_at->diffInMinutes(now()) > $thresholdMinutes;

        if ($isOffline) {
            $this->raiseAlert($terminal, 'offline', "Le terminal n'a pas communiqué depuis plus de {$thresholdMinutes} minutes.");
        } else {
            $this->resolveAlert($terminal, 'offline');
        }
    }

    private function checkBattery(Terminal $terminal)
    {
        if ($terminal->batterie === null) {
            return;
        }

        $threshold = 15; // 15% battery

        if ($terminal->batterie < $threshold) {
            $this->raiseAlert($terminal, 'battery', "Le niveau de batterie est critique ({$terminal->batterie}%).");
        } else {
            $this->resolveAlert($terminal, 'battery');
        }
    }

    private function checkStorage(Terminal $terminal)
    {
        if ($terminal->storage_total_mb === null || $terminal->storage_free_mb === null || $terminal->storage_total_mb == 0) {
            return;
        }

        $freeRatio = $terminal->storage_free_mb / $terminal->storage_total_mb;

        if ($freeRatio < 0.10) { // Less than 10% free
            $freeMb = number_format($terminal->storage_free_mb, 0, ',', ' ');
            $totalMb = number_format($terminal->storage_total_mb, 0, ',', ' ');
            $this->raiseAlert($terminal, 'storage', "Stockage saturé : Il reste seulement {$freeMb} Mo sur {$totalMb} Mo.");
        } else {
            $this->resolveAlert($terminal, 'storage');
        }
    }

    private function raiseAlert(Terminal $terminal, string $type, string $message)
    {
        // Check if an unresolved alert of this type already exists
        $exists = Alert::query()
            ->where('terminal_id', $terminal->id)
            ->where('type', $type)
            ->whereNull('resolved_at')
            ->exists();

        if (!$exists) {
            Alert::create([
                'terminal_id' => $terminal->id,
                'type' => $type,
                'message' => $message,
            ]);
        }
    }

    private function resolveAlert(Terminal $terminal, string $type)
    {
        Alert::query()
            ->where('terminal_id', $terminal->id)
            ->where('type', $type)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
            ]);
    }
}
