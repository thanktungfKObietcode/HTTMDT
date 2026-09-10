<?php

namespace App\Console\Commands;

use App\Services\PaymentDeploymentPreflight;
use Illuminate\Console\Command;

class PaymentDeploymentCheck extends Command
{
    protected $signature = 'payments:deployment-check {--json : Emit machine-readable output}';

    protected $description = 'Read-only payment/order deployment preflight; never fixes data or runs migrations';

    public function handle(PaymentDeploymentPreflight $preflight): int
    {
        try {
            $result = $preflight->run();
        } catch (\Throwable) {
            // Connection exceptions can contain credentials; JSON must remain parseable.
            $result = ['verdict' => 'FAIL', 'summary' => ['pass' => 0, 'warning' => 0, 'fail' => 1],
                'checks' => [['id' => 'preflight_unavailable', 'severity' => 'fail', 'count' => 1,
                    'message' => 'Read-only inspection failed. Check connectivity and schema without printing credentials.']]];
        }
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Check', 'Result', 'Count', 'Description'], array_map(
                fn (array $check): array => [$check['id'], strtoupper($check['severity']), $check['count'], $check['message']],
                $result['checks']
            ));
            $this->line(sprintf('VERDICT=%s PASS=%d WARNING=%d FAIL=%d',
                $result['verdict'], $result['summary']['pass'], $result['summary']['warning'], $result['summary']['fail']));
        }

        return $result['verdict'] === 'FAIL' ? self::FAILURE : self::SUCCESS;
    }
}
