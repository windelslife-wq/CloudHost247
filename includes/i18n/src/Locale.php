<?php
/**
 * CloudHost247 — internationalisation & currency.
 *
 * A locale the site can be displayed in. Immutable value object.
 *
 * Locale codes are normalised on construction (`pt_BR`, `pt-br` and `PT-BR`
 * all collapse to `pt-BR`) so a code coming from a URL, a cookie, a WHMCS
 * language file name or a database row can be compared safely.
 *
 * @package    CloudHost247\I18n
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

declare(strict_types=1);

namespace CloudHost247\I18n;

final class Locale
{
    /** Catalog is 100% translated and passes the completeness gate. */
    const STATUS_COMPLETE = 'complete';

    /** Catalog exists but is not finished — never offered in the switcher. */
    const STATUS_PARTIAL = 'partial';

    /** Registered but nothing translated yet. */
    const STATUS_PLANNED = 'planned';

    /** @var string */
    private $code;

    /** @var string */
    private $englishName;

    /** @var string */
    private $nativeName;

    /** @var string */
    private $flagCode;

    /** @var string ltr|rtl */
    private $direction;

    /** @var string One of the STATUS_* constants. */
    private $status;

    /** @var string|null Parent locale code used before the global default. */
    private $fallback;

    /** @var string Identifier understood by PluralRule. */
    private $pluralRuleId;

    /** @var string BCP-47 tag handed to Intl / used for date+number shaping. */
    private $intlTag;

    /**
     * @param string      $code          Canonical code, e.g. "pt-BR".
     * @param string      $englishName   Name in English, e.g. "Portuguese (Brazil)".
     * @param string      $nativeName    Endonym, e.g. "Português (Brasil)".
     * @param string|null $flagCode      Flag sprite suffix, e.g. "br".
     * @param string      $direction     "ltr" or "rtl".
     * @param string      $status        One of the STATUS_* constants.
     * @param string|null $fallback      Parent locale code.
     * @param string|null $pluralRuleId  PluralRule identifier; defaults to the base language.
     * @param string|null $intlTag       BCP-47 tag; defaults to the code.
     */
    public function __construct(
        string $code,
        string $englishName = '',
        string $nativeName = '',
        $flagCode = null,
        string $direction = 'ltr',
        string $status = self::STATUS_PLANNED,
        $fallback = null,
        $pluralRuleId = null,
        $intlTag = null
    ) {
        $this->code = self::normalise($code);
        $this->englishName = $englishName !== '' ? $englishName : $this->code;
        $this->nativeName = $nativeName !== '' ? $nativeName : $this->englishName;
        $this->flagCode = $flagCode !== null && $flagCode !== '' ? strtolower((string) $flagCode) : $this->baseLanguage();
        $this->direction = strtolower($direction) === 'rtl' ? 'rtl' : 'ltr';
        $this->status = in_array($status, self::validStatuses(), true) ? $status : self::STATUS_PLANNED;
        $this->fallback = $fallback !== null && $fallback !== '' ? self::normalise((string) $fallback) : null;
        $this->pluralRuleId = $pluralRuleId !== null && $pluralRuleId !== ''
            ? (string) $pluralRuleId
            : $this->baseLanguage();
        $this->intlTag = $this->expandIntlTag($intlTag);
    }

    /**
     * Build a locale from a catalog file's `meta` block.
     *
     * @param array $meta
     *
     * @return self
     */
    public static function fromCatalogMeta(array $meta)
    {
        return new self(
            isset($meta['locale']) ? (string) $meta['locale'] : '',
            isset($meta['english_name']) ? (string) $meta['english_name'] : '',
            isset($meta['native_name']) ? (string) $meta['native_name'] : '',
            isset($meta['flag']) ? (string) $meta['flag'] : null,
            isset($meta['direction']) ? (string) $meta['direction'] : 'ltr',
            isset($meta['status']) ? (string) $meta['status'] : self::STATUS_PLANNED,
            isset($meta['fallback']) ? (string) $meta['fallback'] : null,
            isset($meta['plural_rule']) ? (string) $meta['plural_rule'] : null,
            isset($meta['intl_tag']) ? (string) $meta['intl_tag'] : null
        );
    }

    /**
     * Canonical form: lower-case language, upper-case region, hyphen separated.
     *
     * @param string $code
     *
     * @return string
     */
    public static function normalise(string $code): string
    {
        $code = trim(str_replace(array('_', ' '), '-', $code));
        if ($code === '') {
            return '';
        }
        $parts = explode('-', $code);
        $language = strtolower(array_shift($parts));
        $rest = array();
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            // Script is Title case (Hans, Latn); region and variant upper case.
            $rest[] = strlen($part) === 4 ? ucfirst(strtolower($part)) : strtoupper($part);
        }

        return $language . ($rest ? '-' . implode('-', $rest) : '');
    }

    /**
     * @return string[]
     */
    public static function validStatuses(): array
    {
        return array(self::STATUS_COMPLETE, self::STATUS_PARTIAL, self::STATUS_PLANNED);
    }

    /**
     * Language subtag only: "pt-BR" -> "pt".
     *
     * @return string
     */
    public function baseLanguage(): string
    {
        $parts = explode('-', $this->code);

        return $parts[0];
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getEnglishName(): string
    {
        return $this->englishName;
    }

    public function getNativeName(): string
    {
        return $this->nativeName;
    }

    /**
     * Readable name for the switcher: endonym first, English in parentheses
     * so a visitor who lands in the wrong language can still find their way.
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        if (strtolower($this->nativeName) === strtolower($this->englishName)) {
            return $this->nativeName;
        }

        return $this->nativeName . ' (' . $this->englishName . ')';
    }

    public function getFlagCode(): string
    {
        return $this->flagCode;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }

    public function isRtl(): bool
    {
        return $this->direction === 'rtl';
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Only complete locales are offered to customers.
     *
     * @return bool
     */
    public function isSelectable(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    public function getFallback(): ?string
    {
        return $this->fallback;
    }

    public function getPluralRuleId(): string
    {
        return $this->pluralRuleId;
    }

    public function getIntlTag(): string
    {
        return $this->intlTag;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array(
            'code' => $this->code,
            'english_name' => $this->englishName,
            'native_name' => $this->nativeName,
            'display_name' => $this->getDisplayName(),
            'flag' => $this->flagCode,
            'direction' => $this->direction,
            'status' => $this->status,
            'selectable' => $this->isSelectable(),
            'fallback' => $this->fallback,
            'intl_tag' => $this->intlTag,
        );
    }

    /**
     * Turn "pt" into "pt-PT" only when we know the region; otherwise keep the
     * tag exactly as the catalog declared it. Guessing a region changes
     * number and date formatting, so we never do it silently.
     *
     * @param string|null $intlTag
     *
     * @return string
     */
    private function expandIntlTag($intlTag): string
    {
        if ($intlTag !== null && $intlTag !== '') {
            return str_replace('_', '-', (string) $intlTag);
        }
        if (strpos($this->code, '-') !== false) {
            return $this->code;
        }

        // Well-known single-region defaults used by the shipped catalogs.
        $known = array(
            'en' => 'en-US',
            'fr' => 'fr-FR',
            'es' => 'es-ES',
            'de' => 'de-DE',
            'pt' => 'pt-PT',
            'it' => 'it-IT',
            'nl' => 'nl-NL',
            'ru' => 'ru-RU',
            'ar' => 'ar-EG',
            'he' => 'he-IL',
            'fa' => 'fa-IR',
            'zh' => 'zh-CN',
            'ko' => 'ko-KR',
            'ja' => 'ja-JP',
            'sv' => 'sv-SE',
            'da' => 'da-DK',
            'nb' => 'nb-NO',
            'tr' => 'tr-TR',
            'pl' => 'pl-PL',
            'cs' => 'cs-CZ',
        );
        $base = $this->baseLanguage();

        return isset($known[$base]) ? $known[$base] : $base;
    }
}
