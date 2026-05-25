<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\TranslationKey;
use App\Support\ApiLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TranslationController extends Controller
{
    public function languages(Request $request): JsonResponse
    {
        app()->setLocale(ApiLocale::resolve($request));

        $languages = Language::query()
            ->where('is_active', 1)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'direction', 'flag', 'is_active', 'is_default']);

        return response()->json([
            'success' => true,
            'message' => api_trans('languages_fetched_successfully'),
            'data' => $languages,
        ]);
    }

    /**
     * GET /api/v2/translations/{lang}
     *
     * Strings come from the database only (translation_keys + translation_values).
     * Add a language by inserting into `languages` and `translation_values` — no deploy needed.
     */
    public function index(Request $request, string $code): JsonResponse
    {
        $requestedCode = $this->parseTranslationLangCode($code);

        $language = Language::query()
            ->where('code', $requestedCode)
            ->where('is_active', 1)
            ->first();

        if (! $language) {
            return response()->json([
                'success' => false,
                'message' => api_trans('language_not_found_or_inactive'),
                'data' => null,
            ], 404);
        }

        $locale = $language->code;
        app()->setLocale($locale);

        $translations = [];

        $rows = TranslationKey::query()
            ->leftJoin('translation_values', function ($join) use ($language) {
                $join->on('translation_keys.id', '=', 'translation_values.translation_key_id')
                    ->where('translation_values.language_id', '=', $language->id);
            })
            ->select(
                'translation_keys.key',
                'translation_keys.group',
                'translation_values.value'
            )
            ->orderBy('translation_keys.id')
            ->get();

        foreach ($rows as $row) {
            if ($row->key === null || $row->key === '') {
                continue;
            }
            $translations[$row->key] = ($row->value !== null && $row->value !== '')
                ? $row->value
                : $row->key;
        }

        return response()->json([
            'success' => true,
            'message' => $this->translationsFetchedMessage($locale),
            'data' => [
                'language' => [
                    'id' => $language->id,
                    'code' => $language->code,
                    'name' => $language->name,
                    'direction' => $language->direction,
                    'flag' => $language->flag,
                    'is_default' => (bool) $language->is_default,
                ],
                'translations' => $translations,
            ],
        ]);
    }

    private function translationsFetchedMessage(string $locale): string
    {
        $line = trans('api.translations_fetched_successfully', [], $locale);
        if ($line !== 'api.translations_fetched_successfully') {
            return $line;
        }

        return trans('api.translations_fetched_successfully', [], ApiLocale::DEFAULT);
    }

    private function parseTranslationLangCode(string $code): string
    {
        $code = strtolower(trim($code));

        return Str::before(Str::before($code, '_'), '-');
    }
}