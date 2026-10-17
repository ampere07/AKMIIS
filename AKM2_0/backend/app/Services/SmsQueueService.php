<?php

namespace App\Services;

use App\Models\BillingAccount;
use App\Models\SmsQueue;
use App\Services\ItexmoSmsService;
use Illuminate\Support\Collection;
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
                'failed' => 0,
                'withdrawn' => 0
            ];
        }

        Log::info('Processing SMS queue', ['count' => $jobs->count()]);

        return $this->sendJobs($jobs, 'SMS sent from queue', 'SMS failed from queue');
    }

    /**
     * Try again the texts the provider rejected (SmsQueue::scopeRetryable). Failed emails
     * were already retried; a failed SMS used to be final.
     */
    public function retryFailedSms(int $maxAttempts = 3, int $batchSize = 20): array
    {
        $jobs = SmsQueue::retryable($maxAttempts)
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

        Log::info('Retrying failed SMS', ['count' => $jobs->count()]);

        return $this->sendJobs($jobs, 'SMS retry sent', 'SMS retry failed');
    }

    private function sendJobs(Collection $jobs, string $sentLog, string $failedLog): array
    {
        $stats = [
            'processed' => $jobs->count(),
            'sent' => 0,
            'failed' => 0,
            'withdrawn' => 0
        ];

        foreach ($jobs as $job) {
            if (BillingAccount::hasSettledBalance($job->account_no)) {
                $job->delete();
                $stats['withdrawn']++;
                Log::info('SMS withdrawn from queue: account balance is settled', ['id' => $job->id, 'account_no' => $job->account_no]);
                continue;
            }

            $result = $this->smsService->send([
                'contact_no' => $job->contact_no,
                'message' => $job->message
            ]);

            if ($result['success']) {
                $job->markAsSent();
                $stats['sent']++;
                Log::info($sentLog, ['id' => $job->id, 'account_no' => $job->account_no]);
            } else {
                $job->markAsFailed($result['error'] ?? 'Unknown error');
                $stats['failed']++;
                Log::error($failedLog, ['id' => $job->id, 'attempts' => $job->attempts, 'error' => $result['error'] ?? 'Unknown error']);
            }

            // Small delay to avoid hitting API rate limits too hard
            usleep(200000);
        }

        return $stats;
    }
}
