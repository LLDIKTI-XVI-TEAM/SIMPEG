<?php

namespace App\Http\Requests\Cuti;

use App\Models\Employee;
use App\Models\RefJenisCuti;
use App\Services\Cuti\ApprovalChainResolver;
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

        // Cuti tahunan yang melintasi pergantian tahun belum didukung: aturan carry-over saldo antar tahun
        // belum final, sehingga membebankan seluruh hari ke saldo tahun mulai berisiko salah hitung.
        if ($mulai->year !== $selesai->year) {
            $validator->errors()->add(
                'tanggal_selesai',
                'Cuti tahunan yang melintasi pergantian tahun belum dapat diajukan. Pisahkan pengajuan untuk tiap tahun.',
            );

            return;
        }

        $hariKerja = app(WorkdayCalculator::class)->calculate($mulai, $selesai);

        // Saldo dibebankan ke tahun tanggal mulai; rentang sudah dipastikan tidak melintasi tahun di atas.
        $sisaSaldo = (int) ($employee->leaveBalances()
            ->where('tahun', $mulai->year)
            ->value('sisa') ?? 0);

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
