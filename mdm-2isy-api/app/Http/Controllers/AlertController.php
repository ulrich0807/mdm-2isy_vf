<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Terminal;
use App\Support\OrganizationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AlertController extends Controller
{
    public function index(Request $request)
    {
        $organization = OrganizationAccess::resolve($request->user());

        $query = Alert::query()
            ->with(['terminal:id,public_id,imei,modele,livreur,organization_id'])
            ->whereHas('terminal', function ($q) use ($organization) {
                if ($organization) {
                    $q->where('organization_id', $organization->id);
                }
            });

        if ($request->query('status') === 'active') {
            $query->whereNull('resolved_at');
        } elseif ($request->query('status') === 'resolved') {
            $query->whereNotNull('resolved_at');
        }

        $alerts = $query->orderByDesc('created_at')->get();

        return response()->json([
            'success' => true,
            'data' => $alerts,
        ]);
    }

    public function resolve(Request $request, int $id)
    {
        $organization = OrganizationAccess::resolve($request->user());

        $alert = Alert::query()
            ->whereHas('terminal', function ($q) use ($organization) {
                if ($organization) {
                    $q->where('organization_id', $organization->id);
                }
            })
            ->findOrFail($id);

        $alert->update(['resolved_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Alerte résolue manuellement.',
        ]);
    }
}
