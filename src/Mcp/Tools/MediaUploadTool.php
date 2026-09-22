<?php
/**
 * `media_upload` — add a file to the WP media library.
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

final class MediaUploadTool extends AbstractTool
{
    public function __construct(private readonly MediaService $media) {}

    public function name(): string        { return 'media_upload'; }
    public function description(): string { return 'Upload a file to the WordPress media library. Supply either a publicly fetchable URL or inline base64 content. Returns the attachment id and resolved URL — use the id to reference the asset from globals_update (e.g. logo.attachment_id).'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filename'       => ['type' => 'string', 'description' => 'Destination filename (sanitized).'],
                'url'            => ['type' => 'string', 'format' => 'uri', 'description' => 'Source URL to fetch.'],
                'content_base64' => ['type' => 'string', 'description' => 'Alternative to url: base64-encoded payload.'],
                'title'          => ['type' => 'string', 'description' => 'Attachment title.'],
                'alt'            => ['type' => 'string', 'description' => 'Alt text for accessibility.'],
            ],
            'required' => ['filename'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $info = $this->media->upload($arguments);
        return $this->text(sprintf('Uploaded #%d → %s (%d bytes).', $info['id'], $info['url'], $info['bytes']), $info);
    }
}
