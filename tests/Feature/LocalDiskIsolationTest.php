<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocalDiskIsolationTest extends TestCase
{
    public function test_local_disk_uses_testing_storage_instead_of_private_application_storage(): void
    {
        $root = str_replace('\\', '/', rtrim(Storage::disk('local')->path(''), '/\\'));
        $testingDisksRoot = str_replace('\\', '/', storage_path('framework/testing/disks'));
        $sentinelPath = 'local-disk-isolation/'.bin2hex(random_bytes(16)).'.txt';

        $this->assertMatchesRegularExpression(
            '#^'.preg_quote($testingDisksRoot, '#').'/local(?:_test_[^/]+)?$#',
            $root,
        );

        Storage::disk('local')->put($sentinelPath, 'isolated test write');

        $this->assertTrue(Storage::disk('local')->exists($sentinelPath));
        $this->assertFileDoesNotExist(storage_path('app/private/'.$sentinelPath));
    }
}
