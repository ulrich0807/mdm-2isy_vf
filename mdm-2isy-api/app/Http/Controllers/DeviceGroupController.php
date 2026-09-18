<?php

namespace App\Http\Controllers;

use App\Models\DeviceGroup;
use App\Support\OrganizationAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeviceGroupController extends Controller
{
    public function index(Request $request)
    {
        $organization = OrganizationAccess::resolve(
            $request->user(),
            $request->query('organization_id'),
        );

        $groups = DeviceGroup::query()
            ->with('organization:id,name')
            ->withCount('terminals')
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $groups,
        ]);
    }

    public function store(Request $request)
    {
        $organization = OrganizationAccess::resolve(
            $request->user(),
            $request->input('organization_id'),
            true,
        );

        $data = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('device_groups')->where(
                    fn ($query) => $query->where('organization_id', $organization->id),
                ),
            ],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $group = $organization->deviceGroups()->create($data);

        return response()->json([
            'success' => true,
            'message' => 'Groupe créé avec succès.',
            'data' => $group,
        ], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $group = DeviceGroup::query()->findOrFail($id);
        $organization = OrganizationAccess::resolve(
            $request->user(),
            $group->organization_id,
            true,
        );

        abort_unless($group->organization_id === $organization->id, 403, 'Accès refusé.');

        $group->delete();

        return response()->json([
            'success' => true,
            'message' => 'Groupe supprimé. Les terminaux sont maintenant non classés.',
        ]);
    }
}
