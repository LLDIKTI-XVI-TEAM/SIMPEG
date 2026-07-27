<?php

namespace App\Http\Requests\Cuti;

use App\Models\Employee;
use App\Models\RefJenisCuti;
use App\Services\Cuti\ApprovalChainResolver;
use App\Services\Cuti\LeaveBalanceService;
use App\Services\WorkdayCalculator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Memvalidasi pengajuan cuti oleh pegawai.
 * Otorisasi ditegakkan ganda: middleware route (permission:cuti.create) dan authorize() di sini
 * agar backend tidak hanya bergantung pada penyembunyian menu/tombol di UI.
 * Aturan domain (atasan langsung, jenis cuti khusus PNS, dan kecukupan saldo) divalidasi di backend
 * sebagai sumber kebenaran, bukan sekadar batasan tampilan.
 */
class StoreLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Hanya pengguna dengan hak mengajukan cuti yang boleh menyimpan pengajuan.
        return (bool) $this->user()?->hasPermission('cuti.create');
    }

    /**
     * Memangkas spasi di sekitar kontak selama cuti sebelum validasi.
     * Snapshot kontak (alamat dan nomor telepon) adalah data PII yang direkam saat pengajuan,
     * sehingga nilai harus dinormalisasi lebih dulu agar input berisi hanya spasi ditolak sebagai kosong
     * dan batas panjang dihitung tanpa spasi tepi. Hanya nilai string yang dipangkas; nilai non-string
     * (mis. array) sengaja dibiarkan agar aturan validasi yang menangkapnya tetap berjalan.
     */
    protected function prepareForValidation(): void
    {
        $ternormalisasi = [];

        foreach (['alamat_selama_cuti', 'nomor_telepon'] as $field) {
            $nilai = $this->input($field);

            if (is_string($nilai)) {
                $ternormalisasi[$field] = trim($nilai);
            }
        }

        if ($ternormalisasi !== []) {
            $this->merge($ternormalisasi);
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'jenis_cuti_id' => ['required', 'string', 'exists:ref_jenis_cuti,id'],
            'tanggal_mulai' => ['required', 'date_format:Y-m-d'],
            // Tanggal selesai tidak boleh mendahului tanggal mulai agar rentang cuti selalu valid.
            'tanggal_selesai' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_mulai'],
            'alasan' => ['required', 'string', 'max:500'],
            // Snapshot kontak wajib direkam saat pengajuan agar approver dapat menghubungi pemohon selama cuti;
            // batas 1000 karakter menjaga alamat tetap ringkas namun cukup lengkap.
            'alamat_selama_cuti' => ['required', 'string', 'max:1000'],
            // Nomor telepon dibatasi 20 karakter dan hanya boleh berisi angka, spasi, serta simbol telepon lazim
            // (kurung, plus, minus, titik) sehingga huruf atau garis miring ditolak.
            'nomor_telepon' => ['required', 'string', 'max:20', 'regex:/^[0-9()+\-.\s]+$/'],
            // Lampiran opsional; batas 10 MB dan tipe dokumen/gambar yang lazim untuk surat pendukung.
            'lampiran' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }

    public function attributes(): array
    {
        return [
            'jenis_cuti_id' => 'jenis cuti',
            'tanggal_mulai' => 'tanggal mulai',
            'tanggal_selesai' => 'tanggal selesai',
            'alasan' => 'alasan',
            'alamat_selama_cuti' => 'alamat selama cuti',
            'nomor_telepon' => 'nomor telepon',
            'lampiran' => 'lampiran',
        ];
    }

    /**
     * Validasi aturan domain yang tidak dapat dinyatakan oleh aturan field tunggal.
     * Dijalankan setelah aturan dasar lolos agar pesan tetap fokus dan tidak menimpa error format.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Jika validasi dasar sudah gagal, lewati pemeriksaan domain agar pesan tetap relevan.
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // PRD §9.4: satu pengajuan cuti apa pun jenisnya tidak boleh melewati tahun kalender.
            // Dicek paling awal agar berlaku juga saat data pegawai/TMT belum lengkap.
            $mulai = Carbon::createFromFormat('Y-m-d', (string) $this->input('tanggal_mulai'))->startOfDay();
            $selesai = Carbon::createFromFormat('Y-m-d', (string) $this->input('tanggal_selesai'))->startOfDay();

            if ($mulai->year !== $selesai->year) {
                $validator->errors()->add(
                    'tanggal_selesai',
                    'Pengajuan cuti tidak boleh melewati tahun kalender. Pisahkan menjadi dua pengajuan terpisah untuk tiap tahun.',
                );

                return;
            }

            $employee = $this->user()?->employee;

            if ($employee === null) {
                $validator->errors()->add(
                    'tanggal_mulai',
                    'Akun pengguna belum terhubung ke data pegawai sehingga belum dapat mengajukan cuti.',
                );

                return;
            }

            // Chain dinamis wajib dapat di-resolve sebelum pengajuan disimpan agar request langsung punya snapshot step.
            try {
                app(ApprovalChainResolver::class)->resolveEffectiveSteps($employee);
            } catch (\RuntimeException $exception) {
                $validator->errors()->add(
                    'jenis_cuti_id',
                    $exception->getMessage(),
                );

                return;
            }

            $jenis = RefJenisCuti::query()->find($this->input('jenis_cuti_id'));

            // Pengaman defensif; ketidakcocokan id seharusnya sudah ditangani aturan exists di atas.
            if ($jenis === null) {
                return;
            }

            // Jenis cuti khusus PNS tidak boleh diajukan oleh pegawai non-PNS (misalnya PPPK).
            if ($jenis->khusus_pns && ! $this->employeeIsPns($employee)) {
                $validator->errors()->add(
                    'jenis_cuti_id',
                    'Jenis cuti ini hanya dapat diajukan oleh pegawai berstatus PNS.',
                );

                return;
            }

            // Hanya Cuti Tahunan yang memotong saldo; saldo tidak cukup berarti pengajuan ditolak otomatis dan tidak tersimpan.
            if ($jenis->mengurangi_saldo_tahunan) {
                $this->validateSaldoTahunan($validator, $employee);
            }
        });
    }

    /**
     * Memastikan sisa saldo cuti tahunan mencukupi jumlah hari kerja yang diminta.
     * Jumlah hari kerja dihitung di server (bukan dari input) agar pengecekan saldo tidak dapat dimanipulasi klien.
     * Bila baris saldo tahun berjalan belum ada, sisa dianggap nol sehingga pengajuan ditolak.
     */
    private function validateSaldoTahunan(Validator $validator, Employee $employee): void
    {
        $mulai = Carbon::createFromFormat('Y-m-d', (string) $this->input('tanggal_mulai'))->startOfDay();
        $selesai = Carbon::createFromFormat('Y-m-d', (string) $this->input('tanggal_selesai'))->startOfDay();

        if ($employee->appointment?->tmt_pengangkatan === null) {
            $validator->errors()->add(
                'tanggal_mulai',
                'Data TMT pengangkatan pegawai belum tersedia sehingga hak cuti tahunan belum dapat dihitung.',
            );

            return;
        }

        // Batas tahun kalender sudah ditegakkan untuk semua jenis cuti pada withValidator()
        // sebelum method ini dipanggil, sehingga rentang di sini dijamin satu tahun.
        $hariKerja = app(WorkdayCalculator::class)->calculate($mulai, $selesai);

        // Saldo dibebankan ke tahun tanggal mulai; service saldo membaca bucket ledger summary N-2/N-1/tahun berjalan.
        $sisaSaldo = app(LeaveBalanceService::class)->availableFor($employee, $mulai->year, $mulai);

        // Pengecekan saldo di sini bersifat indikatif dan tidak mengunci baris saldo. Pemotongan saldo final
        // beserta pengecekan terhadap akumulasi pengajuan yang masih menunggu harus dilakukan pada tahap
        // persetujuan dengan penguncian baris agar bebas dari kondisi balapan antar-pengajuan.
        if ($sisaSaldo < $hariKerja) {
            $validator->errors()->add(
                'tanggal_selesai',
                "Saldo cuti tahunan tidak mencukupi. Sisa saldo {$sisaSaldo} hari, sedangkan pengajuan membutuhkan {$hariKerja} hari kerja.",
            );
        }
    }

    /**
     * Menentukan apakah pegawai berstatus PNS berdasarkan referensi jenis pegawai.
     */
    private function employeeIsPns(Employee $employee): bool
    {
        return $employee->jenisPegawai?->nama === 'PNS';
    }
}
