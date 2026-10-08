<?php

use App\Http\Controllers\AgentDownloadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Adresse publique et stable : le fichier remplacé à chaque livraison est
// toujours proposé sous le même nom aux administrateurs et aux terminaux.
Route::get('/download/mdm-agent.apk', AgentDownloadController::class)
    ->middleware('throttle:60,1')
    ->name('agent.download');

// Artefact content-addressé : une vague déjà en file ne bascule jamais vers
// un APK plus récent lorsque le lien « latest » est remplacé.
Route::get('/download/mdm-agent/releases/{sha256}.apk', [AgentDownloadController::class, 'release'])
    ->middleware('throttle:300,1')
    ->where('sha256', '[a-f0-9]{64}')
    ->name('agent.release.download');

Route::redirect('/download/mdm-agent', '/download/mdm-agent.apk');
