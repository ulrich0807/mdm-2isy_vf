<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class OrganizationAccess
{
    public static function resolve(
        User $user,
        mixed $requestedOrganizationId = null,
        bool $requiredForSuperAdmin = false,
    ): ?Organization {
        if ($user->role === 'super_admin') {
            if ($requestedOrganizationId === null || $requestedOrganizationId === '') {
                if ($requiredForSuperAdmin) {
                    throw ValidationException::withMessages([
                        'organization_id' => "L'organisation est obligatoire.",
                    ]);
                }

                return null;
            }

            return self::findActive($requestedOrganizationId);
        }

        if (! $user->organization_id) {
            throw new AuthorizationException(
                "Ce compte n'est rattaché à aucune organisation.",
            );
        }

        if (
            $requestedOrganizationId !== null
            && $requestedOrganizationId !== ''
            && (int) $requestedOrganizationId !== (int) $user->organization_id
        ) {
            throw new AuthorizationException(
                "Vous ne pouvez pas accéder aux données d'une autre organisation.",
            );
        }

        $organization = $user->organization;

        if (! $organization || ! $organization->active) {
            throw new AuthorizationException("L'organisation de ce compte est inactive.");
        }

        return $organization;
    }

    private static function findActive(mixed $organizationId): Organization
    {
        $organization = Organization::query()
            ->whereKey((int) $organizationId)
            ->where('active', true)
            ->first();

        if (! $organization) {
            throw ValidationException::withMessages([
                'organization_id' => "L'organisation sélectionnée est introuvable ou inactive.",
            ]);
        }

        return $organization;
    }
}
