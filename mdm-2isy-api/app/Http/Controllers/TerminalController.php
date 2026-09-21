<?php

namespace App\Http\Controllers;

use App\Models\DeviceGroup;
use App\Models\Terminal;
use App\Support\OrganizationAccess;
use Illuminate\Http\Request;

class TerminalController extends Controller
{
    public function index(Request $request)
    {
        $organization = OrganizationAccess::resolve(
            $request->user(),
            $request->query('organization_id'),
        );

        $terminals = Terminal::query()
            ->with([
                'organization:id,name,public_id',
                'deviceGroup:id,name',
                'lic:id,term_id,statut,exp_le',
                'profil:id,nom,kiosk,app_kiosk,no_cam,no_usb,no_bt,pin_fort',
            ])
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $terminals,
        ]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json([
            'success' => true,
            'data' => $this->accessibleTerminal($request, $id)->load([
                'organization:id,name,public_id',
                'deviceGroup:id,name',
                'lic:id,term_id,statut,exp_le',
                'profil:id,nom,kiosk,app_kiosk,no_cam,no_usb,no_bt,pin_fort',
            ]),
        ]);
    }

    /**
     * Pré-enregistre un terminal historique. Un appareil n'est considéré comme
     * enrôlé qu'après le flux sécurisé /api/v1/device/enroll.
     */
    public function store(Request $request)
    {
        $organization = OrganizationAccess::resolve(
            $request->user(),
            $request->input('organization_id'),
            true,
        );

        $data = $request->validate([
            'imei' => ['nullable', 'string', 'max:50', 'unique:terminals,imei'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'livreur' => ['nullable', 'string', 'max:255'],
            'modele' => ['nullable', 'string', 'max:255'],
            'device_group_id' => ['nullable', 'integer'],
        ]);

        $group = $this->groupForOrganization(
            $data['device_group_id'] ?? null,
            $organization->id,
        );

        $terminal = Terminal::create([
            ...$data,
            'organization_id' => $organization->id,
            'device_group_id' => $group?->id,
            'enrollment_status' => 'pending',
            'statut' => 'Hors ligne',
        ]);

        return response()->json([
            'success' => true,
            'message' => "Terminal pré-enregistré. L'enrôlement de l'agent reste requis.",
            'data' => $terminal->load('deviceGroup:id,name'),
        ], 201);
    }

    public function updateGroup(Request $request, int $id)
    {
        $terminal = $this->accessibleTerminal($request, $id);
        $data = $request->validate([
            'device_group_id' => ['nullable', 'integer'],
        ]);

        $group = $this->groupForOrganization(
            $data['device_group_id'] ?? null,
            $terminal->organization_id,
        );

        $terminal->update(['device_group_id' => $group?->id]);

        return response()->json([
            'success' => true,
            'message' => 'Affectation du groupe mise à jour.',
            'data' => $terminal->fresh()->load('deviceGroup:id,name'),
        ]);
    }

    public function updateLivreur(Request $request, int $id)
    {
        $terminal = $this->accessibleTerminal($request, $id);
        $data = $request->validate([
            'livreur' => ['nullable', 'string', 'max:255'],
        ]);

        $terminal->update(['livreur' => $data['livreur']]);

        return response()->json([
            'success' => true,
            'message' => 'Livreur mis à jour avec succès.',
            'data' => $terminal->fresh()->load(['deviceGroup:id,name', 'lic:id,term_id,statut,exp_le', 'profil']),
        ]);
    }

    public function updateProfil(Request $request, int $id)
    {
        $terminal = $this->accessibleTerminal($request, $id);
        $data = $request->validate([
            'profil_id' => ['nullable', 'integer', 'exists:profils,id'],
        ]);

        $terminal->update(['profil_id' => $data['profil_id']]);

        if ($terminal->fcm_token) {
            app(\App\Services\FcmService::class)->sendCommand($terminal->fcm_token, 'sync_policy', []);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profil mis à jour et synchronisation demandée.',
            'data' => $terminal->fresh()->load(['deviceGroup:id,name', 'lic:id,term_id,statut,exp_le', 'profil']),
        ]);
    }

    public function revokeCredential(Request $request, int $id)
    {
        $terminal = $this->accessibleTerminal($request, $id);

        $terminal->credential()->whereNull('revoked_at')->update([
            'revoked_at' => now(),
        ]);
        $terminal->update([
            'enrollment_status' => 'revoked',
            'statut' => 'Hors ligne',
        ]);

        return response()->json([
            'success' => true,
            'message' => "L'identité de l'appareil a été révoquée.",
        ]);
    }

    public function locate(Request $request, int $id)
    {
        return $this->commandUnavailable($this->accessibleTerminal($request, $id));
    }

    public function lock(Request $request, int $id)
    {
        return $this->commandUnavailable($this->accessibleTerminal($request, $id));
    }

    public function wipe(Request $request, int $id)
    {
        return $this->commandUnavailable($this->accessibleTerminal($request, $id));
    }

    public function destroy(Request $request, int $id)
    {
        $terminal = $this->accessibleTerminal($request, $id);
        $terminal->delete();

        return response()->json([
            'success' => true,
            'message' => 'Le terminal a été supprimé de la base de données.'
        ]);
    }

    public function history(Request $request, int $id)
    {
        $terminal = $this->accessibleTerminal($request, $id);
        
        $hours = $request->query('hours', 24);
        
        $history = $terminal->locationHistories()
            ->where('recorded_at', '>=', now()->subHours($hours))
            ->orderBy('recorded_at', 'asc')
            ->get(['lat', 'lng', 'recorded_at']);
            
        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    private function accessibleTerminal(Request $request, int $id): Terminal
    {
        $organization = OrganizationAccess::resolve($request->user());

        return Terminal::query()
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->findOrFail($id);
    }

    private function groupForOrganization(mixed $groupId, int $organizationId): ?DeviceGroup
    {
        if ($groupId === null || $groupId === '') {
            return null;
        }

        return DeviceGroup::query()
            ->where('organization_id', $organizationId)
            ->findOrFail((int) $groupId);
    }

    private function commandUnavailable(Terminal $terminal)
    {
        return response()->json([
            'success' => false,
            'message' => "Le moteur de commandes Android n'est pas encore disponible.",
            'target' => $terminal->public_id,
        ], 501);
    }
}
