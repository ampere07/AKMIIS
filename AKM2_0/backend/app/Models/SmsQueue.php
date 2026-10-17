<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsQueue extends Model
{
    protected $table = 'sms_queue';

    protected $fillable = [
        'account_no',
        'contact_no',
        'message',
        'status',
        'sent_at',
        'time_sent',
        'attempts',
        'error_message'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'sent_at' => 'datetime',
        'time_sent' => 'datetime',
        'attempts' => 'integer'
    ];

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class, 'account_no', 'account_no');
    }

    public function scopePending($query)
    {
        $now = \Carbon\Carbon::now('Asia/Manila');
        return $query->where('status', 'pending')
                     ->where(function($q) use ($now) {
                         $q->whereNull('time_sent')
                           ->orWhere('time_sent', '<=', $now->format('Y-m-d H:i:s'));
                     });
    }

    /**
     * Failed texts worth another try: fewer than $maxAttempts so far, the last one at least
     * $waitMinutes ago, and queued within the last day, so old failures are never resent.
     */
    public function scopeRetryable($query, int $maxAttempts = 3, int $waitMinutes = 5)
    {
        return $query->where('status', 'failed')
                     ->where('attempts', '<', $maxAttempts)
                     ->where('updated_at', '<=', now()->subMinutes($waitMinutes))
                     ->where('created_at', '>=', now()->subDay());
    }

    public function markAsSent(): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now()
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'attempts' => $this->attempts + 1,
            'error_message' => $errorMessage
        ]);
    }
}
