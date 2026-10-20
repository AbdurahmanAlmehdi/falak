<?php

return [

    /*
    | Key hierarchy (docs/INSTALL.md, "Encryption keys"). Every secret Falak stores is sealed with a data key
    | (AES-256-GCM); data keys are wrapped by the key-encryption key (KEK) below. The KEK is not APP_KEY and
    | is never stored in the database or in .env.
    */
    'keys' => [
        // local | aws-kms | vault-transit
        'provider' => env('FALAK_KEK_PROVIDER', 'local'),

        'local' => [
            // 32 random bytes, mode 0400 or 0600. Relative paths are resolved from the application root.
            'path' => env('FALAK_KEK_PATH', '/opt/falak/secrets/kek'),
            // The KEK before the last rotation (only read for data keys it still wraps; may not exist).
            'previous_path' => env('FALAK_KEK_PREVIOUS_PATH', '/opt/falak/secrets/kek.previous'),
        ],

        'aws_kms' => [
            'key_id' => env('FALAK_KEK_AWS_KMS_KEY_ID', ''),
            'region' => env('FALAK_KEK_AWS_REGION', env('AWS_DEFAULT_REGION', '')),
            'access_key_id' => env('FALAK_KEK_AWS_ACCESS_KEY_ID', ''),
            'secret_access_key' => env('FALAK_KEK_AWS_SECRET_ACCESS_KEY', ''),
            'session_token' => env('FALAK_KEK_AWS_SESSION_TOKEN'),
            // Override for VPC endpoints or tests (default https://kms.<region>.amazonaws.com).
            'endpoint' => env('FALAK_KEK_AWS_KMS_ENDPOINT'),
        ],

        'vault' => [
            'address' => env('FALAK_KEK_VAULT_ADDR', ''),
            'token' => env('FALAK_KEK_VAULT_TOKEN', ''),
            'mount' => env('FALAK_KEK_VAULT_MOUNT', 'transit'),
            'key' => env('FALAK_KEK_VAULT_KEY', 'falak'),
            'namespace' => env('FALAK_KEK_VAULT_NAMESPACE'),
        ],

        // Seconds a process keeps using the data key it looked up as active (a rotation elsewhere is seen after
        // this); falak:keys:rotate-data waits this long between passes for those processes to catch up.
        'active_ttl' => (int) env('FALAK_KEYS_ACTIVE_TTL', 60),

        // Rows re-encrypted per batch by falak:keys:rotate-data.
        'rotate_batch' => (int) env('FALAK_KEYS_ROTATE_BATCH', 200),
    ],

    /*
    | Icon library the canvas icon picker searches and loads picked icons from (Cool Icons: /search.json, /i/<slug>.svg).
    | Empty: no picker, only the logos bundled with the panel.
    */
    'icons_url' => rtrim((string) env('FALAK_ICONS_URL', 'https://coolicon.falak.sh'), '/'),

];
