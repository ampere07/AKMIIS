<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Ledger of Job Orders already counted toward an agent quota incentive.
 *
 * One row per processed Job Order. The unique key on job_order_id (see the
 * SQL script) makes duplicate processing impossible at the database level.
 */
class AgentIncentiveHistory extends Model
{
    use HasFactory;

    protected $table = 'agent_incentive_history';

    public $timestamps = true;

    protected $fillable = [
        'agent_id',
        'job_order_id',
        'quota_reached',
        'batch_number',
        'incentive_value',
        'organization_id',
        'processed_at',
    ];

    protected $casts = [
        'agent_id'        => 'integer',
        'job_order_id'    => 'integer',
        'quota_reached'   => 'integer',
        'batch_number'    => 'integer',
        'incentive_value' => 'decimal:2',
        'organization_id' => 'integer',
        'processed_at'    => 'datetime',
    ];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class, 'job_order_id');
    }
}
