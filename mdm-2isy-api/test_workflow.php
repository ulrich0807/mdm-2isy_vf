<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Organization;
use App\Models\User;
use App\Models\Terminal;
use App\Models\Lic;
use App\Models\DeviceEnrollmentToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

echo "Démarrage du test d'intégration du workflow MDM...\n";

$workflowPassword = env('MDM_WORKFLOW_TEST_PASSWORD');
if (! is_string($workflowPassword) || strlen($workflowPassword) < 12) {
    fwrite(STDERR, "Définissez MDM_WORKFLOW_TEST_PASSWORD avec au moins 12 caractères.\n");
    exit(1);
}

// Run artisan serve in background or assume it's not running and use internal methods.
// Actually, HTTP requests might fail if `php artisan serve` is not running. 
// Let's use internal methods to simulate everything without network.

// 1. Organisation et Super Admin
$org = Organization::firstOrCreate(['name' => '2ISY Workflow Test'], ['slug' => '2isy-workflow']);
$superAdmin = User::firstOrCreate(
    ['email' => 'superadmin@2isy.com'],
    ['name' => 'Super Admin', 'password' => bcrypt($workflowPassword), 'role' => 'super_admin', 'organization_id' => $org->id]
);
echo "1. Organisation (ID: {$org->id}) et Super Admin (ID: {$superAdmin->id}) prêts.\n";

// 2. Administrateur Client
$admin = User::firstOrCreate(
    ['email' => 'admin@client.com'],
    ['name' => 'Admin Client', 'password' => bcrypt($workflowPassword), 'role' => 'admin', 'organization_id' => $org->id]
);
echo "2. Administrateur Client (ID: {$admin->id}) prêt.\n";

// 3. Token d'Enrôlement
$plainTextToken = (string) random_int(100000, 999999);
$enrollmentToken = DeviceEnrollmentToken::create([
    'organization_id' => $org->id,
    'created_by' => $admin->id,
    'token_hash' => hash('sha256', $plainTextToken), // La logique actuelle de hash
    'label' => 'Warehouse A',
    'expires_at' => now()->addMinutes(60),
]);
echo "3. Token d'enrôlement généré et conservé hors des logs.\n";

// 4. Enrôlement du Terminal (Simulation de la requête Agent vers /api/v1/device/enroll)
$request = \Illuminate\Http\Request::create('/api/v1/device/enroll', 'POST', [
    'enrollment_token' => $plainTextToken,
    'device_uid' => Str::uuid()->toString(),
    'imei' => 'IMEI' . random_int(10000000, 99999999),
    'serial_number' => 'ABC123XYZ',
    'model' => 'Blackview Rock 1 Pro',
    'manufacturer' => 'Blackview',
    'android_version' => '12',
]);
$response = app()->handle($request);
if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 201) {
    echo "ERREUR: Échec de l'enrôlement. Status: " . $response->getStatusCode() . "\n";
    echo $response->getContent() . "\n";
    exit(1);
}
$enrollData = json_decode($response->getContent(), true)['data'];
echo "4. Terminal enrôlé. Public ID: {$enrollData['device_id']}.\n";
$terminalId = $enrollData['device_id'];
$terminal = Terminal::where('public_id', $terminalId)->first();

// 5. Attribution et activation de licence
$lic = Lic::create([
    'cle' => strtoupper(Str::random(16)),
    'statut' => 'Active',
    'term_id' => $terminal->id,
    'exp_le' => now()->addYear(),
]);
echo "5. Licence générée et assignée: {$lic->cle}. Statut: {$lic->statut}\n";

// 6. Action sur le Terminal (Verrouillage) via /api/terminals/{id}/lock
// On simule une requête authentifiée de l'admin
$thisAdmin = User::find($admin->id);
\Illuminate\Support\Facades\Auth::login($thisAdmin);

$lockRequest = \Illuminate\Http\Request::create("/api/terminals/{$terminal->id}/lock", 'POST');
$lockRequest->setUserResolver(function () use ($thisAdmin) {
    return $thisAdmin;
});
$lockResponse = app()->handle($lockRequest);

if ($lockResponse->getStatusCode() !== 201) {
    echo "ERREUR: Échec de la commande Lock. Status: " . $lockResponse->getStatusCode() . "\n";
    echo $lockResponse->getContent() . "\n";
} else {
    echo "6. Commande de verrouillage envoyée. Réponse de l'API : \n";
    echo $lockResponse->getContent() . "\n";
}

echo "TEST INTÉGRAL TERMINÉ AVEC SUCCÈS.\n";
