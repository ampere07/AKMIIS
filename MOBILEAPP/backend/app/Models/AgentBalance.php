<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentBalance extends Model
{
    use HasFactory;

    protected $table = 'agent_balance';

    protected $fillable = [
        'agent_id',
        'balance',
        'commission',
        'incentives',
        'Bonus',
        'bonus',
        'quota',
        'incentives_value',
        'remarks',
    ];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}

