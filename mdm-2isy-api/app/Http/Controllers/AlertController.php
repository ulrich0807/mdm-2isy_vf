<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Support\OrganizationAccess;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(Request $request)
    {
        $organization = OrganizationAccess::resolve($request->user(), $request->query('organization_id'));

        $query = Alert::query()
            ->with(['terminal:id,public_id,imei,modele,livreur,organization_id'])
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization));

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
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->findOrFail($id);

        $alert->update(['resolved_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Alerte résolue manuellement.',
        ]);
    }
}
