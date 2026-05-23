<?php
/**
 * Shared HTTP plumbing for providers that talk to an HTTP API.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Providers;

use OxaAi\Core\Logger;
use OxaAi\Core\Settings;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

abstract class AbstractHttpProvider implements ProviderInterface
{
    public function __construct(
        protected readonly Settings $settings,
        protected readonly Logger $logger,
    ) {}

    public function isConfigured(): bool
    {
        return $this->settings->secret($this->slug()) !== '';
    }

    /**
     * @param array<string,string> $headers
     */
    protected function postJson(string $url, array $headers, array $body, int $timeout = 60): array
    {
        $response = wp_remote_post($url, [
            'headers' => array_merge([
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'User-Agent'   => 'OxaAi/' . OXA_AI_VERSION . '; WordPress',
            ], $headers),
            'body'    => wp_json_encode($body),
            'timeout' => $timeout,
        ]);

        if ($response instanceof WP_Error) {
            $this->logger->error('Provider transport error', [
                'provider' => $this->slug(),
                'error'    => $response->get_error_message(),
            ]);
            throw new ProviderException(
                sprintf('Transport error: %s', $response->get_error_message()),
                0
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $json = json_decode($raw, true);

        if ($code < 200 || $code >= 300) {
            $message = is_array($json) ? ($this->extractErrorMessage($json) ?? $raw) : $raw;
            $this->logger->error('Provider API error', [
                'provider' => $this->slug(),
                'status'   => $code,
                'message'  => $message,
            ]);
            throw new ProviderException(
                sprintf('Provider returned HTTP %d: %s', $code, $message),
                $code
            );
        }

        if (!is_array($json)) {
            throw new ProviderException('Provider returned non-JSON body.', $code);
        }

        return $json;
    }

    /**
     * Best-effort extraction of a human-readable error string from a
     * provider's JSON error body.
     */
    private function extractErrorMessage(array $json): ?string
    {
        if (isset($json['error']['message']) && is_string($json['error']['message'])) {
            return $json['error']['message'];
        }
        if (isset($json['error']) && is_string($json['error'])) {
            return $json['error'];
        }
        if (isset($json['message']) && is_string($json['message'])) {
            return $json['message'];
        }
        return null;
    }
}
