<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\App;
use App\Models\Profil;
use App\Models\Log;

class DatabaseSeeder extends Seeder
{
    public function run()
    {
        // --- 1. AJOUT DES APPLICATIONS ---
        App::create([
            'nom' => 'Application Métier 2ISY',
            'pkg' => 'com.isy.app',
            'type' => 'blanche',
            'ver' => '1.0.2'
        ]);

        App::create([
            'nom' => 'Facebook',
            'pkg' => 'com.facebook.katana',
            'type' => 'noire',
            'ver' => '-'
        ]);

        App::create([
            'nom' => 'TikTok',
            'pkg' => 'com.zhiliaoapp.musically',
            'type' => 'noire',
            'ver' => '-'
        ]);

        // --- 2. AJOUT DES PROFILS DE SÉCURITÉ ---
        Profil::create([
            'nom' => 'Profil Livreur Strict',
            'kiosk' => true,
            'app_kiosk' => 'com.isy.app',
            'no_cam' => false,
            'no_usb' => true,
            'no_bt' => true,
            'pin_fort' => true
        ]);

        Profil::create([
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
            'usr' => 'Admin (Ulrich)',
            'act' => 'Création du profil Livreur Strict',
            'cible' => 'Système',
            'typ' => 'primary'
        ]);

        Log::create([
            'usr' => 'Système',
            'act' => 'Batterie critique (12%)',
            'cible' => 'IMEI 8645... (Zone Nord)',
            'typ' => 'warning'
        ]);

        Log::create([
            'usr' => 'Admin',
            'act' => 'Wipe (Effacement à distance)',
            'cible' => 'IMEI 1234... (Perdu)',
            'typ' => 'danger'
        ]);
    }
}