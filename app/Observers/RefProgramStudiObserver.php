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

        EducationHistory::where('program_studi_id', $programStudi->id)
            ->update(['jurusan' => $programStudi->nama]);
        Employee::where('program_studi_id', $programStudi->id)
            ->update(['prodi_pendidikan_terakhir' => $programStudi->nama]);
    }
}
