<?php

namespace Tests\Unit;

use App\Jobs\ImportEmployeeBatchJob;
use Tests\TestCase;

class ImportEmployeeBatchJobTimeoutTest extends TestCase
{
    /** Pesan tidak boleh tersedia untuk worker kedua sebelum worker pertama pasti dihentikan. */
    public function test_retry_after_exceeds_import_timeout_for_supported_retrying_backends(): void
    {
        $timeout = (new ImportEmployeeBatchJob('00000000-0000-0000-0000-000000000001', null))->timeout;

        foreach (['database', 'redis', 'beanstalkd'] as $connection) {
            $this->assertGreaterThan(
                $timeout,
                config("queue.connections.{$connection}.retry_after"),
                "retry_after {$connection} harus lebih panjang dari timeout job import.",
            );
        }
    }
}
