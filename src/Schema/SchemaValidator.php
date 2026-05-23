<?php
/**
 * Schema validator.
 *
 * Validates AI-generated structures (single components and full page
 * layouts) against the registered component schemas. The output is a
 * sanitized, defaulted copy of the input — never the input itself.
 *
 * Why hand-rolled: JSON-Schema would be heavier than needed and harder
 * to teach AI to target. Our schema dialect is intentionally small:
 *   types:   string, text, url, boolean, enum, array
 *   keys:    type, required, default, options, item_schema, max_items, min_items
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Schema;

if (!defined('ABSPATH')) {
    exit;
}

final class SchemaValidator
{
    public function __construct(private readonly SchemaRegistry $registry) {}

    /**
     * Validate a single component node:
     *   ['component' => 'hero', 'props' => [...]]
     */
    public function validateComponentNode(mixed $node): ValidationResult
    {
        if (!is_array($node)) {
            return ValidationResult::fail('Component node must be an object.');
        }
        $name = $node['component'] ?? null;
        if (!is_string($name) || $name === '') {
            return ValidationResult::fail('Component node is missing "component" name.');
        }
        $schema = $this->registry->get($name);
        if ($schema === null) {
            return ValidationResult::fail(sprintf('Unknown component "%s".', $name));
        }

        $props = $node['props'] ?? [];
        if (!is_array($props)) {
            return ValidationResult::fail(sprintf('Component "%s" props must be an object.', $name));
        }

        $errors = [];
        $clean  = $this->validateFields($schema['fields'] ?? [], $props, $name, $errors);

        if ($errors) {
            return ValidationResult::fail($errors);
        }

        return ValidationResult::ok([
            'component' => $name,
            'props'     => $clean,
        ]);
    }

    /**
     * Validate a full layout:
     *   [ ['component' => '...', 'props' => [...]], ... ]
     *
     * @param array<int,mixed> $layout
     */
    public function validateLayout(mixed $layout): ValidationResult
    {
        if (!is_array($layout)) {
            return ValidationResult::fail('Layout must be an array of component nodes.');
        }
        $clean  = [];
        $errors = [];
        foreach ($layout as $i => $node) {
            $r = $this->validateComponentNode($node);
            if (!$r->valid) {
                foreach ($r->errors as $msg) {
                    $errors[] = sprintf('[%d] %s', $i, $msg);
                }
                continue;
            }
            $clean[] = $r->value;
        }
        if ($errors) {
            return ValidationResult::fail($errors);
        }
        return ValidationResult::ok($clean);
    }

    /**
     * @param array<string,array> $fields
     * @param array<string,mixed> $input
     * @param array<int,string>   $errors
     * @return array<string,mixed>
     */
    private function validateFields(array $fields, array $input, string $context, array &$errors): array
    {
        $clean = [];
        foreach ($fields as $key => $def) {
            if (!is_array($def)) {
                continue;
            }
            $type     = (string) ($def['type'] ?? 'string');
            $required = !empty($def['required']);
            $hasValue = array_key_exists($key, $input);

            if (!$hasValue) {
                if ($required) {
                    $errors[] = sprintf('%s.%s: required field missing.', $context, $key);
                    continue;
                }
                if (array_key_exists('default', $def)) {
                    $clean[$key] = $def['default'];
                }
                continue;
            }

            $value = $input[$key];
            $r = $this->validateField($type, $def, $value, "{$context}.{$key}");
            if (!$r->valid) {
                foreach ($r->errors as $msg) {
                    $errors[] = $msg;
                }
                continue;
            }
            $clean[$key] = $r->value;
        }
        return $clean;
    }

    private function validateField(string $type, array $def, mixed $value, string $path): ValidationResult
    {
        return match ($type) {
            'string'  => $this->validateString($value, $path),
            'text'    => $this->validateText($value, $path),
            'url'     => $this->validateUrl($value, $path),
            'boolean' => $this->validateBoolean($value, $path),
            'enum'    => $this->validateEnum($value, $def, $path),
            'array'   => $this->validateArray($value, $def, $path),
            default   => ValidationResult::fail(sprintf('%s: unknown type "%s".', $path, $type)),
        };
    }

    private function validateString(mixed $value, string $path): ValidationResult
    {
        if (!is_string($value) && !is_numeric($value)) {
            return ValidationResult::fail(sprintf('%s: expected string, got %s.', $path, gettype($value)));
        }
        return ValidationResult::ok(sanitize_text_field((string) $value));
    }

    private function validateText(mixed $value, string $path): ValidationResult
    {
        if (!is_string($value) && !is_numeric($value)) {
            return ValidationResult::fail(sprintf('%s: expected text, got %s.', $path, gettype($value)));
        }
        return ValidationResult::ok(wp_kses_post((string) $value));
    }

    private function validateUrl(mixed $value, string $path): ValidationResult
    {
        if (!is_string($value)) {
            return ValidationResult::fail(sprintf('%s: expected url string.', $path));
        }
        $clean = esc_url_raw($value);
        if ($clean === '' && $value !== '') {
            return ValidationResult::fail(sprintf('%s: invalid url.', $path));
        }
        return ValidationResult::ok($clean);
    }

    private function validateBoolean(mixed $value, string $path): ValidationResult
    {
        if (is_bool($value)) {
            return ValidationResult::ok($value);
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return ValidationResult::ok((bool) (int) $value);
        }
        if ($value === 'true' || $value === 'false') {
            return ValidationResult::ok($value === 'true');
        }
        return ValidationResult::fail(sprintf('%s: expected boolean.', $path));
    }

    private function validateEnum(mixed $value, array $def, string $path): ValidationResult
    {
        $options = $def['options'] ?? [];
        if (!is_array($options) || empty($options)) {
            return ValidationResult::fail(sprintf('%s: enum has no options.', $path));
        }
        $value = is_scalar($value) ? (string) $value : null;
        if ($value === null || !in_array($value, $options, true)) {
            return ValidationResult::fail(sprintf(
                '%s: must be one of [%s].',
                $path,
                implode(', ', array_map('strval', $options))
            ));
        }
        return ValidationResult::ok($value);
    }

    private function validateArray(mixed $value, array $def, string $path): ValidationResult
    {
        if (!is_array($value)) {
            return ValidationResult::fail(sprintf('%s: expected array.', $path));
        }
        $min = isset($def['min_items']) ? (int) $def['min_items'] : null;
        $max = isset($def['max_items']) ? (int) $def['max_items'] : null;
        if ($min !== null && count($value) < $min) {
            return ValidationResult::fail(sprintf('%s: at least %d items required.', $path, $min));
        }
        if ($max !== null && count($value) > $max) {
            return ValidationResult::fail(sprintf('%s: at most %d items allowed.', $path, $max));
        }
        $itemSchema = $def['item_schema'] ?? null;
        if (!is_array($itemSchema)) {
            // Array of strings — sanitize each.
            return ValidationResult::ok(array_map(
                static fn(mixed $v): string => is_scalar($v) ? sanitize_text_field((string) $v) : '',
                $value
            ));
        }
        $clean  = [];
        $errors = [];
        foreach ($value as $i => $item) {
            if (!is_array($item)) {
                $errors[] = sprintf('%s[%d]: expected object.', $path, (int) $i);
                continue;
            }
            $row = $this->validateFields($itemSchema, $item, "{$path}[{$i}]", $errors);
            if (!empty($row) || empty($errors)) {
                $clean[] = $row;
            }
        }
        if ($errors) {
            return ValidationResult::fail($errors);
        }
        return ValidationResult::ok($clean);
    }
}
