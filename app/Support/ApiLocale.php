<?php

namespace App\Support;

use App\Models\Language;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiLocale
{
    public const DEFAULT = 'en';

    public static function supported(): array
    {
        $list = config('locales.supported', [self::DEFAULT]);

        return $list !== [] ? $list : [self::DEFAULT];
    }

    public static function normalize(?string $locale): string
    {
        $locale = strtolower((string) $locale);
        $locale = Str::before($locale, '_');
        $locale = Str::before($locale, '-');

        if ($locale === '') {
            return self::default();
        }

        if (in_array($locale, self::supported(), true)) {
            return $locale;
        }

        // Any active row in `languages` works without editing APP_LOCALES or lang files.
        if (in_array($locale, self::activeLanguageCodes(), true)) {
            return $locale;
        }

        return self::default();
    }

    /**
     * Active language codes from DB (`languages.is_active = 1`).
     *
     * @return list<string>
     */
    public static function activeLanguageCodes(): array
    {
        static $codes = null;

        if ($codes !== null) {
            return $codes;
        }

        try {
            $codes = Language::query()
                ->where('is_active', 1)
                ->pluck('code')
                ->map(static fn ($code) => strtolower((string) $code))
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable) {
            $codes = [];
        }

        return $codes;
    }

    public static function default(): string
    {
        return self::normalize(config('locales.default', self::DEFAULT));
    }

    /**
     * Per request only: ?lang / headers / Accept-Language → English if missing.
     */
    public static function resolve(Request $request): string
    {
        foreach (self::requestCandidates($request) as $candidate) {
            return self::normalize($candidate);
        }

        return self::default();
    }

    /**
     * @return list<string>
     */
    private static function requestCandidates(Request $request): array
    {
        $candidates = [];

        if ($request->query('lang')) {
            $candidates[] = (string) $request->query('lang');
        }

        foreach (['X-App-Language', 'X-Locale', 'Lang', 'Language'] as $header) {
            if ($request->header($header)) {
                $candidates[] = (string) $request->header($header);
            }
        }

        $accept = (string) $request->header('Accept-Language', '');
        if ($accept !== '') {
            foreach (explode(',', $accept) as $part) {
                $candidates[] = trim(Str::before($part, ';'));
            }
        }

        return $candidates;
    }
}
