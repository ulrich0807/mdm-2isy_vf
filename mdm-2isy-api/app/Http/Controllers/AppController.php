<?php

namespace App\Http\Controllers;

use App\Models\App;
use App\Support\OrganizationAccess;
use App\Services\AgentReleaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            'chemin_apk' => $this->apkUploadRules(),
        ], $this->apkUploadValidationMessages());
        $this->rejectReservedAgentPackage($data['pkg']);
        if ($request->hasFile('chemin_apk')) {
            /** @var UploadedFile $artifact */
            $artifact = $request->file('chemin_apk');
            $data['chemin_apk'] = $artifact->store('applications');
            $data['artifact_type'] = $this->artifactType($artifact);
        } else {
            unset($data['chemin_apk']);
            $data['artifact_type'] = 'apk';
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
            'chemin_apk' => $this->apkUploadRules(),
        ], $this->apkUploadValidationMessages());
        $this->rejectReservedAgentPackage($data['pkg'] ?? $app->pkg);
        if ($request->hasFile('chemin_apk')) {
            /** @var UploadedFile $artifact */
            $artifact = $request->file('chemin_apk');
            $newPath = $artifact->store('applications');
            if ($app->chemin_apk) {
                Storage::disk('local')->delete($app->chemin_apk);
            }
            $data['chemin_apk'] = $newPath;
            $data['artifact_type'] = $this->artifactType($artifact);
        } else {
            unset($data['chemin_apk']);
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
            $app->pkg.($app->artifact_type === 'apks' ? '.apks' : '.apk'),
            ['Content-Type' => $app->artifact_type === 'apks' ? 'application/zip' : 'application/vnd.android.package-archive'],
        );
    }

    /**
     * @return array<int, string>
     */
    private function apkUploadRules(): array
    {
        return [
            'nullable',
            'file',
            'max:'.config('mdm.apk_upload_max_kilobytes', 256000),
            'mimetypes:application/vnd.android.package-archive,application/octet-stream,application/zip',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function apkUploadValidationMessages(): array
    {
        $maxKilobytes = (int) config('mdm.apk_upload_max_kilobytes', 256000);
        $maxMebibytes = (int) floor($maxKilobytes / 1024);

        return [
            'chemin_apk.max' => "Le fichier APK ne doit pas dépasser {$maxMebibytes} Mio.",
            'chemin_apk.mimetypes' => 'Le fichier sélectionné doit être un APK ou un paquet .apks/.zip Android valide.',
        ];
    }

    private function artifactType(UploadedFile $artifact): string
    {
        $extension = strtolower((string) $artifact->getClientOriginalExtension());

        if (! in_array($extension, ['apk', 'apks', 'zip'], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'chemin_apk' => 'Utilisez un fichier .apk autonome ou un paquet .apks/.zip contenant les splits Android.',
            ]);
        }

        return $extension === 'apk' ? 'apk' : 'apks';
    }

    private function rejectReservedAgentPackage(string $packageName): void
    {
        if ($packageName === AgentReleaseService::PACKAGE_NAME) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'pkg' => "Le package de l'agent MDM est réservé. Utilisez l'action dédiée de mise à jour.",
            ]);
        }
    }
}
