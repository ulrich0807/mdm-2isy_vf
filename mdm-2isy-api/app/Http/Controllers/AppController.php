<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Support\OrganizationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Validation\Rule;

class AppController extends Controller
{
    public function index(Request $request)
    {
        $organization = OrganizationAccess::resolve($request->user(), $request->query('organization_id'));

        return response()->json(App::query()
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->orderByDesc('id')
            ->get());
    }

    public function store(Request $request)
    {
        $organization = OrganizationAccess::resolve($request->user(), $request->input('organization_id'), true);
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:255'],
            'pkg' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['blanche', 'noire'])],
            'ver' => ['nullable', 'string', 'max:100'],
            'chemin_apk' => [
                'nullable',
                'file',
                'max:102400',
                'mimetypes:application/vnd.android.package-archive,application/octet-stream,application/zip',
            ],
        ]);
        unset($data['chemin_apk']);

        if ($request->hasFile('chemin_apk')) {
            $data['chemin_apk'] = $request->file('chemin_apk')->store('applications');
        }
        $app = App::create([...$data, 'organization_id' => $organization->id]);

        return response()->json(['success' => true, 'message' => 'Application ajoutée', 'data' => $app], 201);
    }

    public function destroy(Request $request, int $id)
    {
        $organization = OrganizationAccess::resolve($request->user());
        $app = App::query()
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->findOrFail($id);
        if ($app->chemin_apk) {
            Storage::disk('local')->delete($app->chemin_apk);
        }
        $app->delete();

        return response()->json(['success' => true, 'message' => 'Application supprimée']);
    }

    public function update(Request $request, int $id)
    {
        $organization = OrganizationAccess::resolve($request->user());
        $app = App::query()
            ->when($organization, fn ($query) => $query->whereBelongsTo($organization))
            ->findOrFail($id);
        $data = $request->validate([
            'nom' => ['sometimes', 'required', 'string', 'max:255'],
            'pkg' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'required', Rule::in(['blanche', 'noire'])],
            'ver' => ['nullable', 'string', 'max:100'],
            'chemin_apk' => [
                'nullable',
                'file',
                'max:102400',
                'mimetypes:application/vnd.android.package-archive,application/octet-stream,application/zip',
            ],
        ]);
        unset($data['chemin_apk']);

        if ($request->hasFile('chemin_apk')) {
            $newPath = $request->file('chemin_apk')->store('applications');
            if ($app->chemin_apk) {
                Storage::disk('local')->delete($app->chemin_apk);
            }
            $data['chemin_apk'] = $newPath;
        }

        $app->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Application mise à jour.',
            'data' => $app->fresh(),
        ]);
    }

    public function download(App $app): BinaryFileResponse
    {
        abort_unless($app->chemin_apk && Storage::disk('local')->exists($app->chemin_apk), 404);

        return response()->download(
            Storage::disk('local')->path($app->chemin_apk),
            $app->pkg.'.apk',
            ['Content-Type' => 'application/vnd.android.package-archive'],
        );
    }
}
