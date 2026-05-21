<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    protected $fillable = [
        'code',
        'name',
        'flag',
        'direction',
        'is_active',
        'is_default',
    ];

    public function translationValues()
    {
        return $this->hasMany(TranslationValue::class);
    }
}