<x-layouts.app title="Verifikasi Dokumen Cuti">
    <main class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
        <x-ui.card>
            @if ($proof === null)
                <h1 class="text-2xl font-semibold text-ink">Dokumen Tidak Ditemukan</h1>
                <p class="mt-3 text-sm text-muted">Kode verifikasi tidak valid atau dokumen tidak tersedia.</p>
            @else
                @php($leave = $proof->leaveRequest)
                <h1 class="text-2xl font-semibold text-ink">Dokumen Cuti Valid</h1>
                <p class="mt-2 text-sm text-muted">LLDIKTI Wilayah XVI</p>

                <dl class="mt-6 grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-xs text-muted">Nama Pegawai</dt><dd class="font-medium text-ink">{{ $leave?->employee?->nama_lengkap ?? '-' }}</dd></div>
                    <div><dt class="text-xs text-muted">Jenis Cuti</dt><dd class="font-medium text-ink">{{ $leave?->jenisCuti?->nama ?? '-' }}</dd></div>
                    <div><dt class="text-xs text-muted">Tanggal Mulai</dt><dd class="font-medium text-ink">{{ $leave?->tanggal_mulai?->translatedFormat('d M Y') ?? '-' }}</dd></div>
                    <div><dt class="text-xs text-muted">Tanggal Selesai</dt><dd class="font-medium text-ink">{{ $leave?->tanggal_selesai?->translatedFormat('d M Y') ?? '-' }}</dd></div>
                    <div><dt class="text-xs text-muted">Jumlah Hari Kerja</dt><dd class="font-medium text-ink">{{ $leave?->jumlah_hari_kerja ?? '-' }} hari kerja</dd></div>
                    <div><dt class="text-xs text-muted">Status Keputusan</dt><dd class="font-medium text-ink">Disetujui</dd></div>
                    <div><dt class="text-xs text-muted">Tanggal Keputusan</dt><dd class="font-medium text-ink">{{ $decisionAt?->translatedFormat('d M Y H:i') ?? '-' }}</dd></div>
                    <div><dt class="text-xs text-muted">Pejabat Final</dt><dd class="font-medium text-ink">{{ $finalApprover?->nama_lengkap ?? '-' }}</dd></div>
                </dl>
            @endif
        </x-ui.card>
    </main>
</x-layouts.app>
