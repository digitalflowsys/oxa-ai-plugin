<?php
/**
 * `media_delete` — remove an attachment from the media library.
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

final class MediaDeleteTool extends AbstractTool
{
    public function __construct(private readonly MediaService $media) {}

    public function name(): string        { return 'media_delete'; }
    public function description(): string { return 'Delete an attachment by id. By default the file is removed from disk (force=true).'; }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id'    => ['type' => 'integer', 'minimum' => 1],
                'force' => ['type' => 'boolean', 'description' => 'Bypass the trash and remove the file. Default true.'],
            ],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function run(array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);
        $force = array_key_exists('force', $arguments) ? (bool) $arguments['force'] : true;
        $info = $this->media->delete($id, $force);
        return $this->text(sprintf('Attachment %d deleted.', $info['id']), $info);
    }
}
