<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetBusinessData extends Command
{
    protected $signature = 'mdm:reset-business-data
        {--force : Autorise la remise à zéro}
        {--confirmation= : Phrase obligatoire RESET-MDM-DATA}';

    protected $description = 'Supprime les données métier en conservant uniquement les super administrateurs';

    public function handle(): int
    {
        if (! $this->option('force') || $this->option('confirmation') !== 'RESET-MDM-DATA') {
            $this->error('Opération refusée. Utilisez --force --confirmation=RESET-MDM-DATA.');

            return self::FAILURE;
        }

        $superAdminIds = User::query()
            ->where('role', 'super_admin')
            ->pluck('id');

        if ($superAdminIds->isEmpty()) {
            $this->error('Opération annulée : aucun compte super_admin ne serait conservé.');

            return self::FAILURE;
        }

        $deleted = DB::transaction(function () use ($superAdminIds): array {
            User::query()
                ->whereIn('id', $superAdminIds)
                ->update(['organization_id' => null]);

            $tables = [
                'device_command_events',
                'alerts',
                'location_histories',
                'device_commands',
                'device_credentials',
                'licenses',
                'lics',
                'terminals',
                'device_enrollment_tokens',
                'apps',
                'logs',
                'device_groups',
                'profils',
                'contact_requests',
                'jobs',
                'job_batches',
                'failed_jobs',
            ];

            $counts = [];
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    $counts[$table] = DB::table($table)->delete();
                }
            }

            if (Schema::hasTable('personal_access_tokens')) {
                $counts['personal_access_tokens'] = DB::table('personal_access_tokens')
                    ->where('tokenable_type', User::class)
                    ->whereNotIn('tokenable_id', $superAdminIds)
                    ->delete();
            }

            $counts['users'] = User::query()
                ->where('role', '!=', 'super_admin')
                ->orWhereNull('role')
                ->delete();

            if (Schema::hasTable('password_reset_tokens')) {
                $counts['password_reset_tokens'] = DB::table('password_reset_tokens')->delete();
            }

            $counts['organizations'] = Schema::hasTable('organizations')
                ? DB::table('organizations')->delete()
                : 0;

            return $counts;
        });

        $this->info('Remise à zéro terminée.');
        $this->table(
            ['Ressource', 'Éléments supprimés'],
            collect($deleted)->map(fn (int $count, string $table) => [$table, $count])->values()->all(),
        );
        $this->info('Super administrateurs conservés : '.$superAdminIds->count());

        return self::SUCCESS;
    }
}
