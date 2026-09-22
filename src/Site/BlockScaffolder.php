<?php
/**
 * Scaffold a new Gutenberg block under `<theme>/blocks/<slug>/`.
 *
 * Writes:
 *   block.json   — block metadata
 *   render.php   — server-side renderer
 *   index.js     — editor registration (optional, when dynamic=false)
 *   style.css    — front+editor styles
 *
 * The block is server-rendered by default. It complements the existing
 * `oxa/component` block and is intended for one-off bespoke blocks
 * that don't fit the Oxa component contract.
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

final class BlockScaffolder
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9-]{1,40}$/';

    public function __construct(private readonly Logger $logger) {}

    /**
     * @param array{
     *   slug:string,
     *   title?:string,
     *   namespace?:string,
     *   description?:string,
     *   category?:string,
     *   icon?:string,
     *   render_php?:string,
     *   styles_css?:string,
     *   attributes?:array<string,array<string,mixed>>
     * } $args
     *
     * @return array{name:string,dir:string,written:array<int,string>,bytes:int}
     */
    public function scaffold(array $args, string $scope = ThemePaths::SCOPE_TEMPLATE): array
    {
        $slug = (string) ($args['slug'] ?? '');
        if (preg_match(self::NAME_PATTERN, $slug) !== 1) {
            throw new RuntimeException(sprintf('Invalid block slug "%s". Must match %s.', $slug, self::NAME_PATTERN));
        }
        $namespace = (string) ($args['namespace'] ?? 'oxa');
        if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $namespace)) {
            throw new RuntimeException('Invalid namespace; must match /^[a-z][a-z0-9-]{1,30}$/.');
        }
        $title = (string) ($args['title'] ?? ucwords(str_replace('-', ' ', $slug)));
        $description = (string) ($args['description'] ?? '');
        $category = (string) ($args['category'] ?? $namespace);
        $icon = (string) ($args['icon'] ?? 'block-default');
        $attributes = is_array($args['attributes'] ?? null) ? $args['attributes'] : [];

        $renderPhp = isset($args['render_php']) && is_string($args['render_php']) && trim($args['render_php']) !== ''
            ? (string) $args['render_php']
            : $this->defaultRender($namespace, $slug, $attributes);
        if (!str_starts_with(ltrim($renderPhp), '<?php')) {
            throw new RuntimeException('render_php must begin with "<?php".');
        }

        $stylesCss = (string) ($args['styles_css'] ?? $this->defaultStyles($namespace, $slug));

        $relDir = 'blocks/' . $slug;
        $absDir = ThemePaths::root($scope) . $relDir;
        if (is_dir($absDir)) {
            throw new RuntimeException(sprintf('Block "%s" already exists at %s.', $slug, $relDir));
        }
        if (!wp_mkdir_p($absDir)) {
            throw new RuntimeException('Could not create block directory.');
        }
        ThemePaths::assertInside($absDir, $scope);

        $blockJson = [
            '$schema'     => 'https://schemas.wp.org/trunk/block.json',
            'apiVersion'  => 3,
            'name'        => $namespace . '/' . $slug,
            'title'       => $title,
            'category'    => $category,
            'description' => $description,
            'version'     => '0.1.0',
            'textdomain'  => $namespace,
            'icon'        => $icon,
            'attributes'  => $attributes !== [] ? $attributes : new \stdClass(),
            'supports'    => ['html' => false, 'reusable' => true, 'customClassName' => true],
            'render'      => 'file:./render.php',
            'style'       => 'file:./style.css',
        ];

        $payload = [
            'block.json' => (string) wp_json_encode($blockJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
            'render.php' => $renderPhp,
            'style.css'  => $stylesCss,
        ];

        $written = [];
        $bytes = 0;
        foreach ($payload as $name => $contents) {
            $path = $absDir . DIRECTORY_SEPARATOR . $name;
            $tmp  = $path . '.oxa.tmp';
            $w = file_put_contents($tmp, $contents);
            if ($w === false || !rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException(sprintf('Could not write %s.', $name));
            }
            $written[] = $path;
            $bytes += (int) $w;
        }

        $this->logger->info('block scaffolded', ['slug' => $slug, 'dir' => $absDir]);
        return ['name' => $namespace . '/' . $slug, 'dir' => $absDir, 'written' => $written, 'bytes' => $bytes];
    }

    private function defaultRender(string $namespace, string $slug, array $attributes): string
    {
        $attrLines = [];
        foreach (array_keys($attributes) as $k) {
            $attrLines[] = sprintf("    \$%s = \$attributes['%s'] ?? null;", $k, $k);
        }
        $attrBlock = $attrLines ? implode("\n", $attrLines) . "\n" : '';
        $className = $namespace . '-' . $slug;

        return <<<PHP
<?php
/**
 * Render callback for {$namespace}/{$slug}.
 *
 * \$attributes — block attributes as defined in block.json
 * \$content    — innerBlocks markup (already rendered)
 * \$block      — WP_Block instance
 */

if (!defined('ABSPATH')) {
    exit;
}

{$attrBlock}\$class = trim('{$className} ' . (string) (\$attributes['className'] ?? ''));
?>
<div class="<?php echo esc_attr(\$class); ?>">
    <?php echo \$content; ?>
</div>
PHP;
    }

    private function defaultStyles(string $namespace, string $slug): string
    {
        $cls = $namespace . '-' . $slug;
        return <<<CSS
.{$cls} {
    display: block;
}
CSS;
    }
}
