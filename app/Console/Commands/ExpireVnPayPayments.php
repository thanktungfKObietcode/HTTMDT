<?php

namespace App\Console\Commands;

use App\Services\VnPayExpiryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpireVnPayPayments extends Command
{
    protected $signature = 'payments:expire-vnpay {--limit= : Maximum orders, 1-100} {--after-id=0 : Resume after this order ID} {--dry-run : List candidates without queries or writes}';

    protected $description = 'Query expired VNPay attempts, then cancel only verified-unpaid orders safely';

    public function handle(VnPayExpiryService $expiry): int
    {
        $limit = $this->option('limit') ?? config('vnpay.expiry_batch_size', 25);
        $after = $this->option('after-id');
        if (! ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 100
            || ! ctype_digit((string) $after)) {
            $this->error('Invalid batch limit or cursor.');

            return self::INVALID;
        }
        $counts = ['scanned' => 0, 'cancelled' => 0, 'skipped_paid' => 0, 'skipped_conflict' => 0, 'failed' => 0];
        try {
            $candidates = $expiry->candidates()->where('id', '>', (int) $after)->limit((int) $limit)->get();
        } catch (Throwable) {
            $this->error('Expiry candidate lookup failed; no cancellation was attempted.');
            Log::error('VNPay expiry candidate lookup failed.');
            return self::FAILURE;
        }
        $lastId = (int) $after;
        foreach ($candidates as $order) {
            $lastId = $order->id;
            $counts['scanned']++;
            if ($this->option('dry-run')) {
                continue;
            }
            try {
                $counts[$expiry->expire($order)]++;
            } catch (Throwable) {
                $counts['failed']++;
                Log::error('VNPay expiry failed safely.', ['order_id' => $order->id]);
            }
        }
        $this->line(json_encode($counts + ['last_id' => $lastId, 'dry_run' => (bool) $this->option('dry-run')], JSON_THROW_ON_ERROR));
        if (! $this->option('dry-run')) {
            Log::info('VNPay expiry batch completed.', $counts + ['last_id' => $lastId]);
        }

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
