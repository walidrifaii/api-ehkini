<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dictionary extends Model
{
    protected $table = 'dictionary';

    protected $fillable = [
        'key_name',
    ];

    public function translations()
    {
        return $this->hasMany(DictionaryTranslation::class, 'dictionary_id');
    }
}