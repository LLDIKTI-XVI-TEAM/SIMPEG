@php($familyMode = $mode ?? 'server')

@if($familyMode === 'alpine')
    <td class="px-4 py-3">
        <p class="font-bold font-sans" x-text="fam.nama_anggota"></p>
        <p class="text-[10px] text-muted">NIK. <span x-text="fam.nik || '-'"></span></p>
    </td>
    <td class="px-4 py-3">
        <p class="font-sans" x-text="fam.hubungan"></p>
        <p class="text-[10px] text-muted font-sans" x-text="fam.jenis_kelamin"></p>
    </td>
    <td class="px-4 py-3">
        <p class="font-sans" x-text="fam.tempat_lahir || '-'"></p>
        <p class="text-[10px] text-muted" x-text="formatDate(fam.tanggal_lahir)"></p>
    </td>
    <td class="px-4 py-3 font-sans" x-text="fam.pekerjaan || '-'"></td>
    <td class="px-4 py-3">
        <span
            class="inline-flex items-center gap-1 text-[10px] font-bold"
            :class="fam.status === 'Ditanggung' ? 'text-success' : 'text-muted'"
            x-text="fam.status"
        ></span>
    </td>
@else
    <td class="px-4 py-3">
        <p class="font-bold font-sans">{{ $family->nama_anggota }}</p>
        <p class="text-[10px] text-muted">NIK. @include('pegawai.partials.detail.sensitive-value')</p>
    </td>
    <td class="px-4 py-3">
        <p class="font-sans">{{ $family->hubungan ?: '-' }}</p>
        <p class="text-[10px] text-muted font-sans">{{ match ($family->jenis_kelamin) { 'L' => 'Laki-laki', 'P' => 'Perempuan', default => '-' } }}</p>
    </td>
    <td class="px-4 py-3">
        <p class="font-sans">{{ $family->tempat_lahir ?: '-' }}</p>
        <p class="text-[10px] text-muted">@include('pegawai.partials.detail.date', ['value' => $family->tanggal_lahir])</p>
    </td>
    <td class="px-4 py-3 font-sans">{{ $family->pekerjaan ?: '-' }}</td>
    <td class="px-4 py-3">
        <span class="inline-flex items-center gap-1 text-[10px] font-bold {{ $family->status_tunjangan ? 'text-success' : 'text-muted' }}">
            {{ $family->status_tunjangan ? 'Ditanggung' : 'Tidak Ditanggung' }}
        </span>
    </td>
@endif
