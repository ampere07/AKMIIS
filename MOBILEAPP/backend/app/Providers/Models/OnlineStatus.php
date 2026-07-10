<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OnlineStatus extends Model
{
    use HasFactory;

    protected $table = 'online_status';

    protected $fillable = [
        'account_id',
        'account_no',
        'username',
        'session_status',
        'session_group',
        'session_id',
        'total_download',
        'total_upload',
        'ip_address',
        'city',
        'session_mac_address',
        'created_by_user',
        'updated_by_user',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'total_download' => 'decimal:2',
        'total_upload' => 'decimal:2',
    ];

    public function billingAccount()
    {
        return $this->belongsTo(BillingAccount::class, 'account_id');
    }
}

