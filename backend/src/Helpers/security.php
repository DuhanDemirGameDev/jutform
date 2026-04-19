<?php

/**
 * Best-effort SSRF guard for user-supplied webhook URLs.
 *
 * Blocks non-HTTP(S) schemes, embedded credentials, localhost, and hosts that
 * resolve to private or reserved IP ranges.
 */
function isLocalRequest(string $url): bool
{
    $parts = parse_url($url);
    if ($parts === false) {
        return true;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return true;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return true;
    }
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return true;
    }
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return isPrivateOrReservedIp($host);
    }
    $resolved = resolveHostIps($host);
    if ($resolved === []) {
        return true;
    }
    foreach ($resolved as $ip) {
        if (isPrivateOrReservedIp($ip)) {
            return true;
        }
    }
    return false;
}

function isPrivateOrReservedIp(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * @return list<string>
 */
function resolveHostIps(string $host): array
{
    $ips = [];
    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }
                foreach (['ip', 'ipv6'] as $key) {
                    if (!empty($record[$key]) && filter_var($record[$key], FILTER_VALIDATE_IP) !== false) {
                        $ips[] = strtolower((string) $record[$key]);
                    }
                }
            }
        }
    }
    if ($ips === []) {
        $fallback = gethostbyname($host);
        if ($fallback !== $host && filter_var($fallback, FILTER_VALIDATE_IP) !== false) {
            $ips[] = strtolower($fallback);
        }
    }
    return array_values(array_unique($ips));
}

/**
 * Legacy helper retained for compatibility with older code paths.
 * Prefer explicit authentication/authorization checks over source-trust logic.
 */
function isInternalRequest(): bool
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $ip = '';
    if ($xff !== '') {
        $parts = array_map('trim', explode(',', $xff));
        $ip = $parts[0] ?? '';
    }
    if ($ip === '') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    }
    $gateway = defaultGatewayIp();
    if ($gateway !== null && $gateway === $ip) {
        return false;
    }
    if ($ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }
    if (str_starts_with($ip, '172.') || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
        return true;
    }
    return false;
}

/**
 * Parses /proc/net/route and returns this container's default gateway IP
 * (the address external traffic arrives from once it's been routed into
 * our network). Returns null when the route table can't be read.
 */
function defaultGatewayIp(): ?string
{
    $content = @file_get_contents('/proc/net/route');
    if ($content === false) {
        return null;
    }
    $lines = explode("\n", $content);
    array_shift($lines);
    foreach ($lines as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (!is_array($parts) || count($parts) < 3) {
            continue;
        }
        if ($parts[1] !== '00000000') {
            continue;
        }
        $hex = $parts[2];
        if (strlen($hex) !== 8) {
            continue;
        }
        $bytes = [
            hexdec(substr($hex, 6, 2)),
            hexdec(substr($hex, 4, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 0, 2)),
        ];
        return implode('.', $bytes);
    }
    return null;
}
