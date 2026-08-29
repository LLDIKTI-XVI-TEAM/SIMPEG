<?php

namespace App\Observers;

use App\Models\EducationHistory;
use App\Models\Employee;
use App\Models\RefProgramStudi;

class RefProgramStudiObserver
{
    public function updated(RefProgramStudi $programStudi): void
    {
        if (! $programStudi->wasChanged('nama')) {
            return;
        }

        // Sinkronkan snapshot agar ekspor dan tampilan legacy tidak menyajikan nama lama.
        EducationHistory::where('program_studi_id', $programStudi->id)
            ->update(['jurusan' => $programStudi->nama]);
        // Sinkronkan snapshot pada seluruh pegawai, termasuk yang berstatus nonaktif.
        Employee::query()
            ->where('program_studi_id', $programStudi->id)
            ->update(['prodi_pendidikan_terakhir' => $programStudi->nama]);
    }
}
