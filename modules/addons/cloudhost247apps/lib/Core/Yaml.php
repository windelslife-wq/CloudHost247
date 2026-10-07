<?php
/**
 * CloudHost247 App Cloud — YAML subset parser and emitter.
 *
 * Application manifests are YAML because that is what operators expect when they
 * hand-write or import a deployment definition. The module has no Composer
 * dependencies, so this implements the documented subset the manifest format
 * uses — and rejects anything outside it with a precise line number rather than
 * silently mis-parsing:
 *
 *   • block mappings and sequences, nested by indentation (spaces, no tabs)
 *   • scalars: plain, single-quoted, double-quoted (with \n \t \" \\ \u escapes)
 *   • block scalars: `|` literal and `>` folded, with `-` chomping
 *   • inline flow collections: [a, b] and {a: 1, b: 2}
 *   • comments (`#`), document separator (`---`), null (`~`/empty), booleans,
 *     integers, floats, and ISO dates kept as strings
 *   • anchors/aliases are rejected explicitly (a manifest must be readable as-is)
 *
 * @package Ch247Apps
 */

namespace Ch247Apps\Core;

class Yaml
{
    /** Parse a YAML document into nested PHP arrays/scalars. */
    public static function parse($text)
    {
        $lines = self::prepare((string) $text);
        if (!$lines) {
            return [];
        }
        $pos = 0;
        $result = self::parseBlock($lines, $pos, $lines[0]['indent']);
        return $result === null ? [] : $result;
    }

    /** Parse or throw a ValidationException naming the offending line. */
    public static function parseStrict($text, $label = 'manifest')
    {
        try {
            return self::parse($text);
        } catch (\Throwable $e) {
            throw new ValidationException('Invalid YAML in ' . $label . ': ' . $e->getMessage(), [
                'label' => $label,
            ]);
        }
    }

    /**
     * Emit nested arrays as YAML (used when the admin console shows a
     * normalised manifest and when a version snapshot is written back out).
     */
    public static function emit($data, $indent = 0)
    {
        $pad = str_repeat(' ', $indent * 2);
        $out = '';

        if (!is_array($data) || $data === []) {
            return $pad . (is_array($data) ? ($data === [] ? '{}' : '') : self::emitScalar($data)) . "\n";
        }

        $isList = array_keys($data) === range(0, count($data) - 1);
        foreach ($data as $key => $value) {
            if ($isList) {
                if (is_array($value) && $value !== []) {
                    $inner = self::emit($value, $indent + 1);
                    $out .= $pad . "-\n" . $inner;
                } elseif (self::isMultiline($value)) {
                    $out .= $pad . "- |\n" . self::emitBlock((string) $value, $indent + 1);
                } else {
                    $out .= $pad . '- ' . self::emitScalar($value) . "\n";
                }
                continue;
            }
            if (is_array($value) && $value !== []) {
                $out .= $pad . self::emitKey($key) . ":\n" . self::emit($value, $indent + 1);
            } elseif (self::isMultiline($value)) {
                $out .= $pad . self::emitKey($key) . ": |\n" . self::emitBlock((string) $value, $indent + 1);
            } else {
                $out .= $pad . self::emitKey($key) . ': '
                    . (is_array($value) ? ($value === [] ? '[]' : '{}') : self::emitScalar($value)) . "\n";
            }
        }
        return $out;
    }

    private static function isMultiline($value)
    {
        return is_string($value) && strpos($value, "\n") !== false && trim($value) !== '';
    }

    /**
     * Emit a multi-line string as a literal block. A quoted scalar containing a
     * real newline would not round-trip, so block style is the only safe form.
     */
    private static function emitBlock($text, $indent)
    {
        $pad = str_repeat(' ', $indent * 2);
        $lines = explode("\n", rtrim($text, "\n"));
        $out = '';
        foreach ($lines as $line) {
            $out .= $pad . rtrim($line) . "\n";
        }
        return $out;
    }

    /* ------------------------------------------------------------- internals */

    private static function emitKey($key)
    {
        $key = (string) $key;
        return preg_match('/^[A-Za-z0-9_.\-\/]+$/', $key) ? $key : "'" . str_replace("'", "''", $key) . "'";
    }

    private static function emitScalar($value)
    {
        if ($value === null) {
            return '~';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        $value = (string) $value;
        if ($value === '') {
            return "''";
        }
        if (strpos($value, "\n") !== false) {
            // Defensive: multi-line text is emitted as a block scalar upstream.
            $value = preg_replace('/\s*\n\s*/', ' ', $value);
        }
        // Only emit bare (unquoted) when re-parsing the result yields exactly this
        // string. YAML 1.1 booleans ("yes", "on"), numbers, and anything with an
        // indicator character or surrounding space would come back as a different
        // value — and a manifest snapshot that does not survive its own round trip
        // would fail the integrity check the deployment engine relies on.
        $bareSafe = preg_match('/^[-\w.\/:@ ]+$/', $value)
            && !preg_match('/^(true|false|null|~|yes|no|on|off|y|n)$/i', $value)
            && !is_numeric($value)
            && $value === trim($value);
        if ($bareSafe) {
            return $value;
        }
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Normalise the document into [['indent' => int, 'text' => string, 'line' => int], …]
     * stripping comments, blank lines and the document marker.
     */
    private static function prepare($text)
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $raw = explode("\n", $text);
        $lines = [];
        foreach ($raw as $number => $line) {
            if (strpos($line, "\t") !== false && preg_match('/^\s*\t/', $line)) {
                throw new ValidationException('Tab indentation is not allowed (line ' . ($number + 1) . ').');
            }
            $stripped = self::stripComment($line);
            if (trim($stripped) === '') {
                continue;
            }
            if (trim($stripped) === '---' || trim($stripped) === '...') {
                continue;
            }
            preg_match('/^( *)/', $stripped, $m);
            $lines[] = [
                'indent' => strlen($m[1]),
                'text' => ltrim($stripped),
                'line' => $number + 1,
            ];
        }
        return $lines;
    }

    /** Remove a trailing comment that is not inside quotes. */
    private static function stripComment($line)
    {
        $inSingle = false;
        $inDouble = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif ($ch === '#' && !$inSingle && !$inDouble) {
                if ($i === 0 || $line[$i - 1] === ' ' || $line[$i - 1] === "\t") {
                    return substr($line, 0, $i);
                }
            }
        }
        return $line;
    }

    /**
     * Parse a block at $indent starting from $lines[$pos]. Returns an array
     * (map or list) or a scalar for a single-line block.
     */
    private static function parseBlock(array &$lines, &$pos, $indent)
    {
        if (!isset($lines[$pos])) {
            return null;
        }
        if (self::startsWith($lines[$pos]['text'], '- ') || $lines[$pos]['text'] === '-') {
            return self::parseList($lines, $pos, $indent);
        }
        return self::parseMap($lines, $pos, $indent);
    }

    private static function parseList(array &$lines, &$pos, $indent)
    {
        $out = [];
        $count = count($lines);

        while ($pos < $count && $lines[$pos]['indent'] === $indent
            && (self::startsWith($lines[$pos]['text'], '- ') || $lines[$pos]['text'] === '-')) {
            $line = $lines[$pos];
            $rest = $line['text'] === '-' ? '' : substr($line['text'], 2);

            if (trim($rest) === '') {
                // Nested block belongs to this item.
                $pos++;
                if ($pos < $count && $lines[$pos]['indent'] > $indent) {
                    $out[] = self::parseBlock($lines, $pos, $lines[$pos]['indent']);
                } else {
                    $out[] = null;
                }
                continue;
            }

            // "- |" or "- >" introduces a block scalar for this list item.
            if (preg_match('/^[|>][0-9]*[+-]?$/', trim($rest))) {
                $pos++;
                $out[] = self::parseBlockScalar($lines, $pos, $indent, trim($rest));
                continue;
            }

            // "- key: value" starts an inline map whose remaining keys sit at
            // indent + 2 (the position after the dash).
            if (self::looksLikeMapEntry($rest)) {
                $virtualIndent = $indent + 2;
                $lines[$pos] = ['indent' => $virtualIndent, 'text' => $rest, 'line' => $line['line']];
                $out[] = self::parseMap($lines, $pos, $virtualIndent);
                continue;
            }

            $out[] = self::parseScalar($rest, $line['line']);
            $pos++;
        }
        return $out;
    }

    private static function parseMap(array &$lines, &$pos, $indent)
    {
        $out = [];
        $count = count($lines);

        while ($pos < $count && $lines[$pos]['indent'] === $indent) {
            $line = $lines[$pos];
            $text = $line['text'];

            if (self::startsWith($text, '- ') || $text === '-') {
                break; // a list at this indent ends the map
            }
            if (!self::looksLikeMapEntry($text)) {
                throw new ValidationException(
                    'Expected "key: value" at line ' . $line['line'] . ': ' . Str::clip($text, 60)
                );
            }

            list($key, $value) = self::splitMapEntry($text, $line['line']);
            $pos++;

            if (preg_match('/^[|>][0-9]*[+-]?$/', $value)) {
                $out[$key] = self::parseBlockScalar($lines, $pos, $indent, $value);
                continue;
            }

            if (trim($value) === '') {
                // Nested block (or an empty value).
                if ($pos < $count && $lines[$pos]['indent'] > $indent) {
                    $out[$key] = self::parseBlock($lines, $pos, $lines[$pos]['indent']);
                } else {
                    $out[$key] = null;
                }
                continue;
            }

            if (self::startsWith($value, '&') || self::startsWith($value, '*')) {
                throw new ValidationException(
                    'YAML anchors and aliases are not supported in manifests (line ' . $line['line'] . ').'
                );
            }

            $out[$key] = self::parseValue($value, $lines, $pos, $indent, $line['line']);
        }
        return $out;
    }

    /** Flow collections can continue on the following lines. */
    private static function parseValue($value, array &$lines, &$pos, $indent, $lineNumber)
    {
        $value = trim($value);
        if ($value !== '' && (self::startsWith($value, '[') || self::startsWith($value, '{'))) {
            $open = $value[0];
            $close = $open === '[' ? ']' : '}';
            while (!self::endsWith($value, $close) && isset($lines[$pos]) && $lines[$pos]['indent'] >= $indent) {
                $value .= ' ' . trim($lines[$pos]['text']);
                $pos++;
            }
            return self::parseFlow($value, $lineNumber);
        }
        return self::parseScalar($value, $lineNumber);
    }

    private static function parseBlockScalar(array &$lines, &$pos, $indent, $style)
    {
        $style = preg_replace('/[0-9]/', '', trim($style)); // ignore explicit indent indicators
        $folded = self::startsWith($style, '>');
        $chompStrip = self::endsWith($style, '-');
        $parts = [];
        $blockIndent = null;
        $count = count($lines);

        while ($pos < $count && $lines[$pos]['indent'] > $indent) {
            $entry = $lines[$pos];
            if ($blockIndent === null) {
                $blockIndent = $entry['indent'];
            }
            $parts[] = str_repeat(' ', max(0, $entry['indent'] - $blockIndent)) . $entry['text'];
            $pos++;
        }

        // prepare() dropped blank lines; a literal block keeps its structure by
        // joining with newlines, a folded block by joining with spaces.
        $text = $folded ? implode(' ', $parts) : implode("\n", $parts);
        return $chompStrip ? $text : ($text === '' ? '' : $text . ($folded ? ' ' : "\n"));
    }

    /** Parse a flow collection ([…], {…}) into arrays. */
    private static function parseFlow($text, $lineNumber)
    {
        $text = trim($text);
        if ($text === '' || ($text[0] !== '[' && $text[0] !== '{')) {
            return self::parseScalar($text, $lineNumber);
        }
        $close = $text[0] === '[' ? ']' : '}';
        if (!self::endsWith($text, $close)) {
            throw new ValidationException('Unterminated flow collection at line ' . $lineNumber . '.');
        }
        $inner = trim(substr($text, 1, -1));
        if ($inner === '') {
            return [];
        }
        $items = self::splitFlow($inner);
        if ($text[0] === '[') {
            $out = [];
            foreach ($items as $item) {
                $out[] = self::parseScalar(trim($item), $lineNumber);
            }
            return $out;
        }
        $out = [];
        foreach ($items as $item) {
            $item = trim($item);
            $colon = self::flowColon($item);
            if ($colon === false) {
                throw new ValidationException('Invalid flow mapping entry at line ' . $lineNumber . ': ' . $item);
            }
            $key = self::unquote(trim(substr($item, 0, $colon)));
            $out[(string) $key] = self::parseScalar(trim(substr($item, $colon + 1)), $lineNumber);
        }
        return $out;
    }

    private static function splitFlow($inner)
    {
        $parts = [];
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $current = '';
        $len = strlen($inner);
        for ($i = 0; $i < $len; $i++) {
            $ch = $inner[$i];
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif (!$inSingle && !$inDouble) {
                if ($ch === '[' || $ch === '{') {
                    $depth++;
                } elseif ($ch === ']' || $ch === '}') {
                    $depth--;
                } elseif ($ch === ',' && $depth === 0) {
                    $parts[] = $current;
                    $current = '';
                    continue;
                }
            }
            $current .= $ch;
        }
        if (trim($current) !== '') {
            $parts[] = $current;
        }
        return $parts;
    }

    /** Position of the key/value colon in a flow entry (skips quoted text). */
    private static function flowColon($item)
    {
        $inSingle = false;
        $inDouble = false;
        $len = strlen($item);
        for ($i = 0; $i < $len; $i++) {
            $ch = $item[$i];
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif ($ch === ':' && !$inSingle && !$inDouble) {
                if ($i === $len - 1 || $item[$i + 1] === ' ') {
                    return $i;
                }
            }
        }
        return false;
    }

    private static function looksLikeMapEntry($text)
    {
        return self::mapColon($text) !== false;
    }

    /** Position of the separating colon in a block entry, or false. */
    private static function mapColon($text)
    {
        $inSingle = false;
        $inDouble = false;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($ch === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            } elseif ($ch === ':' && !$inSingle && !$inDouble) {
                if ($i === $len - 1 || $text[$i + 1] === ' ') {
                    return $i;
                }
            }
        }
        return false;
    }

    private static function splitMapEntry($text, $lineNumber)
    {
        $colon = self::mapColon($text);
        if ($colon === false) {
            throw new ValidationException('Malformed mapping at line ' . $lineNumber . ': ' . Str::clip($text, 60));
        }
        $key = self::unquote(trim(substr($text, 0, $colon)));
        if (!is_string($key) && !is_int($key)) {
            throw new ValidationException('Invalid mapping key at line ' . $lineNumber . '.');
        }
        return [(string) $key, trim(substr($text, $colon + 1))];
    }

    /** Convert a scalar token into its PHP value. */
    private static function parseScalar($value, $lineNumber)
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '~' || strtolower($value) === 'null') {
            return null;
        }
        if (self::startsWith($value, "'")) {
            if (!self::endsWith($value, "'") || strlen($value) < 2) {
                throw new ValidationException('Unterminated single-quoted scalar at line ' . $lineNumber . '.');
            }
            return str_replace("''", "'", substr($value, 1, -1));
        }
        if (self::startsWith($value, '"')) {
            if (!self::endsWith($value, '"') || strlen($value) < 2) {
                throw new ValidationException('Unterminated double-quoted scalar at line ' . $lineNumber . '.');
            }
            return self::unescape(substr($value, 1, -1));
        }
        if (self::startsWith($value, '[') || self::startsWith($value, '{')) {
            return self::parseFlow($value, $lineNumber);
        }
        $lower = strtolower($value);
        if ($lower === 'true' || $lower === 'yes' || $lower === 'on') {
            return true;
        }
        if ($lower === 'false' || $lower === 'no' || $lower === 'off') {
            return false;
        }
        if (preg_match('/^[-+]?\d+$/', $value)) {
            $int = (int) $value;
            return (string) $int === $value ? $int : $value;
        }
        if (preg_match('/^[-+]?(\d+\.\d*|\.\d+|\d+)([eE][-+]?\d+)?$/', $value) && strpos($value, '.') !== false) {
            return (float) $value;
        }
        return $value;
    }

    private static function unquote($value)
    {
        $value = trim((string) $value);
        if (strlen($value) >= 2 && self::startsWith($value, '"') && self::endsWith($value, '"')) {
            return self::unescape(substr($value, 1, -1));
        }
        if (strlen($value) >= 2 && self::startsWith($value, "'") && self::endsWith($value, "'")) {
            return str_replace("''", "'", substr($value, 1, -1));
        }
        return $value;
    }

    private static function unescape($value)
    {
        return preg_replace_callback('/\\\\(u[0-9a-fA-F]{4}|.)/', function ($m) {
            if ($m[1][0] === 'u') {
                $code = hexdec(substr($m[1], 1));
                return self::utf8($code);
            }
            switch ($m[1]) {
                case 'n':
                    return "\n";
                case 't':
                    return "\t";
                case 'r':
                    return "\r";
                case '0':
                    return "\0";
                default:
                    return $m[1];
            }
        }, (string) $value);
    }

    private static function utf8($code)
    {
        if ($code < 0x80) {
            return chr($code);
        }
        if ($code < 0x800) {
            return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
        }
        return chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
    }

    private static function startsWith($haystack, $needle)
    {
        return strncmp((string) $haystack, (string) $needle, strlen((string) $needle)) === 0;
    }

    private static function endsWith($haystack, $needle)
    {
        $needle = (string) $needle;
        return $needle === '' || substr((string) $haystack, -strlen($needle)) === $needle;
    }
}
