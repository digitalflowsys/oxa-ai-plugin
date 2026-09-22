<?php
/**
 * `globals_update` — patch or replace the site-wide globals document.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Site\Globals;

if (!defined('ABSPATH')) {
    exit;
}

final class GlobalsUpdateTool extends AbstractTool
{
    public function __construct(private readonly Globals $globals) {}

    public function name(): string        { return 'globals_update'; }
    public function description(): string { return 'Update the site-wide globals document. By default the patch is deep-merged into the existing document — pass replace=true to overwrite entirely. Top-level keys must be one of: header, footer, announcement_bar, contact, social, navigation, cta, logo, favicon, meta, misc.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'patch' => [
                    'type' => 'object',
                    'description' => 'Globals patch. Strings are sanitized; HTML in long-form strings is filtered via wp_kses_post.',
                ],
                'replace' => [
                    'type' => 'boolean',
                    'description' => 'If true, replace the entire document with the patch instead of merging.',
                ],
            ],
            'required' => ['patch'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $patch   = is_array($arguments['patch'] ?? null) ? $arguments['patch'] : [];
        $replace = (bool) ($arguments['replace'] ?? false);
        $next    = $this->globals->update($patch, $replace);
        return $this->text(
            $replace ? 'Globals replaced.' : 'Globals merged.',
            ['globals' => $next]
        );
    }
}
