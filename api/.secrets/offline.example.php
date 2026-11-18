<?php
/**
 * Offline / LAN profile for this machine (a laptop acting as the office server).
 *
 * Copy this file to api/.secrets/offline.php — the private copy is git-ignored and
 * is never served over HTTP (api/.secrets/.htaccess denies the whole folder).
 *
 * Keep this file OUT of the public host. On nik-smartcount.com the file must not
 * exist: without it every request keeps the strict HTTPS-cookie + email-code rules.
 *
 * Only requests that arrive from loopback/private LAN addresses get these relaxations,
 * so even if the laptop were exposed to the internet the public visitor gets nothing.
 */
return [
    // Master switch for this machine.
    'enabled' => true,

    // Extra names this laptop is reachable by. Phones on the same Wi-Fi should use
    // one of these (or the laptop's LAN IP), e.g. http://nikbox.local/
    'trustedHosts' => [
        'nikbox.local',
        'nik-smartcount.local',
        'localhost',
        '127.0.0.1',
    ],

    // Leave false. Only set true if an unusual LAN proxy rewrites the Origin header.
    'allowOtherOrigins' => false,

    // 'auto'  = skip the six-digit email code only when local mode is active (recommended)
    // 'never' = keep e-mail MFA even offline (you then need a working local mail sender)
    'emailFallback' => 'auto',
];
