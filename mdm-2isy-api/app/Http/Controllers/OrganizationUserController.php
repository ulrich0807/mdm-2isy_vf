<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\OrganizationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class OrganizationUserController extends Controller
{
    public function index(Request $request)
    {
        $organization = OrganizationAccess::resolve($request->user(), $request->query('organization_id'));

        return response()->json([
            'success' => true,
            'data' => User::query()
                ->select(['id', 'organization_id', 'name', 'email', 'role', 'created_at'])
                ->with('organization:id,name,public_id')
                ->whereIn('role', ['admin', 'operator', 'viewer'])
                ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $organization = OrganizationAccess::resolve(
            $request->user(),
            $request->input('organization_id'),
            true,
        );
        $allowedRoles = $request->user()->role === 'super_admin'
            ? ['admin', 'operator', 'viewer']
            : ['operator', 'viewer'];
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in($allowedRoles)],
            'password' => ['required', 'string', Password::min(12)->letters()->mixedCase()->numbers()],
        ]);

        $user = User::create([
            ...$data,
            'organization_id' => $organization->id,
            'password' => Hash::make($data['password']),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur créé avec succès.',
            'data' => $user->load('organization:id,name,public_id'),
        ], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $organization = OrganizationAccess::resolve($request->user());
        $user = User::query()
            ->whereIn('role', ['admin', 'operator', 'viewer'])
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->findOrFail($id);

        if ($user->is($request->user())) {
            throw new AuthorizationException('Vous ne pouvez pas supprimer votre propre compte.');
        }
        if ($request->user()->role !== 'super_admin' && $user->role === 'admin') {
            throw new AuthorizationException('Un administrateur ne peut pas supprimer un autre administrateur.');
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['success' => true, 'message' => 'Utilisateur supprimé.']);
    }
}
