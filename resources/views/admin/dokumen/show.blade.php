<x-layouts.app title="Detail Dokumen">
    <div class="space-y-6">
        
        {{-- PAGE HEADER --}}
        <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink font-sans">Detail Dokumen Kepegawaian</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Arsip Dokumen', 'url' => route('dokumen')],
                    ['label' => 'Detail']
                ]" />
            </div>
            <div class="flex items-center gap-2">
                <a href="javascript:void(0)" onclick="if(document.referrer.includes(window.location.hostname)) { history.back(); } else { window.location.href = '{{ route('dokumen') }}'; }" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-4 py-2 text-sm font-semibold text-primary shadow-sm transition-colors hover:bg-soft">
                    <svg class="mr-1.5 h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                    </svg>
                    Kembali
                </a>
                <a href="{{ route('dokumen.download', $doc['id']) }}" class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90">
                    <svg class="w-4 h-4 mr-1.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />
                    </svg>
                    Unduh Berkas {{ $doc['file_extension'] }}
                </a>
            </div>
        </div>

        {{-- Detail Card --}}
        <x-ui.card>
            
            {{-- Header info --}}
            <div class="border-b border-border pb-6 flex items-start gap-4">
                <div class="flex h-16 w-12 shrink-0 flex-col items-center justify-between rounded border border-border bg-soft p-1 shadow-sm relative">
                    <div class="w-full space-y-0.5 mt-0.5">
                        <div class="h-0.5 w-6 bg-muted/40 rounded-full mx-auto"></div>
                        <div class="h-0.5 w-5 bg-muted/40 rounded-full mx-auto"></div>
                    </div>
                    <div class="w-full bg-danger rounded-sm py-0.5 text-[8px] font-bold text-white text-center uppercase tracking-wide">
                        {{ $doc['file_extension'] }}
                    </div>
                </div>
                <div class="min-w-0 pt-1">
                    <h3 class="text-xl font-semibold text-ink font-sans leading-tight">{{ $doc['nama'] }}</h3>
                    <p class="text-sm text-muted font-sans mt-1">{{ $doc['kategori_label'] }} · {{ $doc['file_size'] }}</p>
                </div>
            </div>

            {{-- Metadata --}}
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 pt-6">
                <div class="space-y-1">
                    <span class="text-xs font-semibold text-muted font-sans uppercase tracking-wider">Pemilik Dokumen (Pegawai)</span>
                    <p class="text-sm font-semibold text-ink font-sans">{{ $doc['nama_pegawai'] ?? '-' }}</p>
                    <p class="text-xs text-muted">{{ $doc['nip_pegawai'] ?? '-' }}</p>
                </div>
                <div class="space-y-1">
                    <span class="text-xs font-semibold text-muted font-sans uppercase tracking-wider">Unit Kerja</span>
                    <p class="text-sm font-semibold text-ink font-sans">{{ $doc['unit_pegawai'] ?? '-' }}</p>
                </div>
                <div class="space-y-1">
                    <span class="text-xs font-semibold text-muted font-sans uppercase tracking-wider">Nomor Dokumen</span>
                    <p class="text-sm font-semibold text-ink">{{ $doc['nomor'] }}</p>
                </div>
                <div class="space-y-1">
                    <span class="text-xs font-semibold text-muted font-sans uppercase tracking-wider">Tanggal Terbit</span>
                    <p class="text-sm font-semibold text-ink">{{ $doc['tanggal'] !== '-' ? \Carbon\Carbon::parse($doc['tanggal'])->translatedFormat('d F Y') : '-' }}</p>
                </div>
                <div class="col-span-1 sm:col-span-2 space-y-1 mt-2">
                    <span class="text-xs font-semibold text-muted font-sans uppercase tracking-wider">Deskripsi Dokumen</span>
                    <p class="text-sm text-ink font-sans leading-relaxed">{{ $doc['deskripsi'] }}</p>
                </div>
                <div class="col-span-1 sm:col-span-2 space-y-1 mt-2">
                    <span class="text-xs font-semibold text-muted font-sans uppercase tracking-wider">Penyimpanan File</span>
                    <p class="text-xs text-muted bg-soft/50 rounded-lg p-3 border border-border overflow-x-auto">{{ $doc['file_path'] }}</p>
                </div>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
