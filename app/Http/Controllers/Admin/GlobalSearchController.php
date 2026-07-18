<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\RefUnitKerja;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GlobalSearchController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->input('q');

        if (empty($query) || strlen($query) < 2) {
            return response()->json([]);
        }

        $results = [];

        $isPimpinan = auth()->user()?->role === 'pimpinan';
        $op = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        // 1. Search Employees (Pegawai & NIP)
        $employees = Employee::where('nama_lengkap', $op, "%{$query}%")
            ->orWhere('nip', $op, "%{$query}%")
            ->limit(5)
            ->get();

        if ($employees->isNotEmpty()) {
            $results['Pegawai'] = $employees->map(function ($emp) use ($isPimpinan) {
                return [
                    'title' => $emp->nama_lengkap,
                    'subtitle' => 'NIP: '.$emp->nip.' — '.($emp->jabatan_terakhir ?? '-'),
                    'url' => $isPimpinan
                        ? route('pimpinan.pegawai.index', ['search' => $emp->nip])
                        : route('data-pegawai', ['search' => $emp->nip]),
                ];
            });
        }

        // 2. Search Unit Kerja
        if (! $isPimpinan && class_exists(RefUnitKerja::class)) {
            try {
                $units = RefUnitKerja::where('nama', $op, "%{$query}%")
                    ->limit(5)
                    ->get();
                if ($units->isNotEmpty()) {
                    $results['Unit Kerja'] = $units->map(function ($unit) {
                        return [
                            'title' => $unit->nama,
                            'subtitle' => 'Unit Kerja / Departemen',
                            'url' => route('data-master').'?search='.urlencode($unit->nama), // Fallback route
                        ];
                    });
                }
            } catch (\Exception $e) {
            }
        }

        // 3. Search Dokumen
        if (! $isPimpinan && class_exists(Document::class)) {
            try {
                $docs = Document::with('employee')->where('nama_dokumen', $op, "%{$query}%")
                    ->orWhere('nomor_dokumen', $op, "%{$query}%")
                    ->limit(5)
                    ->get();
                if ($docs->isNotEmpty()) {
                    $results['Dokumen'] = $docs->map(function ($doc) {
                        $empName = $doc->employee ? $doc->employee->nama_lengkap : 'Unknown';

                        return [
                            'title' => $doc->nama_dokumen,
                            'subtitle' => $doc->nomor_dokumen.' — Pegawai: '.$empName,
                            'url' => route('dokumen').'?search='.urlencode($doc->nama_dokumen),
                        ];
                    });
                }
            } catch (\Exception $e) {
            }
        }

        // 4. Search Cuti
        if (class_exists(LeaveRequest::class)) {
            try {
                $leaves = LeaveRequest::with('employee')->where('alasan', $op, "%{$query}%")
                    ->limit(5)
                    ->get();
                if ($leaves->isNotEmpty()) {
                    $results['Cuti'] = $leaves->map(function ($leave) use ($isPimpinan) {
                        $empName = $leave->employee ? $leave->employee->nama_lengkap : 'Unknown';

                        return [
                            'title' => 'Pengajuan Cuti: '.$empName,
                            'subtitle' => 'Alasan: '.mb_strimwidth($leave->alasan, 0, 50, '...').' ('.ucfirst($leave->status).')',
                            'url' => $isPimpinan
                                ? route('pimpinan.cuti.index', ['search' => $empName])
                                : route('cuti').'?search='.urlencode($empName),
                        ];
                    });
                }
            } catch (\Exception $e) {
            }
        }

        // 5. Search Users (kept for completeness)
        if (! $isPimpinan) {
            $users = User::where('name', $op, "%{$query}%")
                ->orWhere('email', $op, "%{$query}%")
                ->limit(5)
                ->get();

            if ($users->isNotEmpty()) {
                $results['Pengguna Sistem'] = $users->map(function ($u) {
                    return [
                        'title' => $u->name,
                        'subtitle' => $u->email.' — Role: '.($u->role ?? '-'),
                        'url' => route('user-management', ['search' => $u->name]),
                    ];
                });
            }
        }

        return response()->json($results);
    }
}
