<?php
/**
 * NSC Offline / LAN mode.
 *
 * The product was written for an HTTPS host with a public domain and email MFA.
 * A workshop laptop reached over a LAN — http://192.168.x.x/ or http://nikbox.local/
 * — cannot satisfy those assumptions 1:1:
 *
 *   - Cookies marked "Secure" are dropped by the browser over plain http://,
 *     so nobody could ever log in. (__Host- prefixed cookies require Secure too.)
 *   - Email verification codes need an SMTP/Exim sender that a laptop does not have.
 *   - The installer refuses to create the administrator outside a secure context.
 *
 * This file makes the same code base work in that setting WITHOUT weakening the
 * public host: the local mode must be switched on explicitly on the machine
 * (api/.secrets/offline.php) and is additionally limited to LAN/loopback traffic.
 * A request that arrives from a public internet address never gets local mode,
 * even if the file exists.
 *
 * Activation: copy api/.secrets/offline.example.php to api/.secrets/offline.php
 */

function nscOfflineConfig(): array {
    static $config = null;
    if ($config !== null) return $config;
    $config = ['enabled' => false, 'trustedHosts' => [], 'allowOtherOrigins' => false, 'emailFallback' => 'auto'];
    $path = __DIR__ . '/.secrets/offline.php';
    if (!is_file($path)) return $config;
    $stored = require $path;
    if (!is_array($stored)) return $config;
    $config['enabled'] = (bool)($stored['enabled'] ?? true);
    $config['trustedHosts'] = array_values(array_filter(array_map(
        static fn($h) => strtolower(trim((string)$h)),
        (array)($stored['trustedHosts'] ?? [])
    )));
    $config['allowOtherOrigins'] = (bool)($stored['allowOtherOrigins'] ?? false);
    $config['emailFallback'] = (string)($stored['emailFallback'] ?? 'auto');
    return $config;
}

/** True when the guest address is loopback or one of the private LAN ranges. */
function nscIsLanAddress(?string $ip): bool {
    $ip = trim((string)$ip);
    if ($ip === '' || $ip === 'unknown') return false;
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) return false;
    if ($ip === '::1') return true;
    // 10/8, 172.16/12, 192.168/16, 169.254/16, 127/8 plus IPv6 ULA/link-local.
    if (preg_match('/^(?:10\.|127\.|192\.168\.|169\.254\.|172\.(?:1[6-9]|2[0-9]|3[01])\.)/', $ip)) return true;
    if (preg_match('/^(?:f[cd][0-9a-f]{2}:|fe80:)/i', $ip)) return true;
    return false;
}

/**
 * Local mode is active only when explicitly configured AND the visitor is on the
 * LAN. Public visitors to the same host never receive it.
 */
function nscOfflineMode(): bool {
    static $active = null;
    if ($active !== null) return $active;
    $config = nscOfflineConfig();
    if (!$config['enabled']) return $active = false;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    if (PHP_SAPI === 'cli') return $active = true; // installer/CLI tooling on the laptop itself
    return $active = nscIsLanAddress($ip);
}

/** The public origin this request arrived on, e.g. "http://nikbox.local:8080". */
function nscRequestOrigin(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '') $host = 'localhost';
    // Accept host names from the offline allowlist, LAN IPs and localhost only.
    $bareHost = preg_replace('/:\d+$/', '', $host);
    $allowed = array_merge(['localhost', '127.0.0.1', '[::1]', '::1'], nscOfflineConfig()['trustedHosts']);
    $isAllowed = in_array($bareHost, $allowed, true) || nscIsLanAddress($bareHost);
    if ($host === '*' || !$isAllowed) return '';
    return $scheme . '://' . $host;
}

/**
 * Cookies: on a public HTTPS host nothing changes. Locally the Secure flag is
 * removed because http:// cookies marked Secure are discarded by the browser —
 * that is the single reason the LAN login could never work.
 * The NAME keeps its __Host- prefix on purpose: the existing client code looks
 * for those names, and we only relax the transport requirement.
 */
function nscCookieSecure(): bool {
    if (nscOfflineMode()) return false;
    return !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
}

/** Email MFA: on a laptop without mail transport it is skipped when configured as fallback. */
function nscOfflineSkipsEmail(): bool {
    if (!nscOfflineMode()) return false;
    $config = nscOfflineConfig();
    if ($config['emailFallback'] === 'never') return false;
    // 'auto' and 'always': skip only inside local mode.
    return true;
}

/** Print one line about the current transport, used by the health/install pages. */
function nscOfflineStatus(): array {
    return [
        'offlineMode' => nscOfflineMode(),
        'configured' => (bool)nscOfflineConfig()['enabled'],
        'origin' => nscRequestOrigin(),
        'cookieSecure' => nscCookieSecure(),
        'emailMfaSkipped' => nscOfflineSkipsEmail(),
        'isLanAddress' => nscIsLanAddress($_SERVER['REMOTE_ADDR'] ?? null),
    ];
}
