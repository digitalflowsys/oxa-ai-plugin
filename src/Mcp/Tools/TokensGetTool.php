<?php
/**
 * `tokens_get` — read the theme's design-token CSS file.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Site\ThemePaths;
use OxaAi\Site\Tokens;

if (!defined('ABSPATH')) {
    exit;
}

final class TokensGetTool extends AbstractTool
{
    public function __construct(private readonly Tokens $tokens) {}

    public function name(): string        { return 'tokens_get'; }
    public function description(): string { return 'Read the design-token CSS file (tokens.css) from the active theme. The theme is expected to enqueue this on every request.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'scope' => ['type' => 'string', 'enum' => [ThemePaths::SCOPE_TEMPLATE, ThemePaths::SCOPE_STYLESHEET]],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $scope = (string) ($arguments['scope'] ?? ThemePaths::SCOPE_TEMPLATE);
        $info  = $this->tokens->read($scope);
        $summary = $info['exists']
            ? sprintf('tokens.css — %d bytes.', $info['bytes'])
            : 'tokens.css does not exist yet (empty defaults will be returned).';
        return $this->text($summary, $info);
    }
}
