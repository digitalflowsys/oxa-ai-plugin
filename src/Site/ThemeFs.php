<?php
/**
 * Filesystem operations scoped to the active theme.
 *
 * All theme_file_* and theme_dir_* tools delegate here so that path
 * validation, size limits, and binary handling live in one place.
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

final class ThemeFs
{
    /** Maximum bytes returned by theme_file_read in plain-text mode. */
    private const MAX_READ_BYTES = 2 * 1024 * 1024; // 2 MiB

    /** Maximum bytes accepted by theme_file_write. */
    private const MAX_WRITE_BYTES = 4 * 1024 * 1024; // 4 MiB

    public function __construct(private readonly Logger $logger) {}

    /**
     * @return array{
     *   path:string,
     *   abs_path:string,
     *   bytes:int,
     *   mtime:int,
     *   encoding:string,
     *   content:string
     * }
     */
    public function readFile(string $relative, string $scope, ?int $offset = null, ?int $length = null): array
    {
        $abs = ThemePaths::resolve($relative, $scope);
        ThemePaths::assertInside($abs, $scope);

        if (!is_file($abs)) {
            throw new RuntimeException(sprintf('File not found: %s', $relative));
        }
        $size = (int) filesize($abs);
        $start = $offset !== null && $offset > 0 ? $offset : 0;
        $want  = $length !== null && $length > 0
            ? min($length, self::MAX_READ_BYTES)
            : self::MAX_READ_BYTES;

        $fh = fopen($abs, 'rb');
        if ($fh === false) {
            throw new RuntimeException(sprintf('Could not open file: %s', $relative));
        }
        try {
            if ($start > 0 && fseek($fh, $start) !== 0) {
                throw new RuntimeException(sprintf('Could not seek to offset %d in %s.', $start, $relative));
            }
            $raw = stream_get_contents($fh, $want);
        } finally {
            fclose($fh);
        }
        if (!is_string($raw)) {
            throw new RuntimeException(sprintf('Could not read file: %s', $relative));
        }

        $isText = $this->looksLikeText($raw);
        return [
            'path'     => $relative,
            'abs_path' => $abs,
            'bytes'    => $size,
            'mtime'    => (int) filemtime($abs),
            'encoding' => $isText ? 'utf-8' : 'base64',
            'content'  => $isText ? $raw : base64_encode($raw),
        ];
    }

    /**
     * Write a file. `content` is interpreted per `$encoding` ("utf-8" or "base64").
     * Creates parent directories as needed. Atomic via temp-rename.
     *
     * @return array{path:string,abs_path:string,bytes:int,created:bool}
     */
    public function writeFile(string $relative, string $content, string $scope, string $encoding = 'utf-8'): array
    {
        $abs = ThemePaths::resolve($relative, $scope);
        $existed = is_file($abs);

        if ($encoding === 'base64') {
            $decoded = base64_decode($content, true);
            if (!is_string($decoded)) {
                throw new RuntimeException('Invalid base64 content.');
            }
            $bytes = $decoded;
        } elseif ($encoding === 'utf-8' || $encoding === '') {
            $bytes = $content;
        } else {
            throw new RuntimeException(sprintf('Unsupported encoding "%s". Use "utf-8" or "base64".', $encoding));
        }

        if (strlen($bytes) > self::MAX_WRITE_BYTES) {
            throw new RuntimeException(sprintf('File too large (>%d bytes). Split or upload via media tool.', self::MAX_WRITE_BYTES));
        }

        $dir = dirname($abs);
        if (!is_dir($dir)) {
            if (!wp_mkdir_p($dir)) {
                throw new RuntimeException(sprintf('Could not create parent directory: %s', dirname($relative)));
            }
        }
        ThemePaths::assertInside($abs, $scope);

        $tmp = $abs . '.oxa.tmp';
        $written = file_put_contents($tmp, $bytes);
        if ($written === false) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Could not write %s. Check filesystem permissions.', $relative));
        }
        if (!rename($tmp, $abs)) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Could not finalize %s.', $relative));
        }

        $this->logger->info('theme file written', ['path' => $relative, 'bytes' => $written, 'created' => !$existed]);
        return [
            'path'     => $relative,
            'abs_path' => $abs,
            'bytes'    => (int) $written,
            'created'  => !$existed,
        ];
    }

    public function deleteFile(string $relative, string $scope): array
    {
        $abs = ThemePaths::resolve($relative, $scope);
        ThemePaths::assertInside($abs, $scope);
        if (!is_file($abs)) {
            throw new RuntimeException(sprintf('File not found: %s', $relative));
        }
        if (!@unlink($abs)) {
            throw new RuntimeException(sprintf('Could not delete file: %s', $relative));
        }
        $this->logger->info('theme file deleted', ['path' => $relative]);
        return ['path' => $relative, 'deleted' => true];
    }

    /**
     * List entries directly inside a directory (no recursion by default).
     *
     * @return array{path:string,abs_path:string,entries:array<int,array{name:string,type:string,bytes:int,mtime:int,path:string}>}
     */
    public function listDir(string $relative, string $scope, bool $recursive = false): array
    {
        $rel = $relative === '' || $relative === '/' ? '' : ltrim($relative, '/');
        $abs = $rel === '' ? rtrim(ThemePaths::root($scope), '/') : ThemePaths::resolve($rel, $scope);
        ThemePaths::assertInside($abs, $scope);

        if (!is_dir($abs)) {
            throw new RuntimeException(sprintf('Directory not found: %s', $relative));
        }

        $entries = [];
        $this->collect($abs, $rel, $entries, $recursive);
        usort($entries, static function (array $a, array $b): int {
            if ($a['type'] === $b['type']) {
                return strcmp($a['name'], $b['name']);
            }
            return $a['type'] === 'dir' ? -1 : 1;
        });
        return [
            'path'     => $rel === '' ? '/' : $rel,
            'abs_path' => $abs,
            'entries'  => $entries,
        ];
    }

    public function createDir(string $relative, string $scope): array
    {
        $abs = ThemePaths::resolve($relative, $scope);
        if (is_dir($abs)) {
            return ['path' => $relative, 'abs_path' => $abs, 'created' => false, 'existed' => true];
        }
        if (!wp_mkdir_p($abs)) {
            throw new RuntimeException(sprintf('Could not create directory: %s', $relative));
        }
        ThemePaths::assertInside($abs, $scope);
        $this->logger->info('theme dir created', ['path' => $relative]);
        return ['path' => $relative, 'abs_path' => $abs, 'created' => true, 'existed' => false];
    }

    public function deleteDir(string $relative, string $scope, bool $recursive = false): array
    {
        $abs = ThemePaths::resolve($relative, $scope);
        ThemePaths::assertInside($abs, $scope);
        if (!is_dir($abs)) {
            throw new RuntimeException(sprintf('Directory not found: %s', $relative));
        }
        // Forbid deleting the theme root itself.
        $root = rtrim(realpath(ThemePaths::root($scope)) ?: '', DIRECTORY_SEPARATOR);
        if (realpath($abs) === $root) {
            throw new RuntimeException('Refusing to delete the theme root.');
        }
        $deleted = $this->rmrf($abs, $recursive);
        $this->logger->info('theme dir deleted', ['path' => $relative, 'recursive' => $recursive, 'count' => $deleted]);
        return ['path' => $relative, 'deleted' => true, 'entries_removed' => $deleted];
    }

    // -------- internals --------

    private function collect(string $absDir, string $relDir, array &$out, bool $recursive): void
    {
        $iter = @scandir($absDir);
        if ($iter === false) {
            return;
        }
        foreach ($iter as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $abs = $absDir . DIRECTORY_SEPARATOR . $name;
            $rel = $relDir === '' ? $name : $relDir . '/' . $name;
            $isDir = is_dir($abs);
            $out[] = [
                'name'  => $name,
                'type'  => $isDir ? 'dir' : 'file',
                'bytes' => $isDir ? 0 : (int) @filesize($abs),
                'mtime' => (int) @filemtime($abs),
                'path'  => $rel,
            ];
            if ($recursive && $isDir) {
                $this->collect($abs, $rel, $out, true);
            }
        }
    }

    private function rmrf(string $dir, bool $recursive): int
    {
        $count = 0;
        $entries = @scandir($dir) ?: [];
        $children = array_values(array_filter($entries, static fn(string $e): bool => $e !== '.' && $e !== '..'));

        if (!$recursive && !empty($children)) {
            throw new RuntimeException(sprintf('Directory not empty: %s. Pass recursive=true to delete contents.', $dir));
        }

        foreach ($children as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path)) {
                $count += $this->rmrf($path, true);
            } else {
                if (@unlink($path)) {
                    $count++;
                }
            }
        }
        if (!@rmdir($dir)) {
            throw new RuntimeException(sprintf('Could not remove directory: %s', $dir));
        }
        return $count;
    }

    private function looksLikeText(string $bytes): bool
    {
        if ($bytes === '') {
            return true;
        }
        if (str_contains($bytes, "\0")) {
            return false;
        }
        // mb_check_encoding is reliable for UTF-8 detection.
        return function_exists('mb_check_encoding') ? mb_check_encoding($bytes, 'UTF-8') : true;
    }
}
