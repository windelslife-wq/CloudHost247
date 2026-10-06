<?php
/**
 * Cross-reference gate.
 *
 * The load gate (lint.php) proves every file parses and every class resolves.
 * It does not prove that `Foo::bar()` actually exists — PHP only finds that
 * out at the moment of the call, which in a send path means at 2am on a live
 * campaign. This walks the token stream of every shipped file, resolves each
 * `Class::member` against the file's namespace and use-statements, and fails
 * the build when the target is missing.
 *
 * Only Ch247Mkt\ classes are checked; WHMCS and SPL symbols are out of scope.
 */

require_once dirname(__DIR__) . '/autoload.php';

$moduleRoot = dirname(__DIR__);

$files = [];
foreach ([$moduleRoot . '/lib', $moduleRoot . '/install', $moduleRoot . '/cron'] as $root) {
    if (!is_dir($root)) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (substr($file->getPathname(), -4) === '.php') {
            $files[] = $file->getPathname();
        }
    }
}
foreach (glob($moduleRoot . '/*.php') as $path) {
    $files[] = $path;
}
sort($files);

/** Load every class so reflection sees it. */
foreach ($files as $file) {
    $short = substr($file, strlen($moduleRoot) + 1);
    if (strpos($short, 'lib/') !== 0) {
        continue;
    }
    require_once $file;
}

$problems = [];
$checked = 0;

foreach ($files as $file) {
    $short = substr($file, strlen($moduleRoot) + 1);
    $source = file_get_contents($file);
    $tokens = token_get_all($source);

    $namespace = '';
    $aliases = [];
    $count = count($tokens);

    // Pass 1: namespace + use statements.
    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            continue;
        }
        if ($token[0] === T_NAMESPACE) {
            $namespace = ch247m_read_name($tokens, $i);
        } elseif ($token[0] === T_USE && ch247m_is_top_level_use($tokens, $i)) {
            $name = ch247m_read_name($tokens, $i);
            if ($name === '') {
                continue;
            }
            $alias = substr($name, strrpos($name, '\\') === false ? 0 : strrpos($name, '\\') + 1);
            // "use A\B as C"
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_AS) {
                    $alias = ch247m_read_name($tokens, $j);
                    break;
                }
                if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                    break;
                }
            }
            $aliases[$alias] = ltrim($name, '\\');
        }
    }

    // Pass 2: every Name::member.
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_DOUBLE_COLON) {
            continue;
        }
        // Walk back over the class name (may be a qualified name).
        $left = '';
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $left = $t[1] . $left;
                continue;
            }
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                break;
            }
            break;
        }
        if ($left === '' || in_array($left, ['self', 'static', 'parent'], true)) {
            continue;
        }

        // Resolve to a FQCN.
        $fqcn = ch247m_resolve($left, $namespace, $aliases);
        if (strpos($fqcn, 'Ch247Mkt\\') !== 0) {
            continue;
        }

        // Right-hand member.
        $right = null;
        for ($j = $i + 1; $j < $count; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                continue;
            }
            if (is_array($t) && in_array($t[0], [T_STRING, T_CLASS], true)) {
                $right = $t[1];
            }
            break;
        }
        if ($right === null || strtolower($right) === 'class') {
            continue;
        }

        $checked++;
        if (!class_exists($fqcn) && !interface_exists($fqcn)) {
            $problems[] = $short . ': unknown class ' . $fqcn;
            continue;
        }
        if (method_exists($fqcn, $right)) {
            continue;
        }
        if (defined($fqcn . '::' . $right)) {
            continue;
        }
        if (property_exists($fqcn, $right)) {
            continue;
        }
        $problems[] = $short . ': ' . $fqcn . '::' . $right . ' does not exist';
    }
}

/* ----------------------------------------------------------- helpers -- */

function ch247m_read_name(array $tokens, $start)
{
    $name = '';
    $count = count($tokens);
    for ($i = $start + 1; $i < $count; $i++) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_WHITESPACE) {
            if ($name !== '') {
                break;
            }
            continue;
        }
        if (is_array($t) && in_array($t[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $name .= $t[1];
            continue;
        }
        break;
    }
    return trim($name, '\\');
}

/** A `use` inside a class body is a trait import; inside a closure a binding. */
function ch247m_is_top_level_use(array $tokens, $index)
{
    for ($i = $index - 1; $i >= 0; $i--) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_WHITESPACE) {
            continue;
        }
        return $t === ';' || $t === '{' || $t === '}' || (is_array($t) && $t[0] === T_OPEN_TAG);
    }
    return true;
}

function ch247m_resolve($name, $namespace, array $aliases)
{
    if (strpos($name, '\\') === 0) {
        return ltrim($name, '\\');
    }
    $head = strpos($name, '\\') === false ? $name : substr($name, 0, strpos($name, '\\'));
    if (isset($aliases[$head])) {
        $rest = substr($name, strlen($head));
        return $aliases[$head] . $rest;
    }
    if ($namespace !== '') {
        return $namespace . '\\' . $name;
    }
    return $name;
}

$unique = array_values(array_unique($problems));
sort($unique);

echo 'CHECKED=' . $checked . "\n";
echo 'BAD=' . count($unique) . "\n";
foreach ($unique as $line) {
    echo $line . "\n";
}
exit(count($unique) > 0 ? 1 : 0);
