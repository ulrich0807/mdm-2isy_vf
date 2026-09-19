<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\AuthCtrl;
use App\Http\Controllers\DeviceCommandController;
use App\Http\Controllers\DeviceEnrollmentController;
use App\Http\Controllers\DeviceGroupController;
use App\Http\Controllers\DeviceHeartbeatController;
use App\Http\Controllers\EnrollmentTokenController;
use App\Http\Controllers\LicController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProfilController;
use App\Http\Controllers\TerminalCommandController;
use App\Http\Controllers\TerminalController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// --- ROUTES PUBLIQUES ---

// Authentification
Route::post('/auth/in', [AuthCtrl::class, 'in'])->middleware('throttle:5,1');

// Route de vérification de l'état du serveur
Route::get('/ping', function () {
    return response()->json(['success' => true, 'message' => 'pong']);
});

Route::post('/v1/device/enroll', [DeviceEnrollmentController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('device.enroll');

Route::post('/v1/device/heartbeat', [DeviceHeartbeatController::class, 'store'])
    ->middleware(['device.auth', 'throttle:device-heartbeat'])
    ->name('device.heartbeat');

Route::middleware('device.auth')
    ->prefix('v1/device/commands')
    ->group(function () {
        Route::get('/', [DeviceCommandController::class, 'index'])
            ->middleware('throttle:device-command-poll')
            ->name('device.commands.index');
        Route::post('/{command}/ack', [DeviceCommandController::class, 'acknowledge'])
            ->middleware('throttle:device-command-transition')
            ->whereUuid('command')
            ->name('device.commands.acknowledge');
        Route::post('/{command}/result', [DeviceCommandController::class, 'result'])
            ->middleware('throttle:device-command-transition')
            ->whereUuid('command')
            ->name('device.commands.result');
    });

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/organizations', [OrganizationController::class, 'index']);
    Route::post('/organizations', [OrganizationController::class, 'store']);

    Route::get('/device-groups', [DeviceGroupController::class, 'index']);
    Route::post('/device-groups', [DeviceGroupController::class, 'store']);
    Route::delete('/device-groups/{id}', [DeviceGroupController::class, 'destroy'])
        ->whereNumber('id');

    Route::get('/device-enrollments', [EnrollmentTokenController::class, 'index']);
    Route::post('/device-enrollments', [EnrollmentTokenController::class, 'store']);
    Route::delete('/device-enrollments/{token}', [EnrollmentTokenController::class, 'destroy'])
        ->whereUuid('token');
});

// Alias versionnés conservés pour les premiers clients de l'API.
Route::middleware('auth:sanctum')->prefix('v1/admin')->group(function () {
    Route::get('/enrollment-tokens', [EnrollmentTokenController::class, 'index']);
    Route::post('/enrollment-tokens', [EnrollmentTokenController::class, 'store']);
    Route::delete('/enrollment-tokens/{token}', [EnrollmentTokenController::class, 'destroy'])
        ->whereUuid('token');
});

// --- ROUTES PROTÉGÉES (Nécessitent un Token Sanctum) ---

Route::middleware('auth:sanctum')->group(function () {
    // Déconnexion
    Route::post('/auth/out', [AuthCtrl::class, 'out']);

    // Gestion Terminaux
    Route::get('/terminals', [TerminalController::class, 'index']);
    Route::post('/terminals', [TerminalController::class, 'store']);
    Route::get('/terminals/{terminal}/commands', [TerminalCommandController::class, 'index'])
        ->whereUuid('terminal');
    Route::post('/terminals/{terminal}/commands', [TerminalCommandController::class, 'store'])
        ->whereUuid('terminal');
    Route::get('/terminals/{id}', [TerminalController::class, 'show'])->whereNumber('id');
    Route::delete('/terminals/{id}', [TerminalController::class, 'destroy'])->whereNumber('id');
    Route::put('/terminals/{id}/group', [TerminalController::class, 'updateGroup'])->whereNumber('id');
    Route::put('/terminals/{id}/livreur', [TerminalController::class, 'updateLivreur'])->whereNumber('id');
    Route::put('/terminals/{id}/profil', [TerminalController::class, 'updateProfil'])->whereNumber('id');
    Route::post('/terminals/{id}/revoke-credential', [TerminalController::class, 'revokeCredential'])
        ->whereNumber('id');
    Route::post('/terminals/{id}/locate', [TerminalCommandController::class, 'locate'])->whereNumber('id');
    Route::post('/terminals/{id}/lock', [TerminalCommandController::class, 'lock'])->whereNumber('id');
    Route::post('/terminals/{id}/wipe', [TerminalCommandController::class, 'wipe'])->whereNumber('id');
    Route::post('/terminals/{id}/install-app', [TerminalCommandController::class, 'install'])->whereNumber('id');
    Route::post('/terminals/{id}/uninstall-app', [TerminalCommandController::class, 'uninstallApp'])->whereNumber('id');

    // Gestion Licences
    Route::get('/lics', [LicController::class, 'index']);
    Route::post('/lics', [LicController::class, 'store']); // Générer
    Route::post('/lics/{id}/actv', [LicController::class, 'actv']); // Activer
    Route::post('/lics/{id}/assign', [LicController::class, 'assign']);
    // Route pour la création d'un client
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    // Routes pour le profil (Paramètres)
    Route::put('/users/prof', [UserController::class, 'updProf']);
    Route::put('/users/pwd', [UserController::class, 'updPwd']);

    // --- GESTION DES APPLICATIONS (MAM) ---
    Route::get('/apps', [AppController::class, 'index']);
    Route::post('/apps', [AppController::class, 'store']);
    Route::delete('/apps/{id}', [AppController::class, 'destroy']);

    // --- GESTION DES PROFILS DE SÉCURITÉ ---
    Route::get('/profils', [ProfilController::class, 'index']);
    Route::post('/profils', [ProfilController::class, 'store']);
    Route::delete('/profils/{id}', [ProfilController::class, 'destroy']);

    // --- JOURNAL D'AUDIT (LOGS) ---
    Route::get('/logs', [LogController::class, 'index']);

    // --- CENTRE D'ALERTES ---
    Route::get('/alerts', [AlertController::class, 'index']);
    Route::post('/alerts/{id}/resolve', [AlertController::class, 'resolve'])->whereNumber('id');

});
