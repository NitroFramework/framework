<?php

namespace Nitro\Translation;

/**
 * Picks the form of a line that matches a count, in the locale's own terms.
 *
 * English has two forms and a rule anyone can guess: one apple, two apples.
 * Most languages do not. Russian has three and chooses between them by the
 * last digit; Arabic has six; Japanese has one. A translator that assumes
 * "one or more than one" is correct in English and wrong in most places, and
 * wrong in a way nobody reviewing English copy will ever see.
 *
 * So the line is split on the pipe and the form is chosen by index:
 *
 *     'один файл|:count файла|:count файлов'
 *
 * A form may instead name the counts it covers, which wins over the rule:
 *
 *     '{0} nothing|[1,19] some|[20,*] many'
 *
 * The rules below are ported from Laravel's MessageSelector, which is in turn
 * gettext's table. They are data, and {@see PLURAL_RULES} keeps them as data
 * rather than as a switch, so the locale list can be diffed against its source.
 */
class MessageSelector
{
    /**
     * Which rule each locale follows.
     *
     * Keyed by rule number, as used by {@see getPluralIndex()}. A locale not named here
     * falls back to rule 0 — one form — because guessing two would be a
     * confident wrong answer where one is merely incomplete.
     *
     * @var array<int, array<int, string>>
     */
    private const PLURAL_RULES = [
        // One form, whatever the count.
        0 => [
            'az', 'az_AZ', 'bo', 'bo_CN', 'bo_IN', 'dz', 'dz_BT', 'id', 'id_ID', 'ja', 'ja_JP',
            'jv', 'ka', 'ka_GE', 'km', 'km_KH', 'kn', 'kn_IN', 'ko', 'ko_KR', 'ms', 'ms_MY',
            'th', 'th_TH', 'tr', 'tr_CY', 'tr_TR', 'vi', 'vi_VN', 'zh', 'zh_CN', 'zh_HK',
            'zh_SG', 'zh_TW',
        ],

        // Two forms, splitting at one. English and most of Europe.
        1 => [
            'af', 'af_ZA', 'bn', 'bn_BD', 'bn_IN', 'bg', 'bg_BG', 'ca', 'ca_AD', 'ca_ES',
            'ca_FR', 'ca_IT', 'da', 'da_DK', 'de', 'de_AT', 'de_BE', 'de_CH', 'de_DE', 'de_LI',
            'de_LU', 'el', 'el_CY', 'el_GR', 'en', 'en_AG', 'en_AU', 'en_BW', 'en_CA', 'en_DK',
            'en_GB', 'en_HK', 'en_IE', 'en_IN', 'en_NG', 'en_NZ', 'en_PH', 'en_SG', 'en_US',
            'en_ZA', 'en_ZM', 'en_ZW', 'eo', 'eo_US', 'es', 'es_AR', 'es_BO', 'es_CL', 'es_CO',
            'es_CR', 'es_CU', 'es_DO', 'es_EC', 'es_ES', 'es_GT', 'es_HN', 'es_MX', 'es_NI',
            'es_PA', 'es_PE', 'es_PR', 'es_PY', 'es_SV', 'es_US', 'es_UY', 'es_VE', 'et',
            'et_EE', 'eu', 'eu_ES', 'eu_FR', 'fa', 'fa_IR', 'fi', 'fi_FI', 'fo', 'fo_FO', 'fur',
            'fur_IT', 'fy', 'fy_DE', 'fy_NL', 'gl', 'gl_ES', 'gu', 'gu_IN', 'ha', 'ha_NG', 'he',
            'he_IL', 'hu', 'hu_HU', 'is', 'is_IS', 'it', 'it_CH', 'it_IT', 'ku', 'ku_TR', 'lb',
            'lb_LU', 'ml', 'ml_IN', 'mn', 'mn_MN', 'mr', 'mr_IN', 'nah', 'nb', 'nb_NO', 'ne',
            'ne_NP', 'nl', 'nl_AW', 'nl_BE', 'nl_NL', 'nn', 'nn_NO', 'no', 'om', 'om_ET',
            'om_KE', 'or', 'or_IN', 'pa', 'pa_IN', 'pa_PK', 'pap', 'pap_AN', 'pap_AW', 'pap_CW',
            'ps', 'ps_AF', 'pt', 'pt_BR', 'pt_PT', 'so', 'so_DJ', 'so_ET', 'so_KE', 'so_SO',
            'sq', 'sq_AL', 'sq_MK', 'sv', 'sv_FI', 'sv_SE', 'sw', 'sw_KE', 'sw_TZ', 'ta',
            'ta_IN', 'ta_LK', 'te', 'te_IN', 'tk', 'tk_TM', 'ur', 'ur_IN', 'ur_PK', 'zu',
            'zu_ZA',
        ],

        // Two forms, with zero taking the singular. French, Hindi.
        2 => [
            'am', 'am_ET', 'bh', 'fil', 'fil_PH', 'fr', 'fr_BE', 'fr_CA', 'fr_CH', 'fr_FR',
            'fr_LU', 'gun', 'hi', 'hi_IN', 'hy', 'hy_AM', 'ln', 'ln_CD', 'mg', 'mg_MG', 'nso',
            'nso_ZA', 'ti', 'ti_ER', 'ti_ET', 'wa', 'wa_BE', 'xbr',
        ],

        // Three forms by last digit. Russian, Ukrainian, Serbian, Croatian.
        3 => [
            'be', 'be_BY', 'bs', 'bs_BA', 'hr', 'hr_HR', 'ru', 'ru_RU', 'ru_UA', 'sr', 'sr_ME',
            'sr_RS', 'uk', 'uk_UA',
        ],

        // Three forms: one, a few, many. Czech, Slovak.
        4 => ['cs', 'cs_CZ', 'sk', 'sk_SK'],

        // Three forms: one, two, more. Irish.
        5 => ['ga', 'ga_IE'],

        6 => ['lt', 'lt_LT'],
        7 => ['sl', 'sl_SI'],
        8 => ['mk', 'mk_MK'],
        9 => ['mt', 'mt_MT'],
        10 => ['lv', 'lv_LV'],
        11 => ['pl', 'pl_PL'],
        12 => ['cy', 'cy_GB'],
        13 => ['ro', 'ro_RO'],

        // Six forms. Arabic.
        14 => [
            'ar', 'ar_AE', 'ar_BH', 'ar_DZ', 'ar_EG', 'ar_IN', 'ar_IQ', 'ar_JO', 'ar_KW',
            'ar_LB', 'ar_LY', 'ar_MA', 'ar_OM', 'ar_QA', 'ar_SA', 'ar_SD', 'ar_SS', 'ar_SY',
            'ar_TN', 'ar_YE',
        ],
    ];

    /** Locale => rule number, built once from the table above. */
    private static ?array $ruleFor = null;

    /**
     * The form of $line that matches $number.
     *
     * @param string $line   Forms separated by a pipe.
     * @param int|float $number
     */
    public function choose(string $line, int|float $number, string $locale): string
    {
        $segments = explode('|', $line);

        // A form naming its own counts is the translator being explicit, and
        // wins over whatever the language's rule would have chosen.
        if (($explicit = $this->explicit($segments, $number)) !== null) {
            return trim($explicit);
        }

        $segments = array_map(
            static fn (string $part): string
                => (string) preg_replace('/^[\{\[][-?\d|*,\.]*[\}\]]/', '', $part),
            $segments,
        );

        $index = $this->getPluralIndex($locale, $number);

        return count($segments) === 1 || ! isset($segments[$index])
            ? $segments[0]
            : $segments[$index];
    }

    /**
     * Which form a locale uses for this count, as an index into the forms.
     *
     * A locale is tried whole and then by its language, so en_GB follows en
     * even when the table names neither — Laravel lists both, but a locale
     * from outside its list should still behave like its language.
     */
    public function getPluralIndex(string $locale, int|float $number): int
    {
        static::$ruleFor ??= static::buildLookup();

        $rule = static::$ruleFor[$locale]
            ?? static::$ruleFor[strtok($locale, '_-')]
            ?? 0;

        return $this->apply($rule, $number);
    }

    /** @return array<string, int> */
    private static function buildLookup(): array
    {
        $lookup = [];

        foreach (self::PLURAL_RULES as $rule => $locales) {
            foreach ($locales as $locale) {
                $lookup[$locale] = $rule;
            }
        }

        return $lookup;
    }

    /**
     * One rule, applied.
     *
     * Each arm is gettext's expression for that family, kept in the same shape
     * so it can be read against the source it came from.
     */
    private function apply(int $rule, int|float $number): int
    {
        $n = (int) $number;

        return match ($rule) {
            0 => 0,
            1 => $number == 1 ? 0 : 1,
            2 => $number == 0 || $number == 1 ? 0 : 1,
            3 => $n % 10 == 1 && $n % 100 != 11
                ? 0
                : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20) ? 1 : 2),
            4 => $number == 1 ? 0 : ($number >= 2 && $number <= 4 ? 1 : 2),
            5 => $number == 1 ? 0 : ($number == 2 ? 1 : 2),
            6 => $n % 10 == 1 && $n % 100 != 11
                ? 0
                : ($n % 10 >= 2 && ($n % 100 < 10 || $n % 100 >= 20) ? 1 : 2),
            7 => $n % 100 == 1
                ? 0
                : ($n % 100 == 2 ? 1 : ($n % 100 == 3 || $n % 100 == 4 ? 2 : 3)),
            8 => $n % 10 == 1 ? 0 : 1,
            9 => $number == 1
                ? 0
                : ($number == 0 || ($n % 100 > 1 && $n % 100 < 11)
                    ? 1
                    : ($n % 100 > 10 && $n % 100 < 20 ? 2 : 3)),
            10 => $number == 0 ? 0 : ($n % 10 == 1 && $n % 100 != 11 ? 1 : 2),
            11 => $number == 1
                ? 0
                : ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14) ? 1 : 2),
            12 => $number == 1
                ? 0
                : ($number == 2 ? 1 : ($number == 8 || $number == 11 ? 2 : 3)),
            13 => $number == 1
                ? 0
                : ($number == 0 || ($n % 100 > 0 && $n % 100 < 20) ? 1 : 2),
            14 => $number == 0
                ? 0
                : ($number == 1
                    ? 1
                    : ($number == 2
                        ? 2
                        : ($n % 100 >= 3 && $n % 100 <= 10
                            ? 3
                            : ($n % 100 >= 11 && $n % 100 <= 99 ? 4 : 5)))),
            default => 0,
        };
    }

    /**
     * A form that names the counts it covers, when one matches.
     *
     * '{0}', '[1,19]' and '[20,*]' are all accepted, and '*' stands for no
     * bound on that side.
     *
     * @param array<int, string> $segments
     */
    private function explicit(array $segments, int|float $number): ?string
    {
        foreach ($segments as $part) {
            if (preg_match('/^[\{\[]([-?\d|*,\.]*)[\}\]](.*)/s', $part, $matches) !== 1) {
                continue;
            }

            [, $condition, $value] = $matches;

            if (! str_contains($condition, ',')) {
                if ($condition == $number) {
                    return $value;
                }

                continue;
            }

            [$from, $to] = explode(',', $condition, 2);

            // Loose, so '5' from the line compares against 5 from the caller.
            $matched = match (true) {
                $to === '*' => $number >= $from,
                $from === '*' => $number <= $to,
                default => $number >= $from && $number <= $to,
            };

            if ($matched) {
                return $value;
            }
        }

        return null;
    }

    /** Every locale the table names, for a test that wants to walk them. */
    public static function knownLocales(): array
    {
        return array_keys(static::$ruleFor ??= static::buildLookup());
    }
}
