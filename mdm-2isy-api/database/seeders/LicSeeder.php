<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\License;

class LicSeeder extends Seeder
{
    public function run(): void
    {
        // On assigne une licence inactive au terminal ID 1
        License::create([
            'terminal_id' => 1,
            'cle' => 'MDM-2ISY-A1B2-C3D4',
            'est_valide' => false
        ]);
    }
}