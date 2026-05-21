<?php

use App\Models\Language;
use App\Models\TranslationKey;

if (!function_exists('db_trans')) {
    function db_trans($key, $langCode = null)
    {
        $langCode = $langCode ?? app()->getLocale();

        $language = Language::where('code', $langCode)
            ->where('is_active', 1)
            ->first();

        if (!$language) {
            $language = Language::where('is_default', 1)->first();
        }

        if (!$language) return $key;

        $translationKey = TranslationKey::where('key', $key)->first();

        if (!$translationKey) return $key;

        $value = \App\Models\TranslationValue::where('translation_key_id', $translationKey->id)
            ->where('language_id', $language->id)
            ->value('value');

        return $value ?: $key;
    }
}