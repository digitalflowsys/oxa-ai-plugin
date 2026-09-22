<?php
/**
 * `globals_get` — read the site-wide globals document.
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

final class GlobalsGetTool extends AbstractTool
{
    public function __construct(private readonly Globals $globals) {}

    public function name(): string        { return 'globals_get'; }
    public function description(): string { return 'Read the site-wide globals document (header, footer, announcement_bar, contact, social, navigation, cta, logo, favicon, meta, misc). Pass section to return just one branch.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'section' => [
                    'type' => 'string',
                    'description' => 'Optional top-level section name. Omit for the full document.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $section = isset($arguments['section']) && is_string($arguments['section']) ? $arguments['section'] : null;
        $data = $this->globals->read($section);
        $label = $section === null || $section === '' ? 'site globals' : sprintf('globals.%s', $section);
        return $this->text(sprintf('Read %s.', $label), $data);
    }
}
