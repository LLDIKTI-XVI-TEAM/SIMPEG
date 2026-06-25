<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\Request;
use App\Http\Requests\StoreEmployeeRequest;
use App\Models\Appointment;
use App\Models\PositionHistory;
use App\Models\RefJenisPegawai;
use App\Models\RefUnitKerja;
use App\Models\RefAgama;
use App\Models\RefStatusPerkawinan;
use App\Models\RefGolongan;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
            'atasan_jabatan' => 'Kepala Bagian Umum'
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
            'atasan_jabatan' => 'Kepala Bagian Keuangan'
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
            'atasan_jabatan' => 'Kepala Bagian SDM'
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
            'atasan_jabatan' => 'Kepala Bagian IT'
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
            'atasan_jabatan' => 'Kepala Bagian Umum'
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
            'atasan_jabatan' => 'Kepala Bagian SDM'
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
            'atasan_jabatan' => 'Kepala Bagian Keuangan'
        ]
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
            $pegawaiQuery->where('golongan_terakhir', 'like', $filters['golongan'] . '%');
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

        return view('admin.pegawai.index', compact(
            'pegawaiData',
            'perPage',
            'sort',
            'direction',
            'filters',
            'golonganOptions',
            'unitKerjaOptions',
            'jenisPegawaiOptions',
'statusOptions'
        ));
    }

    public function create()
    {
        $jenisPegawai = RefJenisPegawai::all();
        $agama = RefAgama::all();
        $statusKawin = RefStatusPerkawinan::all();
        
        return view('admin.pegawai.create', compact('jenisPegawai', 'agama', 'statusKawin'));
    }

    public function store(StoreEmployeeRequest $request)
    {
        $validated = $request->validated();

        try {
            DB::beginTransaction();

            // Handle Photo
            if ($request->hasFile('foto')) {
                $validated['foto'] = $request->file('foto')->store('employees/photos', 'public');
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

            if ($request->hasFile('file_sk')) {
                $appointmentData['file_sk'] = $request->file('file_sk')->store('appointments/sk', 'public');
            }

            Appointment::create($appointmentData);

            AuditService::log('CREATE', 'Employee', $employee->id, null, $employee->toArray(), $request);

            DB::commit();

            return redirect()->route('data-pegawai')
                ->with('success', 'Data pegawai ' . $employee->nama_lengkap . ' berhasil ditambahkan.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Gagal menambahkan pegawai: ' . $e->getMessage());
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
            'jenisPegawai'
        ])->findOrFail($id);

        return view('admin.pegawai.show', compact('p'));
    }

    public function edit($id)
    {
        $p = Employee::with('appointment')->findOrFail($id);
        $jenisPegawai = RefJenisPegawai::all();
        $agama = RefAgama::all();
        $statusKawin = RefStatusPerkawinan::all();
        
        return view('admin.pegawai.edit', compact('p', 'jenisPegawai', 'agama', 'statusKawin'));
    }

    public function update(\App\Http\Requests\UpdateEmployeeRequest $request, $id)
    {
        $employee = Employee::findOrFail($id);
        $validated = $request->validated();

        try {
            DB::beginTransaction();

            $oldValues = $employee->toArray();

            if ($request->hasFile('foto')) {
                // Delete old photo if needed (omitted for brevity)
                $validated['foto'] = $request->file('foto')->store('employees/photos', 'public');
            }

            $employee->update($validated);

            $appointment = $employee->appointment;
            if ($appointment) {
                $appointmentData = [
                    'jenis_pengangkatan' => $validated['jenis_pengangkatan'] ?? $appointment->jenis_pengangkatan,
                    'tmt_pengangkatan' => $validated['tmt'] ?? $appointment->tmt_pengangkatan,
                    'no_sk' => $validated['nomor_sk'] ?? $appointment->no_sk,
                    'tanggal_sk' => $validated['tanggal_sk'] ?? $appointment->tanggal_sk,
                ];

                if ($request->hasFile('file_sk')) {
                    $appointmentData['file_sk'] = $request->file('file_sk')->store('appointments/sk', 'public');
                }

                $appointment->update($appointmentData);
            }

            AuditService::log('UPDATE', 'Employee', $employee->id, $oldValues, $employee->toArray(), $request);

            DB::commit();

            return redirect()->route('data-pegawai')
                ->with('success', 'Data pegawai ' . $employee->nama_lengkap . ' berhasil diperbarui.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', 'Gagal memperbarui pegawai: ' . $e->getMessage());
        }
    }

    public function destroy($id, Request $request)
    {
        $employee = Employee::findOrFail($id);
        $nama = $employee->nama_lengkap;
        $oldValues = $employee->toArray();
        $employee->delete();
        
        AuditService::log('DELETE', 'Employee', $id, $oldValues, null, $request);

        return redirect()->route('data-pegawai')
            ->with('success', 'Data pegawai ' . $nama . ' berhasil dihapus dari sistem.');
    }
    public function storeRiwayat($id, Request $request)
    {
        $employee = Employee::findOrFail($id);
        $type = $request->input('type');
        
        try {
            DB::beginTransaction();
            
            switch ($type) {
                case 'keluarga':
                    $employee->families()->create([
                        'nama_anggota' => $request->input('nama'),
                        'hubungan' => $request->input('hubungan'),
                        'tanggal_lahir' => $request->input('tgl_lahir'),
                        'pekerjaan' => $request->input('pekerjaan'),
                        'status_tunjangan' => true,
                    ]);
                    break;
                case 'pangkat':
                    $gol = RefGolongan::where('nama', $request->input('golongan'))->first();
                    $employee->rankHistories()->create([
                        'golongan_id' => $gol ? $gol->id : null,
                        'no_sk' => $request->input('no_sk'),
                        'tanggal_sk' => $request->input('tgl_sk'),
                        'tmt_pangkat' => $request->input('tmt'),
                        'is_latest' => true,
                    ]);
                    break;
                case 'jabatan':
                    $unit = RefUnitKerja::where('nama', $request->input('unit'))->first();
                    $employee->positionHistories()->create([
                        'nama_jabatan' => $request->input('jabatan'),
                        'unit_kerja_id' => $unit ? $unit->id : null,
                        'no_sk' => $request->input('no_sk'),
                        'tanggal_sk' => $request->input('tgl_sk'),
                        'tmt_jabatan' => $request->input('tmt'),
                        'is_latest' => true,
                    ]);
                    break;
                case 'kgb':
                    $gaji = preg_replace('/[^0-9]/', '', $request->input('gaji'));
                    $employee->salaryHistories()->create([
                        'gaji_pokok' => $gaji ?: 0,
                        'no_sk' => $request->input('no_sk'),
                        'tanggal_sk' => $request->input('tgl_sk'),
                        'tmt_kgb' => $request->input('tmt'),
                        'is_latest' => true,
                    ]);
                    break;
                case 'disiplin':
                    $tglMulai = $request->input('tgl_sk');
                    $masa = $request->input('masa');
                    $tglAkhir = null;
                    if ($masa) {
                        if (stripos($masa, 'bulan') !== false) {
                            $months = (int) preg_replace('/[^0-9]/', '', $masa);
                            if ($months > 0) {
                                $tglAkhir = \Carbon\Carbon::parse($tglMulai)->addMonths($months)->format('Y-m-d');
                            }
                        } elseif (stripos($masa, 'tahun') !== false) {
                            $years = (int) preg_replace('/[^0-9]/', '', $masa);
                            if ($years > 0) {
                                $tglAkhir = \Carbon\Carbon::parse($tglMulai)->addYears($years)->format('Y-m-d');
                            }
                        }
                    }
                    $employee->disciplineRecords()->create([
                        'jenis_hukuman' => $request->input('jenis'),
                        'deskripsi' => $request->input('alasan'),
                        'no_sk' => $request->input('no_sk'),
                        'tanggal_sk' => $request->input('tgl_sk'),
                        'tanggal_mulai' => $tglMulai,
                        'tanggal_berakhir' => $tglAkhir,
                    ]);
                    break;
                case 'pendidikan':
                    $jenjang = \App\Models\RefJenjangPendidikan::where('nama', $request->input('tingkat'))->first();
                    $employee->educationHistories()->create([
                        'jenjang_id' => $jenjang ? $jenjang->id : null,
                        'nama_institusi' => $request->input('institusi'),
                        'jurusan' => $request->input('prodi'),
                        'tahun_lulus' => $request->input('lulus'),
                        'no_ijazah' => $request->input('no_ijazah'),
                    ]);
                    break;
                default:
                    throw new \Exception('Tipe riwayat tidak valid.');
            }
            
            DB::commit();
            return response()->json(['success' => true, 'message' => 'Data riwayat berhasil disimpan.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function export(Request $request)
    {
        $requestedNips = collect($request->input('nips', []))
            ->filter(fn($nip) => is_string($nip) && trim($nip) !== '')
            ->map(fn(string $nip) => trim($nip))
            ->unique()
            ->values();

        $pegawaiData = Employee::query()
            ->with('jenisPegawai:id,nama')
            ->when(
                $requestedNips->isNotEmpty(),
                fn($query) => $query->whereIn('nip', $requestedNips->all())
            )
            ->orderBy('nama_lengkap')
            ->get();

        if ($requestedNips->isNotEmpty()) {
            $requestedOrder = $requestedNips->flip();
            $pegawaiData = $pegawaiData
                ->sortBy(fn(Employee $employee) => $requestedOrder[$employee->nip] ?? PHP_INT_MAX)
                ->values();
        }

        $spreadsheet = $this->generateExcelSpreadsheet($pegawaiData);

        $filename = 'Data_Pegawai_SIMPEG_' . now()->format('Ymd') . '.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function generateExcelSpreadsheet($pegawaiData): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
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
            $sheet->setCellValue($col . '1', $label);
        }
        $sheet->getRowDimension(1)->setRowHeight(32);

        $sheet->getStyle('A1:P1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10, 'name' => 'Calibri'],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F5A83']],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => '69BFE3']]],
        ]);

        foreach ($pegawaiData as $i => $employee) {
            $r = $i + 2;

            $sheet->setCellValue('A' . $r, $i + 1);
            $sheet->setCellValue('B' . $r, $employee->nama_lengkap);
            $sheet->setCellValue('C' . $r, $employee->email ?? '');
            $sheet->setCellValue('D' . $r, $employee->golongan_terakhir ?? '');
            $sheet->setCellValue('E' . $r, $employee->jabatan_terakhir ?? '');
            $sheet->setCellValue('F' . $r, $employee->kelas_jabatan ?? '');
            $sheet->setCellValueExplicit('G' . $r, $employee->nip, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('H' . $r, $employee->no_hp ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValue('I' . $r, $employee->pangkat_terakhir ?? '');
            $sheet->setCellValue('J' . $r, $employee->pendidikan_terakhir ?? '');

            if ($employee->tanggal_pensiun !== null) {
                $sheet->setCellValue('K' . $r, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($employee->tanggal_pensiun));
            }

            $sheet->setCellValue('L' . $r, $employee->nama_lengkap);
            $sheet->setCellValue('M' . $r, $employee->nama_lengkap);
            $sheet->setCellValue('N' . $r, $employee->prodi_pendidikan_terakhir ?? '');
            $sheet->setCellValue('O' . $r, $employee->jenisPegawai?->nama ?? '');

            if ($employee->tanggal_lahir !== null) {
                $sheet->setCellValue('P' . $r, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($employee->tanggal_lahir));
            }

            $sheet->getRowDimension($r)->setRowHeight(21);
            $sheet->getStyle('A' . $r . ':P' . $r)->applyFromArray([
                'font' => ['size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '111827']],
                'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9F2FB']],
                'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => false],
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => '69BFE3']]],
            ]);
        }

        $lastRow = $pegawaiData->count() + 1;

        if ($pegawaiData->isNotEmpty()) {
            $sheet->getStyle('A2:A' . $lastRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('D2:D' . $lastRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('F2:K' . $lastRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('O2:P' . $lastRow)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('K2:K' . $lastRow)->getNumberFormat()->setFormatCode('mmmm d, yyyy');
            $sheet->getStyle('P2:P' . $lastRow)->getNumberFormat()->setFormatCode('mmmm d, yyyy');
        }

        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:P' . $lastRow);
        $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.3)->setRight(0.25)->setBottom(0.3)->setLeft(0.25);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);

        return $spreadsheet;
    }
}

