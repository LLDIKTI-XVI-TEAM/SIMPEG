<x-pegawai.detail.table
    name="pengangkatan"
    :headings="['Jenis Pengangkatan', 'Nomor SK Pengangkatan', 'Tanggal SK', 'TMT Pengangkatan', 'Berkas']"
    :empty="$appointment === null"
    empty-label="Belum ada data pengangkatan."
>
    @if($appointment)
        <tr class="transition-colors hover:bg-soft/30">
            <td class="px-4 py-3 font-bold">{{ $appointment->jenis_pengangkatan ?: '-' }}</td>
            <td class="px-4 py-3">{{ $appointment->no_sk ?: '-' }}</td>
            <td class="px-4 py-3">@include('pegawai.partials.detail.date', ['value' => $appointment->tanggal_sk])</td>
            <td class="px-4 py-3">@include('pegawai.partials.detail.date', ['value' => $appointment->tmt_pengangkatan])</td>
            <td class="px-4 py-3">
                @if($attachmentDownloadUrl)
                    <a href="{{ $attachmentDownloadUrl }}" class="font-semibold text-primary hover:underline">Unduh SK</a>
                @else
                    -
                @endif
            </td>
        </tr>
    @endif
</x-pegawai.detail.table>
