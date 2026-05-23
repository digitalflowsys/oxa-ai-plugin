<?php
/**
 * Provider abstraction.
 *
 * Implementations talk to a specific LLM vendor (OpenAI, Anthropic, etc.)
 * and return raw provider responses. Higher-level concerns (prompt
 * construction, JSON parsing, validation) live above this layer.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Providers;

if (!defined('ABSPATH')) {
    exit;
}

interface ProviderInterface
{
    /**
     * Stable, lowercase identifier used in settings and the registry.
     */
    public function slug(): string;

    /**
     * Human-readable label for the admin UI.
     */
    public function label(): string;

    /**
     * Whether this provider has the credentials it needs to run.
     */
    public function isConfigured(): bool;

    /**
     * Default model identifier for this provider.
     */
    public function defaultModel(): string;

    /**
     * Send a system + user message pair and return the raw assistant text.
     *
     * Must throw \OxaAi\Providers\ProviderException on any failure.
     *
     * @param array{system:string,user:string} $messages
     * @param array{model?:string,max_tokens?:int,temperature?:float} $options
     */
    public function complete(array $messages, array $options = []): string;
}
