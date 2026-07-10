<?php

namespace App\Services;

use App\Models\SmsQueue;
use App\Services\ItexmoSmsService;
use Illuminate\Support\Facades\Log;

class SmsQueueService
{
    protected ItexmoSmsService $smsService;

    public function __construct(ItexmoSmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    public function queueSms(array $data): SmsQueue
    {
        return SmsQueue::create([
            'account_no' => $data['account_no'] ?? null,
            'contact_no' => $data['contact_no'],
            'message' => $data['message'],
            'status' => 'pending',
            'time_sent' => $data['time_sent'] ?? null
        ]);
    }

    public function processPendingSms(int $batchSize = 50): array
    {
        $jobs = SmsQueue::pending()
            ->orderBy('created_at', 'asc')
            ->limit($batchSize)
            ->get();

        if ($jobs->isEmpty()) {
            return [
                'processed' => 0,
                'sent' => 0,
                'failed' => 0
            ];
        }

        Log::info('Processing SMS queue', ['count' => $jobs->count()]);

        $stats = [
            'processed' => $jobs->count(),
            'sent' => 0,
            'failed' => 0
        ];

        foreach ($jobs as $job) {
            $result = $this->smsService->send([
                'contact_no' => $job->contact_no,
                'message' => $job->message
            ]);

            if ($result['success']) {
                $job->markAsSent();
                $stats['sent']++;
                Log::info('SMS sent from queue', ['id' => $job->id, 'account_no' => $job->account_no]);
            } else {
                $job->markAsFailed($result['error'] ?? 'Unknown error');
                $stats['failed']++;
                Log::error('SMS failed from queue', ['id' => $job->id, 'error' => $result['error'] ?? 'Unknown error']);
            }

            // Small delay to avoid hitting API rate limits too hard
            usleep(200000);
        }

        return $stats;
    }
}
