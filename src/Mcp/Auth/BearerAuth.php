<?php
/**
 * Bearer-token authentication for the MCP endpoint.
 *
 * The token is generated once in the admin UI, shown to the user
 * a single time, and stored as a SHA-256 hash. Auth compares hashes
 * with `hash_equals` for timing safety.
 *
 * Token format:  `oxa_` + 43 chars of url-safe base64 (= 32 bytes entropy)
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Auth;

if (!defined('ABSPATH')) {
    exit;
}

final class BearerAuth
{
    public const OPTION = 'oxa_ai_mcp_token';
    public const PREFIX = 'oxa_';

    /**
     * Generate a fresh token, persist its hash, and return the plain
     * value (which the caller must show to the user once).
     */
    public function rotate(): string
    {
        $token = self::PREFIX . self::base64UrlEncode(random_bytes(32));
        update_option(self::OPTION, [
            'hash'       => hash('sha256', $token),
            'fingerprint'=> substr($token, 0, 12) . '…' . substr($token, -4),
            'created_at' => time(),
        ], false);
        return $token;
    }

    public function revoke(): void
    {
        delete_option(self::OPTION);
    }

    /**
     * @return array{fingerprint:string,created_at:int}|null
     */
    public function info(): ?array
    {
        $stored = get_option(self::OPTION);
        if (!is_array($stored) || empty($stored['hash'])) {
            return null;
        }
        return [
            'fingerprint' => (string) ($stored['fingerprint'] ?? ''),
            'created_at'  => (int) ($stored['created_at'] ?? 0),
        ];
    }

    public function hasToken(): bool
    {
        return $this->info() !== null;
    }

    /**
     * Validate a candidate token taken from any of the supported
     * locations, in this order of preference:
     *
     *   1. `Authorization: Bearer <token>`  (recommended)
     *   2. `X-Oxa-Token: <token>`           (fallback for clients
     *                                         that block `Authorization`)
     *   3. `?token=<token>` query parameter (last resort — leaks into
     *                                         logs; use only when the
     *                                         client cannot set headers)
     */
    public function validate(?string $authHeader, ?string $altHeader = null, ?string $queryToken = null): bool
    {
        $stored = get_option(self::OPTION);
        if (!is_array($stored) || empty($stored['hash'])) {
            return false;
        }

        $candidate = $this->extractToken($authHeader, $altHeader, $queryToken);
        if ($candidate === null) {
            return false;
        }

        return hash_equals((string) $stored['hash'], hash('sha256', $candidate));
    }

    private function extractToken(?string $authHeader, ?string $altHeader, ?string $queryToken): ?string
    {
        if (is_string($authHeader) && $authHeader !== '') {
            if (preg_match('/^Bearer\s+(.+)$/i', trim($authHeader), $m) === 1) {
                return trim($m[1]);
            }
        }
        if (is_string($altHeader) && $altHeader !== '') {
            return trim($altHeader);
        }
        if (is_string($queryToken) && $queryToken !== '') {
            return trim($queryToken);
        }
        return null;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
