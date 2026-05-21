<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TranslationValue extends Model
{
    protected $fillable = [
        'translation_key_id',
        'language_id',
        'value',
    ];

    public function translationKey()
    {
        return $this->belongsTo(TranslationKey::class, 'translation_key_id');
    }

    public function language()
    {
        return $this->belongsTo(Language::class);
    }
}