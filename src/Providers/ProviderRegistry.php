<?php
/**
 * Provider registry.
 *
 * Holds the set of installed providers and resolves the active one
 * based on user settings (or an explicit override).
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Providers;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class ProviderRegistry
{
    /** @var array<string,ProviderInterface> */
    private array $providers = [];

    public function add(ProviderInterface $provider): void
    {
        $this->providers[$provider->slug()] = $provider;
    }

    public function get(string $slug): ProviderInterface
    {
        if (!isset($this->providers[$slug])) {
            throw new RuntimeException(sprintf('Provider "%s" is not registered.', $slug));
        }
        return $this->providers[$slug];
    }

    public function has(string $slug): bool
    {
        return isset($this->providers[$slug]);
    }

    /** @return array<string,ProviderInterface> */
    public function all(): array
    {
        return $this->providers;
    }
}
