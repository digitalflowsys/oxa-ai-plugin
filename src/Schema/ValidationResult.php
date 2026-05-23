<?php
/**
 * Immutable result object for schema validation.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Schema;

if (!defined('ABSPATH')) {
    exit;
}

final class ValidationResult
{
    /**
     * @param array<int,string> $errors
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors = [],
        public readonly mixed $value = null,
    ) {}

    public static function ok(mixed $value): self
    {
        return new self(true, [], $value);
    }

    /**
     * @param array<int,string>|string $errors
     */
    public static function fail(array|string $errors): self
    {
        return new self(false, is_array($errors) ? $errors : [$errors], null);
    }
}
