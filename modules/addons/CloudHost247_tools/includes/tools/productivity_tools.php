<?php
/**
 * CloudHost247 Tools - Productivity Tools Implementation
 */

if (!defined("WHMCS") && !defined("CLOUDHOST247_TOOLS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/../functions.php';

function CloudHost247_tool_qr_generator($post)
{
    $text = CloudHost247_tools_sanitize($post['text'] ?? '', 'string');
    $size = min(max((int) ($post['size'] ?? 300), 100), 1000);

    if (empty($text)) {
        return ['error' => 'Please enter text or URL for QR code'];
    }

    // Use Google Chart API for QR generation
    $apiUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=' . $size . 'x' . $size . '&chl=' . urlencode($text) . '&chld=H|0';

    return [
        'text' => $text,
        'qr_url' => $apiUrl,
        'size' => $size,
    ];
}

function CloudHost247_tool_qr_scanner($post)
{
    // QR Scanner is JS-based on frontend, backend just returns success
    return ['message' => 'QR Scanner requires camera access. Use the frontend scanner.'];
}

function CloudHost247_tool_lorem_ipsum($post)
{
    $count = min(max((int) ($post['count'] ?? 5), 1), 50);
    $type = CloudHost247_tools_sanitize($post['type'] ?? 'paragraphs', 'string');

    $words = ['lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit', 'sed', 'do',
        'eiusmod', 'tempor', 'incididunt', 'ut', 'labore', 'et', 'dolore', 'magna', 'aliqua', 'ut', 'enim',
        'ad', 'minim', 'veniam', 'quis', 'nostrud', 'exercitation', 'ullamco', 'laboris', 'nisi', 'ut',
        'aliquip', 'ex', 'ea', 'commodo', 'consequat', 'duis', 'aute', 'irure', 'in', 'reprehenderit',
        'voluptate', 'velit', 'esse', 'cillum', 'fugiat', 'nulla', 'pariatur', 'excepteur', 'sint',
        'occaecat', 'cupidatat', 'non', 'proident', 'sunt', 'culpa', 'qui', 'officia', 'deserunt',
        'mollit', 'anim', 'id', 'est', 'laborum'];

    $output = [];

    if ($type === 'paragraphs') {
        for ($p = 0; $p < $count; $p++) {
            $sentences = mt_rand(3, 8);
            $paragraph = '';
            for ($s = 0; $s < $sentences; $s++) {
                $sentenceWords = mt_rand(8, 20);
                $sentence = '';
                for ($w = 0; $w < $sentenceWords; $w++) {
                    $sentence .= $words[array_rand($words)] . ' ';
                }
                $sentence = ucfirst(trim($sentence)) . '.';
                $paragraph .= $sentence . ' ';
            }
            $output[] = trim($paragraph);
        }
    } elseif ($type === 'sentences') {
        for ($s = 0; $s < $count; $s++) {
            $sentenceWords = mt_rand(8, 20);
            $sentence = '';
            for ($w = 0; $w < $sentenceWords; $w++) {
                $sentence .= $words[array_rand($words)] . ' ';
            }
            $output[] = ucfirst(trim($sentence)) . '.';
        }
    } elseif ($type === 'words') {
        $wordList = [];
        for ($w = 0; $w < $count; $w++) {
            $wordList[] = $words[array_rand($words)];
        }
        $output[] = implode(' ', $wordList);
    }

    return ['type' => $type, 'count' => $count, 'content' => $output];
}

function CloudHost247_tool_time_calculator($post)
{
    $start = CloudHost247_tools_sanitize($post['start'] ?? '', 'string');
    $end = CloudHost247_tools_sanitize($post['end'] ?? '', 'string');
    $format = CloudHost247_tools_sanitize($post['format'] ?? 'hours', 'string');

    $startTime = strtotime($start);
    $endTime = strtotime($end);

    if ($startTime === false || $endTime === false) {
        return ['error' => 'Invalid date format. Use YYYY-MM-DD HH:MM'];
    }

    $diff = abs($endTime - $startTime);

    $results = [
        'seconds' => $diff,
        'minutes' => round($diff / 60, 2),
        'hours' => round($diff / 3600, 2),
        'days' => round($diff / 86400, 2),
        'weeks' => round($diff / 604800, 2),
        'months' => round($diff / 2592000, 2),
        'years' => round($diff / 31536000, 2),
    ];

    return [
        'start' => date('Y-m-d H:i:s', $startTime),
        'end' => date('Y-m-d H:i:s', $endTime),
        'difference' => $results[$format] ?? $results['hours'],
        'all_units' => $results,
    ];
}

function CloudHost247_tool_bin_checker($post)
{
    $bin = CloudHost247_tools_sanitize($post['bin'] ?? '', 'string');
    $bin = preg_replace('/[^0-9]/', '', $bin);

    if (strlen($bin) < 6) {
        return ['error' => 'BIN must be at least 6 digits'];
    }

    $bin6 = substr($bin, 0, 6);

    // Small built-in BIN database
    $bins = [
        '4' => ['network' => 'Visa', 'type' => 'Credit/Debit'],
        '5' => ['network' => 'Mastercard', 'type' => 'Credit/Debit'],
        '37' => ['network' => 'American Express', 'type' => 'Credit'],
        '34' => ['network' => 'American Express', 'type' => 'Credit'],
        '6011' => ['network' => 'Discover', 'type' => 'Credit'],
        '622' => ['network' => 'China UnionPay', 'type' => 'Credit/Debit'],
        '35' => ['network' => 'JCB', 'type' => 'Credit'],
        '30' => ['network' => 'Diners Club', 'type' => 'Credit'],
        '36' => ['network' => 'Diners Club', 'type' => 'Credit'],
        '38' => ['network' => 'Diners Club', 'type' => 'Credit'],
    ];

    $network = 'Unknown';
    $type = 'Unknown';

    foreach ($bins as $prefix => $data) {
        if (strpos($bin6, $prefix) === 0) {
            $network = $data['network'];
            $type = $data['type'];
            break;
        }
    }

    // Try external API
    $apiResult = CloudHost247_tools_curl("https://lookup.binlist.net/{$bin6}", null, [], 10);
    if ($apiResult['code'] === 200) {
        $data = json_decode($apiResult['body'], true);
        if ($data) {
            $network = $data['scheme'] ?? $network;
            $type = $data['type'] ?? $type;
            $bank = $data['bank']['name'] ?? 'Unknown';
            $country = $data['country']['name'] ?? 'Unknown';
        }
    }

    return [
        'bin' => $bin6,
        'network' => ucfirst($network),
        'type' => ucfirst($type),
        'bank' => $bank ?? 'Unknown',
        'country' => $country ?? 'Unknown',
    ];
}

function CloudHost247_tool_credit_card_validator($post)
{
    $number = CloudHost247_tools_sanitize($post['number'] ?? '', 'string');
    $number = preg_replace('/[^0-9]/', '', $number);

    if (empty($number)) {
        return ['error' => 'Please enter a card number'];
    }

    // Luhn algorithm
    $sum = 0;
    $alt = false;
    for ($i = strlen($number) - 1; $i >= 0; $i--) {
        $n = (int) $number[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) $n -= 9;
        }
        $sum += $n;
        $alt = !$alt;
    }

    $valid = $sum % 10 === 0;
    $cardType = 'Unknown';

    if (preg_match('/^4/', $number)) $cardType = 'Visa';
    elseif (preg_match('/^5[1-5]/', $number)) $cardType = 'Mastercard';
    elseif (preg_match('/^3[47]/', $number)) $cardType = 'American Express';
    elseif (preg_match('/^6011/', $number)) $cardType = 'Discover';
    elseif (preg_match('/^35/', $number)) $cardType = 'JCB';
    elseif (preg_match('/^3[068]/', $number)) $cardType = 'Diners Club';

    return [
        'valid' => $valid,
        'luhn_passed' => $valid,
        'card_type' => $cardType,
        'length' => strlen($number),
        'number_masked' => substr($number, 0, 4) . ' **** **** ' . substr($number, -4),
    ];
}

function CloudHost247_tool_reverse_image_search($post)
{
    $url = CloudHost247_tools_sanitize($post['url'] ?? '', 'url');
    if (!CloudHost247_tools_validate_url($url)) {
        return ['error' => 'Invalid image URL'];
    }

    $engines = [
        ['name' => 'Google Images', 'url' => 'https://www.google.com/searchbyimage?image_url=' . urlencode($url)],
        ['name' => 'TinEye', 'url' => 'https://tineye.com/search?url=' . urlencode($url)],
        ['name' => 'Bing Visual Search', 'url' => 'https://www.bing.com/images/search?view=detailv2&iss=sbi&FORM=SBIVSP&selectedindex=0&mediaurl=' . urlencode($url)],
        ['name' => 'Yandex Images', 'url' => 'https://yandex.com/images/search?rpt=imageview&url=' . urlencode($url)],
    ];

    return [
        'image_url' => $url,
        'engines' => $engines,
    ];
}

function CloudHost247_tool_username_checker($post)
{
    $username = CloudHost247_tools_sanitize($post['username'] ?? '', 'string');
    if (empty($username) || strlen($username) < 3) {
        return ['error' => 'Username must be at least 3 characters'];
    }

    // Simulate checking common platforms
    $platforms = [
        ['name' => 'Twitter/X', 'url' => 'https://twitter.com/' . $username, 'available' => mt_rand(0, 1) === 1],
        ['name' => 'Instagram', 'url' => 'https://instagram.com/' . $username, 'available' => mt_rand(0, 1) === 1],
        ['name' => 'GitHub', 'url' => 'https://github.com/' . $username, 'available' => mt_rand(0, 1) === 1],
        ['name' => 'Reddit', 'url' => 'https://reddit.com/user/' . $username, 'available' => mt_rand(0, 1) === 1],
        ['name' => 'TikTok', 'url' => 'https://tiktok.com/@' . $username, 'available' => mt_rand(0, 1) === 1],
    ];

    // Note: Real availability checking would require APIs or scraping
    return [
        'username' => $username,
        'note' => 'These are simulated results for demonstration. Real username checking requires platform APIs.',
        'platforms' => $platforms,
    ];
}

function CloudHost247_tool_online_notepad($post)
{
    $content = $_POST['content'] ?? '';
    $action = CloudHost247_tools_sanitize($post['notepad_action'] ?? 'save', 'string');

    if ($action === 'save') {
        // Store in session for temporary persistence
        $_SESSION['CloudHost247_notepad'] = $content;
        return ['saved' => true, 'length' => strlen($content), 'words' => str_word_count($content)];
    } else {
        $content = $_SESSION['CloudHost247_notepad'] ?? '';
        return ['content' => $content, 'length' => strlen($content), 'words' => str_word_count($content)];
    }
}

function CloudHost247_tool_small_text($post)
{
    $text = CloudHost247_tools_sanitize($post['text'] ?? '', 'string');
    if (empty($text)) {
        return ['error' => 'Please enter text'];
    }

    $smallCaps = '';
    $superscript = '';
    $subscript = '';
    $tiny = '';

    $smallMap = [
        'a' => 'ᴀ', 'b' => 'ʙ', 'c' => 'ᴄ', 'd' => 'ᴅ', 'e' => 'ᴇ', 'f' => 'ғ', 'g' => 'ɢ',
        'h' => 'ʜ', 'i' => 'ɪ', 'j' => 'ᴊ', 'k' => 'ᴋ', 'l' => 'ʟ', 'm' => 'ᴍ', 'n' => 'ɴ',
        'o' => 'ᴏ', 'p' => 'ᴘ', 'q' => 'q', 'r' => 'ʀ', 's' => 's', 't' => 'ᴛ', 'u' => 'ᴜ',
        'v' => 'ᴠ', 'w' => 'ᴡ', 'x' => 'x', 'y' => 'ʏ', 'z' => 'ᴢ',
        'A' => 'ᴀ', 'B' => 'ʙ', 'C' => 'ᴄ', 'D' => 'ᴅ', 'E' => 'ᴇ', 'F' => 'ғ', 'G' => 'ɢ',
        'H' => 'ʜ', 'I' => 'ɪ', 'J' => 'ᴊ', 'K' => 'ᴋ', 'L' => 'ʟ', 'M' => 'ᴍ', 'N' => 'ɴ',
        'O' => 'ᴏ', 'P' => 'ᴘ', 'Q' => 'Q', 'R' => 'ʀ', 'S' => 'S', 'T' => 'ᴛ', 'U' => 'ᴜ',
        'V' => 'ᴠ', 'W' => 'ᴡ', 'X' => 'X', 'Y' => 'ʏ', 'Z' => 'ᴢ',
    ];

    $tinyMap = [
        'a' => 'ₐ', 'b' => 'b', 'c' => 'c', 'd' => 'd', 'e' => 'ₑ', 'f' => 'f', 'g' => 'g',
        'h' => 'ₕ', 'i' => 'ᵢ', 'j' => 'ⱼ', 'k' => 'ₖ', 'l' => 'ₗ', 'm' => 'ₘ', 'n' => 'ₙ',
        'o' => 'ₒ', 'p' => 'ₚ', 'q' => 'q', 'r' => 'ᵣ', 's' => 'ₛ', 't' => 'ₜ', 'u' => 'ᵤ',
        'v' => 'ᵥ', 'w' => 'w', 'x' => 'ₓ', 'y' => 'y', 'z' => 'z',
    ];

    for ($i = 0; $i < strlen($text); $i++) {
        $char = $text[$i];
        $smallCaps .= $smallMap[$char] ?? $char;
        $tiny .= $tinyMap[$char] ?? $char;
        $superscript .= '^' . $char;
        $subscript .= '_' . $char;
    }

    return [
        'original' => $text,
        'small_caps' => $smallCaps,
        'tiny' => $tiny,
        'superscript' => $superscript,
        'subscript' => $subscript,
    ];
}

function CloudHost247_tool_word_counter($post)
{
    $text = $_POST['text'] ?? '';
    $characters = strlen($text);
    $charactersNoSpaces = strlen(preg_replace('/\s/', '', $text));
    $words = str_word_count($text);
    $lines = substr_count($text, "\n") + 1;
    $sentences = preg_match_all('/[.!?]+/', $text, $matches) ? count($matches[0]) : 0;
    $paragraphs = count(array_filter(explode("\n\n", $text)));

    return [
        'characters' => $characters,
        'characters_no_spaces' => $charactersNoSpaces,
        'words' => $words,
        'sentences' => $sentences,
        'paragraphs' => $paragraphs,
        'lines' => $lines,
        'average_word_length' => $words > 0 ? round($charactersNoSpaces / $words, 1) : 0,
    ];
}

function CloudHost247_tool_domain_availability($post)
{
    $domain = CloudHost247_tools_sanitize($post['domain'] ?? '', 'domain');
    if (!CloudHost247_tools_validate_domain($domain)) {
        return ['error' => 'Invalid domain format'];
    }

    $tld = substr(strrchr($domain, '.'), 1);
    $whois = CloudHost247_tools_whois($domain);
    $result = $whois['result'] ?? '';

    $available = stripos($result, 'No match') !== false ||
        stripos($result, 'NOT FOUND') !== false ||
        stripos($result, 'not found') !== false ||
        stripos($result, 'No entries found') !== false ||
        stripos($result, 'Domain Status: free') !== false;

    return [
        'domain' => $domain,
        'available' => $available,
        'tld' => $tld,
        'whois_server' => $whois['server'] ?? 'Unknown',
    ];
}

function CloudHost247_tool_rot13($post)
{
    $text = $_POST['text'] ?? '';
    $mode = CloudHost247_tools_sanitize($post['mode'] ?? 'encode', 'string');

    if (empty($text)) {
        return ['error' => 'Please enter text'];
    }

    $result = str_rot13($text);
    return ['original' => $text, 'result' => $result, 'mode' => $mode];
}

function CloudHost247_tool_morse_code($post)
{
    $text = CloudHost247_tools_sanitize($post['text'] ?? '', 'string');
    $mode = CloudHost247_tools_sanitize($post['mode'] ?? 'encode', 'string');

    if (empty($text)) {
        return ['error' => 'Please enter text'];
    }

    $morse = [
        'A' => '.-', 'B' => '-...', 'C' => '-.-.', 'D' => '-..', 'E' => '.', 'F' => '..-.',
        'G' => '--.', 'H' => '....', 'I' => '..', 'J' => '.---', 'K' => '-.-', 'L' => '.-..',
        'M' => '--', 'N' => '-.', 'O' => '---', 'P' => '.--.', 'Q' => '--.-', 'R' => '.-.',
        'S' => '...', 'T' => '-', 'U' => '..-', 'V' => '...-', 'W' => '.--', 'X' => '-..-',
        'Y' => '-.--', 'Z' => '--..', '1' => '.----', '2' => '..---', '3' => '...--',
        '4' => '....-', '5' => '.....', '6' => '-....', '7' => '--...', '8' => '---..',
        '9' => '----.', '0' => '-----', ' ' => ' / ', '.' => '.-.-.-', ',' => '--..--',
        '?' => '..--..', '!' => '-.-.--', '/' => '-..-.', '@' => '.--.-.',
    ];

    $reverse = array_flip($morse);

    if ($mode === 'encode') {
        $result = '';
        $upper = strtoupper($text);
        for ($i = 0; $i < strlen($upper); $i++) {
            $char = $upper[$i];
            $result .= ($morse[$char] ?? $char) . ' ';
        }
        return ['original' => $text, 'morse' => trim($result), 'mode' => 'encode'];
    } else {
        $result = '';
        $words = explode(' / ', $text);
        foreach ($words as $word) {
            $chars = explode(' ', trim($word));
            foreach ($chars as $char) {
                $result .= $reverse[trim($char)] ?? '?';
            }
            $result .= ' ';
        }
        return ['original' => $text, 'decoded' => trim($result), 'mode' => 'decode'];
    }
}

function CloudHost247_tool_bimi_checker($post)
{
    $domain = CloudHost247_tools_sanitize($post['domain'] ?? '', 'domain');
    if (!CloudHost247_tools_validate_domain($domain)) {
        return ['error' => 'Invalid domain'];
    }

    $records = CloudHost247_tools_dns_query('default._bimi.' . $domain, 'TXT');
    $found = false;
    $record = '';

    foreach ($records as $r) {
        $txt = $r['txt'] ?? '';
        if (stripos($txt, 'v=BIMI1') !== false) {
            $found = true;
            $record = $txt;
            break;
        }
    }

    return [
        'domain' => $domain,
        'found' => $found,
        'record' => $record,
    ];
}

function CloudHost247_tool_image_to_text($post)
{
    return [
        'note' => 'OCR functionality requires a third-party OCR API key configured in module settings.',
        'status' => 'placeholder',
        'instructions' => 'Upload an image to extract text using OCR technology.',
    ];
}

// ---------------------------------------------------------------------
//  Runic translator
// ---------------------------------------------------------------------

/**
 * Transliteration maps for the supported runic alphabets.
 */
function CloudHost247_tools_runic_alphabets()
{
    return [
        'elder_futhark' => [
            'name' => 'Elder Futhark',
            'era'  => 'c. 150-800 CE',
            'map'  => [
                'th' => 'ᚦ', 'ng' => 'ᛜ', 'ei' => 'ᛇ',
                'f' => 'ᚠ', 'u' => 'ᚢ', 'a' => 'ᚨ', 'r' => 'ᚱ', 'k' => 'ᚲ',
                'g' => 'ᚷ', 'w' => 'ᚹ', 'h' => 'ᚺ', 'n' => 'ᚾ', 'i' => 'ᛁ',
                'j' => 'ᛃ', 'p' => 'ᛈ', 'z' => 'ᛉ', 's' => 'ᛊ', 't' => 'ᛏ',
                'b' => 'ᛒ', 'e' => 'ᛖ', 'm' => 'ᛗ', 'l' => 'ᛚ', 'd' => 'ᛞ',
                'o' => 'ᛟ',
                'c' => 'ᚲ', 'q' => 'ᚲ', 'v' => 'ᚹ', 'x' => 'ᚲᛊ', 'y' => 'ᛁ',
            ],
        ],
        'younger_futhark' => [
            'name' => 'Younger Futhark (long-branch)',
            'era'  => 'c. 800-1100 CE',
            'map'  => [
                'th' => 'ᚦ',
                'f' => 'ᚠ', 'u' => 'ᚢ', 'a' => 'ᚬ', 'r' => 'ᚱ', 'k' => 'ᚴ',
                'h' => 'ᚼ', 'n' => 'ᚾ', 'i' => 'ᛁ', 's' => 'ᛋ', 't' => 'ᛏ',
                'b' => 'ᛒ', 'm' => 'ᛘ', 'l' => 'ᛚ', 'y' => 'ᛦ',
                'c' => 'ᚴ', 'g' => 'ᚴ', 'q' => 'ᚴ', 'd' => 'ᛏ', 'e' => 'ᛁ',
                'o' => 'ᚢ', 'p' => 'ᛒ', 'v' => 'ᚠ', 'w' => 'ᚢ', 'x' => 'ᚴᛋ',
                'z' => 'ᛋ', 'j' => 'ᛁ',
            ],
        ],
        'anglo_saxon' => [
            'name' => 'Anglo-Saxon Futhorc',
            'era'  => 'c. 400-1100 CE',
            'map'  => [
                'th' => 'ᚦ', 'ng' => 'ᛝ', 'ae' => 'ᚫ', 'ea' => 'ᛠ', 'oe' => 'ᛟ',
                'st' => 'ᛥ', 'io' => 'ᛡ',
                'f' => 'ᚠ', 'u' => 'ᚢ', 'o' => 'ᚩ', 'r' => 'ᚱ', 'c' => 'ᚳ',
                'g' => 'ᚷ', 'w' => 'ᚹ', 'h' => 'ᚻ', 'n' => 'ᚾ', 'i' => 'ᛁ',
                'j' => 'ᛄ', 'p' => 'ᛈ', 'x' => 'ᛉ', 's' => 'ᛋ', 't' => 'ᛏ',
                'b' => 'ᛒ', 'e' => 'ᛖ', 'm' => 'ᛗ', 'l' => 'ᛚ', 'd' => 'ᛞ',
                'a' => 'ᚪ', 'y' => 'ᚣ', 'k' => 'ᚳ', 'q' => 'ᚳᚹ', 'v' => 'ᚠ',
                'z' => 'ᛋ',
            ],
        ],
    ];
}

/**
 * Runic Translator - transliterate Latin text to runes and back.
 */
function CloudHost247_tool_runic_translator($post)
{
    $text     = (string) ($post['text'] ?? '');
    $alphabet = (string) ($post['alphabet'] ?? 'elder_futhark');
    $mode     = strtolower((string) ($post['mode'] ?? 'encode'));

    if (trim($text) === '') {
        return ['error' => 'Please enter some text to transliterate.'];
    }
    if (mb_strlen($text) > 10000) {
        return ['error' => 'Input is too long (limit 10,000 characters).'];
    }

    $alphabets = CloudHost247_tools_runic_alphabets();
    if (!isset($alphabets[$alphabet])) {
        return ['error' => 'Unknown runic alphabet. Choose Elder Futhark, Younger Futhark or Anglo-Saxon Futhorc.'];
    }
    $set = $alphabets[$alphabet];

    if ($mode === 'decode') {
        // Build a reverse map, preferring the longest rune sequences first.
        $reverse = [];
        foreach ($set['map'] as $latin => $rune) {
            if (!isset($reverse[$rune])) {
                $reverse[$rune] = $latin;
            }
        }
        uksort($reverse, function ($a, $b) {
            return mb_strlen($b) <=> mb_strlen($a);
        });

        $out = $text;
        foreach ($reverse as $rune => $latin) {
            $out = str_replace($rune, $latin, $out);
        }
        // Runic word separators.
        $out = str_replace(['᛫', '᛬', '᛭'], [' ', ' ', ' '], $out);

        return [
            'mode'     => 'decode',
            'alphabet' => $set['name'],
            'input'    => $text,
            'result'   => $out,
            'note'     => 'Runic alphabets have fewer letters than the Latin alphabet, so several Latin letters share one rune. Decoding is therefore approximate and cannot always recover the original spelling.',
        ];
    }

    $lower = mb_strtolower($text, 'UTF-8');
    $map   = $set['map'];

    // Longest-first so digraphs such as "th" win over "t" + "h".
    $keys = array_keys($map);
    usort($keys, function ($a, $b) {
        return strlen($b) <=> strlen($a);
    });

    $result = '';
    $used   = [];
    $i      = 0;
    $len    = mb_strlen($lower, 'UTF-8');

    while ($i < $len) {
        $matched = false;
        foreach ($keys as $key) {
            $klen = mb_strlen($key, 'UTF-8');
            if ($klen > 0 && mb_substr($lower, $i, $klen, 'UTF-8') === $key) {
                $result .= $map[$key];
                $used[$key] = $map[$key];
                $i += $klen;
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $char = mb_substr($lower, $i, 1, 'UTF-8');
            // Runes have no digits or punctuation; preserve them as-is.
            $result .= ($char === ' ') ? '᛫' : $char;
            $i++;
        }
    }

    return [
        'mode'      => 'encode',
        'alphabet'  => $set['name'],
        'era'       => $set['era'],
        'input'     => $text,
        'result'    => $result,
        'plain'     => str_replace('᛫', ' ', $result),
        'runes_used'=> $used,
        'note'      => 'This is a transliteration, not a translation: the sounds of your text are written with runic letters, the language stays the same. Historical runic writing used ᛫ as a word divider rather than a space, and had no distinct upper and lower case.',
    ];
}

// ---------------------------------------------------------------------
//  Invisible characters
// ---------------------------------------------------------------------

/**
 * Invisible Character generator / detector.
 */
function CloudHost247_tool_invisible_character($post)
{
    $catalogue = [
        ['name' => 'Zero Width Space',            'code' => 'U+200B', 'char' => "\u{200B}", 'use' => 'Allows a line break without a visible gap.'],
        ['name' => 'Zero Width Non-Joiner',       'code' => 'U+200C', 'char' => "\u{200C}", 'use' => 'Prevents two characters forming a ligature.'],
        ['name' => 'Zero Width Joiner',           'code' => 'U+200D', 'char' => "\u{200D}", 'use' => 'Joins characters - used to build emoji sequences.'],
        ['name' => 'Word Joiner',                 'code' => 'U+2060', 'char' => "\u{2060}", 'use' => 'Prevents a line break, with zero width.'],
        ['name' => 'Hangul Filler',               'code' => 'U+3164', 'char' => "\u{3164}", 'use' => 'Renders blank in most fonts; often used as an "empty" name.'],
        ['name' => 'Braille Pattern Blank',       'code' => 'U+2800', 'char' => "\u{2800}", 'use' => 'A Braille cell with no raised dots; appears blank.'],
        ['name' => 'Non-Breaking Space',          'code' => 'U+00A0', 'char' => "\u{00A0}", 'use' => 'A space that never breaks across lines.'],
        ['name' => 'Narrow No-Break Space',       'code' => 'U+202F', 'char' => "\u{202F}", 'use' => 'A narrow space that never breaks.'],
        ['name' => 'En Quad',                     'code' => 'U+2000', 'char' => "\u{2000}", 'use' => 'Fixed-width space equal to 1 en.'],
        ['name' => 'Em Quad',                     'code' => 'U+2001', 'char' => "\u{2001}", 'use' => 'Fixed-width space equal to 1 em.'],
        ['name' => 'Three-Per-Em Space',          'code' => 'U+2004', 'char' => "\u{2004}", 'use' => 'One third of an em.'],
        ['name' => 'Figure Space',                'code' => 'U+2007', 'char' => "\u{2007}", 'use' => 'As wide as a digit; aligns numbers in tables.'],
        ['name' => 'Invisible Separator',         'code' => 'U+2063', 'char' => "\u{2063}", 'use' => 'Mathematical invisible comma.'],
        ['name' => 'Invisible Times',             'code' => 'U+2062', 'char' => "\u{2062}", 'use' => 'Mathematical invisible multiplication sign.'],
        ['name' => 'Left-to-Right Mark',          'code' => 'U+200E', 'char' => "\u{200E}", 'use' => 'Forces left-to-right text direction.'],
        ['name' => 'Right-to-Left Mark',          'code' => 'U+200F', 'char' => "\u{200F}", 'use' => 'Forces right-to-left text direction.'],
        ['name' => 'Soft Hyphen',                 'code' => 'U+00AD', 'char' => "\u{00AD}", 'use' => 'Hyphen shown only if the word wraps there.'],
        ['name' => 'Mongolian Vowel Separator',   'code' => 'U+180E', 'char' => "\u{180E}", 'use' => 'Zero-width in modern Unicode.'],
    ];

    $mode = strtolower((string) ($post['mode'] ?? 'generate'));

    if ($mode === 'detect') {
        $text = (string) ($post['text'] ?? '');
        if ($text === '') {
            return ['error' => 'Please paste some text to scan for invisible characters.'];
        }
        if (strlen($text) > 200000) {
            return ['error' => 'Input is too long (limit 200,000 characters).'];
        }

        $byCode = [];
        foreach ($catalogue as $entry) {
            $byCode[$entry['char']] = $entry;
        }

        $found = [];
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $index => $char) {
            if (!isset($byCode[$char])) {
                continue;
            }
            $code = $byCode[$char]['code'];
            if (!isset($found[$code])) {
                $found[$code] = [
                    'name'      => $byCode[$char]['name'],
                    'code'      => $code,
                    'count'     => 0,
                    'positions' => [],
                ];
            }
            $found[$code]['count']++;
            if (count($found[$code]['positions']) < 50) {
                $found[$code]['positions'][] = $index;
            }
        }

        $cleaned = str_replace(array_keys($byCode), '', $text);

        return [
            'mode'        => 'detect',
            'total_chars' => count($chars),
            'found'       => array_values($found),
            'hidden_count'=> array_sum(array_column($found, 'count')),
            'cleaned'     => $cleaned,
            'is_clean'    => empty($found),
            'note'        => empty($found)
                ? 'No invisible characters were found in this text.'
                : 'Invisible characters are often introduced by copying from web pages or word processors, and can break string comparisons, usernames and CSV imports. A cleaned copy is provided above.',
        ];
    }

    // Generate
    $code  = strtoupper(trim((string) ($post['character'] ?? 'U+200B')));
    $count = (int) ($post['count'] ?? 1);
    if ($count < 1 || $count > 1000) {
        return ['error' => 'Choose between 1 and 1000 characters.'];
    }

    $selected = null;
    foreach ($catalogue as $entry) {
        if ($entry['code'] === $code) {
            $selected = $entry;
            break;
        }
    }
    if (!$selected) {
        return ['error' => 'Unknown character code. Pick one from the list.'];
    }

    return [
        'mode'      => 'generate',
        'character' => $selected['name'],
        'code'      => $selected['code'],
        'count'     => $count,
        'result'    => str_repeat($selected['char'], $count),
        'html'      => str_repeat('&#x' . substr($selected['code'], 2) . ';', $count),
        'bytes'     => strlen(str_repeat($selected['char'], $count)),
        'catalogue' => $catalogue,
        'use'       => $selected['use'],
        'note'      => 'Copy the result with the copy button - selecting it by hand will not work, because there is nothing visible to select. Many platforms strip or reject invisible characters, and using them to evade moderation usually violates the platform terms of service.',
    ];
}

// ---------------------------------------------------------------------
//  Wi-Fi QR
// ---------------------------------------------------------------------

/**
 * Wi-Fi QR Scanner / Builder - parse and build WIFI: URIs.
 *
 * Decoding of the image itself happens in the browser; this handler parses
 * and builds the WIFI: payload. Wi-Fi passwords are never logged.
 */
function CloudHost247_tool_wifi_qr_scanner($post)
{
    $mode = strtolower((string) ($post['mode'] ?? 'parse'));

    $escape = function ($value) {
        return str_replace(['\\', ';', ',', ':', '"'], ['\\\\', '\\;', '\\,', '\\:', '\\"'], (string) $value);
    };

    if ($mode === 'build') {
        $ssid = (string) ($post['ssid'] ?? '');
        if (trim($ssid) === '') {
            return ['error' => 'Please enter the network name (SSID).'];
        }
        if (strlen($ssid) > 32) {
            return ['error' => 'An SSID cannot be longer than 32 bytes.'];
        }

        $auth = strtoupper((string) ($post['auth'] ?? 'WPA'));
        if (!in_array($auth, ['WPA', 'WEP', 'NOPASS', 'WPA2-EAP'], true)) {
            return ['error' => 'Security type must be WPA, WEP, WPA2-EAP or NOPASS.'];
        }

        $password = (string) ($post['password'] ?? '');
        if ($auth !== 'NOPASS' && $password === '') {
            return ['error' => 'Please enter the network password, or choose "Open network".'];
        }
        if ($auth === 'WPA' && $password !== '' && (strlen($password) < 8 || strlen($password) > 63)) {
            return ['error' => 'A WPA/WPA2 passphrase must be between 8 and 63 characters.'];
        }

        $hidden = !empty($post['hidden']);

        $uri = 'WIFI:T:' . $auth . ';S:' . $escape($ssid) . ';';
        if ($auth !== 'NOPASS') {
            $uri .= 'P:' . $escape($password) . ';';
        }
        if ($hidden) {
            $uri .= 'H:true;';
        }
        $uri .= ';';

        return [
            'mode'     => 'build',
            'ssid'     => $ssid,
            'auth'     => $auth,
            'hidden'   => $hidden,
            'uri'      => $uri,
            'note'     => 'Render this payload as a QR code and guests can join by pointing a camera at it. CloudHost247 does not store or log the password — the QR image is drawn in your browser.',
            'security' => 'Anyone who can photograph the code gets your Wi-Fi password. For public spaces, use a guest network with a separate passphrase.',
        ];
    }

    // Parse
    $uri = trim((string) ($post['uri'] ?? $post['text'] ?? ''));
    if ($uri === '') {
        return ['error' => 'Scan a QR code or paste its WIFI: payload.'];
    }
    if (stripos($uri, 'WIFI:') !== 0) {
        return ['error' => 'That is not a Wi-Fi QR payload. Wi-Fi codes start with "WIFI:".'];
    }
    if (strlen($uri) > 1024) {
        return ['error' => 'That payload is too long to be a valid Wi-Fi QR code.'];
    }

    $body   = substr($uri, 5);
    $fields = [];
    $buffer = '';
    $len    = strlen($body);
    for ($i = 0; $i < $len; $i++) {
        $char = $body[$i];
        if ($char === '\\' && $i + 1 < $len) {
            $buffer .= $body[$i + 1];
            $i++;
            continue;
        }
        if ($char === ';') {
            if ($buffer !== '') {
                $fields[] = $buffer;
            }
            $buffer = '';
            continue;
        }
        $buffer .= $char;
    }
    if ($buffer !== '') {
        $fields[] = $buffer;
    }

    $parsed = [];
    foreach ($fields as $field) {
        $pos = strpos($field, ':');
        if ($pos === false) {
            continue;
        }
        $parsed[strtoupper(substr($field, 0, $pos))] = substr($field, $pos + 1);
    }

    if (empty($parsed['S'])) {
        return ['error' => 'The payload does not contain a network name (SSID).'];
    }

    $auth = strtoupper($parsed['T'] ?? 'NOPASS');
    $labels = [
        'WPA'      => 'WPA/WPA2 Personal',
        'WPA2-EAP' => 'WPA2 Enterprise (EAP)',
        'WEP'      => 'WEP (insecure - deprecated)',
        'NOPASS'   => 'Open network (no encryption)',
    ];

    $warnings = [];
    if ($auth === 'WEP') {
        $warnings[] = 'WEP can be broken in minutes with freely available tools. Move this network to WPA2 or WPA3.';
    }
    if ($auth === 'NOPASS') {
        $warnings[] = 'This network is unencrypted. Traffic on it can be read by anyone nearby.';
    }
    if (!empty($parsed['P']) && strlen($parsed['P']) < 12 && $auth === 'WPA') {
        $warnings[] = 'The passphrase is short. A 12+ character passphrase resists offline cracking far better.';
    }

    return [
        'mode'      => 'parse',
        'ssid'      => $parsed['S'],
        'auth'      => $auth,
        'auth_label'=> $labels[$auth] ?? $auth,
        'password'  => $parsed['P'] ?? '',
        'hidden'    => isset($parsed['H']) && strtolower($parsed['H']) === 'true',
        'warnings'  => $warnings,
        'note'      => 'Decoded entirely from the payload you supplied. CloudHost247 never stores or logs Wi-Fi credentials.',
    ];
}

// ---------------------------------------------------------------------
//  Connection speed test
// ---------------------------------------------------------------------

/**
 * Speed Test - server side of the browser-driven measurement.
 *
 * The browser performs the actual timing against these endpoints. No
 * numbers are invented: if a measurement cannot be taken, it is reported
 * as unavailable.
 *
 *   action=config    -> parameters for the browser to run the test
 *   action=download  -> emits N bytes of incompressible random data
 *   action=upload    -> accepts a payload and reports what was received
 *   action=ping      -> minimal response for latency/jitter sampling
 */
function CloudHost247_tool_speed_test($post)
{
    $action = strtolower((string) ($post['action'] ?? 'config'));

    switch ($action) {
        case 'ping':
            return [
                'action'      => 'ping',
                'server_time' => round(microtime(true) * 1000, 3),
                'sequence'    => (int) ($post['sequence'] ?? 0),
            ];

        case 'download':
            $size = (int) ($post['size'] ?? 1048576);
            $size = max(65536, min(26214400, $size)); // 64 KB - 25 MB

            return [
                'action'      => 'download',
                'size'        => $size,
                'stream'      => true,
                'server_time' => round(microtime(true) * 1000, 3),
                'note'        => 'The browser requests this payload and times the transfer. The data is random and incompressible so compression cannot distort the result.',
            ];

        case 'upload':
            $received = isset($post['payload']) ? strlen((string) $post['payload']) : 0;
            if ($received === 0 && isset($_SERVER['CONTENT_LENGTH'])) {
                $received = (int) $_SERVER['CONTENT_LENGTH'];
            }

            return [
                'action'        => 'upload',
                'bytes_received'=> $received,
                'server_time'   => round(microtime(true) * 1000, 3),
            ];

        case 'config':
        default:
            return [
                'action'       => 'config',
                'client_ip'    => CloudHost247ToolsSecurity::clientIp(),
                'server_time'  => round(microtime(true) * 1000, 3),
                'phases'       => [
                    'latency'  => ['samples' => 10, 'endpoint' => '/tools/api/speed-test?action=ping'],
                    'download' => ['sizes' => [262144, 1048576, 5242880], 'endpoint' => '/tools/api/speed-test?action=download'],
                    'upload'   => ['sizes' => [262144, 1048576], 'endpoint' => '/tools/api/speed-test?action=upload'],
                ],
                'max_duration' => 20,
                'note'         => 'This measures the connection between your browser and this CloudHost247 server only. It is a single-server diagnostic, not a substitute for a multi-server speed test, and your result depends on distance to this server, current server load and the route between you.',
                'disclaimer'   => 'Results are indicative. CloudHost247 reports exactly what was measured and never substitutes estimated or cached figures.',
            ];
    }
}
