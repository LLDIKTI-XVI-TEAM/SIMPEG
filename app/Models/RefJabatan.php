<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $nama
 * @property string|null $jenis_jabatan_id
 * @property string|null $eselon_id
 * @property int|null $default_bup
 * @property bool $is_active
 * @property string|null $keterangan
 * @property-read RefJenisJabatan|null $jenisJabatan
 * @property-read RefEselon|null $eselon
 */
class RefJabatan extends Model
{
    use HasUuid;

    protected $table = 'ref_jabatan';

    protected $fillable = [
        'nama',
        'jenis_jabatan_id',
        'eselon_id',
        'default_bup',
        'is_active',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'default_bup' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function jenisJabatan(): BelongsTo
    {
        return $this->belongsTo(RefJenisJabatan::class, 'jenis_jabatan_id');
    }

    public function eselon(): BelongsTo
    {
        return $this->belongsTo(RefEselon::class, 'eselon_id');
    }

    /**
     * Menghitung pegawai yang penugasan terkininya memakai jabatan ini dan tanggal pensiunnya
     * sudah tersimpan.
     *
     * Tanggal pensiun pegawai adalah snapshot yang hanya diisi ketika masih kosong, karena
     * tanggal manual maupun hasil impor dianggap data resmi dan tidak boleh tertimpa. Akibatnya
     * mengubah dasar perhitungan pensiun pada jabatan tidak menyinkronkan snapshot yang sudah
     * terisi, sehingga jumlah ini dipakai untuk memperingatkan admin secara terbuka.
     *
     * Urutan penentuan riwayat terkini sengaja disalin dari kalkulator TMT dan mesin EWS, sebab
     * hanya penugasan terkini yang menjadi dasar perhitungan. Pegawai yang pernah memegang
     * jabatan ini tetapi sudah berpindah tidak terpengaruh dan tidak boleh ikut dihitung.
     */
    public function jumlahPemegangDenganTanggalPensiunTersimpan(): int
    {
        return Employee::query()
            ->whereNotNull('tanggal_pensiun')
            ->where(function ($query): void {
                $query->select('jabatan_id')
                    ->from('position_histories')
                    ->whereColumn('position_histories.employee_id', 'employees.id')
                    ->whereNotNull('tmt_jabatan')
                    ->orderByDesc('tmt_jabatan')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(1);
            }, $this->id)
            ->count();
    }
}
