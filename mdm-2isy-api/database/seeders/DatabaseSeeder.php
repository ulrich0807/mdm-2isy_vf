<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\App;
use App\Models\Profil;
use App\Models\Log;
use App\Models\Organization;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        $organizationId = Organization::query()->where('slug', 'legacy-fleet')->value('id');

        // --- 1. AJOUT DES APPLICATIONS ---
        App::create([
            'organization_id' => $organizationId,
            'nom' => 'Application Métier 2ISY',
            'pkg' => 'com.isy.app',
            'type' => 'blanche',
            'ver' => '1.0.2'
        ]);

        App::create([
            'organization_id' => $organizationId,
            'nom' => 'Facebook',
            'pkg' => 'com.facebook.katana',
            'type' => 'noire',
            'ver' => '-'
        ]);

        App::create([
            'organization_id' => $organizationId,
            'nom' => 'TikTok',
            'pkg' => 'com.zhiliaoapp.musically',
            'type' => 'noire',
            'ver' => '-'
        ]);

        // --- 2. AJOUT DES PROFILS DE SÉCURITÉ ---
        Profil::create([
            'organization_id' => $organizationId,
            'nom' => 'Profil Livreur Strict',
            'kiosk' => true,
            'app_kiosk' => 'com.isy.app',
            'no_cam' => false,
            'no_usb' => true,
            'no_bt' => true,
            'pin_fort' => true
        ]);

        Profil::create([
            'organization_id' => $organizationId,
            'nom' => 'Profil Superviseur',
            'kiosk' => false,
            'app_kiosk' => null,
            'no_cam' => false,
            'no_usb' => false,
            'no_bt' => false,
            'pin_fort' => true
        ]);

        // --- 3. AJOUT DES LOGS (JOURNAL D'AUDIT) ---
        Log::create([
            'organization_id' => $organizationId,
            'usr' => 'Admin (Ulrich)',
            'act' => 'Création du profil Livreur Strict',
            'cible' => 'Système',
            'typ' => 'primary'
        ]);

        Log::create([
            'organization_id' => $organizationId,
            'usr' => 'Système',
            'act' => 'Batterie critique (12%)',
            'cible' => 'IMEI 8645... (Zone Nord)',
            'typ' => 'warning'
        ]);

        Log::create([
            'organization_id' => $organizationId,
            'usr' => 'Admin',
            'act' => 'Wipe (Effacement à distance)',
            'cible' => 'IMEI 1234... (Perdu)',
            'typ' => 'danger'
        ]);
    }
}
