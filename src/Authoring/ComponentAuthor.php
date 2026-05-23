<?php
/**
 * Read / write / lint Oxa components.
 *
 * Components live at `themes/oxa/components/<name>/` with four files:
 *   schema.json   — AI contract
 *   template.php  — server-side renderer
 *   styles.css    — scoped styles
 *   README.md     — human + AI docs
 *
 * This class is the only place in the system that writes those files.
 * It enforces:
 *   - name pattern (no path traversal)
 *   - destination scope (must be inside the components dir)
 *   - PHP syntax lint before write
 *   - schema structural validation
 *   - cache reset (both theme + plugin) so the change is live immediately
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Authoring;

use OxaAi\Core\Logger;
use OxaAi\Schema\SchemaRegistry;
use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class ComponentAuthor
{
    /** Match folder/component slugs. Starts with a letter; lowercase + digits + hyphens. */
    private const NAME_PATTERN = '/^[a-z][a-z0-9-]{0,40}$/';

    /** Field types the schema validator accepts. */
    private const ALLOWED_FIELD_TYPES = ['string', 'text', 'url', 'boolean', 'enum', 'array'];

    public function __construct(
        private readonly SchemaRegistry $schemas,
        private readonly Logger $logger,
    ) {}

    public function componentsDir(): string
    {
        return trailingslashit(get_template_directory()) . 'components/';
    }

    /**
     * Validate name pattern, return true if a component with that name
     * already exists on disk.
     */
    public function exists(string $name): bool
    {
        $this->assertValidName($name);
        return is_dir($this->componentDir($name));
    }

    /**
     * Read the four source files of a component.
     *
     * @return array{
     *   name:string,
     *   exists:bool,
     *   schema:?array,
     *   template_php:?string,
     *   styles_css:?string,
     *   readme_md:?string,
     *   files:array<string,string>
     * }
     */
    public function read(string $name): array
    {
        $this->assertValidName($name);
        $dir = $this->componentDir($name);

        $result = [
            'name'        => $name,
            'exists'      => is_dir($dir),
            'schema'      => null,
            'template_php'=> null,
            'styles_css'  => null,
            'readme_md'   => null,
            'files'       => [],
        ];

        $map = [
            'schema'       => 'schema.json',
            'template_php' => 'template.php',
            'styles_css'   => 'styles.css',
            'readme_md'    => 'README.md',
        ];
        foreach ($map as $field => $basename) {
            $path = $dir . $basename;
            if (!is_file($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }
            $result['files'][$basename] = $path;
            if ($field === 'schema') {
                $decoded = json_decode($contents, true);
                $result[$field] = is_array($decoded) ? $decoded : null;
            } else {
                $result[$field] = $contents;
            }
        }
        return $result;
    }

    /**
     * Create or update a component. The caller specifies an explicit
     * `$mode` so we never silently overwrite.
     *
     * @param array{
     *   schema?: array,
     *   template_php?: string,
     *   styles_css?: string,
     *   readme_md?: string
     * } $files
     *
     * @return array{name:string,dir:string,written:array<int,string>,bytes:int}
     */
    public function write(string $name, array $files, string $mode): array
    {
        $this->assertValidName($name);
        if (!in_array($mode, ['create', 'update'], true)) {
            throw new RuntimeException(sprintf('Invalid mode "%s".', $mode));
        }

        $dir       = $this->componentDir($name);
        $existed   = is_dir($dir);

        if ($mode === 'create' && $existed) {
            throw new RuntimeException(sprintf(
                'Component "%s" already exists. Use update_component to modify it.',
                $name
            ));
        }
        if ($mode === 'update' && !$existed) {
            throw new RuntimeException(sprintf(
                'Component "%s" does not exist. Use create_component to add it.',
                $name
            ));
        }

        // For create mode we require schema + template at minimum;
        // for update mode any subset is fine.
        if ($mode === 'create') {
            if (empty($files['schema']))       throw new RuntimeException('"schema" is required when creating a component.');
            if (empty($files['template_php'])) throw new RuntimeException('"template_php" is required when creating a component.');
        }

        // Validate / lint everything BEFORE touching disk.
        $payload = [];
        if (isset($files['schema'])) {
            $payload['schema.json']  = $this->prepareSchema($name, $files['schema']);
        }
        if (isset($files['template_php'])) {
            $payload['template.php'] = $this->prepareTemplate($name, (string) $files['template_php']);
        }
        if (isset($files['styles_css'])) {
            $payload['styles.css']   = $this->prepareStyles((string) $files['styles_css']);
        }
        if (isset($files['readme_md'])) {
            $payload['README.md']    = $this->prepareReadme((string) $files['readme_md']);
        }
        if (empty($payload)) {
            throw new RuntimeException('No files to write. Provide at least one of schema, template_php, styles_css, readme_md.');
        }

        // All-or-nothing: create the dir, write to *.tmp files, then rename.
        if (!$existed && !wp_mkdir_p($dir)) {
            throw new RuntimeException(sprintf('Could not create directory %s', $dir));
        }
        if (!$this->isInsideComponentsDir($dir)) {
            throw new RuntimeException('Refusing to write outside the components directory.');
        }

        $written = [];
        $totalBytes = 0;
        $tempPaths  = [];

        try {
            foreach ($payload as $basename => $contents) {
                $finalPath = $dir . $basename;
                $tmpPath   = $finalPath . '.oxa.tmp';
                $bytes     = file_put_contents($tmpPath, $contents);
                if ($bytes === false) {
                    throw new RuntimeException(sprintf(
                        'Failed to write %s. Check filesystem permissions on %s.',
                        $basename,
                        $dir
                    ));
                }
                $tempPaths[$tmpPath] = $finalPath;
                $totalBytes += (int) $bytes;
            }
            // Atomic-ish move: rename each temp into place.
            foreach ($tempPaths as $tmp => $final) {
                if (!rename($tmp, $final)) {
                    throw new RuntimeException(sprintf('Failed to finalize %s.', basename($final)));
                }
                $written[] = $final;
            }
        } catch (\Throwable $e) {
            // Roll back any temp files we managed to write.
            foreach ($tempPaths as $tmp => $_) {
                @unlink($tmp);
            }
            throw $e;
        }

        $this->resetCaches();

        $this->logger->info('Component ' . ($mode === 'create' ? 'created' : 'updated'), [
            'name'    => $name,
            'files'   => array_keys($payload),
            'bytes'   => $totalBytes,
        ]);

        return [
            'name'    => $name,
            'dir'     => $dir,
            'written' => $written,
            'bytes'   => $totalBytes,
        ];
    }

    /**
     * Delete a component's folder. Caller should check for pages that
     * still reference it; this method does not.
     */
    public function delete(string $name): bool
    {
        $this->assertValidName($name);
        $dir = $this->componentDir($name);
        if (!$this->isInsideComponentsDir($dir) || !is_dir($dir)) {
            return false;
        }

        $files = glob($dir . '*') ?: [];
        foreach ($files as $f) {
            if (is_file($f)) @unlink($f);
        }
        $ok = @rmdir($dir);

        $this->resetCaches();
        $this->logger->info('Component deleted', ['name' => $name, 'ok' => $ok]);
        return (bool) $ok;
    }

    /**
     * Invalidate the in-process caches in both the theme's loader and
     * the plugin's registry, so subsequent tool calls see the change.
     */
    public function resetCaches(): void
    {
        if (class_exists(\Oxa\Theme\ComponentLoader::class)) {
            \Oxa\Theme\ComponentLoader::reset();
        }
        $this->schemas->reset();
    }

    // -------- internals --------

    private function componentDir(string $name): string
    {
        return $this->componentsDir() . $name . '/';
    }

    private function assertValidName(string $name): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new RuntimeException(sprintf(
                'Invalid component name "%s". Must match %s.',
                $name,
                self::NAME_PATTERN
            ));
        }
    }

    private function isInsideComponentsDir(string $path): bool
    {
        $base = realpath($this->componentsDir());
        $real = realpath($path) ?: $path; // dir may not exist yet — fall back to literal
        if ($base === false) {
            return false;
        }
        // For not-yet-created dirs, compare the *parent* (which must exist).
        if (!is_dir($path)) {
            $real = realpath(dirname(rtrim($path, '/'))) ?: '';
        }
        return $real !== '' && str_starts_with($real . '/', rtrim($base, '/') . '/');
    }

    private function prepareSchema(string $name, mixed $schema): string
    {
        if (!is_array($schema)) {
            throw new RuntimeException('"schema" must be an object.');
        }
        // Force `name` to match the folder so the registry never gets a mismatch.
        $schema['name'] = $name;

        if (!isset($schema['title']) || !is_string($schema['title']) || $schema['title'] === '') {
            throw new RuntimeException('Schema is missing required field "title" (string).');
        }
        if (!isset($schema['fields']) || !is_array($schema['fields'])) {
            throw new RuntimeException('Schema is missing required field "fields" (object).');
        }
        $this->validateFieldDefs($schema['fields'], 'fields');

        $json = wp_json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Failed to encode schema as JSON.');
        }
        return $json . "\n";
    }

    private function validateFieldDefs(array $fields, string $path): void
    {
        foreach ($fields as $key => $def) {
            if (!is_string($key) || $key === '') {
                throw new RuntimeException(sprintf('%s contains an invalid field name.', $path));
            }
            if (!is_array($def)) {
                throw new RuntimeException(sprintf('%s.%s must be an object.', $path, $key));
            }
            $type = $def['type'] ?? null;
            if (!is_string($type) || !in_array($type, self::ALLOWED_FIELD_TYPES, true)) {
                throw new RuntimeException(sprintf(
                    '%s.%s.type must be one of: %s (got "%s").',
                    $path, $key, implode(', ', self::ALLOWED_FIELD_TYPES), (string) $type
                ));
            }
            if ($type === 'enum') {
                $options = $def['options'] ?? null;
                if (!is_array($options) || empty($options)) {
                    throw new RuntimeException(sprintf('%s.%s is enum but has no "options" array.', $path, $key));
                }
            }
            if ($type === 'array' && isset($def['item_schema']) && is_array($def['item_schema'])) {
                $this->validateFieldDefs($def['item_schema'], $path . '.' . $key . '.item_schema');
            }
        }
    }

    private function prepareTemplate(string $name, string $php): string
    {
        $php = $this->normalizeNewlines($php);
        if (!str_starts_with(ltrim($php), '<?php')) {
            throw new RuntimeException('template.php must begin with "<?php".');
        }
        $this->lintPhp($php, $name);
        $this->scanTemplateForDangerousCalls($php, $name);
        return $php;
    }

    private function prepareStyles(string $css): string
    {
        $css = $this->normalizeNewlines($css);
        // Reject `</style` and `<script` smuggling so the CSS file truly is a CSS file.
        if (stripos($css, '</style') !== false || stripos($css, '<script') !== false) {
            throw new RuntimeException('styles.css must not contain </style or <script tokens.');
        }
        return $css;
    }

    private function prepareReadme(string $md): string
    {
        return $this->normalizeNewlines($md);
    }

    private function normalizeNewlines(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        if ($s !== '' && substr($s, -1) !== "\n") {
            $s .= "\n";
        }
        return $s;
    }

    /**
     * Lint PHP via `php -l` in a sandboxed tmp file. Throws on syntax error.
     */
    private function lintPhp(string $php, string $name): void
    {
        if (!function_exists('shell_exec') || !defined('PHP_BINARY')) {
            // Fall back to tokenizer-only check.
            $this->lintPhpFallback($php);
            return;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'oxa-lint-');
        if ($tmp === false) {
            $this->lintPhpFallback($php);
            return;
        }
        try {
            file_put_contents($tmp, $php);
            $cmd = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1';
            $out = (string) shell_exec($cmd);
            if (!str_contains($out, 'No syntax errors')) {
                // Strip the temp path from the error so it's relatable to the user.
                $out = str_replace($tmp, "components/{$name}/template.php", trim($out));
                throw new RuntimeException(sprintf("PHP syntax error in template:\n%s", $out));
            }
        } finally {
            @unlink($tmp);
        }
    }

    private function lintPhpFallback(string $php): void
    {
        $tokens = @token_get_all($php);
        if ($tokens === false) {
            throw new RuntimeException('Could not tokenize template.php — likely a syntax error.');
        }
    }

    /**
     * Block the most obvious sins so an AI-generated template can't do
     * what no Oxa template should ever do. This is NOT a security
     * boundary — it's a sanity check. The real boundary is the bearer
     * token on the MCP endpoint.
     */
    private function scanTemplateForDangerousCalls(string $php, string $name): void
    {
        $deny = [
            'shell_exec', 'exec', 'system', 'passthru', 'proc_open', 'popen',
            'eval', 'assert', 'create_function',
            'unlink', 'file_put_contents', 'fopen', 'rename',
            'curl_exec', 'wp_remote_get', 'wp_remote_post',
        ];
        // Strip strings and comments first so we don't false-positive on
        // a function name appearing inside a literal or comment.
        $stripped = $this->stripStringsAndComments($php);

        foreach ($deny as $fn) {
            if (preg_match('/(?<![A-Za-z0-9_])' . preg_quote($fn, '/') . '\s*\(/', $stripped) === 1) {
                throw new RuntimeException(sprintf(
                    'Template uses "%s()" which is not allowed in components/%s/template.php. '
                    . 'Components must be pure render functions.',
                    $fn,
                    $name
                ));
            }
        }
    }

    private function stripStringsAndComments(string $php): string
    {
        $tokens = @token_get_all($php);
        if ($tokens === false) {
            return $php;
        }
        $out = '';
        foreach ($tokens as $t) {
            if (is_array($t)) {
                [$id, $text] = [$t[0], $t[1]];
                if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                    $out .= str_repeat(' ', strlen($text));
                    continue;
                }
                $out .= $text;
            } else {
                $out .= $t;
            }
        }
        return $out;
    }
}
