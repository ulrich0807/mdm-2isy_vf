<?php

namespace App\Http\Controllers;

use App\Models\DeviceEnrollmentToken;
use App\Models\DeviceGroup;
use App\Support\OrganizationAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EnrollmentTokenController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdministrator($user->role);

        $organization = OrganizationAccess::resolve(
            $user,
            $request->query('organization_id'),
        );

        $query = DeviceEnrollmentToken::query()->latest('id');

        if ($organization) {
            $query->where('organization_id', $organization->id);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()->map(
                fn (DeviceEnrollmentToken $token): array => $this->serializeToken($token),
            )->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdministrator($user->role);

        $data = $request->validate([
            'organization_id' => ['nullable', 'integer'],
            'device_group_id' => ['nullable', 'integer'],
            'label' => ['nullable', 'string', 'max:255'],
            'expires_in_minutes' => [
                'required_without:duration_minutes',
                'integer',
                'between:5,10080',
            ],
            'duration_minutes' => [
                'required_without:expires_in_minutes',
                'integer',
                'between:5,10080',
            ],
        ]);

        $organization = OrganizationAccess::resolve(
            $user,
            $data['organization_id'] ?? null,
            requiredForSuperAdmin: true,
        );

        if (isset($data['device_group_id'])) {
            $groupExists = DeviceGroup::query()
                ->whereKey($data['device_group_id'])
                ->where('organization_id', $organization->id)
                ->exists();

            if (! $groupExists) {
                throw ValidationException::withMessages([
                    'device_group_id' => "Le groupe sélectionné n'appartient pas à l'organisation.",
                ]);
            }
        }

        $plainTextToken = (string) random_int(100000, 999999);
        $expiresInMinutes = $data['expires_in_minutes'] ?? $data['duration_minutes'];

        $token = new DeviceEnrollmentToken;
        $token->forceFill([
            'public_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'device_group_id' => $data['device_group_id'] ?? null,
            'created_by' => $user->id,
            'token_hash' => hash('sha256', $plainTextToken),
            'label' => $data['label'] ?? null,
            'expires_at' => now()->addMinutes($expiresInMinutes),
        ])->save();

        return response()->json([
            'success' => true,
            'data' => [
                ...$this->serializeToken($token),
                'enrollment_token' => $plainTextToken,
                'enrollment_payload' => [
                    'version' => 1,
                    'api_url' => url('/api/v1/device'),
                    'token' => $plainTextToken,
                ],
                'device_owner_qr_payload' => [
                    'android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME' => 'com.mdm2isy.agent/com.mdm2isy.agent.admin.MdmDeviceAdminReceiver',
                    'android.app.extra.PROVISIONING_DEVICE_ADMIN_PACKAGE_DOWNLOAD_LOCATION' => url('/download/mdm-agent.apk'),
                    'android.app.extra.PROVISIONING_ADMIN_EXTRAS_BUNDLE' => [
                        'api_url' => url('/api/v1/device'),
                        'token' => $plainTextToken,
                    ],
                ]
            ],
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        $user = $request->user();
        $this->ensureAdministrator($user->role);

        $enrollmentToken = DeviceEnrollmentToken::query()
            ->where('public_id', $token)
            ->firstOrFail();

        OrganizationAccess::resolve(
            $user,
            $enrollmentToken->organization_id,
            requiredForSuperAdmin: true,
        );

        if ($enrollmentToken->revoked_at === null) {
            $enrollmentToken->forceFill(['revoked_at' => now()])->save();
        }

        return response()->json([
            'success' => true,
            'data' => null,
        ]);
    }

    private function ensureAdministrator(string $role): void
    {
        if (! in_array($role, ['admin', 'super_admin'], true)) {
            throw new AuthorizationException('Administrator access is required.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeToken(DeviceEnrollmentToken $token): array
    {
        return [
            'public_id' => $token->public_id,
            'organization_id' => $token->organization_id,
            'device_group_id' => $token->device_group_id,
            'created_by' => $token->created_by,
            'label' => $token->label,
            'expires_at' => $token->expires_at?->toIso8601String(),
            'used_at' => $token->used_at?->toIso8601String(),
            'revoked_at' => $token->revoked_at?->toIso8601String(),
            'created_at' => $token->created_at?->toIso8601String(),
        ];
    }
}
