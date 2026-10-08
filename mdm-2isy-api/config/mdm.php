<?php

return [
    // Laravel exprime la limite de la règle "max" des fichiers en kibioctets.
    'apk_upload_max_kilobytes' => (int) env('MDM_APK_UPLOAD_MAX_KILOBYTES', 256000),

    'bootstrap_admin' => [
        'name' => env('MDM_BOOTSTRAP_ADMIN_NAME'),
        'email' => env('MDM_BOOTSTRAP_ADMIN_EMAIL'),
        'password' => env('MDM_BOOTSTRAP_ADMIN_PASSWORD'),
    ],
];
