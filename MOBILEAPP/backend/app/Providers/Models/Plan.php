<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $table = 'plan_list';

    public $timestamps = false;

    protected $fillable = [
        'plan_name',
        'description',
        'price',
        'group_id',
        'modified_by_user',
        'modified_date',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'modified_date' => 'datetime',
    ];

    public function billingAccounts()
    {
        return $this->hasMany(BillingAccount::class);
    }
}
