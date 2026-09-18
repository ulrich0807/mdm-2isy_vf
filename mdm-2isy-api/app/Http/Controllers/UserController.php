<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $req)
    {
        // Seul le super_admin peut voir la liste des clients
        if ($req->user()->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
        }

        // On récupère uniquement les clients (rôle 'admin')
        $clients = User::query()
            ->with('organization:id,name,public_id')
            ->where('role', 'admin')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $clients,
        ]);
    }

    public function store(Request $req)
    {
        // 1. Barrière de sécurité : Seul le super_admin passe
        if ($req->user()->role !== 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé. Droits insuffisants.',
            ], 403);
        }

        // 2. Validation des données envoyées par Angular
        $req->validate([
            'name' => 'required|string|max:255',
            'organization_name' => ['required', 'string', 'max:255'],
            'email' => 'required|string|email|max:255|unique:users',
            'password' => [
                'required',
                'string',
                Password::min(12)->letters()->mixedCase()->numbers(),
            ],
        ]);

        // 3. Création du compte client (Forcé en tant que simple 'admin')
        $user = DB::transaction(function () use ($req) {
            $organization = Organization::create([
                'name' => $req->organization_name,
                'slug' => OrganizationController::uniqueSlug($req->organization_name),
                'active' => true,
            ]);

            return User::create([
                'organization_id' => $organization->id,
                'name' => $req->name,
                'email' => $req->email,
                'password' => Hash::make($req->password),
                'role' => 'admin', // On force le rôle pour éviter toute faille
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Le compte client a été créé avec succès.',
            'data' => $user->load('organization:id,name,public_id'),
        ], 201);
    }

    // Mettre à jour les informations du profil
    public function updProf(Request $req)
    {
        $usr = $req->user();

        $req->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,'.$usr->id,
        ]);

        $usr->update($req->only('name', 'email'));

        return response()->json([
            'success' => true,
            'message' => 'Profil mis à jour avec succès.',
            'data' => $usr,
        ]);
    }

    // Mettre à jour le mot de passe
    public function updPwd(Request $req)
    {
        $usr = $req->user();

        $req->validate([
            'old_pwd' => ['required', 'string'],
            'new_pwd' => [
                'required',
                'string',
                'confirmed',
                Password::min(12)->letters()->mixedCase()->numbers(),
            ],
        ]);

        if (! Hash::check($req->old_pwd, $usr->password)) {
            return response()->json(['success' => false, 'message' => 'Ancien mot de passe incorrect.'], 400);
        }

        // Enregistrement du nouveau mot de passe
        $usr->update(['password' => Hash::make($req->new_pwd)]);

        // Un changement de mot de passe invalide toutes les sessions existantes.
        $usr->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Mot de passe modifié avec succès.',
        ]);
    }
}
