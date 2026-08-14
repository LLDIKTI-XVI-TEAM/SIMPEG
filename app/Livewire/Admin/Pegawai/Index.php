<?php

namespace App\Livewire\Admin\Pegawai;

use App\Models\Employee;
use App\Models\RefEselon;
use App\Models\RefGolongan;
use App\Models\RefJabatan;
use App\Models\RefJenisJabatan;
use App\Models\RefJenisPegawai;
use App\Models\RefStatusPegawai;
use App\Models\RefUnitKerja;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Data Pegawai')]
class Index extends Component
{
    public function render(Request $request)
    {
        $perPage = (int) $request->query('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50], true) ? $perPage : 10;

        $unitKerjaOptions = Cache::remember('ref.unit_kerja', now()->addHours(6), function () {
            return RefUnitKerja::query()->orderBy('nama')->get(['id', 'nama']);
        });
        $jenisPegawaiOptions = Cache::remember('ref.jenis_pegawai', now()->addHours(6), function () {
            return RefJenisPegawai::query()->orderBy('nama')->get(['id', 'nama']);
        });
        $statusOptions = Cache::remember('ref.status_pegawai', now()->addHours(6), function () {
            return RefStatusPegawai::query()->orderByDesc('is_default')->orderBy('nama')->get(['id', 'nama']);
        });
        $golonganOptions = Cache::remember('ref.golongan_distinct', now()->addHours(1), function () {
            $opts = Employee::query()
                ->whereNotNull('golongan_terakhir')
                ->distinct()
                ->orderBy('golongan_terakhir')
                ->pluck('golongan_terakhir')
                ->map(fn (?string $golongan) => $golongan ? strtok($golongan, '/') : null)
                ->filter()
                ->unique()
                ->values();

            return $opts->isEmpty() ? collect(['II', 'III', 'IV']) : $opts;
        });
        $golonganRefOptions = Cache::remember('ref.golongan', now()->addHours(6), function () {
            return RefGolongan::orderBy('kode')->get();
        });
        $jabatanOptions = Cache::remember('ref.jabatan_with_jenis', now()->addHours(6), function () {
            return RefJabatan::with('jenisJabatan')->orderBy('nama')->get();
        });
        $jenisJabatanOptions = Cache::remember('ref.jenis_jabatan', now()->addHours(6), function () {
            return RefJenisJabatan::orderBy('nama')->get();
        });
        $eselonOptions = Cache::remember('ref.eselon', now()->addHours(6), function () {
            return RefEselon::orderBy('nama')->get();
        });

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'golongan' => trim((string) $request->query('golongan', '')),
            'unit_kerja_id' => trim((string) $request->query('unit_kerja_id', '')),
            'jenis_pegawai_id' => trim((string) $request->query('jenis_pegawai_id', '')),
            'status_pegawai_id' => trim((string) $request->query('status_pegawai_id', 'all')),
            'status_aktif' => trim((string) $request->query('status_aktif', '')),
            'show_nonaktif' => $request->boolean('show_nonaktif'),
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

        if (($filters['status_pegawai_id'] === '' || $filters['status_pegawai_id'] === 'all') && $filters['status_aktif'] !== '') {
            $filters['status_pegawai_id'] = $statusOptions->firstWhere('nama', $filters['status_aktif'])?->id ?? 'all';
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
        if ($filters['status_pegawai_id'] !== 'all' && ! $statusOptions->contains('id', $filters['status_pegawai_id'])) {
            $filters['status_pegawai_id'] = '';
        }
        if ($filters['status_aktif'] !== '' && ! $statusOptions->contains('nama', $filters['status_aktif'])) {
            $filters['status_aktif'] = '';
        }
        if ($filters['golongan'] !== '' && ! $golonganOptions->contains($filters['golongan'])) {
            $filters['golongan'] = '';
        }

        $allowedSorts = ['nama_lengkap', 'jabatan_terakhir', 'golongan_terakhir', 'created_at'];
        $sort = $request->query('sort', 'nama_lengkap');
        $sort = in_array($sort, $allowedSorts, true) ? $sort : 'nama_lengkap';

        $direction = strtolower((string) $request->query('direction', 'asc'));
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : 'asc';

        $initialRows = [];
        $initialMeta = [
            'total' => 0,
            'current_page' => 1,
            'last_page' => 1,
            'from' => 0,
            'to' => 0,
            'per_page' => $perPage,
        ];

        // Perubahan status tetap merupakan kewenangan Super Admin. Form ditampilkan
        // di konteks baris pegawai agar operator tidak perlu berpindah halaman.
        $canChangeStatus = $request->user()?->role === 'super_admin';
        $statusChangeOptions = $canChangeStatus
            ? RefStatusPegawai::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('nama')->get(['id', 'nama'])
            : collect();

        $statusFormEmployee = null;
        $oldEmployeeId = old('pegawai_id');
        if (is_string($oldEmployeeId) && Str::isUuid($oldEmployeeId)) {
            $statusFormEmployee = Employee::query()
                ->select(['id', 'nama_lengkap', 'nip'])
                ->find($oldEmployeeId);
        }

        $statusFormErrors = ['pegawai_id', 'status_pegawai_id', 'tanggal', 'keterangan', 'berkas'];
        $statusErrorBag = $request->session()->get('errors');
        $openStatusModal = session('open_status_modal', false)
            || ($statusErrorBag !== null && $statusErrorBag->hasAny($statusFormErrors));

        return view('admin.pegawai.index', compact(
            'perPage',
            'sort',
            'direction',
            'filters',
            'initialRows',
            'initialMeta',
            'golonganOptions',
            'unitKerjaOptions',
            'jenisPegawaiOptions',
            'statusOptions',
            'golonganRefOptions',
            'jabatanOptions',
            'jenisJabatanOptions',
            'eselonOptions',
            'canChangeStatus',
            'statusChangeOptions',
            'statusFormEmployee',
            'openStatusModal'
        ));
    }
}
