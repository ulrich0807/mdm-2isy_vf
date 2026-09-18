<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthCtrl extends Controller
{
    public function in(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Identifiants incorrects.',
            ], 401);
        }

        $user->load('organization:id,name,public_id,active');

        if ($user->role !== 'super_admin' && (! $user->organization || ! $user->organization->active)) {
            return response()->json([
                'success' => false,
                'message' => "Ce compte n'est rattaché à aucune organisation active.",
            ], 403);
        }

        return response()->json([
            'success' => true,
            'tok' => $user->createToken('AdminToken', ['admin'])->plainTextToken,
            'usr' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'organization_id' => $user->organization_id,
                'organization' => $user->organization,
            ],
        ]);
    }

    public function out(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie.',
        ]);
    }
}
