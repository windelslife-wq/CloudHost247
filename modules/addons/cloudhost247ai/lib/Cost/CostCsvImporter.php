<?php
/** Strict, all-or-nothing manual CSV importer for provider invoice totals. */

namespace Ch247Ai\Cost;

use Ch247Ai\Core\Clock;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\ValidationException;

class CostCsvImporter
{
    const MAX_BYTES = 1048576;
    const MAX_ROWS = 500;
    const HEADERS = ['provider', 'invoice_reference', 'period_start', 'period_end', 'amount', 'currency'];

    /**
     * Import invoice-level totals. Duplicate (provider, invoice_reference)
     * rows reject the entire file; no partial writes occur.
     * @return int inserted row count
     */
    public static function import($csv, $adminId)
    {
        $csv = (string) $csv;
        if ($csv === '' || strlen($csv) > self::MAX_BYTES) {
            throw new ValidationException('CSV must be non-empty and no larger than 1 MiB.');
        }
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new ValidationException('Unable to read the uploaded CSV.');
        }
        fwrite($stream, $csv);
        rewind($stream);
        $headers = fgetcsv($stream, 0, ',', '"', '\\');
        if (!is_array($headers)) {
            fclose($stream);
            throw new ValidationException('CSV header row is missing.');
        }
        if (isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
        }
        if ($headers !== self::HEADERS) {
            fclose($stream);
            throw new ValidationException('CSV headers must be exactly: ' . implode(',', self::HEADERS));
        }

        $rows = [];
        $seen = [];
        $line = 1;
        while (($values = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            $line++;
            if ($values === [null] || $values === []) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                fclose($stream);
                throw new ValidationException('CSV may contain at most ' . self::MAX_ROWS . ' data rows.');
            }
            if (count($values) !== count(self::HEADERS)) {
                fclose($stream);
                throw new ValidationException('CSV line ' . $line . ' has the wrong number of columns.');
            }
            $row = array_combine(self::HEADERS, array_map('trim', $values));
            $row = self::validateRow($row, $line);
            $key = strtolower($row['provider']) . "\0" . strtolower($row['invoice_reference']);
            if (isset($seen[$key])) {
                fclose($stream);
                throw new ValidationException('CSV contains a duplicate provider and invoice reference on line ' . $line . '.');
            }
            $seen[$key] = true;
            $rows[] = $row;
        }
        fclose($stream);
        if ($rows === []) {
            throw new ValidationException('CSV contains no cost rows.');
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($rows as $row) {
                $existing = Db::query('SELECT id FROM ' . Db::t('infrastructure_costs')
                    . ' WHERE LOWER(provider) = LOWER(?) AND LOWER(invoice_reference) = LOWER(?) LIMIT 1',
                    [$row['provider'], $row['invoice_reference']]);
                if ($existing !== []) {
                    throw new ValidationException('Invoice ' . $row['invoice_reference'] . ' for provider ' . $row['provider'] . ' was already imported.');
                }
                Db::insert('infrastructure_costs', $row + [
                    'imported_by' => max(0, (int) $adminId),
                    'created_at' => Clock::now(),
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return count($rows);
    }

    private static function validateRow(array $row, $line)
    {
        if ($row['provider'] === '' || strlen($row['provider']) > 96
            || preg_match('/[\x00-\x1F\x7F]/', $row['provider'])) {
            throw new ValidationException('CSV line ' . $line . ' has an invalid provider name.');
        }
        if ($row['invoice_reference'] === '' || strlen($row['invoice_reference']) > 128
            || preg_match('/[\x00-\x1F\x7F]/', $row['invoice_reference'])) {
            throw new ValidationException('CSV line ' . $line . ' has an invalid invoice reference.');
        }
        foreach (['period_start', 'period_end'] as $field) {
            $date = \DateTime::createFromFormat('!Y-m-d', $row[$field]);
            $errors = \DateTime::getLastErrors();
            if ($date === false || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || $date->format('Y-m-d') !== $row[$field]) {
                throw new ValidationException('CSV line ' . $line . ' has an invalid ' . $field . ' date.');
            }
        }
        if ($row['period_start'] > $row['period_end']) {
            throw new ValidationException('CSV line ' . $line . ' has a period ending before it starts.');
        }
        if (!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,4})?$/', $row['amount'])) {
            throw new ValidationException('CSV line ' . $line . ' has an invalid amount. Use a decimal amount with up to four fractional digits.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $row['currency'])) {
            throw new ValidationException('CSV line ' . $line . ' has an invalid currency code.');
        }
        return $row;
    }
}
