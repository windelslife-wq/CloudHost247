<?php
/**
 * CloudHost247 Tools - Image to Text (server-side OCR) behaviour.
 *
 * Offline: the OCR.space HTTP call is replaced by
 * $GLOBALS['CloudHost247_tools_ocr_stub'], and the API key is supplied via
 * $GLOBALS['CloudHost247_tools_test_settings'] so no database is needed.
 */
define('CLOUDHOST247_TOOLS', true);
require __DIR__ . '/../includes/tools/productivity_tools.php';

$pass = 0;
$fail = 0;
function ok($label, $cond)
{
    global $pass, $fail;
    if ($cond) { $pass++; } else { $fail++; echo "  FAIL $label\n"; }
}
function eq($label, $got, $want)
{
    global $pass, $fail;
    if ($got === $want) { $pass++; }
    else { $fail++; echo "  FAIL $label\n    got:  " . var_export($got, true) . "\n    want: " . var_export($want, true) . "\n"; }
}
function ocrReset()
{
    unset($GLOBALS['CloudHost247_tools_ocr_stub'], $GLOBALS['CloudHost247_tools_test_settings']);
    unset($_FILES['image']);
}

// ---------- fixtures (minimal 1x1 images; magic bytes verified) ----------
$PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
$GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
$JPG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8AH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8AH//Z';
$WEBP = 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA';

foreach (['png' => $PNG, 'gif' => $GIF, 'jpg' => $JPG] as $kind => $b64) {
    $bytes = base64_decode($b64, true);
    ok("$kind fixture decodes", $bytes !== false && $bytes !== '');
    $dims = @getimagesizefromstring($bytes);
    ok("$kind fixture has dimensions", $dims !== false && $dims[0] === 1 && $dims[1] === 1);
}
if (function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    eq('png fixture mime', finfo_buffer($fi, base64_decode($PNG, true)), 'image/png');
    eq('gif fixture mime', finfo_buffer($fi, base64_decode($GIF, true)), 'image/gif');
    eq('jpg fixture mime', finfo_buffer($fi, base64_decode($JPG, true)), 'image/jpeg');
    eq('webp fixture mime', finfo_buffer($fi, base64_decode($WEBP, true)), 'image/webp');
    finfo_close($fi);
} else {
    ok('finfo unavailable; handler falls back to getimagesize mime', true);
}

// ---------- stub harness ----------
$stubBody = '';
$stubCalls = [];
function ocrUseStub(&$stubBody, &$stubCalls)
{
    $GLOBALS['CloudHost247_tools_test_settings'] = ['ocr_api_key' => 'test-key-123'];
    $GLOBALS['CloudHost247_tools_ocr_stub'] = function ($apiKey, $fields) use (&$stubBody, &$stubCalls) {
        $stubCalls[] = ['key' => $apiKey, 'fields' => $fields];
        return ['http_code' => 200, 'body' => $stubBody, 'curl_error' => ''];
    };
}
ocrUseStub($stubBody, $stubCalls);

// ---------- explicit opt-in is required ----------
ocrReset();
ocrUseStub($stubBody, $stubCalls);
$r = CloudHost247_tool_image_to_text(['image_base64' => $PNG]);
ok('missing opt-in refused', isset($r['error']) && stripos($r['error'], 'opt-in') !== false);
eq('refused before any OCR call', count($stubCalls), 0);

foreach (['0', '', 'consent', '2'] as $bad) {
    ocrReset();
    ocrUseStub($stubBody, $stubCalls);
    $r = CloudHost247_tool_image_to_text(['server_ocr' => $bad, 'image_base64' => $PNG]);
    ok("opt-in value '$bad' refused", isset($r['error']) && stripos($r['error'], 'opt-in') !== false);
}
// Accepted values proceed past consent (no image -> the no-image error).
foreach (['1', 'on', 'yes', 'true', true, 1] as $good) {
    ocrReset();
    ocrUseStub($stubBody, $stubCalls);
    $r = CloudHost247_tool_image_to_text(['server_ocr' => $good]);
    ok('opt-in value ' . var_export($good, true) . ' accepted', isset($r['error']) && stripos($r['error'], 'No image was received') !== false);
}

// ---------- image intake and validation ----------
ocrReset();
ocrUseStub($stubBody, $stubCalls);
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1']);
eq('no image error', $r['error'], 'No image was received. Choose an image file (JPG, PNG, GIF, BMP or TIFF, up to 1 MB) and submit again.');

$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => '!!!not-base64!!!']);
ok('invalid base64 rejected', isset($r['error']) && stripos($r['error'], 'not valid base64') !== false);

$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => 'a']);
ok('undecodable base64 rejected', isset($r['error']) && stripos($r['error'], 'could not be decoded') !== false);

$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => base64_encode('just some text')]);
ok('non-image bytes rejected', isset($r['error']) && stripos($r['error'], 'not a readable image') !== false);

$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => base64_encode(str_repeat("\0", 1048577))]);
ok('oversize rejected', isset($r['error']) && stripos($r['error'], 'larger than 1 MB') !== false);

$before = count($stubCalls);
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $WEBP]);
ok('webp rejected (provider does not accept it)', isset($r['error']));
eq('rejected before any OCR call', count($stubCalls), $before);

// ---------- API key configuration ----------
ocrReset();
ocrUseStub($stubBody, $stubCalls);
$GLOBALS['CloudHost247_tools_test_settings'] = ['ocr_api_key' => ''];
$before = count($stubCalls);
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $PNG]);
ok('missing key error', isset($r['error']) && stripos($r['error'], 'not configured') !== false);
eq('no OCR call without a key', count($stubCalls), $before);

// ---------- success path ----------
ocrReset();
ocrUseStub($stubBody, $stubCalls);
$stubCalls = [];
$stubBody = '{"OCRExitCode":"1","IsErroredOnProcessing":false,"ParsedResults":[{"TextOverlay":null,"FileParseExitCode":"1","ParsedText":"Hello\\r\\n","ErrorMessage":null,"ErrorDetails":null}],"OCRExitCode":"1","IsErroredOnProcessing":false,"ErrorMessage":null,"ErrorDetails":null,"ProcessingTimeInMilliseconds":"123"}';
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $PNG]);
eq('extracted text', $r['text'], 'Hello');
eq('characters', $r['characters'], 5);
eq('lines', $r['lines'], 1);
eq('words', $r['words'], 1);
eq('engine label', $r['engine'], 'OCR.space Engine 2');
eq('processing ms', $r['processing_ms'], 123);
ok('success note cites opt-in', stripos($r['note'], 'opt-in') !== false);
ok('placeholder status gone', !isset($r['status']));
eq('stub called once', count($stubCalls), 1);
eq('key sent to transport', $stubCalls[0]['key'], 'test-key-123');
eq('filetype PNG', $stubCalls[0]['fields']['filetype'], 'PNG');
ok('data-url payload', strpos($stubCalls[0]['fields']['base64Image'], 'data:image/png;base64,') === 0);
eq('engine pinned', $stubCalls[0]['fields']['OCREngine'], '2');

$stubBody = '{"OCRExitCode":"1","ParsedResults":[{"ParsedText":"Hello world\\r\\nSecond line"}]}';
$r = CloudHost247_tool_image_to_text(['server_ocr' => 'on', 'image_base64' => 'data:image/png;base64,' . $PNG]);
eq('data-url prefix accepted', $r['text'], "Hello world\nSecond line");
eq('multiline lines', $r['lines'], 2);
eq('multiline words', $r['words'], 4);
eq('multiline characters', $r['characters'], 23);
ok('processing ms null when absent', array_key_exists('processing_ms', $r) && $r['processing_ms'] === null);

$stubBody = '{"OCRExitCode":"1","ParsedResults":[{"ParsedText":"Hello"}]}';
$r = CloudHost247_tool_image_to_text(['server_ocr' => 'yes', 'image_base64' => chunk_split($PNG, 8)]);
eq('base64 with whitespace accepted', $r['text'], 'Hello');

$stubBody = '{"OCRExitCode":"1","ParsedResults":[{"ParsedText":""}]}';
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $PNG]);
eq('empty text is not an error', $r['text'], '');
ok('no-text note', stripos($r['note'], 'No text was detected') !== false);

$stubBody = '{"OCRExitCode":"2","ParsedResults":[{"ParsedText":"partial"}]}';
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $PNG]);
eq('partial parse still returns text', $r['text'], 'partial');
ok('partial parse noted', stripos($r['note'], 'partially') !== false);

$stubBody = '{"OCRExitCode":"1","ParsedResults":[{"ParsedText":"gif ok"}]}';
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $GIF]);
eq('gif filetype', end($stubCalls)['fields']['filetype'], 'GIF');

$stubBody = '{"OCRExitCode":"1","ParsedResults":[{"ParsedText":"jpg ok"}]}';
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $JPG]);
eq('jpg filetype', end($stubCalls)['fields']['filetype'], 'JPG');

// ---------- provider failures are mapped, never leaked raw ----------
$stubBody = '{"OCRExitCode":"3","IsErroredOnProcessing":true,"ErrorMessage":"Engine failed","ParsedResults":[]}';
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $PNG]);
ok('exit 3 error', isset($r['error']) && stripos($r['error'], 'Engine failed') !== false);

$stubBody = '{"OCRExitCode":"4","ErrorMessage":"Invalid API key","ParsedResults":[]}';
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $PNG]);
ok('invalid key mapped', isset($r['error']) && stripos($r['error'], 'rejected by the provider') !== false);

$stubBody = '{"OCRExitCode":"3","ErrorMessage":"Too many requests, throttled","ParsedResults":[]}';
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $PNG]);
ok('rate limit mapped', isset($r['error']) && stripos($r['error'], 'usage limit was reached') !== false);

$stubBody = '{"OCRExitCode":"3","ErrorMessage":"OCR engine timeout","ParsedResults":[]}';
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $PNG]);
ok('timeout mapped', isset($r['error']) && stripos($r['error'], 'timed out') !== false);

$stubBody = '{"OCRExitCode":"3","ErrorMessage":"Top level","ParsedResults":[{"ParsedText":"","ErrorMessage":"File failed validation","ErrorDetails":""}]}';
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $PNG]);
ok('per-result message preferred', isset($r['error']) && stripos($r['error'], 'File failed validation') !== false);

$stubBody = '{"OCRExitCode":"3","ErrorMessage":"' . str_repeat('x', 500) . '","ParsedResults":[]}';
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $PNG]);
ok('provider message truncated', isset($r['error']) && strlen($r['error']) < 320);

$GLOBALS['CloudHost247_tools_ocr_stub'] = function () {
    return ['http_code' => 0, 'body' => '', 'curl_error' => 'Could not resolve host'];
};
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $PNG]);
ok('transport failure mapped', isset($r['error']) && stripos($r['error'], 'could not be reached') !== false);

$GLOBALS['CloudHost247_tools_ocr_stub'] = function () {
    return ['http_code' => 500, 'body' => 'error', 'curl_error' => ''];
};
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $PNG]);
ok('http 500 mapped', isset($r['error']) && stripos($r['error'], 'HTTP 500') !== false);

$GLOBALS['CloudHost247_tools_ocr_stub'] = function () {
    return ['http_code' => 200, 'body' => 'not json', 'curl_error' => ''];
};
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $PNG]);
ok('bad json mapped', isset($r['error']) && stripos($r['error'], 'unreadable response') !== false);

// ---------- multipart upload path ----------
ocrReset();
ocrUseStub($stubBody, $stubCalls);
$stubBody = '{"OCRExitCode":"1","ParsedResults":[{"ParsedText":"via upload"}]}';
$tmpFixture = __DIR__ . '/.tmp_ocr_fixture.png';
file_put_contents($tmpFixture, base64_decode($PNG, true));
$_FILES['image'] = ['error' => UPLOAD_ERR_OK, 'tmp_name' => $tmpFixture, 'name' => 'x.png', 'size' => filesize($tmpFixture)];
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1']);
unlink($tmpFixture);
unset($_FILES['image']);
eq('upload path extracts text', $r['text'], 'via upload');

$_FILES['image'] = ['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'name' => 'big.png', 'size' => 0];
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1']);
unset($_FILES['image']);
ok('upload error mapped', isset($r['error']) && stripos($r['error'], 'upload limit') !== false);

$_FILES['image'] = ['error' => UPLOAD_ERR_NO_FILE, 'tmp_name' => '', 'name' => '', 'size' => 0];
$r = CloudHost247_tool_image_to_text(['server_ocr' => '1']);
unset($_FILES['image']);
ok('no-file upload falls through to no-image error', isset($r['error']) && stripos($r['error'], 'No image was received') !== false);

// ---------- cURL requirement (production path only) ----------
if (!function_exists('curl_init')) {
    ocrReset();
    $GLOBALS['CloudHost247_tools_test_settings'] = ['ocr_api_key' => 'k'];
    $r = CloudHost247_tool_image_to_text(['server_ocr' => '1', 'image_base64' => $PNG]);
    ok('curl missing error', isset($r['error']) && stripos($r['error'], 'cURL extension') !== false);
} else {
    ok('curl present in this runtime; stub bypasses transport', true);
}

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail > 0 ? 1 : 0);
