<?php
/**
 * Anthropic Claude provider.
 *
 * Uses the Messages API. The prompt builder is responsible for asking
 * Claude to respond with strict JSON.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Providers;

if (!defined('ABSPATH')) {
    exit;
}

final class ClaudeProvider extends AbstractHttpProvider
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    public function slug(): string  { return 'claude'; }
    public function label(): string { return 'Anthropic Claude'; }

    public function defaultModel(): string
    {
        return 'claude-sonnet-4-6';
    }

    public function complete(array $messages, array $options = []): string
    {
        $apiKey = $this->settings->secret($this->slug());
        if ($apiKey === '') {
            throw new ProviderException('Claude API key is not configured.', 412);
        }

        $config = $this->settings->config();
        $model  = (string) ($options['model'] ?? '') ?: ($config['model'] ?: $this->defaultModel());

        $payload = [
            'model'      => $model,
            'max_tokens' => (int) ($options['max_tokens'] ?? $config['max_tokens']),
            'temperature'=> (float) ($options['temperature'] ?? $config['temperature']),
            'system'     => (string) ($messages['system'] ?? ''),
            'messages'   => [
                [
                    'role'    => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => (string) ($messages['user'] ?? '')],
                    ],
                ],
            ],
        ];

        $headers = [
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
        ];

        $json = $this->postJson(self::API_URL, $headers, $payload, 90);

        $text = $this->extractText($json);
        if ($text === '') {
            throw new ProviderException('Claude returned empty content.', 502);
        }
        return $text;
    }

    private function extractText(array $json): string
    {
        $content = $json['content'] ?? null;
        if (!is_array($content)) {
            return '';
        }
        $out = '';
        foreach ($content as $block) {
            if (!is_array($block)) continue;
            if (($block['type'] ?? '') === 'text' && isset($block['text']) && is_string($block['text'])) {
                $out .= $block['text'];
            }
        }
        return $out;
    }
}
