<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentCommissionHistory extends Model
{
    use HasFactory;

    protected $table = 'agent_commission_history';

    protected $fillable = [
        'ref_number',
        'total_amount',
        'created_by',
        'remarks',
        'proof_of_payment',
        'agent_id',
        'organization_id',
        'commission_id_list',
        'updated_by',
        'updated_at',
        'approved_by'
    ];
    
    public $timestamps = false; // The table has created_at but uses CURRENT_TIMESTAMP, and no updated_at

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}