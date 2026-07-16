<?php

$profileFingerprintKey = env('PROFILE_FINGERPRINT_KEY');

return [
    'profile_fingerprint_key' => is_string($profileFingerprintKey) && trim($profileFingerprintKey) !== ''
        ? $profileFingerprintKey
        : env('APP_KEY'),
    'hsts_enabled' => (bool) env('HSTS_ENABLED', env('APP_ENV') === 'production'),
    'csp_report_only_enabled' => (bool) env('CSP_REPORT_ONLY_ENABLED', true),
    'csp_report_max_bytes' => (int) env('CSP_REPORT_MAX_BYTES', 32_768),
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')),
    ))),
];
