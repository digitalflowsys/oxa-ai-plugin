<?php
/**
 * Plugin-level settings persistence.
 *
 * API keys are stored in a separate option from non-secret config
 * so the admin UI can render config without ever exposing keys.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Core;

if (!defined('ABSPATH')) {
    exit;
}

final class Settings
{
    public const OPTION_CONFIG  = 'oxa_ai_config';
    public const OPTION_SECRETS = 'oxa_ai_secrets';

    /** @return array{provider:string,model:string,max_tokens:int,temperature:float} */
    public function config(): array
    {
        $stored = get_option(self::OPTION_CONFIG, []);
        if (!is_array($stored)) {
            $stored = [];
        }
        return [
            'provider'    => (string) ($stored['provider']    ?? 'mock'),
            'model'       => (string) ($stored['model']       ?? ''),
            'max_tokens'  => (int)    ($stored['max_tokens']  ?? 4096),
            'temperature' => (float)  ($stored['temperature'] ?? 0.4),
        ];
    }

    public function saveConfig(array $config): void
    {
        $clean = [
            'provider'    => sanitize_key($config['provider'] ?? 'mock'),
            'model'       => sanitize_text_field((string) ($config['model'] ?? '')),
            'max_tokens'  => max(256, min(16384, (int) ($config['max_tokens'] ?? 4096))),
            'temperature' => max(0.0, min(2.0, (float) ($config['temperature'] ?? 0.4))),
        ];
        update_option(self::OPTION_CONFIG, $clean, false);
    }

    public function secret(string $providerSlug): string
    {
        $store = get_option(self::OPTION_SECRETS, []);
        if (!is_array($store)) {
            return '';
        }
        $value = $store[$providerSlug] ?? '';
        return is_string($value) ? $value : '';
    }

    public function saveSecret(string $providerSlug, string $value): void
    {
        $store = get_option(self::OPTION_SECRETS, []);
        if (!is_array($store)) {
            $store = [];
        }
        $providerSlug = sanitize_key($providerSlug);
        if ($value === '') {
            unset($store[$providerSlug]);
        } else {
            $store[$providerSlug] = $value;
        }
        update_option(self::OPTION_SECRETS, $store, false);
    }
}
