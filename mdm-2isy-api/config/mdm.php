<?php

return [
    // Laravel exprime la limite de la règle "max" des fichiers en kibioctets.
    'apk_upload_max_kilobytes' => (int) env('MDM_APK_UPLOAD_MAX_KILOBYTES', 256000),

    // Une mise à jour d'agent doit rester disponible pour les appareils qui
    // passent plusieurs jours hors ligne. L'URL APK est stable et le contenu
    // est verrouillé par le SHA-256 du manifeste publié.
    'agent_update_queue_days' => (int) env('MDM_AGENT_UPDATE_QUEUE_DAYS', 7),

    'bootstrap_admin' => [
        'name' => env('MDM_BOOTSTRAP_ADMIN_NAME'),
        'email' => env('MDM_BOOTSTRAP_ADMIN_EMAIL'),
        'password' => env('MDM_BOOTSTRAP_ADMIN_PASSWORD'),
    ],
];
