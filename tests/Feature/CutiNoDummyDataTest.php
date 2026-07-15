<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\CutiController;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CutiNoDummyDataTest extends TestCase
{
    public function test_cuti_tidak_lagi_menyimpan_data_dummy_atau_route_laporan_legacy(): void
    {
        $controller = new \ReflectionClass(CutiController::class);
        $routes = file_get_contents(base_path('routes/web.php'));

        $this->assertFalse($controller->hasProperty('riwayatCuti'));
        $this->assertFalse(Route::has('laporan.cuti'));
        $this->assertFalse(Route::has('laporan.cuti.excel'));
        $this->assertIsString($routes);
        $this->assertStringNotContainsString('CutiController::$riwayatCuti', $routes);
        $this->assertStringNotContainsString('stage_atasan', $routes);
        $this->assertStringNotContainsString('stage_kepala', $routes);
        $this->assertStringNotContainsString('admin.laporan.export-cuti', $routes);
        $this->assertFileDoesNotExist(resource_path('views/admin/laporan/export-cuti.blade.php'));
    }

    public function test_view_tidak_merujuk_laporan_cuti_legacy(): void
    {
        foreach (File::allFiles(resource_path('views')) as $view) {
            $source = $view->getContents();
            $path = $view->getRelativePathname();

            foreach (["route('laporan.cuti')", 'route("laporan.cuti")', '/laporan/export-cuti', 'admin.laporan.export-cuti'] as $legacyReference) {
                $this->assertStringNotContainsString($legacyReference, $source, "Referensi legacy ditemukan di {$path}");
            }
        }
    }
}
