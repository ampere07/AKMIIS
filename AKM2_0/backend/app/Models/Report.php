<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    use HasFactory;

    protected $fillable = [
        'report_name',
        'report_type',
        'report_schedule',
        'report_time',
        'day',
        'time_and_date',
        'send_to',
        'date_range',
        'created_by',
        'file_url'
    ];

    public $timestamps = false;
    
    // As in your table definition you have created_at as DATETIME DEFAULT CURRENT_TIMESTAMP, 
    // we should override the eloquent timestamps if they don't exactly match 'created_at' & 'updated_at'
    // Let's rely on standard Laravel to fill created_at and let updated_at be ignored.
    
    const CREATED_AT = 'created_at';
    const UPDATED_AT = null;
}
