<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StringEntry extends Model
{
    //
    protected $table = 'strings';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['id', 'value', 'properties'];
    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
