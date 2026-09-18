<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Terminal;
use Illuminate\Database\Seeder;

class TerminalSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()
            ->where('slug', 'legacy-fleet')
            ->firstOrFail();

        $terminaux = [
            ['imei' => 'IMEI12345678901', 'livreur' => 'Kouassi Jean', 'modele' => 'Samsung Galaxy A14', 'batterie' => 85, 'statut' => 'En ligne', 'lat' => 5.3599, 'lng' => -4.0083],
            ['imei' => 'IMEI12345678902', 'livreur' => 'Bamba Ali', 'modele' => 'Tecno Spark 10', 'batterie' => 42, 'statut' => 'En ligne', 'lat' => 5.3610, 'lng' => -4.0100],
            ['imei' => 'IMEI12345678903', 'livreur' => 'Touré Marc', 'modele' => 'Samsung Galaxy A14', 'batterie' => 5, 'statut' => 'Hors ligne', 'lat' => 5.3500, 'lng' => -4.0200],
        ];

        foreach ($terminaux as $t) {
            Terminal::updateOrCreate(
                ['imei' => $t['imei']],
                [
                    ...$t,
                    'organization_id' => $organization->id,
                    'enrollment_status' => 'legacy',
                ],
            );
        }
    }
}
