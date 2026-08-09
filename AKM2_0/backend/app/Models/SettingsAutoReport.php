<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SettingsAutoReport extends Model
{
    protected $table = 'settings_auto_report';

    protected $fillable = [
        'is_enabled',
        'updated_by',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];
}
