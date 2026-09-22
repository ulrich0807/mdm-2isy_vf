<?php

namespace App\Http\Controllers;

use App\Models\Lic;
use App\Models\Terminal;
use App\Support\OrganizationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LicController extends Controller
{
    public function index(Request $request)
    {
        $organization = OrganizationAccess::resolve($request->user(), $request->query('organization_id'));
        $licences = Lic::query()
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->orderByDesc('id')
            ->get();

        $licences->each(function (Lic $licence): void {
            if ($licence->statut === 'Active' && $licence->exp_le?->isPast()) {
                $licence->update(['statut' => 'Expirée']);
            }
        });

        return response()->json(['success' => true, 'data' => $licences]);
    }

    public function store(Request $request)
    {
        if ($request->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
        }

        $organization = OrganizationAccess::resolve(
            $request->user(),
            $request->input('organization_id'),
            true,
        );
        $licence = Lic::create([
            'organization_id' => $organization->id,
            'cle' => 'MDM-'.date('Y').'-'.strtoupper(Str::random(6)),
            'statut' => 'Vierge',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Licence générée avec succès',
            'data' => $licence,
        ], 201);
    }

    public function actv(Request $request, int $id)
    {
        $licence = $this->accessibleLicence($request, $id);

        if ($licence->statut === 'Expirée' || $licence->exp_le?->isPast()) {
            $licence->update(['statut' => 'Expirée']);

            return response()->json(['success' => false, 'message' => 'Licence invalide ou expirée'], 400);
        }

        $licence->update([
            'exp_le' => $licence->exp_le ?? now()->addYear(),
            'statut' => 'Active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Licence activée avec succès ! Fin le : '.$licence->fresh()->exp_le->format('d/m/Y'),
        ]);
    }

    public function assign(Request $request, int $id)
    {
        $licence = $this->accessibleLicence($request, $id);
        $data = $request->validate(['term_id' => ['nullable', 'integer']]);

        if ($licence->statut !== 'Active' || $licence->exp_le?->isPast()) {
            if ($licence->exp_le?->isPast()) {
                $licence->update(['statut' => 'Expirée']);
            }

            return response()->json(['success' => false, 'message' => 'Licence invalide ou non active'], 400);
        }

        $terminalId = $data['term_id'] ?? null;
        if ($terminalId !== null) {
            Terminal::query()
                ->where('organization_id', $licence->organization_id)
                ->findOrFail($terminalId);

            $alreadyAssigned = Lic::query()
                ->where('term_id', $terminalId)
                ->where('id', '!=', $licence->id)
                ->exists();

            if ($alreadyAssigned) {
                throw ValidationException::withMessages([
                    'term_id' => 'Ce terminal possède déjà une licence.',
                ]);
            }
        }

        $licence->update(['term_id' => $terminalId]);

        return response()->json([
            'success' => true,
            'message' => $terminalId ? 'Licence assignée avec succès.' : 'Licence détachée du terminal.',
        ]);
    }

    private function accessibleLicence(Request $request, int $id): Lic
    {
        $organization = OrganizationAccess::resolve($request->user());

        return Lic::query()
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->findOrFail($id);
    }
}
