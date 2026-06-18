<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\EmployeeImport\CsvEmployeeReader;
use App\Support\EmployeeValidationRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class EmployeeImportController extends Controller
{
    public function store(Request $request, CsvEmployeeReader $reader): JsonResponse|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
        ], [
            'file.mimes' => 'Format awal yang didukung adalah CSV. Silakan export file Excel ke CSV terlebih dahulu.',
        ]);

        try {
            $rows = $reader->read($request->file('file'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'file' => [$exception->getMessage()],
            ]);
        }

        $inserted = 0;
        $errors = [];

        foreach ($rows as $row) {
            $validator = Validator::make($row['data'], EmployeeValidationRules::import(), [], EmployeeValidationRules::attributes());

            if ($validator->fails()) {
                $errors[] = [
                    'row' => $row['row'],
                    'errors' => $validator->errors()->toArray(),
                ];

                continue;
            }

            Employee::create($validator->validated() + [
                'status_aktif' => 'Aktif',
                'profil_status' => 'belum_lengkap',
                'is_kinerja_baik' => true,
            ]);

            $inserted++;
        }

        $summary = [
            'message' => 'Import selesai.',
            'inserted' => $inserted,
            'failed' => count($errors),
            'errors' => $errors,
        ];

        if ($request->expectsJson()) {
            return response()->json($summary);
        }

        return back()->with('import_summary', $summary);
    }
}
