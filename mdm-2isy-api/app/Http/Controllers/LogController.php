<?php

namespace App\Http\Controllers;

use App\Models\Log;
use App\Support\OrganizationAccess;
use Illuminate\Http\Request;

class LogController extends Controller 
{
    public function index(Request $request)
    {
        $organization = OrganizationAccess::resolve($request->user(), $request->query('organization_id'));
        $logs = Log::query()
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->orderByDesc('created_at')
            ->get()
            ->each(fn (Log $log) => $log->date = $log->created_at->format('d/m/Y H:i'));
        
        return response()->json($logs);
    }
}
