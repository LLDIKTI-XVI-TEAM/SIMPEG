<x-layouts.app title="Detail Dokumen">
    <div class="mx-auto max-w-2xl space-y-6">
        
        <x-admin.page-header title="Detail Dokumen Kepegawaian">
            <x-slot:breadcrumb>
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <a href="{{ route('dokumen') }}" class="transition-colors hover:text-ink">Dokumen</a>
                <span>/</span>
                <span class="font-medium text-ink">Detail Dokumen</span>
            </x-slot:breadcrumb>
        </x-admin.page-header>

        {{-- Detail Card --}}
        <x-ui.card padding="lg" class="space-y-6">
            
            {{-- Header info --}}
            <div class="border-b border-border pb-4 flex items-start gap-4">
                <div class="flex h-16 w-12 shrink-0 flex-col items-center justify-between rounded border border-border bg-soft p-1 shadow-sm relative">
                    <div class="w-full space-y-0.5 mt-0.5">
                        <div class="h-0.5 w-6 bg-muted/40 rounded-full mx-auto"></div>
                        <div class="h-0.5 w-5 bg-muted/40 rounded-full mx-auto"></div>
                    </div>
                    <div class="w-full bg-danger rounded-sm py-0.5 text-[8px] font-bold text-white text-center uppercase tracking-wide">
                        {{ $doc['file_extension'] }}
                    </div>
                </div>
                <div class="min-w-0">
                    <h3 class="text-lg font-bold text-ink font-sans leading-tight">{{ $doc['nama'] }}</h3>
                    <p class="text-xs text-muted font-sans mt-0.5">{{ $doc['kategori_label'] }} · {{ $doc['file_size'] }}</p>
                </div>
            </div>

            {{-- Metadata --}}
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div class="space-y-0.5">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Pemilik Dokumen (Pegawai)</span>
                    <p class="text-sm font-semibold text-ink font-sans">{{ $doc['nama_pegawai'] ?? '-' }}</p>
                    <p class="text-xs text-muted font-mono">{{ $doc['nip_pegawai'] ?? '-' }}</p>
                </div>
                <div class="space-y-0.5">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Unit Kerja</span>
                    <p class="text-sm font-semibold text-ink font-sans">{{ $doc['unit_pegawai'] ?? '-' }}</p>
                </div>
                <div class="space-y-0.5">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Nomor Dokumen</span>
                    <p class="text-sm font-semibold text-ink font-mono">{{ $doc['nomor'] }}</p>
                </div>
                <div class="space-y-0.5">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Tanggal Terbit</span>
                    <p class="text-sm font-semibold text-ink font-mono">{{ $doc['tanggal'] !== '-' ? \Carbon\Carbon::parse($doc['tanggal'])->translatedFormat('d F Y') : '-' }}</p>
                </div>
                <div class="col-span-1 sm:col-span-2 space-y-0.5">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Deskripsi Dokumen</span>
                    <p class="text-sm text-ink font-sans leading-relaxed">{{ $doc['deskripsi'] }}</p>
                </div>
                <div class="col-span-1 sm:col-span-2 space-y-0.5">
                    <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Penyimpanan File</span>
                    <p class="text-xs text-muted font-mono bg-soft/50 rounded p-2.5 border border-border overflow-x-auto">{{ $doc['file_path'] }}</p>
                </div>
            </div>

            {{-- Footer actions --}}
            <div class="border-t border-border pt-6 flex justify-end gap-3">

                <x-ui.button href="{{ route('dokumen') }}" variant="secondary">
                    Kembali ke Daftar
                </x-ui.button>
                <x-ui.button href="{{ route('dokumen.download', $doc['id']) }}" variant="primary">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    Unduh Berkas PDF
                </x-ui.button>

            </div>

        </x-ui.card>
    </div>
</x-layouts.app>
