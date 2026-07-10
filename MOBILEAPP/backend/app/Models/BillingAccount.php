<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingAccount extends Model
{
    use HasFactory;

    protected $table = 'billing_accounts';

    protected $fillable = [
        'customer_id',
        'account_no',
        'date_installed',
        'plan_id',
        'account_balance',
        'balance_update_date',
        'billing_day',
        'billing_status_id',
        'created_by',
        'updated_by',
        'vip_expiration',
        'vip_remarks',
    ];

    protected $casts = [
        'date_installed' => 'date',
        'balance_update_date' => 'datetime',
        'account_balance' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function technicalDetails()
    {
        return $this->hasMany(TechnicalDetail::class, 'account_id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function staggeredInstallations()
    {
        return $this->hasMany(StaggeredInstallation::class, 'account_no', 'account_no');
    }

    public function onlineStatus()
    {
        return $this->hasOne(OnlineStatus::class, 'account_id');
    }

    public function billingStatus()
    {
        return $this->belongsTo(BillingStatus::class, 'billing_status_id');
    }
}

