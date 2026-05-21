<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TranslationKey extends Model
{
    protected $fillable = [
        'key',
        'group',
    ];

    public function values()
    {
        return $this->hasMany(TranslationValue::class, 'translation_key_id');
    }
}