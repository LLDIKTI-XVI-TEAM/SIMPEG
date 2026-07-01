<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\EmployeeImportController as AdminEmployeeImportController;
use App\Http\Controllers\Admin\LeaveBalanceController as AdminLeaveBalanceController;
use App\Http\Controllers\Api\V1\EmployeeImportController as ApiEmployeeImportController;
use App\Http\Controllers\Api\V1\LeaveBalanceController as ApiLeaveBalanceController;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HttpControllerNamespaceTest extends TestCase
{
    public function test_dual_surface_leave_balance_routes_use_surface_specific_controllers(): void
    {
        $this->assertRouteAction('api.v1.profil-saya.saldo-cuti', ApiLeaveBalanceController::class.'@showMyBalance');
        $this->assertRouteAction('cuti.saldo', AdminLeaveBalanceController::class.'@showMyBalanceWeb');
    }

    public function test_dual_surface_employee_import_routes_use_surface_specific_controllers(): void
    {
        $this->assertRouteAction('api.v1.pegawai.import.store', ApiEmployeeImportController::class.'@store');

        foreach ([
            'pegawai.import-template' => 'template',
            'pegawai.import.upload' => 'upload',
            'pegawai.import.preview' => 'preview',
            'pegawai.import.validate' => 'validate',
            'pegawai.import.execute' => 'execute',
            'pegawai.import.status' => 'status',
        ] as $routeName => $method) {
            $this->assertRouteAction($routeName, AdminEmployeeImportController::class.'@'.$method);
        }
    }

    /**
     * Pastikan refactor namespace HTTP tidak mengubah kontrak route publik.
     */
    private function assertRouteAction(string $routeName, string $expectedAction): void
    {
        $route = Route::getRoutes()->getByName($routeName);

        $this->assertNotNull($route, "Route {$routeName} harus terdaftar.");
        $this->assertSame($expectedAction, ltrim($route->getActionName(), '\\'));
    }
}
