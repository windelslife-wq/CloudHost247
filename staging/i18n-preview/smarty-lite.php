<?php
/**
 * CloudHost247 — a very small Smarty-subset renderer.
 *
 * Purpose: the staging preview harness renders the REAL shipped templates
 * (templates/hostx/includes/language-currency-switcher.tpl and its mobile
 * sibling) with the REAL view-model produced by includes/i18n, so what you see
 * in the preview is the same markup WHMCS will emit — not a hand-written
 * mockup that can drift from it.
 *
 * It supports exactly the constructs those templates use and nothing more:
 *
 *   {* comments *}
 *   {if $a}…{else}…{/if}        with eq / neq / && / || / ! / count()
 *   {foreach $list as $item}…{/foreach}
 *   {$var}  {$var.key}  {$var['key']}
 *   {$var|escape:'html':'UTF-8'}  {$var|replace:'a':'b'}
 *   {include file="relative.tpl"}
 *
 * This is a developer preview tool. It uses eval() to evaluate {if} conditions
 * against a controlled, locally-generated data set. It is NOT a template engine
 * and must never be shipped into the WHMCS request path — see README.md.
 *
 * @package    CloudHost247\Staging
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

final class SmartyLite
{
    /** @var string */
    private $templateDir;

    /** @var array */
    private $vars;

    /**
     * @param string $templateDir Base directory for {include file="..."}.
     * @param array  $vars
     */
    public function __construct(string $templateDir, array $vars = array())
    {
        $this->templateDir = rtrim($templateDir, '/');
        $this->vars = $vars;
    }

    /**
     * @param string $file Path relative to the template dir.
     * @param array  $vars
     *
     * @return string
     */
    public function render(string $file, array $vars = array()): string
    {
        $path = $this->templateDir . '/' . ltrim($file, '/');
        if (!is_file($path)) {
            throw new RuntimeException('Template not found: ' . $path);
        }

        return $this->renderString(file_get_contents($path), $vars);
    }

    /**
     * @param string $source
     * @param array  $vars
     *
     * @return string
     */
    public function renderString(string $source, array $vars = array()): string
    {
        $scope = array_merge($this->vars, $vars);

        // 1. Strip Smarty comments.
        $source = preg_replace('/\{\*[\s\S]*?\*\}/', '', $source);

        // 2. {include file="..."}
        $source = preg_replace_callback(
            '/\{include\s+file="([^"]+)"\s*\}/',
            function (array $m) use ($scope) {
                $file = $m[1];
                $file = str_replace('$template/', '', $file);
                return $this->render($file, $scope);
            },
            $source
        );

        // 3. {foreach $list as [$key =>] $item} … {/foreach} (innermost first)
        $pattern = '/\{foreach\s+(\$[A-Za-z0-9_\.\'"\[\]]+)\s+as\s+(?:\$(\w+)\s*=>\s*)?\$(\w+)\}([\s\S]*?)\{\/foreach\}/';
        while (preg_match($pattern, $source)) {
            $source = preg_replace_callback(
                $pattern,
                function (array $m) use ($scope) {
                    $list = $this->lookup($m[1], $scope);
                    if (!is_array($list)) {
                        return '';
                    }
                    $keyName = $m[2];
                    $valueName = $m[3];
                    $body = $m[4];
                    $out = '';
                    foreach ($list as $key => $value) {
                        $inner = $scope;
                        $inner[$valueName] = $value;
                        if ($keyName !== '') {
                            $inner[$keyName] = $key;
                        }
                        $out .= $this->renderString($body, $inner);
                    }
                    return $out;
                },
                $source
            );
        }

        // 4. {if cond} … [{else} …] {/if}
        //
        // This must be nesting-aware. A lazy regex pairs the FIRST {if} with
        // the FIRST {/if}, so with an outer {if $ch247Switcher} wrapping an
        // inner {if …direction eq 'rtl'}, the outer block closed early and
        // silently dropped the rest of the template. So: find each {if}, scan
        // forward for its matching {/if} counting depth, resolve the body
        // recursively (which resolves any nested conditionals first), then
        // splice and restart. Each pass removes exactly one {if}/{/if} pair,
        // so the recursion terminates.
        $source = $this->resolveConditionals($source, $scope);

        // 5. {$var|modifier:args}
        return preg_replace_callback(
            '/\{\$([A-Za-z0-9_]+(?:(?:\.[A-Za-z0-9_]+)|(?:\[\'[^\']+\'\]))*)((?:\|[^}]*)?)\}/',
            function (array $m) use ($scope) {
                $value = $this->lookup('$' . $m[1], $scope);
                $modifiers = isset($m[2]) ? trim($m[2]) : '';

                return $this->applyModifiers($value, $modifiers);
            },
            $source
        );
    }

    /**
     * Resolve every {if}/{else}/{/if} block, innermost-first.
     *
     * @param string $source
     * @param array  $scope
     *
     * @return string
     */
    private function resolveConditionals(string $source, array $scope): string
    {
        $start = strpos($source, '{if ');
        if ($start === false) {
            return $source;
        }

        // Scan forward for the {/if} that closes THIS {if}, tracking depth and
        // noting a directly-owned {else}.
        $depth = 0;
        $elseAt = null;
        $endAt = null;
        $length = strlen($source);
        $i = $start;
        while ($i < $length) {
            if (substr($source, $i, 4) === '{if ') {
                $depth++;
                $i += 4;
                continue;
            }
            if (substr($source, $i, 5) === '{/if}') {
                $depth--;
                if ($depth === 0) {
                    $endAt = $i;
                    break;
                }
                $i += 5;
                continue;
            }
            if ($depth === 1 && substr($source, $i, 7) === '{else}') {
                $elseAt = $i;
                $i += 7;
                continue;
            }
            $i++;
        }

        // Unbalanced: leave the source alone rather than guessing.
        if ($endAt === null) {
            return $source;
        }

        $conditionEnd = strpos($source, '}', $start);
        if ($conditionEnd === false) {
            return $source;
        }
        $condition = substr($source, $start + 4, $conditionEnd - ($start + 4));

        if ($elseAt !== null) {
            $thenBody = substr($source, $conditionEnd + 1, $elseAt - ($conditionEnd + 1));
            $elseBody = substr($source, $elseAt + 7, $endAt - ($elseAt + 7));
        } else {
            $thenBody = substr($source, $conditionEnd + 1, $endAt - ($conditionEnd + 1));
            $elseBody = '';
        }

        // renderString resolves anything nested inside the chosen branch.
        $chosen = $this->evaluateCondition($condition, $scope)
            ? $this->renderString($thenBody, $scope)
            : $this->renderString($elseBody, $scope);

        $rebuilt = substr($source, 0, $start) . $chosen . substr($source, $endAt + 5);

        return $this->resolveConditionals($rebuilt, $scope);
    }

    /**
     * Resolve `$foo.bar['baz']` against the scope.
     *
     * @param string $expression
     * @param array  $scope
     *
     * @return mixed
     */
    private function lookup(string $expression, array $scope)
    {
        $expression = ltrim($expression, '$');
        if ($expression === '') {
            return null;
        }

        // Split on . and ['x'] while keeping the pieces.
        preg_match_all('/\w+|\[\'[^\']+\'\]/', $expression, $matches);
        $parts = array();
        foreach ($matches[0] as $part) {
            if (strpos($part, "['") === 0) {
                $parts[] = substr($part, 2, -2);
            } else {
                $parts[] = $part;
            }
        }

        $value = isset($scope[$parts[0]]) ? $scope[$parts[0]] : null;
        $count = count($parts);
        for ($i = 1; $i < $count; $i++) {
            $key = $parts[$i];
            if (is_array($value) && array_key_exists($key, $value)) {
                $value = $value[$key];
            } elseif (is_object($value) && isset($value->$key)) {
                $value = $value->$key;
            } else {
                return null;
            }
        }

        return $value;
    }

    /**
     * @param string $condition
     * @param array  $scope
     *
     * @return bool
     */
    private function evaluateCondition(string $condition, array $scope): bool
    {
        $php = $condition;
        $php = preg_replace('/\b(count)\s*\(/', 'count(', $php);
        $php = str_replace(array(' eq ', ' neq ', ' gt ', ' lt ', ' ge ', ' le '), array(' == ', ' != ', ' > ', ' < ', ' >= ', ' <= '), $php);
        // Resolve every $foo.bar reference to a literal for a safe eval.
        $php = preg_replace_callback(
            '/\$[A-Za-z0-9_]+(?:(?:\.[A-Za-z0-9_]+)|(?:\[\'[^\']+\'\]))*|\$template/',
            function (array $m) use ($scope) {
                $token = $m[0];
                if ($token === '$template') {
                    return var_export('', true);
                }
                return var_export($this->lookup($token, $scope), true);
            },
            $php
        );

        // Anything still variable-shaped means we could not resolve it.
        if (preg_match('/\$[A-Za-z0-9_]/', $php)) {
            return false;
        }

        $result = false;
        // The condition now contains only literals and operators.
        // phpcs:ignore Squiz.PHP.Eval.Discouraged
        eval('$result = (bool) (' . $php . ');');

        return $result;
    }

    /**
     * @param mixed  $value
     * @param string $modifiers e.g. "|escape:'html':'UTF-8'|replace:'{name}':$foo"
     *
     * @return string
     */
    private function applyModifiers($value, string $modifiers): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        if ($modifiers === '') {
            return $text;
        }

        foreach (explode('|', ltrim($modifiers, '|')) as $modifier) {
            if ($modifier === '') {
                continue;
            }
            $bits = $this->splitArguments($modifier);
            $name = array_shift($bits);

            if ($name === 'escape') {
                $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            } elseif ($name === 'replace') {
                $from = isset($bits[0]) ? trim($bits[0], "'\"") : '';
                $to = isset($bits[1]) ? trim($bits[1], "'\"") : '';
                $text = str_replace($from, $to, $text);
            } elseif ($name === 'default') {
                if ($text === '') {
                    $text = isset($bits[0]) ? trim($bits[0], "'\"") : '';
                }
            }
        }

        return $text;
    }

    /**
     * Split a modifier's arguments on colons that are not inside quotes.
     *
     * @param string $modifier
     *
     * @return array
     */
    private function splitArguments(string $modifier): array
    {
        $out = array();
        $buffer = '';
        $quote = null;
        $length = strlen($modifier);
        for ($i = 0; $i < $length; $i++) {
            $char = $modifier[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                $buffer .= $char;
                continue;
            }
            if ($char === "'" || $char === '"') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ':') {
                $out[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }
        $out[] = $buffer;

        return $out;
    }
}
