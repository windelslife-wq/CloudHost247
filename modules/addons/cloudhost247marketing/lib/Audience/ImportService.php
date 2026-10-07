<?php
/**
 * Bulk subscriber import and export.
 *
 * CSV and TSV are parsed natively. XLSX is a ZIP of XML and would need a
 * spreadsheet library this repository does not vendor — rather than ship a
 * half-working parser, the UI asks for "Save as CSV" and says why.
 *
 * Import is chunk-safe and idempotent: re-running the same file updates the
 * same subscribers instead of duplicating them, and suppressed addresses are
 * reported as skipped rather than quietly re-added.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Audience;

use Ch247Mkt\Core\Audit;
use Ch247Mkt\Core\Str;
use Ch247Mkt\Core\ValidationException;
use Ch247Mkt\Delivery\ComplianceService;

class ImportService
{
    public const MAX_ROWS = 100000;

    /** Header aliases we recognise without the operator mapping anything. */
    public const ALIASES = [
        'email'      => ['email', 'email address', 'e-mail', 'mail', 'emailaddress'],
        'first_name' => ['first name', 'firstname', 'first', 'given name', 'fname'],
        'last_name'  => ['last name', 'lastname', 'last', 'surname', 'family name', 'lname'],
        'company'    => ['company', 'company name', 'organisation', 'organization', 'business'],
        'client_id'  => ['client id', 'clientid', 'whmcs id', 'user id', 'userid'],
        'tags'       => ['tags', 'tag', 'labels'],
    ];

    /**
     * Parse a delimited file into header + rows.
     *
     * @return array{headers:string[],rows:array[],delimiter:string}
     */
    public static function parse($path, $maxRows = self::MAX_ROWS)
    {
        if (!is_readable($path)) {
            throw new ValidationException('That upload could not be read. Try again.');
        }
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new ValidationException('That upload could not be opened.');
        }

        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            throw new ValidationException('That file is empty.');
        }
        // Strip a UTF-8 BOM so the first header is not "\xEF\xBB\xBFemail".
        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
        $delimiter = self::sniffDelimiter($firstLine);
        rewind($handle);

        $headers = [];
        $rows = [];
        $lineNo = 0;
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $lineNo++;
            if ($lineNo === 1) {
                $headers = array_map(function ($h) {
                    return trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h));
                }, $data);
                continue;
            }
            if ($data === [null] || (count($data) === 1 && trim((string) $data[0]) === '')) {
                continue; // blank line
            }
            $rows[] = $data;
            if (count($rows) >= $maxRows) {
                break;
            }
        }
        fclose($handle);

        if ($headers === []) {
            throw new ValidationException('No header row was found in that file.');
        }
        return ['headers' => $headers, 'rows' => $rows, 'delimiter' => $delimiter];
    }

    /** Guess the column mapping from the header row. */
    public static function suggestMapping(array $headers)
    {
        $map = [];
        foreach ($headers as $index => $header) {
            $needle = strtolower(trim((string) $header));
            foreach (self::ALIASES as $field => $aliases) {
                if (in_array($needle, $aliases, true) && !in_array($field, $map, true)) {
                    $map[$index] = $field;
                    continue 2;
                }
            }
        }
        return $map;
    }

    /**
     * Import parsed rows.
     *
     * @param array $rows    raw rows from parse()
     * @param array $mapping column index => subscriber field (or custom:<name>)
     * @param array $options lists[], consent_source, status, actor_id, dry_run
     * @return array{imported:int,updated:int,skipped:int,invalid:int,suppressed:int,errors:array}
     */
    public static function import(array $rows, array $mapping, array $options = [])
    {
        $emailColumn = array_search('email', $mapping, true);
        if ($emailColumn === false) {
            throw new ValidationException('Map one column to "Email address" before importing.', ['email' => 'required']);
        }

        $consentSource = Str::clip($options['consent_source'] ?? '', 60);
        if ($consentSource === '') {
            throw new ValidationException('Record where this permission came from (e.g. "signup form", "existing customers") before importing.', ['consent_source' => 'required']);
        }

        $dryRun = !empty($options['dry_run']);
        $lists = array_map('intval', (array) ($options['lists'] ?? []));
        $status = in_array($options['status'] ?? '', SubscriberService::STATUSES, true)
            ? $options['status']
            : SubscriberService::STATUS_SUBSCRIBED;

        $result = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'invalid' => 0, 'suppressed' => 0, 'errors' => []];
        $seen = [];

        foreach ($rows as $lineNo => $row) {
            $email = Str::normalizeEmail($row[$emailColumn] ?? '');
            if (!Str::isEmail($email)) {
                $result['invalid']++;
                if (count($result['errors']) < 25) {
                    $result['errors'][] = 'Row ' . ($lineNo + 2) . ': "' . Str::clip($row[$emailColumn] ?? '', 40) . '" is not a valid email address.';
                }
                continue;
            }
            if (isset($seen[$email])) {
                $result['skipped']++;
                continue;
            }
            $seen[$email] = true;

            if (ComplianceService::isSuppressed($email)) {
                $result['suppressed']++;
                continue;
            }

            $data = [
                'email'          => $email,
                'status'         => $status,
                'consent_source' => $consentSource,
                'custom_fields'  => [],
                'tags'           => [],
            ];
            foreach ($mapping as $index => $field) {
                if ($field === 'email' || $field === '' || !array_key_exists($index, $row)) {
                    continue;
                }
                $value = trim((string) $row[$index]);
                if (strpos($field, 'custom:') === 0) {
                    $key = Str::slug(substr($field, 7), 40);
                    if ($key !== '' && $value !== '') {
                        $data['custom_fields'][str_replace('-', '_', $key)] = Str::clip($value, 500);
                    }
                    continue;
                }
                if ($field === 'tags') {
                    $data['tags'] = SubscriberService::normalizeTags($value);
                    continue;
                }
                if ($field === 'client_id') {
                    $data['client_id'] = (int) $value;
                    continue;
                }
                if (in_array($field, ['first_name', 'last_name', 'company'], true)) {
                    $data[$field] = $value;
                }
            }

            if ($dryRun) {
                $result[SubscriberService::findByEmail($email) === null ? 'imported' : 'updated']++;
                continue;
            }

            $existed = SubscriberService::findByEmail($email) !== null;
            try {
                SubscriberService::upsert($data, ['lists' => $lists, 'actor' => 'admin', 'actor_id' => (int) ($options['actor_id'] ?? 0)]);
                $result[$existed ? 'updated' : 'imported']++;
            } catch (\Throwable $e) {
                $result['invalid']++;
                if (count($result['errors']) < 25) {
                    $result['errors'][] = 'Row ' . ($lineNo + 2) . ': ' . $e->getMessage();
                }
            }
        }

        if (!$dryRun) {
            ListService::recountAll();
            Audit::admin((int) ($options['actor_id'] ?? 0), 'subscribers.imported', [
                'imported' => $result['imported'], 'updated' => $result['updated'],
                'invalid' => $result['invalid'], 'suppressed' => $result['suppressed'],
                'consent_source' => $consentSource, 'lists' => $lists,
            ]);
        }
        return $result;
    }

    /** Stream subscribers out as CSV. @return string */
    public static function exportCsv(array $filters = [])
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['email', 'first_name', 'last_name', 'company', 'client_id', 'status', 'tags', 'consent_source', 'consent_at', 'created_at']);
        $page = 1;
        do {
            $result = SubscriberService::search($filters, $page, 500);
            foreach ($result['rows'] as $row) {
                fputcsv($out, [
                    $row['email'], $row['first_name'], $row['last_name'], $row['company'],
                    $row['client_id'], $row['status'], implode(',', (array) $row['tags']),
                    $row['consent_source'], $row['consent_at'], $row['created_at'],
                ]);
            }
            $page++;
        } while ($result['rows'] !== [] && ($page - 1) * 500 < $result['total']);
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return (string) $csv;
    }

    protected static function sniffDelimiter($line)
    {
        $candidates = [',' => substr_count($line, ','), "\t" => substr_count($line, "\t"), ';' => substr_count($line, ';'), '|' => substr_count($line, '|')];
        arsort($candidates);
        $best = key($candidates);
        return $candidates[$best] > 0 ? $best : ',';
    }
}
