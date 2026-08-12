<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\EmployeeImportController;
use App\Http\Controllers\Admin\PegawaiController;
use App\Http\Controllers\Api\V1\EmployeeController as ApiEmployeeController;
use App\Http\Requests\Cuti\ApprovalChainConfigRequest;
use App\Http\Requests\Cuti\ApproveLeaveRequest;
use App\Http\Requests\Cuti\CalculateWorkdaysRequest;
use App\Http\Requests\Cuti\PostponeLeaveRequest;
use App\Http\Requests\Cuti\StoreLeaveRequestRequest;
use App\Http\Requests\Employee\DeactivateEmployeeRequest;
use App\Http\Requests\Employee\ListEmployeesRequest;
use App\Http\Requests\Employee\RestoreEmployeeRequest;
use App\Http\Requests\Employee\StoreEmployeeFamilyRequest;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeFamilyRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Http\Requests\HariLibur\StoreHariLiburRequest;
use App\Http\Requests\HariLibur\UpdateHariLiburRequest;
use App\Http\Requests\History\StoreDisciplineRecordRequest;
use App\Http\Requests\History\StoreKgbHistoryRequest;
use App\Http\Requests\History\StorePositionHistoryRequest;
use App\Http\Requests\History\StoreRankHistoryRequest;
use App\Http\Requests\Import\ExecuteImportBatchRequest;
use App\Http\Requests\Import\ImportEmployeesRequest;
use App\Http\Requests\Import\ValidateImportBatchRequest;
use Tests\TestCase;

class FormRequestNamespaceTest extends TestCase
{
    public function test_form_requests_live_in_domain_namespaces(): void
    {
        foreach ([
            ApprovalChainConfigRequest::class,
            ApproveLeaveRequest::class,
            CalculateWorkdaysRequest::class,
            PostponeLeaveRequest::class,
            StoreLeaveRequestRequest::class,
            DeactivateEmployeeRequest::class,
            ListEmployeesRequest::class,
            RestoreEmployeeRequest::class,
            StoreEmployeeFamilyRequest::class,
            StoreEmployeeRequest::class,
            UpdateEmployeeFamilyRequest::class,
            UpdateEmployeeRequest::class,
            StoreHariLiburRequest::class,
            UpdateHariLiburRequest::class,
            StoreDisciplineRecordRequest::class,
            StoreKgbHistoryRequest::class,
            StorePositionHistoryRequest::class,
            StoreRankHistoryRequest::class,
            ExecuteImportBatchRequest::class,
            ImportEmployeesRequest::class,
            ValidateImportBatchRequest::class,
        ] as $requestClass) {
            $this->assertTrue(class_exists($requestClass), "{$requestClass} harus berada di namespace domain.");
        }
    }

    public function test_employee_deactivate_and_restore_mutations_receive_domain_form_requests(): void
    {
        $mutations = [
            [PegawaiController::class, 'destroy', 1, DeactivateEmployeeRequest::class],
            [PegawaiController::class, 'restore', 1, RestoreEmployeeRequest::class],
            [ApiEmployeeController::class, 'destroy', 1, DeactivateEmployeeRequest::class],
            [ApiEmployeeController::class, 'restore', 1, RestoreEmployeeRequest::class],
        ];

        foreach ($mutations as [$controllerClass, $method, $parameterIndex, $requestClass]) {
            $type = (new \ReflectionClass($controllerClass))
                ->getMethod($method)
                ->getParameters()[$parameterIndex]
                ->getType();

            $this->assertInstanceOf(\ReflectionNamedType::class, $type);
            $this->assertSame(
                $requestClass,
                $type->getName(),
                "{$controllerClass}::{$method} harus menerima FormRequest domain.",
            );
        }
    }

    public function test_legacy_flat_form_request_namespace_is_empty(): void
    {
        $flatRequestFiles = glob(app_path('Http/Requests/*.php')) ?: [];

        $this->assertSame([], $flatRequestFiles);
    }

    public function test_import_batch_mutations_receive_domain_form_requests(): void
    {
        $controller = new \ReflectionClass(EmployeeImportController::class);
        $validateRequestType = $controller->getMethod('validate')->getParameters()[0]->getType();
        $executeRequestType = $controller->getMethod('execute')->getParameters()[0]->getType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $validateRequestType);
        $this->assertInstanceOf(\ReflectionNamedType::class, $executeRequestType);

        $this->assertSame(
            ValidateImportBatchRequest::class,
            $validateRequestType->getName(),
        );
        $this->assertSame(
            ExecuteImportBatchRequest::class,
            $executeRequestType->getName(),
        );
    }
}
