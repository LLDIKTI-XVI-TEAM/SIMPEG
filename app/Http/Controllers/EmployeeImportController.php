<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportEmployeesRequest;
use App\Models\Employee;
use App\Services\AuditService;
use App\Models\RefJenisPegawai;
use App\Support\EmployeeImport\CsvEmployeeReader;
use App\Support\EmployeeValidationRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EmployeeImportController extends Controller
{
    private ?array $jenisPegawaiCache = null;

    private function getJenisPegawaiCache(): array
    {
        if ($this->jenisPegawaiCache === null) {
            $this->jenisPegawaiCache = RefJenisPegawai::pluck('id', 'nama')->all();
        }
        return $this->jenisPegawaiCache;
    }

    public function store(ImportEmployeesRequest $request, CsvEmployeeReader $reader): JsonResponse|RedirectResponse
    {
        try {
            $rows = $reader->read($request->file('file'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'file' => [$exception->getMessage()],
            ]);
        }


        $validatedRows = [];
        $errors = [];
        $seenNips = [];
        $seenEmails = [];

        foreach ($rows as $row) {
            $validator = Validator::make($row['data'], EmployeeValidationRules::import(), [], EmployeeValidationRules::attributes());

            if ($validator->fails()) {
                $errors[] = [
                    'row' => $row['row'],
                    'errors' => $validator->errors()->toArray(),
                ];

                continue;
            }

            $data = $validator->validated();
            $referenceErrors = $this->resolveReferences($data);
            $duplicateErrors = $this->duplicateErrors($data, $row['row'], $seenNips, $seenEmails);
            $rowErrors = array_merge_recursive($referenceErrors, $duplicateErrors);

            if ($rowErrors !== []) {
                $errors[] = [
                    'row' => $row['row'],
                    'errors' => $rowErrors,
                ];

                continue;
            }

            $validatedRows[] = $data + [
                'status_aktif' => 'Aktif',
                'profil_status' => 'belum_lengkap',
                'is_kinerja_baik' => true,
            ];
        }

        if ($errors !== []) {
            return $this->failedImportResponse($request, $errors);
        }

        DB::transaction(function () use ($validatedRows): void {
            foreach ($validatedRows as $data) {
                Employee::create($data);
            }
        });

        AuditService::log('IMPORT', 'Employee', null, null, [
            'total_inserted' => count($validatedRows),
            'filename' => $request->file('file')->getClientOriginalName(),
        ], $request);

        $summary = [
            'message' => 'Import selesai.',
            'inserted' => count($validatedRows),
            'failed' => 0,
            'errors' => [],
        ];

        if ($request->expectsJson()) {
            return response()->json($summary);
        }

        return back()->with('import_summary', $summary);
    }

    private function resolveReferences(array &$data): array
    {
        $errors = [];
        $jenisPegawai = $data['jenis_pegawai'] ?? null;

        if ($jenisPegawai !== null) {
            $cache = $this->getJenisPegawaiCache();
            $cacheNormalized = array_change_key_case($cache, CASE_UPPER);
            $key = strtoupper(trim($jenisPegawai));

            if (isset($cacheNormalized[$key])) {
                $data['jenis_pegawai_id'] = $cacheNormalized[$key];
            } else {
                $errors['jenis_pegawai'][] = 'Jenis pegawai harus salah satu dari: ' . implode(', ', array_keys($cache)) . '.';
            }
            unset($data['jenis_pegawai']);
        }

        return $errors;
    }

    private function duplicateErrors(array $data, int $row, array &$seenNips, array &$seenEmails): array
    {
        $errors = [];

        if (! empty($data['nip'])) {
            $nip = $data['nip'];

            if (isset($seenNips[$nip])) {
                $errors['nip'][] = "NIP sudah ada pada baris {$seenNips[$nip]}.";
            } else {
                $seenNips[$nip] = $row;
            }
        }

        if (! empty($data['email_pribadi'])) {
            $email = strtolower($data['email_pribadi']);

            if (isset($seenEmails[$email])) {
                $errors['email_pribadi'][] = "Email pribadi sudah ada pada baris {$seenEmails[$email]}.";
            } else {
                $seenEmails[$email] = $row;
            }
        }

        return $errors;
    }

    private function failedImportResponse(ImportEmployeesRequest $request, array $errors): JsonResponse|RedirectResponse
    {
        $summary = [
            'message' => 'Import gagal. Perbaiki baris bermasalah lalu unggah ulang.',
            'inserted' => 0,
            'failed' => count($errors),
            'errors' => $errors,
        ];

        if ($request->expectsJson()) {
            return response()->json($summary, 422);
        }

        return back()
            ->withErrors(['file' => $summary['message']])
            ->with('import_summary', $summary);
    }
}
