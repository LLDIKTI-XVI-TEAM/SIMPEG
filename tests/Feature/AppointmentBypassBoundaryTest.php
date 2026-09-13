<?php

namespace Tests\Feature;

use App\Http\Requests\Appointment\SaveAppointmentRequest;
use App\Http\Requests\Appointment\UploadAppointmentSkRequest;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * Kontrak bypass lokal untuk pengangkatan: ditolak di boundary authorize (403),
 * bukan lolos authorize lalu rollback actor-null di tengah transaksi.
 */
class AppointmentBypassBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_save_appointment_rejects_anonymous_even_in_local_bypass(): void
    {
        config(['services.simpeg.disable_employee_api_auth' => true]);
        $this->app['env'] = 'local';

        $employee = Employee::factory()->create();
        $request = SaveAppointmentRequest::create("/api/v1/pegawai/{$employee->id}/pengangkatan", 'POST');
        $route = new Route(['POST'], '/api/v1/pegawai/{employee}/pengangkatan', []);
        $route->bind($request);
        $route->setParameter('employee', $employee);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => null);

        $this->assertFalse($request->authorize(), 'Save appointment harus tolak anonymous walau bypass aktif');
    }

    public function test_upload_appointment_rejects_anonymous_even_in_local_bypass(): void
    {
        config(['services.simpeg.disable_employee_api_auth' => true]);
        $this->app['env'] = 'local';

        $employee = Employee::factory()->create();
        $request = UploadAppointmentSkRequest::create("/api/v1/pegawai/{$employee->id}/pengangkatan/upload-sk", 'POST');
        $route = new Route(['POST'], '/api/v1/pegawai/{employee}/pengangkatan/upload-sk', []);
        $route->bind($request);
        $route->setParameter('employee', $employee);
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => null);

        $this->assertFalse($request->authorize(), 'Upload SK pengangkatan harus tolak anonymous walau bypass aktif');
    }
}
