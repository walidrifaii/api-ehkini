<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DictionaryTranslation extends Model
{
    protected $table = 'dictionary_translations';

    protected $fillable = [
        'dictionary_id',
        'language_id',
        'value',
    ];

    public function dictionary()
    {
        return $this->belongsTo(Dictionary::class, 'dictionary_id');
    }

    public function language()
    {
        return $this->belongsTo(Language::class, 'language_id');
    }
}