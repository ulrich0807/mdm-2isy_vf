<?php

namespace App\Http\Controllers;

use App\Models\Profil;
use App\Support\OrganizationAccess;
use Illuminate\Http\Request;

class ProfilController extends Controller 
{
    public function index(Request $request)
    {
        $organization = OrganizationAccess::resolve($request->user(), $request->query('organization_id'));

        return response()->json(Profil::query()
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->orderByDesc('id')
            ->get());
    }

    public function store(Request $request)
    {
        $organization = OrganizationAccess::resolve($request->user(), $request->input('organization_id'), true);
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'kiosk' => ['sometimes', 'boolean'],
            'app_kiosk' => ['nullable', 'string', 'max:255'],
            'kiosk_apps' => ['sometimes', 'array'],
            'kiosk_apps.*' => ['string', 'max:255'],
            'no_cam' => ['sometimes', 'boolean'],
            'no_usb' => ['sometimes', 'boolean'],
            'no_bt' => ['sometimes', 'boolean'],
            'no_wifi' => ['sometimes', 'boolean'],
            'no_data' => ['sometimes', 'boolean'],
            'no_airplane' => ['sometimes', 'boolean'],
            'pin_fort' => ['sometimes', 'boolean'],
            'blacklist_apps' => ['sometimes', 'array'],
            'blacklist_apps.*' => ['string', 'max:255'],
            'whitelist_apps' => ['sometimes', 'array'],
            'whitelist_apps.*' => ['string', 'max:255'],
        ]);
        $prof = Profil::create([...$data, 'organization_id' => $organization->id]);

        return response()->json(['success' => true, 'message' => 'Profil créé', 'data' => $prof], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $organization = OrganizationAccess::resolve($request->user());
        $profile = Profil::query()
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->findOrFail($id);
        $profile->delete();

        return response()->json(['success' => true, 'message' => 'Profil supprimé']);
    }

    public function update(Request $request, int $id)
    {
        $organization = OrganizationAccess::resolve($request->user());
        $profile = Profil::query()
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->findOrFail($id);
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'kiosk' => ['required', 'boolean'],
            'app_kiosk' => ['nullable', 'string', 'max:255'],
            'kiosk_apps' => ['array'],
            'kiosk_apps.*' => ['string', 'max:255'],
            'no_cam' => ['required', 'boolean'],
            'no_usb' => ['required', 'boolean'],
            'no_bt' => ['required', 'boolean'],
            'no_wifi' => ['required', 'boolean'],
            'no_data' => ['required', 'boolean'],
            'no_airplane' => ['required', 'boolean'],
            'pin_fort' => ['required', 'boolean'],
            'blacklist_apps' => ['array'],
            'blacklist_apps.*' => ['string', 'max:255'],
            'whitelist_apps' => ['array'],
            'whitelist_apps.*' => ['string', 'max:255'],
        ]);
        $profile->update($data);

        $profile->terminals()
            ->whereNotNull('fcm_token')
            ->pluck('fcm_token')
            ->each(fn (string $token) => app(\App\Services\FcmService::class)->sendCommand($token, 'sync_policy', []));

        return response()->json([
            'success' => true,
            'message' => 'Profil mis à jour et synchronisation demandée.',
            'data' => $profile->fresh(),
        ]);
    }
}
