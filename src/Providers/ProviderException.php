<?php
/**
 * Thrown by providers for transport or response errors.
 *
 * Carries an optional HTTP status code so callers can translate to
 * a REST response.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Providers;

use RuntimeException;
use Throwable;

if (!defined('ABSPATH')) {
    exit;
}

final class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
