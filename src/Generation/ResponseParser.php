<?php
/**
 * Parses raw LLM text into a structured page payload.
 *
 * LLMs sometimes wrap JSON in markdown fences or trailing prose even
 * when asked not to. We strip those defensively before decoding.
 *
 * @package OxaAi
 */

declare(strict_types=1);

namespace OxaAi\Generation;

use RuntimeException;

if (!defined('ABSPATH')) {
    exit;
}

final class ResponseParser
{
    /**
     * @return array{page_title:string,layout:array<int,array>}
     */
    public function parse(string $raw): array
    {
        $clean = $this->stripFences($raw);
        $decoded = json_decode($clean, true);

        if (!is_array($decoded)) {
            // Last resort: try to grab the first balanced { ... } block.
            $extracted = $this->extractFirstJsonObject($raw);
            $decoded   = $extracted !== null ? json_decode($extracted, true) : null;
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('AI response was not valid JSON.');
        }

        $title  = isset($decoded['page_title']) && is_string($decoded['page_title'])
            ? $decoded['page_title']
            : 'Untitled';

        $layout = isset($decoded['layout']) && is_array($decoded['layout'])
            ? $decoded['layout']
            : [];

        return [
            'page_title' => $title,
            'layout'     => $layout,
        ];
    }

    private function stripFences(string $raw): string
    {
        $raw = trim($raw);
        // ```json\n...\n```  or  ```\n...\n```
        if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/s', $raw, $m) === 1) {
            return trim($m[1]);
        }
        return $raw;
    }

    private function extractFirstJsonObject(string $raw): ?string
    {
        $start = strpos($raw, '{');
        if ($start === false) {
            return null;
        }
        $depth   = 0;
        $inStr   = false;
        $escaped = false;
        $len     = strlen($raw);
        for ($i = $start; $i < $len; $i++) {
            $ch = $raw[$i];
            if ($inStr) {
                if ($escaped)        { $escaped = false; continue; }
                if ($ch === '\\')    { $escaped = true;  continue; }
                if ($ch === '"')     { $inStr   = false; }
                continue;
            }
            if ($ch === '"') { $inStr = true; continue; }
            if ($ch === '{') { $depth++; }
            if ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }
        return null;
    }
}
