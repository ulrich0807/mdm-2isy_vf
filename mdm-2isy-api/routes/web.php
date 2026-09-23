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

Route::redirect('/download/mdm-agent', '/download/mdm-agent.apk');
