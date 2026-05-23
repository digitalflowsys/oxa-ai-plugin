<?php
/**
 * OpenAI provider (Chat Completions API).
 *
 * Configured to request JSON output via response_format so the
 * downstream parser can trust the structure.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Providers;

if (!defined('ABSPATH')) {
    exit;
}

final class OpenAIProvider extends AbstractHttpProvider
{
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    public function slug(): string  { return 'openai'; }
    public function label(): string { return 'OpenAI'; }

    public function defaultModel(): string
    {
        return 'gpt-4o-mini';
    }

    public function complete(array $messages, array $options = []): string
    {
        $apiKey = $this->settings->secret($this->slug());
        if ($apiKey === '') {
            throw new ProviderException('OpenAI API key is not configured.', 412);
        }

        $config = $this->settings->config();
        $model  = (string) ($options['model'] ?? '') ?: ($config['model'] ?: $this->defaultModel());

        $payload = [
            'model'           => $model,
            'max_tokens'      => (int) ($options['max_tokens'] ?? $config['max_tokens']),
            'temperature'     => (float) ($options['temperature'] ?? $config['temperature']),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => (string) ($messages['system'] ?? '')],
                ['role' => 'user',   'content' => (string) ($messages['user']   ?? '')],
            ],
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
        ];

        $json = $this->postJson(self::API_URL, $headers, $payload, 90);

        $text = $json['choices'][0]['message']['content'] ?? '';
        if (!is_string($text) || $text === '') {
            throw new ProviderException('OpenAI returned empty content.', 502);
        }
        return $text;
    }
}
