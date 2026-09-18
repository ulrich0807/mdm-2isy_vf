<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Support\OrganizationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class OrganizationController extends Controller
{
    public function index(Request $request)
    {
        $organization = OrganizationAccess::resolve($request->user());

        $organizations = $organization === null
            ? Organization::query()->orderBy('name')->get()
            : Organization::query()->whereKey($organization->id)->get();

        return response()->json([
            'success' => true,
            'data' => $organizations,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->role === 'super_admin', 403, 'Accès refusé.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $organization = Organization::create([
            'name' => $data['name'],
            'slug' => self::uniqueSlug($data['name']),
            'active' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Organisation créée avec succès.',
            'data' => $organization,
        ], 201);
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organisation';
        $slug = $base;
        $suffix = 2;

        while (Organization::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
