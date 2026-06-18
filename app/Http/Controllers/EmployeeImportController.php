<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportEmployeesRequest;
use App\Models\Employee;
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
            $duplicateErrors = $this->duplicateErrors($data, $row['row'], $seenNips, $seenEmails);

            if ($duplicateErrors !== []) {
                $errors[] = [
                    'row' => $row['row'],
                    'errors' => $duplicateErrors,
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
