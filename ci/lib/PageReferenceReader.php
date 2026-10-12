<?php
/**
 * CI/dev-only literal reader. Reads English content from the actual landing
 * pages without loading WHMCS or executing a page/controller/database query.
 * Only PHP literal arrays, strings, numbers and concatenation are accepted.
 * Do not load this file on the WHMCS request path.
 */
namespace CloudHost247\Ci;

final class PageReferenceReader
{
    public static function read(string $sourceFile, array $reference): array
    {
        $source = file_get_contents($sourceFile);
        if (isset($reference['variable'])) {
            $pattern = '/\$' . preg_quote($reference['variable'], '/') . '\s*=\s*\[/';
        } else {
            $pattern = '/ch247_content\(\s*\'' . preg_quote($reference['catalog'], '/') . '\'\s*,\s*\[/';
        }
        if (!preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
            throw new \RuntimeException('English array not found in ' . $sourceFile);
        }
        $start = $match[0][1] + strlen($match[0][0]) - 1;
        $tokens = token_get_all('<?php ' . substr($source, $start));
        $depth = 0;
        $expression = '';
        foreach (array_slice($tokens, 1) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (!is_array($token)) {
                if ($token === '[') {
                    $depth++;
                } elseif ($token === ']') {
                    $depth--;
                }
            }
            $expression .= $text;
            if ($depth === 0) {
                break;
            }
        }
        if ($depth !== 0 || $expression === '') {
            throw new \RuntimeException('Unbalanced English array in ' . $sourceFile);
        }
        return self::literal($expression);
    }

    private static function literal(string $expression): array
    {
        foreach (token_get_all('<?php ' . $expression) as $token) {
            if (!is_array($token)) {
                if (!in_array($token, array('[', ']', '(', ')', ',', '.', '-', '+'), true)) {
                    throw new \RuntimeException('Nonliteral punctuation in page reference');
                }
                continue;
            }
            if (in_array($token[0], array(T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER, T_DOUBLE_ARROW, T_ARRAY), true)) {
                continue;
            }
            if ($token[0] === T_STRING && in_array(strtolower($token[1]), array('true', 'false', 'null'), true)) {
                continue;
            }
            throw new \RuntimeException('Nonliteral token in page reference: ' . token_name($token[0]));
        }
        // The token allowlist above forbids variables, function calls,
        // interpolation, includes, assignments and every side effect.
        $data = eval('return ' . $expression . ';');
        if (!is_array($data)) {
            throw new \RuntimeException('Page reference did not yield an array');
        }
        return $data;
    }

    /** Stable paths, using record ids rather than positional list indices. */
    public static function strings(array $data, string $path = ''): array
    {
        $out = array();
        foreach ($data as $key => $value) {
            if (\CloudHost247\I18n\PageContent::isTechnicalField($key)) {
                continue;
            }
            $part = is_array($value) && isset($value['id']) ? '@' . $value['id'] : (string) $key;
            $next = $path === '' ? $part : $path . '.' . $part;
            if (is_array($value)) {
                $out = array_merge($out, self::strings($value, $next));
            } elseif (is_string($value) && trim($value) !== '' && !\CloudHost247\I18n\PageContent::isTechnicalValue($value)) {
                $out[$next] = $value;
            }
        }
        return $out;
    }
}
