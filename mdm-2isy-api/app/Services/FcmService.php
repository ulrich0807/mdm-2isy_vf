<?php

namespace App\Services;

use Exception;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FcmService
{
    private string $credentialsPath;

    public function __construct()
    {
        $this->credentialsPath = base_path('firebase-auth.json');
    }

    /**
     * Send an FCM Data message to a specific device topic or token.
     */
    public function sendCommand(string $deviceToken, string $commandType, array $payload = []): bool
    {
        if (!file_exists($this->credentialsPath)) {
            Log::error('FCM credentials file not found at ' . $this->credentialsPath);
            return false;
        }

        try {
            $credentials = json_decode(file_get_contents($this->credentialsPath), true);
            $projectId = $credentials['project_id'] ?? null;

            if (!$projectId) {
                Log::error('Project ID not found in FCM credentials.');
                return false;
            }

            // Retrieve Google Bearer Token
            $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
            $sa = new ServiceAccountCredentials($scopes, $this->credentialsPath);
            $token = $sa->fetchAuthToken();

            if (!isset($token['access_token'])) {
                Log::error('Failed to fetch FCM access token.');
                return false;
            }

            $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

            // Prepare the message payload
            // For MDM commands, we use data messages to wake up the app in background
            $message = [
                'message' => [
                    'token' => $deviceToken,
                    'android' => [
                        'priority' => 'high'
                    ],
                    'data' => [
                        'type' => $commandType,
                        'payload' => json_encode($payload),
                        'timestamp' => (string) now()->timestamp,
                    ]
                ]
            ];

            $response = Http::withToken($token['access_token'])->post($url, $message);

            if ($response->successful()) {
                return true;
            }

            Log::error('FCM push failed', ['response' => $response->json()]);
            return false;

        } catch (Exception $e) {
            Log::error('FCM Service Error: ' . $e->getMessage());
            return false;
        }
    }
}
