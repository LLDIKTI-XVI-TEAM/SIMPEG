<?php
$count = 0;
foreach (\App\Models\Employee::has('educationHistories')->get() as $emp) {
    $latest = $emp->educationHistories()
        ->leftJoin('ref_jenjang_pendidikan', 'education_histories.jenjang_id', '=', 'ref_jenjang_pendidikan.id')
        ->orderByDesc('ref_jenjang_pendidikan.urutan')
        ->orderByDesc('education_histories.tahun_lulus')
        ->select('education_histories.*', 'ref_jenjang_pendidikan.nama as jenjang_nama')
        ->first();

    if ($latest && $emp->pendidikan_terakhir !== $latest->jenjang_nama) {
        $emp->updateQuietly([
            'pendidikan_terakhir' => $latest->jenjang_nama,
            'prodi_pendidikan_terakhir' => $latest->jurusan,
        ]);
        $count++;
    }
}
echo "$count employees updated.\n";
