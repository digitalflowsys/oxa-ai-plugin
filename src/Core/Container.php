<?php
/**
 * Minimal DI container.
 *
 * Lazy singletons, no auto-wiring, no surprises. We use it instead of
 * globals so that providers, validators, and the renderer can be
 * swapped in tests.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Core;

use Closure;
use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class Container
{
    /** @var array<string,Closure> */
    private array $factories = [];
    /** @var array<string,object> */
    private array $instances = [];

    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new RuntimeException(sprintf('Service "%s" is not registered.', $id));
        }
        return $this->instances[$id] = ($this->factories[$id])($this);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || isset($this->instances[$id]);
    }
}
