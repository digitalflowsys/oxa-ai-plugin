<?php
/**
 * Wrap WP media library reads/writes.
 *
 * Backs media_upload / media_list / media_delete.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Site;

use OxaAi\Core\Logger;
use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class MediaService
{
    /** Hard cap on a single uploaded payload, regardless of php.ini. */
    private const MAX_UPLOAD_BYTES = 32 * 1024 * 1024; // 32 MiB

    public function __construct(private readonly Logger $logger) {}

    /**
     * Accept a file as either an HTTP URL or an inline base64 payload.
     *
     * @return array{
     *   id:int,
     *   url:string,
     *   file:string,
     *   mime:string,
     *   bytes:int,
     *   width:?int,
     *   height:?int,
     *   alt:string,
     *   filename:string
     * }
     */
    public function upload(array $args): array
    {
        $filename = (string) ($args['filename'] ?? '');
        if ($filename === '') {
            throw new RuntimeException('filename is required.');
        }
        $filename = sanitize_file_name($filename);

        if (!empty($args['url']) && is_string($args['url'])) {
            $bytes = $this->fetchUrl($args['url']);
        } elseif (!empty($args['content_base64']) && is_string($args['content_base64'])) {
            $bytes = base64_decode($args['content_base64'], true);
            if (!is_string($bytes)) {
                throw new RuntimeException('content_base64 is not valid base64.');
            }
        } else {
            throw new RuntimeException('Provide either "url" or "content_base64".');
        }

        if (strlen($bytes) === 0) {
            throw new RuntimeException('Empty upload payload.');
        }
        if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw new RuntimeException(sprintf('Upload exceeds %d bytes.', self::MAX_UPLOAD_BYTES));
        }

        // Persist to wp uploads via wp_handle_sideload.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmpFile = wp_tempnam($filename);
        if (file_put_contents($tmpFile, $bytes) === false) {
            throw new RuntimeException('Could not buffer upload to disk.');
        }

        $fileArray = [
            'name'     => $filename,
            'tmp_name' => $tmpFile,
            'error'    => 0,
            'size'     => strlen($bytes),
        ];

        $overrides = ['test_form' => false, 'test_size' => true];
        $sideload  = wp_handle_sideload($fileArray, $overrides);
        if (isset($sideload['error'])) {
            @unlink($tmpFile);
            throw new RuntimeException('Upload rejected: ' . $sideload['error']);
        }

        $attachment = [
            'post_mime_type' => $sideload['type'],
            'post_title'     => (string) ($args['title'] ?? pathinfo($filename, PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attachId = wp_insert_attachment($attachment, $sideload['file']);
        if (is_wp_error($attachId)) {
            throw new RuntimeException('wp_insert_attachment failed: ' . $attachId->get_error_message());
        }
        $meta = wp_generate_attachment_metadata($attachId, $sideload['file']);
        wp_update_attachment_metadata($attachId, $meta);

        if (!empty($args['alt']) && is_string($args['alt'])) {
            update_post_meta($attachId, '_wp_attachment_image_alt', sanitize_text_field($args['alt']));
        }

        $this->logger->info('media uploaded', ['id' => $attachId, 'file' => $sideload['file'], 'bytes' => strlen($bytes)]);
        return $this->describe((int) $attachId);
    }

    /**
     * @return array{total:int,items:array<int,array<string,mixed>>}
     */
    public function list(array $args): array
    {
        $perPage = isset($args['per_page']) ? max(1, min(100, (int) $args['per_page'])) : 20;
        $page    = isset($args['page']) ? max(1, (int) $args['page']) : 1;

        $q = new \WP_Query([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => $perPage,
            'paged'          => $page,
            's'              => (string) ($args['search'] ?? ''),
            'post_mime_type' => isset($args['mime']) && is_string($args['mime']) && $args['mime'] !== '' ? $args['mime'] : '',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);

        $items = [];
        foreach ($q->posts as $post) {
            $items[] = $this->describe((int) $post->ID);
        }
        return ['total' => (int) $q->found_posts, 'items' => $items];
    }

    public function delete(int $id, bool $force = true): array
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment') {
            throw new RuntimeException(sprintf('Attachment %d not found.', $id));
        }
        $result = wp_delete_attachment($id, $force);
        if (!$result) {
            throw new RuntimeException(sprintf('Could not delete attachment %d.', $id));
        }
        return ['id' => $id, 'deleted' => true];
    }

    /** @return array<string,mixed> */
    public function describe(int $id): array
    {
        $post = get_post($id);
        if (!$post) {
            return ['id' => $id, 'missing' => true];
        }
        $file = get_attached_file($id);
        $meta = wp_get_attachment_metadata($id);
        return [
            'id'       => $id,
            'url'      => (string) wp_get_attachment_url($id),
            'file'     => is_string($file) ? $file : '',
            'mime'     => (string) get_post_mime_type($id),
            'bytes'    => is_string($file) && is_file($file) ? (int) filesize($file) : 0,
            'width'    => is_array($meta) && isset($meta['width'])  ? (int) $meta['width']  : null,
            'height'   => is_array($meta) && isset($meta['height']) ? (int) $meta['height'] : null,
            'alt'      => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'filename' => is_string($file) ? basename($file) : '',
            'title'    => $post->post_title,
        ];
    }

    private function fetchUrl(string $url): string
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Invalid URL.');
        }
        $resp = wp_remote_get($url, ['timeout' => 30]);
        if (is_wp_error($resp)) {
            throw new RuntimeException('Fetch failed: ' . $resp->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) {
            throw new RuntimeException(sprintf('Fetch returned HTTP %d.', $code));
        }
        $body = wp_remote_retrieve_body($resp);
        return is_string($body) ? $body : '';
    }
}
