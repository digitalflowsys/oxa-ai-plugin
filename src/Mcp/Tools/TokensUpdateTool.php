<?php
/**
 * `tokens_update` — overwrite the design-token CSS file.
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

final class TokensUpdateTool extends AbstractTool
{
    public function __construct(private readonly Tokens $tokens) {}

    public function name(): string        { return 'tokens_update'; }
    public function description(): string { return 'Overwrite the design-token CSS file (tokens.css). Body must be CSS — </style and <script tokens are rejected.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'css'   => ['type' => 'string', 'description' => 'Full CSS body. Use :root { --foo: ...; } for custom properties.'],
                'scope' => ['type' => 'string', 'enum' => [ThemePaths::SCOPE_TEMPLATE, ThemePaths::SCOPE_STYLESHEET]],
            ],
            'required' => ['css'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $css   = (string) ($arguments['css'] ?? '');
        $scope = (string) ($arguments['scope'] ?? ThemePaths::SCOPE_TEMPLATE);
        $info  = $this->tokens->update($css, $scope);
        return $this->text(sprintf('%s tokens.css (%d bytes).', $info['created'] ? 'Created' : 'Updated', $info['bytes']), $info);
    }
}
