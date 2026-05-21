<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\TranslationKey;
use Illuminate\Http\JsonResponse;

class TranslationController extends Controller
{
    public function languages(): JsonResponse
    {
        $languages = Language::query()
            ->where('is_active', 1)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'direction', 'flag', 'is_active', 'is_default']);

        return response()->json([
            'success' => true,
            'message' => 'Languages fetched successfully',
            'data' => $languages,
        ]);
    }

    public function index(string $code): JsonResponse
    {
        $language = Language::query()
            ->where('code', $code)
            ->where('is_active', 1)
            ->first();

        if (!$language) {
            return response()->json([
                'success' => false,
                'message' => 'Language not found or inactive',
                'data' => null,
            ], 404);
        }

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

        $translations = [];

        foreach ($rows as $row) {
            $translations[$row->key] = $row->value ?? $row->key;
        }

        return response()->json([
            'success' => true,
            'message' => 'Translations fetched successfully',
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
}