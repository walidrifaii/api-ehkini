<?php

use App\Models\Dictionary;
use App\Models\Language;
use App\Models\TranslationKey;
use App\Support\ApiLocale;

if (!function_exists('api_trans')) {
    /**
     * Translate by key. Falls back to English, then the key string.
     * DB dictionary (key_name + en/ar) overrides lang files when present.
     */
    function api_trans(string $key, array $replace = []): string
    {
        $locale = ApiLocale::normalize(app()->getLocale());

        $dictionary = Dictionary::query()->where('key_name', $key)->first();
        if ($dictionary) {
            $text = match ($locale) {
                'ar' => (string) ($dictionary->ar ?? ''),
                default => (string) ($dictionary->en ?? ''),
            };
            if ($text === '' && $locale !== ApiLocale::DEFAULT) {
                $text = (string) ($dictionary->en ?? '');
            }
            if ($text !== '') {
                return $replace ? str_replace(array_keys($replace), array_values($replace), $text) : $text;
            }
        }

        $line = trans('api.'.$key, $replace, $locale);
        if ($line !== 'api.'.$key) {
            return $line;
        }

        $fallback = trans('api.'.$key, $replace, ApiLocale::DEFAULT);

        return $fallback !== 'api.'.$key ? $fallback : $key;
    }
}

if (!function_exists('api_translate_message')) {
    /**
     * Translate English API response text (used by middleware on JSON responses).
     */
    function api_translate_message(string $message): string
    {
        $locale = ApiLocale::normalize(app()->getLocale());
        if ($locale === ApiLocale::DEFAULT) {
            return $message;
        }

        if (str_starts_with($message, 'api.')) {
            $translated = api_trans(substr($message, 4));

            return $translated !== substr($message, 4) ? $translated : $message;
        }

        static $englishToKey = null;
        if ($englishToKey === null) {
            $englishToKey = [];
            $strings = trans('api', [], ApiLocale::DEFAULT);
            if (is_array($strings)) {
                foreach ($strings as $key => $english) {
                    if (is_string($english) && $english !== '') {
                        $englishToKey[$english] = $key;
                    }
                }
            }
        }

        $lookupKey = $englishToKey[$message] ?? null;
        if ($lookupKey) {
            return api_trans($lookupKey);
        }

        return $message;
    }
}

if (!function_exists('db_trans')) {
    function db_trans($key, $langCode = null)
    {
        $langCode = ApiLocale::normalize($langCode ?? app()->getLocale());

        $language = Language::where('code', $langCode)
            ->where('is_active', 1)
            ->first();

        if (! $language) {
            $language = Language::where('is_default', 1)->first();
        }

        if (! $language) {
            return $key;
        }

        $translationKey = TranslationKey::where('key', $key)->first();

        if (! $translationKey) {
            return $key;
        }

        $value = \App\Models\TranslationValue::where('translation_key_id', $translationKey->id)
            ->where('language_id', $language->id)
            ->value('value');

        return $value ?: $key;
    }
}
