<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AgentAchievementClaim extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_id',
        'milestone',
        'amount',
    ];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
