<?php

namespace Tests\Feature;

use App\Http\Requests\Cuti\ApprovalChainConfigRequest;
use App\Http\Requests\Cuti\ApproveLeaveRequest;
use App\Http\Requests\Cuti\CalculateWorkdaysRequest;
use App\Http\Requests\Cuti\PostponeLeaveRequest;
use App\Http\Requests\Cuti\StoreLeaveRequestRequest;
use App\Http\Requests\Employee\ListEmployeesRequest;
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
use App\Http\Requests\Import\ImportEmployeesRequest;
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
            ListEmployeesRequest::class,
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
            ImportEmployeesRequest::class,
        ] as $requestClass) {
            $this->assertTrue(class_exists($requestClass), "{$requestClass} harus berada di namespace domain.");
        }
    }

    public function test_legacy_flat_form_request_namespace_is_empty(): void
    {
        $flatRequestFiles = glob(app_path('Http/Requests/*.php')) ?: [];

        $this->assertSame([], $flatRequestFiles);
    }
}
