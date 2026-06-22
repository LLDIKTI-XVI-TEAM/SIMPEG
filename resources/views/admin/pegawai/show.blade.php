<x-layouts.app title="Detail Pegawai">
    <div class="mb-4">
        <nav class="flex items-center gap-1.5 text-xs text-muted">
            <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
            <span>/</span>
            <a href="{{ route('data-pegawai') }}" class="transition-colors hover:text-ink">Data Pegawai</a>
            <span>/</span>
            <span class="font-medium text-ink">Detail</span>
        </nav>
    </div>

    <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6 max-w-4xl mx-auto">
        <div class="border-b border-border pb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-4">
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 text-xl font-bold text-primary shrink-0">
                    {{ strtoupper(substr($p['nama'], 0, 1)) }}
                </div>
                <div class="min-w-0">
                    <h2 class="text-xl font-bold text-ink font-sans leading-tight">{{ $p['nama'] }}</h2>
                    <p class="text-xs text-muted font-sans font-mono mt-0.5">NIP. {{ $p['nip'] }}</p>
                    <span class="inline-block mt-1.5 rounded-full bg-primary/10 text-primary px-2.5 py-0.5 text-xs font-semibold font-sans uppercase">{{ $p['jenis'] }}</span>
                </div>
            </div>
            <div class="flex items-center gap-3 shrink-0">
                <a href="{{ route('data-pegawai') }}" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary transition-colors hover:bg-soft">
                    Kembali
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="space-y-4">
                <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5">Informasi Jabatan</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Jabatan</span>
                        <p class="text-sm font-medium text-ink font-sans">{{ $p['jabatan'] }}</p>
                    </div>
                    <div class="space-y-1">
                        <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Unit Kerja</span>
                        <p class="text-sm font-medium text-ink font-sans">{{ $p['unit'] }}</p>
                    </div>
                    <div class="space-y-1">
                        <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Golongan / Pangkat</span>
                        <p class="text-sm font-medium text-ink font-sans">{{ $p['golongan'] }}</p>
                    </div>
                    <div class="space-y-1">
                        <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Status Kerja</span>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-success/10 text-success px-2.5 py-0.5 text-xs font-semibold font-sans mt-0.5">
                            <span class="h-1.5 w-1.5 rounded-full bg-success"></span>
                            Aktif
                        </span>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans border-b border-border pb-1.5">Hubungan & Kontak</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Alamat Email</span>
                        <p class="text-sm font-medium text-ink font-sans font-mono">{{ $p['email'] ?? '-' }}</p>
                    </div>
                    <div class="space-y-1">
                        <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Nomor Telepon</span>
                        <p class="text-sm font-medium text-ink font-sans font-mono">{{ $p['telepon'] ?? '-' }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
