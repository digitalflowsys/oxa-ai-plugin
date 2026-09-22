<?php
/**
 * `media_list` — paginated listing of media library attachments.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Mcp\Tools;

use OxaAi\Mcp\AbstractTool;
use OxaAi\Site\MediaService;

if (!defined('ABSPATH')) {
    exit;
}

final class MediaListTool extends AbstractTool
{
    public function __construct(private readonly MediaService $media) {}

    public function name(): string        { return 'media_list'; }
    public function description(): string { return 'List media library attachments with pagination, optional search term and mime-type filter.'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page'     => ['type' => 'integer', 'minimum' => 1, 'description' => 'Defaults to 1.'],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Defaults to 20.'],
                'search'   => ['type' => 'string', 'description' => 'Match against attachment title.'],
                'mime'     => ['type' => 'string', 'description' => 'Filter by mime, e.g. "image" or "image/png".'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $info = $this->media->list($arguments);
        return $this->text(sprintf('%d total; %d returned.', $info['total'], count($info['items'])), $info);
    }
}
