<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\TranslationKey;
use App\Support\ApiLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * {lang} sets response locale + which DB strings to load (en, ar, …).
     */
    public function index(Request $request, string $code): JsonResponse
    {
        $locale = ApiLocale::normalize($code);
        app()->setLocale($locale);

        $language = Language::query()
            ->where('code', $locale)
            ->where('is_active', 1)
            ->first();

        if (! $language) {
            return response()->json([
                'success' => false,
                'message' => api_trans('language_not_found_or_inactive'),
                'data' => null,
            ], 404);
        }

        $translations = $this->fileApiMessages($locale);

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
            $translations[$row->key] = $row->value ?? $row->key;
        }

        return response()->json([
            'success' => true,
            'message' => api_trans('translations_fetched_successfully'),
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

    /**
     * API response strings from lang/{locale}/api.php (login, errors, etc.).
     *
     * @return array<string, string>
     */
    private function fileApiMessages(string $locale): array
    {
        $messages = trans('api', [], $locale);

        if (! is_array($messages)) {
            return [];
        }

        $out = [];
        foreach ($messages as $key => $value) {
            if (is_string($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}