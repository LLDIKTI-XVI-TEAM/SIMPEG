<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Employees\AssignSupervisorAction;
use App\Actions\Employees\DeactivateEmployeeAction;
use App\Actions\Employees\ListInactiveEmployeesAction;
use App\Actions\Employees\RestoreEmployeeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEmployeeRequest;
use App\Http\Requests\Employee\UpdateEmployeeRequest;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\PositionHistory;
use App\Models\RefAgama;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefJenjangPendidikan;
use App\Models\RefStatusPerkawinan;
use App\Models\RefUnitKerja;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PegawaiController extends Controller
{
    public static $pegawaiList = [
        [
            'id' => 1,
            'nama' => 'Ahmad Fauzi',
            'nip' => '19850312201001 1 001',
            'nik' => '3273251203850002',
            'kk' => '3273250102120045',
            'tempat_lahir' => 'Bandung',
            'tanggal_lahir' => '1985-03-12',
            'jenis_kelamin' => 'Laki-laki',
            'agama' => 'Islam',
            'status_kawin' => 'Kawin',
            'golongan_darah' => 'O',
            'alamat' => 'Jl. Buah Batu No. 120, Lengkong, Bandung',
            'telepon' => '081234567890',
            'telepon_rumah' => '0227301234',
            'email' => 'ahmadfauzi@gmail.com',
            'email_dinas' => 'ahmadfauzi@lldikti16.go.id',
            'jabatan' => 'Analis Kepegawaian',
            'pangkat' => 'Penata Tkt. I',
            'kelas_jabatan' => '8',
            'unit' => 'Bag. Umum',
            'golongan' => 'III/c',
            'jenis' => 'PNS',
            'tmt' => '2010-10-01',
            'status' => 'aktif',
            'dok' => 'ok',
            'pendidikan_terakhir' => 'Sarjana (S1)',
            'prodi_pendidikan' => 'Manajemen Sumber Daya Manusia',
            'jenis_pengangkatan' => 'PNS Formasi Umum',
            'nomor_sk' => 'SK-882-KP-2024',
            'tanggal_sk' => '2024-03-15',
            'foto' => 'ahmad_fauzi.png',
            'kinerja_baik' => true,
            'atasan_nama' => 'Yudha Prasetya, M.Kom.',
            'atasan_nip' => '19780520200212 1 002',
            'atasan_jabatan' => 'Kepala Bagian Umum',
        ],
        [
            'id' => 2,
            'nama' => 'Siti Rahayu',
            'nip' => '19901120201501 2 003',
            'nik' => '3171052011900003',
            'kk' => '3171050204160012',
            'tempat_lahir' => 'Jakarta',
            'tanggal_lahir' => '1990-11-20',
            'jenis_kelamin' => 'Perempuan',
            'agama' => 'Islam',
            'status_kawin' => 'Kawin',
            'golongan_darah' => 'A',
            'alamat' => 'Jl. Tebet Barat Dalam Raya No. 45, Tebet, Jakarta Selatan',
            'telepon' => '085298765432',
            'telepon_rumah' => '0218305678',
            'email' => 'sitirahayu@gmail.com',
            'email_dinas' => 'sitirahayu@lldikti16.go.id',
            'jabatan' => 'Analis Ahli Madya',
            'pangkat' => 'Pemula Tkt. I',
            'kelas_jabatan' => '8',
            'unit' => 'Bag. Keuangan',
            'golongan' => 'II/d',
            'jenis' => 'PNS',
            'tmt' => '2015-01-01',
            'status' => 'aktif',
            'dok' => 'warn',
            'pendidikan_terakhir' => 'Magister (S2)',
            'prodi_pendidikan' => 'Akuntansi Sektor Publik',
            'jenis_pengangkatan' => 'PNS Formasi Umum',
            'nomor_sk' => 'SK-104-JAB-2022',
            'tanggal_sk' => '2022-08-01',
            'foto' => null,
            'kinerja_baik' => true,
            'atasan_nama' => 'Dr. Hendra Wijaya, M.E.',
            'atasan_nip' => '19750912199903 1 001',
            'atasan_jabatan' => 'Kepala Bagian Keuangan',
        ],
        [
            'id' => 3,
            'nama' => 'Sabrina Rossa Adriani Wibowo',
            'nip' => '20261210820500 0 04',
            'nik' => '3578021008980005',
            'kk' => '3578021511190089',
            'tempat_lahir' => 'Surabaya',
            'tanggal_lahir' => '1998-08-10',
            'jenis_kelamin' => 'Perempuan',
            'agama' => 'Katolik',
            'status_kawin' => 'Belum Kawin',
            'golongan_darah' => 'AB',
            'alamat' => 'Griya Kertajaya Indah Regency Blok B-12, Sukolilo, Surabaya',
            'telepon' => '081285066001',
            'telepon_rumah' => null,
            'email' => 'sabrinarossa24@gmail.com',
            'email_dinas' => 'sabrina.rossa@lldikti16.go.id',
            'jabatan' => 'Analis SDM Aparatur Ahli Pertama',
            'pangkat' => 'Pemula Tkt. I',
            'kelas_jabatan' => '8',
            'unit' => 'Bag. SDM',
            'golongan' => 'III/a',
            'jenis' => 'PNS',
            'tmt' => '2026-01-01',
            'status' => 'aktif',
            'dok' => 'ok',
            'pendidikan_terakhir' => 'Sarjana (S1)',
            'prodi_pendidikan' => 'Administrasi Negara',
            'jenis_pengangkatan' => 'PNS Formasi Umum',
            'nomor_sk' => 'SK-220-PNS-2026',
            'tanggal_sk' => '2025-12-15',
            'foto' => 'sabrina_rossa.png',
            'kinerja_baik' => true,
            'atasan_nama' => 'Rina Amalia, S.Sos., M.M.',
            'atasan_nip' => '19810405200501 2 004',
            'atasan_jabatan' => 'Kepala Bagian SDM',
        ],
        [
            'id' => 4,
            'nama' => 'Cimma Sari Oktariani Di Silapu',
            'nip' => '26110820520600 0 04',
            'nik' => '7371120809960002',
            'kk' => '7371121010180034',
            'tempat_lahir' => 'Makassar',
            'tanggal_lahir' => '1996-09-08',
            'jenis_kelamin' => 'Perempuan',
            'agama' => 'Kristen',
            'status_kawin' => 'Belum Kawin',
            'golongan_darah' => 'B',
            'alamat' => 'Kompleks IDI Blok GA No. 7, Panakkukang, Makassar',
            'telepon' => '081258206006',
            'telepon_rumah' => null,
            'email' => 'sikaemma@gmail.com',
            'email_dinas' => 'cimasari@lldikti16.go.id',
            'jabatan' => 'Pranata SDM Terampil',
            'pangkat' => 'Pengatur DO',
            'kelas_jabatan' => '6',
            'unit' => 'Bag. IT',
            'golongan' => 'III/c',
            'jenis' => 'PNS',
            'tmt' => '2020-10-01',
            'status' => 'aktif',
            'dok' => 'danger',
            'pendidikan_terakhir' => 'Diploma III (D3)',
            'prodi_pendidikan' => 'Teknik Informatika',
            'jenis_pengangkatan' => 'PNS Formasi Umum',
            'nomor_sk' => 'SK-551-KP-2020',
            'tanggal_sk' => '2020-09-10',
            'foto' => null,
            'kinerja_baik' => false,
            'atasan_nama' => 'Irwan Santoso, M.T.',
            'atasan_nip' => '19800215200604 1 003',
            'atasan_jabatan' => 'Kepala Bagian IT',
        ],
        [
            'id' => 5,
            'nama' => 'Nurarningsih Dumbea, S.P.',
            'nip' => '19880123202 1 005',
            'nik' => '7501024301880001',
            'kk' => '7501021405100023',
            'tempat_lahir' => 'Gorontalo',
            'tanggal_lahir' => '1988-01-23',
            'jenis_kelamin' => 'Perempuan',
            'agama' => 'Islam',
            'status_kawin' => 'Kawin',
            'golongan_darah' => 'O',
            'alamat' => 'Jl. Panjaitan No. 89, Kota Tengah, Gorontalo',
            'telepon' => '082302200526',
            'telepon_rumah' => '0435821234',
            'email' => 'rainingdumbea47@gmail.com',
            'email_dinas' => 'nurarningsih@lldikti16.go.id',
            'jabatan' => 'Pejabat Lelang Operational',
            'pangkat' => 'Penata Tkt. I',
            'kelas_jabatan' => '7',
            'unit' => 'Bag. Umum',
            'golongan' => 'III/b',
            'jenis' => 'PPPK',
            'tmt' => '2021-11-01',
            'status' => 'aktif',
            'dok' => 'ok',
            'pendidikan_terakhir' => 'Sarjana (S1)',
            'prodi_pendidikan' => 'Agribisnis',
            'jenis_pengangkatan' => 'PPPK Tahap I',
            'nomor_sk' => 'SK-091-PPPK-2021',
            'tanggal_sk' => '2021-10-25',
            'foto' => null,
            'kinerja_baik' => true,
            'atasan_nama' => 'Yudha Prasetya, M.Kom.',
            'atasan_nip' => '19780520200212 1 002',
            'atasan_jabatan' => 'Kepala Bagian Umum',
        ],
        [
            'id' => 6,
            'nama' => 'Nadia Kusuma',
            'nip' => '19950822202001 2 002',
            'nik' => '3204286208950004',
            'kk' => '3204280203170056',
            'tempat_lahir' => 'Soreang',
            'tanggal_lahir' => '1995-08-22',
            'jenis_kelamin' => 'Perempuan',
            'agama' => 'Islam',
            'status_kawin' => 'Belum Kawin',
            'golongan_darah' => 'B',
            'alamat' => 'Kopo Elok Regency Blok C-3, Soreang, Bandung',
            'telepon' => '081299887766',
            'telepon_rumah' => null,
            'email' => 'nadiakusuma@gmail.com',
            'email_dinas' => 'nadiakusuma@lldikti16.go.id',
            'jabatan' => 'Pengelola Kepegawaian',
            'pangkat' => 'Pengatur',
            'kelas_jabatan' => '6',
            'unit' => 'Bag. SDM',
            'golongan' => 'II/c',
            'jenis' => 'PPPK',
            'tmt' => '2020-01-01',
            'status' => 'cuti',
            'dok' => 'ok',
            'pendidikan_terakhir' => 'Diploma III (D3)',
            'prodi_pendidikan' => 'Administrasi Perkantoran',
            'jenis_pengangkatan' => 'PPPK Tahap II',
            'nomor_sk' => 'SK-042-PPPK-2020',
            'tanggal_sk' => '2019-12-20',
            'foto' => null,
            'kinerja_baik' => true,
            'atasan_nama' => 'Rina Amalia, S.Sos., M.M.',
            'atasan_nip' => '19810405200501 2 004',
            'atasan_jabatan' => 'Kepala Bagian SDM',
        ],
        [
            'id' => 7,
            'nama' => 'Yucna Dara, S.P., M.M.',
            'nip' => '19840120099 2 002',
            'nik' => '3273102001840003',
            'kk' => '3273101509120087',
            'tempat_lahir' => 'Garut',
            'tanggal_lahir' => '1984-01-20',
            'jenis_kelamin' => 'Perempuan',
            'agama' => 'Islam',
            'status_kawin' => 'Kawin',
            'golongan_darah' => 'A',
            'alamat' => 'Perum Tarogong Indah No. A-9, Tarogong Kidul, Garut',
            'telepon' => '081284920002',
            'telepon_rumah' => '0262234567',
            'email' => 'hanaryog101@gmail.com',
            'email_dinas' => 'yucnadara@lldikti16.go.id',
            'jabatan' => 'Analis Ahli Pertama',
            'pangkat' => 'Penata Tkt. I',
            'kelas_jabatan' => '8',
            'unit' => 'Bag. Keuangan',
            'golongan' => 'III/b',
            'jenis' => 'PNS',
            'tmt' => '2009-01-01',
            'status' => 'aktif',
            'dok' => 'warn',
            'pendidikan_terakhir' => 'Magister (S2)',
            'prodi_pendidikan' => 'Magister Manajemen Keuangan',
            'jenis_pengangkatan' => 'PNS Formasi Umum',
            'nomor_sk' => 'SK-312-PNS-2009',
            'tanggal_sk' => '2008-12-10',
            'foto' => null,
            'kinerja_baik' => true,
            'atasan_nama' => 'Dr. Hendra Wijaya, M.E.',
            'atasan_nip' => '19750912199903 1 001',
            'atasan_jabatan' => 'Kepala Bagian Keuangan',
        ],
    ];

    public function index(Request $request)
    {
        $perPage = (int) $request->query('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;

        $unitKerjaOptions = RefUnitKerja::query()
            ->orderBy('nama')
            ->get(['id', 'nama']);
        $jenisPegawaiOptions = RefJenisPegawai::query()
            ->orderBy('nama')
            ->get(['id', 'nama']);
        $statusOptions = ['Aktif', 'Non-Aktif', 'Pensiun', 'Mutasi'];
        $golonganOptions = Employee::query()
            ->whereNotNull('golongan_terakhir')
            ->distinct()
            ->orderBy('golongan_terakhir')
            ->pluck('golongan_terakhir')
            ->map(fn (?string $golongan) => $golongan ? strtok($golongan, '/') : null)
            ->filter()
            ->unique()
            ->values();

        if ($golonganOptions->isEmpty()) {
            $golonganOptions = collect(['II', 'III', 'IV']);
        }

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'golongan' => trim((string) $request->query('golongan', '')),
            'unit_kerja_id' => trim((string) $request->query('unit_kerja_id', '')),
            'jenis_pegawai_id' => trim((string) $request->query('jenis_pegawai_id', '')),
            'status_aktif' => trim((string) $request->query('status_aktif', '')),
        ];

        // Backward-compatible query params from the pagination branch.
        if ($filters['unit_kerja_id'] === '' && $request->filled('unit')) {
            $legacyUnit = (string) $request->query('unit');
            $matchedUnit = $unitKerjaOptions->firstWhere('nama', $legacyUnit);
            $filters['unit_kerja_id'] = $matchedUnit?->id ?? '';
        }

        if ($filters['jenis_pegawai_id'] === '' && $request->filled('jenis')) {
            $legacyJenis = (string) $request->query('jenis');
            $matchedJenis = $jenisPegawaiOptions->firstWhere('nama', $legacyJenis);
            $filters['jenis_pegawai_id'] = $matchedJenis?->id ?? '';
        }

        if ($filters['status_aktif'] === '' && $request->filled('status')) {
            $legacyStatus = strtolower((string) $request->query('status'));
            $filters['status_aktif'] = match ($legacyStatus) {
                'aktif' => 'Aktif',
                'nonaktif', 'non-aktif' => 'Non-Aktif',
                'pensiun' => 'Pensiun',
                'mutasi' => 'Mutasi',
                default => '',
            };
        }

        if ($request->query('filter') === 'pensiun' && $filters['status_aktif'] === '') {
            $filters['status_aktif'] = 'Pensiun';
        }

        if (! $unitKerjaOptions->contains('id', $filters['unit_kerja_id'])) {
            $filters['unit_kerja_id'] = '';
        }

        if (! $jenisPegawaiOptions->contains('id', $filters['jenis_pegawai_id'])) {
            $filters['jenis_pegawai_id'] = '';
        }

        if (! in_array($filters['status_aktif'], $statusOptions, true)) {
            $filters['status_aktif'] = '';
        }

        if ($filters['golongan'] !== '' && ! $golonganOptions->contains($filters['golongan'])) {
            $filters['golongan'] = '';
        }

        $allowedSorts = ['pegawai', 'jabatan', 'golongan', 'tmt'];
        $sort = $request->query('sort', 'pegawai');
        $sort = in_array($sort, $allowedSorts, true) ? $sort : 'pegawai';

        $direction = strtolower((string) $request->query('direction', 'asc'));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';

        $pegawaiQuery = Employee::query()
            ->with([
                'jenisPegawai',
                'appointment',
                'positionHistories' => fn ($query) => $query
                    ->with('unitKerja')
                    ->orderByDesc('is_latest')
                    ->orderByDesc('tmt_jabatan'),
            ]);

        if ($filters['search'] !== '') {
            $search = mb_strtolower($filters['search']);
            $pegawaiQuery->where(function ($query) use ($search): void {
                $query
                    ->whereRaw('LOWER(nama_lengkap) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(nip) LIKE ?', ["%{$search}%"]);
            });
        }

        if ($filters['golongan'] !== '') {
            $pegawaiQuery->where('golongan_terakhir', 'like', $filters['golongan'].'%');
        }

        if ($filters['unit_kerja_id'] !== '') {
            $pegawaiQuery->whereHas('positionHistories', function ($query) use ($filters): void {
                $query
                    ->where('unit_kerja_id', $filters['unit_kerja_id'])
                    ->where('is_latest', true);
            });
        }

        if ($filters['jenis_pegawai_id'] !== '') {
            $pegawaiQuery->where('jenis_pegawai_id', $filters['jenis_pegawai_id']);
        }

        if ($filters['status_aktif'] !== '') {
            $pegawaiQuery->where('status_aktif', $filters['status_aktif']);
        }

        match ($sort) {
            'jabatan' => $pegawaiQuery
                ->orderBy('jabatan_terakhir', $direction)
                ->orderBy('nama_lengkap'),
            'golongan' => $pegawaiQuery
                ->orderBy('golongan_terakhir', $direction)
                ->orderBy('nama_lengkap'),
            'tmt' => $pegawaiQuery
                ->orderBy(
                    PositionHistory::query()
                        ->select('tmt_jabatan')
                        ->whereColumn('position_histories.employee_id', 'employees.id')
                        ->orderByDesc('is_latest')
                        ->orderByDesc('tmt_jabatan')
                        ->limit(1),
                    $direction
                )
                ->orderBy(
                    Appointment::query()
                        ->select('tmt_pengangkatan')
                        ->whereColumn('appointments.employee_id', 'employees.id')
                        ->orderBy('tmt_pengangkatan')
                        ->limit(1),
                    $direction
                )
                ->orderBy('nama_lengkap'),
            default => $pegawaiQuery
                ->orderBy('nama_lengkap', $direction)
                ->orderBy('nip'),
        };

        $pegawaiData = $pegawaiQuery
            ->paginate($perPage)
            ->withQueryString();

        // Data referensi untuk modal "Tambah Riwayat" langsung dari halaman daftar pegawai.
        $golonganRefOptions = RefGolongan::orderBy('kode')->get();
        $jenisJabatanOptions = RefJenisJabatan::orderBy('nama')->get();
        $eselonOptions = RefEselon::orderBy('nama')->get();

        return view('admin.pegawai.index', compact(
            'pegawaiData',
            'perPage',
            'sort',
            'direction',
            'filters',
            'golonganOptions',
            'unitKerjaOptions',
            'jenisPegawaiOptions',
            'statusOptions',
            'golonganRefOptions',
            'jenisJabatanOptions',
            'eselonOptions'
        ));
    }

    public function create()
    {
        $jenisPegawai = RefJenisPegawai::all();
        $agama = RefAgama::all();
        $statusKawin = RefStatusPerkawinan::all();
        $unitKerja = RefUnitKerja::all();
        $jenisJabatanOptions = RefJenisJabatan::all();

        return view('admin.pegawai.create', compact('jenisPegawai', 'agama', 'statusKawin', 'unitKerja', 'jenisJabatanOptions'));
    }

    public function inactive(Request $request, ListInactiveEmployeesAction $action)
    {
        $unitKerjaOptions = RefUnitKerja::query()
            ->orderBy('nama')
            ->get(['id', 'nama']);
        $jenisPegawaiOptions = RefJenisPegawai::query()
            ->orderBy('nama')
            ->get(['id', 'nama']);
        $golonganOptions = Employee::query()
            ->whereNotNull('golongan_terakhir')
            ->distinct()
            ->orderBy('golongan_terakhir')
            ->pluck('golongan_terakhir')
            ->map(fn (?string $golongan) => $golongan ? strtok($golongan, '/') : null)
            ->filter()
            ->unique()
            ->values();

        if ($golonganOptions->isEmpty()) {
            $golonganOptions = collect(['II', 'III', 'IV']);
        }

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'golongan' => trim((string) $request->query('golongan', '')),
            'unit_kerja_id' => trim((string) $request->query('unit_kerja_id', '')),
            'jenis_pegawai_id' => trim((string) $request->query('jenis_pegawai_id', '')),
        ];

        $employees = $action->execute($filters);

        return view('admin.pegawai.nonaktif', compact(
            'employees',
            'filters',
            'unitKerjaOptions',
            'jenisPegawaiOptions',
            'golonganOptions'
        ));
    }

    public function store(StoreEmployeeRequest $request)
    {
        $validated = $request->validated();

        try {
            DB::beginTransaction();

            // Handle Photo
            if ($request->hasFile('foto') && $request->file('foto')->isValid()) {
                $file = $request->file('foto');
                $filename = $file->hashName();
                $file->move(storage_path('app/public/employees/photos'), $filename);
                $path = 'employees/photos/'.$filename;

                if ($path) {
                    $validated['foto'] = $path;
                } else {
                    unset($validated['foto']);
                }
            } else {
                unset($validated['foto']);
            }

            // Create Employee
            $employee = Employee::create($validated);

            // Handle Appointment (SK Pengangkatan)
            $appointmentData = [
                'employee_id' => $employee->id,
                'jenis_pengangkatan' => $validated['jenis_pengangkatan'],
                'tmt_pengangkatan' => $validated['tmt'],
                'no_sk' => $validated['nomor_sk'],
                'tanggal_sk' => $validated['tanggal_sk'],
            ];

            if ($request->hasFile('file_sk') && $request->file('file_sk')->isValid()) {
                $skFile = $request->file('file_sk');
                $skFilename = $skFile->hashName();
                $skFile->move(storage_path('app/public/appointments/sk'), $skFilename);
                $appointmentData['file_sk'] = 'appointments/sk/'.$skFilename;
            }

            Appointment::create($appointmentData);

            if (! empty($validated['jabatan_terakhir']) || ! empty($validated['unit_kerja_id'])) {
                PositionHistory::create([
                    'employee_id' => $employee->id,
                    'nama_jabatan' => $validated['jabatan_terakhir'] ?? '-',
                    'jenis_jabatan_id' => $validated['jenis_jabatan_id'] ?? RefJenisJabatan::first()->id,
                    'unit_kerja_id' => $validated['unit_kerja_id'] ?? RefUnitKerja::first()->id,
                    'tmt_jabatan' => $validated['tmt'] ?? now()->format('Y-m-d'),
                    'no_sk' => $validated['nomor_sk'] ?? '-',
                    'tanggal_sk' => $validated['tanggal_sk'] ?? now()->format('Y-m-d'),
                    'is_latest' => true,
                ]);
            }

            AuditService::log('CREATE', 'Employee', $employee->id, null, $employee->toArray(), $request);

            DB::commit();

            return redirect()->route('data-pegawai')
                ->with('success', 'Data pegawai '.$employee->nama_lengkap.' berhasil ditambahkan.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->with('error', 'Gagal menambahkan pegawai: '.$e->getMessage());
        }
    }

    public function show($id)
    {
        $p = Employee::with([
            'families',
            'rankHistories',
            'positionHistories',
            'salaryHistories',
            'disciplineRecords',
            'educationHistories',
            'documents',
            'agama',
            'statusKawin',
            'jenisPegawai',
        ])->findOrFail($id);

        $golonganOptions = RefGolongan::all();
        $jenisJabatanOptions = RefJenisJabatan::all();
        $unitKerjaOptions = RefUnitKerja::all();
        $eselonOptions = RefEselon::all();

        return view('admin.pegawai.show', compact('p', 'golonganOptions', 'jenisJabatanOptions', 'unitKerjaOptions', 'eselonOptions'));
    }

    public function edit($id)
    {
        $p = Employee::with([
            'appointment',
            'positionHistories.unitKerja',
            'positionHistories.jenisJabatan',
            'rankHistories.golongan',
            'salaryHistories'
        ])->findOrFail($id);

        $jenisPegawai = RefJenisPegawai::all();
        $agama = RefAgama::all();
        $statusKawin = RefStatusPerkawinan::all();
        $unitKerja = RefUnitKerja::all();
        $jenisJabatanOptions = RefJenisJabatan::all();
        $golonganRefOptions = RefGolongan::orderBy('kode')->get();
        $eselonOptions = RefEselon::orderBy('nama')->get();

        return view('admin.pegawai.edit', compact(
            'p',
            'jenisPegawai',
            'agama',
            'statusKawin',
            'unitKerja',
            'jenisJabatanOptions',
            'golonganRefOptions',
            'eselonOptions'
        ));
    }

    public function update(UpdateEmployeeRequest $request, $id)
    {
        $employee = Employee::findOrFail($id);
        $validated = $request->validated();

        try {
            DB::beginTransaction();

            $oldValues = $employee->toArray();

            Log::info('Update Request received for employee '.$employee->id);
            Log::info('Files uploaded keys:', array_keys($request->allFiles()));
            Log::info('Has foto?', ['has' => $request->hasFile('foto')]);
            if ($request->hasFile('foto') && $request->file('foto')->isValid()) {
                // Delete old photo if needed (omitted for brevity)
                $file = $request->file('foto');
                $filename = $file->hashName();
                $file->move(storage_path('app/public/employees/photos'), $filename);
                $path = 'employees/photos/'.$filename;

                if ($path) {
                    Log::info('Stored foto at:', ['path' => $path]);
                    $validated['foto'] = $path;
                } else {
                    Log::error('Store foto failed');
                    unset($validated['foto']);
                }
            } else {
                Log::warning('Foto not valid or not present', ['error' => $request->hasFile('foto') ? $request->file('foto')->getErrorMessage() : 'No file']);
                unset($validated['foto']);
            }

            // Update Employee
            $employee->update($validated);

            // 1. Pangkat (RankHistory)
            // Only process if the essential fields (golongan_id AND no_sk AND dates) are all present
            if ($request->filled('pangkat_golongan_id') && $request->filled('pangkat_no_sk')
                && $request->filled('pangkat_tanggal_sk') && $request->filled('pangkat_tmt_pangkat')) {
                $pangkatData = [
                    'golongan_id' => $validated['pangkat_golongan_id'],
                    'no_sk' => $validated['pangkat_no_sk'] ?? null,
                    'tanggal_sk' => $validated['pangkat_tanggal_sk'] ?? null,
                    'tmt_pangkat' => $validated['pangkat_tmt_pangkat'] ?? null,
                ];

                if ($request->hasFile('file_sk_pangkat') && $request->file('file_sk_pangkat')->isValid()) {
                    $file = $request->file('file_sk_pangkat');
                    $filename = $file->hashName();
                    $file->move(storage_path('app/public/ranks/sk'), $filename);
                    $pangkatData['file_sk'] = 'ranks/sk/'.$filename;
                }

                $pangkatId = $request->input('pangkat_history_id');
                if ($pangkatId && $pangkatId !== 'new') {
                    $history = $employee->rankHistories()->find($pangkatId);
                    if ($history) {
                        $history->update($pangkatData);
                        if ($history->is_latest) {
                            $golongan = RefGolongan::find($validated['pangkat_golongan_id']);
                            if ($golongan) {
                                $employee->update([
                                    'golongan_terakhir' => $golongan->kode,
                                    'pangkat_terakhir' => $golongan->nama,
                                ]);
                            }
                        }
                    }
                } else {
                    $employee->rankHistories()->update(['is_latest' => false]);
                    $pangkatData['is_latest'] = true;
                    $employee->rankHistories()->create($pangkatData);
                    
                    $golongan = RefGolongan::find($validated['pangkat_golongan_id']);
                    if ($golongan) {
                        $employee->update([
                            'golongan_terakhir' => $golongan->kode,
                            'pangkat_terakhir' => $golongan->nama,
                        ]);
                    }
                }
            }

            // 2. Jabatan (PositionHistory)
            // Only process if ALL essential fields are present (including jenis_jabatan_id which is NOT NULL in DB)
            if ($request->filled('jabatan_nama_jabatan') && $request->filled('jabatan_jenis_jabatan_id')
                && $request->filled('jabatan_unit_kerja_id') && $request->filled('jabatan_no_sk')
                && $request->filled('jabatan_tanggal_sk') && $request->filled('jabatan_tmt_jabatan')) {
                $jabatanData = [
                    'nama_jabatan' => $validated['jabatan_nama_jabatan'],
                    'jenis_jabatan_id' => $validated['jabatan_jenis_jabatan_id'],
                    'eselon_id' => $validated['jabatan_eselon_id'] ?? null,
                    'unit_kerja_id' => $validated['jabatan_unit_kerja_id'],
                    'no_sk' => $validated['jabatan_no_sk'],
                    'tanggal_sk' => $validated['jabatan_tanggal_sk'],
                    'tmt_jabatan' => $validated['jabatan_tmt_jabatan'],
                ];

                if ($request->hasFile('file_sk_jabatan') && $request->file('file_sk_jabatan')->isValid()) {
                    $file = $request->file('file_sk_jabatan');
                    $filename = $file->hashName();
                    $file->move(storage_path('app/public/positions/sk'), $filename);
                    $jabatanData['file_sk'] = 'positions/sk/'.$filename;
                }

                $jabatanId = $request->input('jabatan_history_id');
                if ($jabatanId && $jabatanId !== 'new') {
                    $history = $employee->positionHistories()->find($jabatanId);
                    if ($history) {
                        $history->update($jabatanData);
                        if ($history->is_latest) {
                            $employee->update([
                                'jabatan_terakhir' => $validated['jabatan_nama_jabatan'],
                            ]);
                        }
                    }
                } else {
                    $employee->positionHistories()->update(['is_latest' => false]);
                    $jabatanData['is_latest'] = true;
                    $employee->positionHistories()->create($jabatanData);
                    
                    $employee->update([
                        'jabatan_terakhir' => $validated['jabatan_nama_jabatan'],
                    ]);
                }
            }

            // 3. KGB (SalaryHistory)
            // Only process if essential fields are all present
            if ($request->filled('kgb_gaji_pokok') && $request->filled('kgb_no_sk')
                && $request->filled('kgb_tanggal_sk') && $request->filled('kgb_tmt_kgb')) {
                $kgbData = [
                    'gaji_pokok' => $validated['kgb_gaji_pokok'] ?? null,
                    'no_sk' => $validated['kgb_no_sk'] ?? null,
                    'tanggal_sk' => $validated['kgb_tanggal_sk'] ?? null,
                    'tmt_kgb' => $validated['kgb_tmt_kgb'] ?? null,
                ];

                if ($request->hasFile('file_sk_kgb') && $request->file('file_sk_kgb')->isValid()) {
                    $file = $request->file('file_sk_kgb');
                    $filename = $file->hashName();
                    $file->move(storage_path('app/public/salaries/sk'), $filename);
                    $kgbData['file_sk'] = 'salaries/sk/'.$filename;
                }

                $kgbId = $request->input('kgb_history_id');
                if ($kgbId && $kgbId !== 'new') {
                    $history = $employee->salaryHistories()->find($kgbId);
                    if ($history) {
                        $history->update($kgbData);
                    }
                } else {
                    $employee->salaryHistories()->update(['is_latest' => false]);
                    $kgbData['is_latest'] = true;
                    $employee->salaryHistories()->create($kgbData);
                }
            }

            // 4. Pengangkatan (Appointment)
            if ($request->filled('pengangkatan_jenis_pengangkatan')) {
                $appointmentData = [
                    'jenis_pengangkatan' => $validated['pengangkatan_jenis_pengangkatan'],
                    'tmt_pengangkatan' => $validated['pengangkatan_tmt_pengangkatan'] ?? null,
                    'no_sk' => $validated['pengangkatan_no_sk'] ?? null,
                    'tanggal_sk' => $validated['pengangkatan_tanggal_sk'] ?? null,
                ];

                if ($request->hasFile('file_sk_pengangkatan') && $request->file('file_sk_pengangkatan')->isValid()) {
                    $file = $request->file('file_sk_pengangkatan');
                    $filename = $file->hashName();
                    $file->move(storage_path('app/public/appointments/sk'), $filename);
                    $appointmentData['file_sk'] = 'appointments/sk/'.$filename;
                }

                $appointment = $employee->appointment;
                if ($appointment) {
                    $appointment->update($appointmentData);
                } else {
                    $employee->appointment()->create($appointmentData);
                }

                // Sync jenis_pegawai_id on employee based on jenis_pengangkatan (PNS/PPPK/CPNS)
                $jenisPegawai = RefJenisPegawai::whereRaw('UPPER(nama) = ?', [
                    strtoupper($validated['pengangkatan_jenis_pengangkatan'])
                ])->first();
                if ($jenisPegawai) {
                    $employee->update(['jenis_pegawai_id' => $jenisPegawai->id]);
                }
            }

            AuditService::log('UPDATE', 'Employee', $employee->id, $oldValues, $employee->toArray(), $request);

            DB::commit();

            return redirect()->route('data-pegawai')
                ->with('success', 'Data pegawai '.$employee->nama_lengkap.' berhasil diperbarui.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withInput()->with('error', 'Gagal memperbarui pegawai: '.$e->getMessage());
        }
    }

    public function destroy($id, Request $request, DeactivateEmployeeAction $action)
    {
        $employee = Employee::findOrFail($id);
        $nama = $employee->nama_lengkap;

        $action->execute($employee, $request);

        return redirect()->route('data-pegawai')
            ->with('success', 'Data pegawai '.$nama.' berhasil dinonaktifkan.');
    }

    public function bulkDestroy(Request $request, DeactivateEmployeeAction $action)
    {
        $ids = $request->input('ids', []);

        if (empty($ids)) {
            return back()->with('error', 'Tidak ada data pegawai yang dipilih.');
        }

        try {
            DB::beginTransaction();

            $employees = Employee::whereIn('id', $ids)->get();
            $count = $employees->count();

            if ($count === 0) {
                DB::rollBack();

                return back()->with('error', 'Data pegawai tidak ditemukan.');
            }

            foreach ($employees as $employee) {
                $action->execute($employee, $request);
            }

            DB::commit();

            return back()->with('success', $count.' pegawai berhasil dinonaktifkan.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->with('error', 'Terjadi kesalahan saat menonaktifkan pegawai: '.$e->getMessage());
        }
    }

    public function restore($id, Request $request, RestoreEmployeeAction $action)
    {
        $employee = Employee::onlyTrashed()->findOrFail($id);
        $nama = $employee->nama_lengkap;

        $action->execute($employee, $request);

        return redirect()->route('data-nonaktif')
            ->with('success', 'Data pegawai '.$nama.' berhasil diaktifkan kembali.');
    }

    public function storeRiwayat($id, Request $request)
    {
        $employee = Employee::findOrFail($id);
        $type = $request->input('type');

        try {
            DB::beginTransaction();

            switch ($type) {
                case 'pendidikan':
                    $jenjang = RefJenjangPendidikan::where('nama', $request->input('tingkat'))->first();
                    $employee->educationHistories()->create([
                        'jenjang_id' => $jenjang ? $jenjang->id : null,
                        'nama_institusi' => $request->input('institusi'),
                        'jurusan' => $request->input('prodi'),
                        'tahun_lulus' => $request->input('lulus'),
                        'no_ijazah' => $request->input('no_ijazah'),
                    ]);
                    break;
                default:
                    throw new \Exception('Tipe riwayat tidak valid atau sudah dimigrasikan ke endpoint khusus.');
            }

            DB::commit();

            return response()->json(['success' => true, 'message' => 'Data riwayat berhasil disimpan.']);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * @return StreamedResponse
     */
    public function export(Request $request)
    {
        $requestedNips = collect($request->input('nips', []))
            ->filter(fn ($nip) => is_string($nip) && trim($nip) !== '')
            ->map(fn (string $nip) => trim($nip))
            ->unique()
            ->values();

        $query = Employee::query()->with('jenisPegawai:id,nama');

        if ($requestedNips->isNotEmpty()) {
            $query->whereIn('nip', $requestedNips->all());
        } else {
            $search = mb_strtolower(trim((string) $request->query('search', '')));
            $golongan = trim((string) $request->query('golongan', ''));
            $unitKerjaId = trim((string) $request->query('unit_kerja_id', ''));
            $jenisPegawaiId = trim((string) $request->query('jenis_pegawai_id', ''));
            $statusAktif = trim((string) $request->query('status_aktif', ''));

            if ($unitKerjaId === '' && $request->filled('unit')) {
                $unitKerjaId = RefUnitKerja::where('nama', $request->query('unit'))->value('id') ?? '';
            }
            if ($jenisPegawaiId === '' && $request->filled('jenis')) {
                $jenisPegawaiId = RefJenisPegawai::where('nama', $request->query('jenis'))->value('id') ?? '';
            }
            if ($statusAktif === '' && $request->filled('status')) {
                $statusAktif = match (strtolower((string) $request->query('status'))) {
                    'aktif' => 'Aktif', 'nonaktif', 'non-aktif' => 'Non-Aktif', 'pensiun' => 'Pensiun', 'mutasi' => 'Mutasi', default => ''
                };
            }
            if ($request->query('filter') === 'pensiun' && $statusAktif === '') {
                $statusAktif = 'Pensiun';
            }

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->whereRaw('LOWER(nama_lengkap) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(nip) LIKE ?', ["%{$search}%"]);
                });
            }
            if ($golongan !== '') {
                $query->where('golongan_terakhir', 'like', $golongan.'%');
            }
            if ($unitKerjaId !== '') {
                $query->whereHas('positionHistories', function ($q) use ($unitKerjaId) {
                    $q->where('unit_kerja_id', $unitKerjaId)->where('is_latest', true);
                });
            }
            if ($jenisPegawaiId !== '') {
                $query->where('jenis_pegawai_id', $jenisPegawaiId);
            }
            if ($statusAktif !== '') {
                $query->where('status_aktif', $statusAktif);
            }
        }

        $pegawaiData = $query->orderBy('nama_lengkap')->get();

        if ($requestedNips->isNotEmpty()) {
            $requestedOrder = $requestedNips->flip();
            $pegawaiData = $pegawaiData
                ->sortBy(fn (Employee $employee) => $requestedOrder[$employee->nip] ?? PHP_INT_MAX)
                ->values();
        }

        $spreadsheet = $this->generateExcelSpreadsheet($pegawaiData);

        $filename = 'Data_Pegawai_SIMPEG_'.now()->format('Ymd').'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function generateExcelSpreadsheet($pegawaiData): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Pegawai');
        $sheet->setShowGridlines(false);

        $cols = [
            'A' => ['No', 5],
            'B' => ['Nama Pegawai', 31],
            'C' => ['Email Pegawai', 29],
            'D' => ['Golongan', 12],
            'E' => ['Jabatan', 34],
            'F' => ['Kelas Jabatan', 15],
            'G' => ['NIP', 23],
            'H' => ['Nomor Telepon', 19],
            'I' => ['Pangkat', 18],
            'J' => ['Pendidikan Terakhir', 18],
            'K' => ['Pensiun', 20],
            'L' => ['Person', 22],
            'M' => ['Person Formula', 22],
            'N' => ['Prodi Pendidikan Terakhir', 28],
            'O' => ['Status Kepegawaian', 20],
            'P' => ['Tanggal Lahir', 20],
        ];

        foreach ($cols as $col => [$label, $width]) {
            $sheet->getColumnDimension($col)->setWidth($width);
            $sheet->setCellValue($col.'1', $label);
        }
        $sheet->getRowDimension(1)->setRowHeight(32);

        $sheet->getStyle('A1:P1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10, 'name' => 'Calibri'],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F5A83']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '69BFE3']]],
        ]);

        foreach ($pegawaiData as $i => $employee) {
            $r = $i + 2;

            $sheet->setCellValue('A'.$r, $i + 1);
            $sheet->setCellValue('B'.$r, $employee->nama_lengkap);
            $sheet->setCellValue('C'.$r, $employee->email ?? '');
            $sheet->setCellValue('D'.$r, $employee->golongan_terakhir ?? '');
            $sheet->setCellValue('E'.$r, $employee->jabatan_terakhir ?? '');
            $sheet->setCellValue('F'.$r, $employee->kelas_jabatan ?? '');
            $sheet->setCellValueExplicit('G'.$r, $employee->nip, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('H'.$r, $employee->no_hp ?? '', DataType::TYPE_STRING);
            $sheet->setCellValue('I'.$r, $employee->pangkat_terakhir ?? '');
            $sheet->setCellValue('J'.$r, $employee->pendidikan_terakhir ?? '');

            if ($employee->tanggal_pensiun !== null) {
                $sheet->setCellValue('K'.$r, Date::PHPToExcel($employee->tanggal_pensiun));
            }

            $sheet->setCellValue('L'.$r, $employee->nama_lengkap);
            $sheet->setCellValue('M'.$r, $employee->nama_lengkap);
            $sheet->setCellValue('N'.$r, $employee->prodi_pendidikan_terakhir ?? '');
            $sheet->setCellValue('O'.$r, $employee->jenisPegawai?->nama ?? '');

            if ($employee->tanggal_lahir !== null) {
                $sheet->setCellValue('P'.$r, Date::PHPToExcel($employee->tanggal_lahir));
            }

            $sheet->getRowDimension($r)->setRowHeight(21);
            $sheet->getStyle('A'.$r.':P'.$r)->applyFromArray([
                'font' => ['size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '111827']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9F2FB']],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => false],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '69BFE3']]],
            ]);
        }

        $lastRow = $pegawaiData->count() + 1;

        if ($pegawaiData->isNotEmpty()) {
            $sheet->getStyle('A2:A'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('D2:D'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('F2:K'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('O2:P'.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('K2:K'.$lastRow)->getNumberFormat()->setFormatCode('mmmm d, yyyy');
            $sheet->getStyle('P2:P'.$lastRow)->getNumberFormat()->setFormatCode('mmmm d, yyyy');
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:P'.$lastRow);
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.3)->setRight(0.25)->setBottom(0.3)->setLeft(0.25);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);

        return $spreadsheet;
    }

    public function assignAtasan(Request $request, $id, AssignSupervisorAction $action)
    {
        $request->validate([
            'supervisor_id' => 'nullable|uuid|exists:employees,id',
        ]);

        $employee = Employee::findOrFail($id);

        try {
            $action->execute($employee, $request->input('supervisor_id'), $request);

            return redirect()->route('pegawai.show', $id)
                ->with('success', 'Atasan langsung untuk '.$employee->nama_lengkap.' berhasil diperbarui.');
        } catch (\Exception $e) {
            return redirect()->route('pegawai.show', $id)
                ->with('error', 'Gagal memperbarui atasan langsung: '.$e->getMessage());
        }
    }
}
