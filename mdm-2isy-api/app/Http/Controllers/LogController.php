<?php

namespace App\Http\Controllers;

use App\Models\Log;

class LogController extends Controller 
{
    public function index() 
    {
        // On formate la date directement pour l'affichage sur Angular
        $logs = Log::orderBy('created_at', 'desc')->get()->map(function($log) {
            $log->date = $log->created_at->format('d/m/Y H:i');
            return $log;
        });
        
        return response()->json($logs);
    }
}