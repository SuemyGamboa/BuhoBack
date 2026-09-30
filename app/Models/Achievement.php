<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Achievement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'description',
        'icon_url',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
