<x-layouts.app title="Semua Notifikasi">
    <div class="space-y-6 max-w-4xl mx-auto">
        
        {{-- Breadcrumbs & Title --}}
        <div class="flex flex-col gap-1.5 bg-surface border border-border rounded-lg p-6 shadow-sm">
            <h2 class="text-2xl font-bold text-ink font-sans">Pusat Notifikasi & Peringatan</h2>
            <p class="text-xs text-muted mt-0.5 font-sans">Semua notifikasi administrasi kepegawaian mengenai batas waktu kontrak, pensiun, dan kelengkapan berkas.</p>
            <nav class="mt-2 flex items-center gap-1.5 text-xs text-muted">
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink">Dashboard</a>
                <span>/</span>
                <span class="font-medium text-ink">Notifikasi</span>
            </nav>
        </div>

        {{-- Log Cards --}}
        <div class="space-y-4">
            
            {{-- Notif 1 --}}
            <div class="rounded-lg border border-border border-l-4 border-l-danger bg-surface p-5 shadow-sm flex gap-4 items-start hover:bg-soft/20 transition-colors">
                <div class="rounded-lg bg-danger/10 p-2.5 text-danger shrink-0 mt-0.5">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                </div>
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-danger font-sans">Dokumen Kadaluarsa (H-30)</span>
                        <span class="text-[10px] text-muted font-sans font-mono shrink-0">19 Juni 2026, 11:25</span>
                    </div>
                    <h3 class="text-sm font-bold text-ink font-sans leading-snug">Berkas SK Pengangkatan Budi Santoso Segera Berakhir</h3>
                    <p class="text-xs text-muted font-sans leading-relaxed">Masa berlaku dokumen penting kepegawaian (SK Pengangkatan) atas nama Budi Santoso tersisa kurang dari 30 hari. Diperlukan unggah berkas pembaharuan berkas SK terbaru agar data kepegawaian tetap valid.</p>
                    <div class="pt-2 flex gap-3">
                        <a href="{{ route('dokumen') }}" class="text-xs font-semibold text-primary hover:underline font-sans">Buka Dokumen</a>
                        <span class="text-border">|</span>
                        <a href="{{ route('data-pegawai') }}" class="text-xs font-semibold text-primary hover:underline font-sans">Detail Pegawai</a>
                    </div>
                </div>
            </div>

            {{-- Notif 2 --}}
            <div class="rounded-lg border border-border border-l-4 border-l-warning bg-surface p-5 shadow-sm flex gap-4 items-start hover:bg-soft/20 transition-colors">
                <div class="rounded-lg bg-warning/10 p-2.5 text-warning shrink-0 mt-0.5">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                </div>
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-warning font-sans">Masa Pensiun Mendekat (H-60)</span>
                        <span class="text-[10px] text-muted font-sans font-mono shrink-0">18 Juni 2026, 14:10</span>
                    </div>
                    <h3 class="text-sm font-bold text-ink font-sans leading-snug">Persiapan Pensiun Batas Usia Pensiun (BUP) Siti Rahayu</h3>
                    <p class="text-xs text-muted font-sans leading-relaxed">Pegawai atas nama Siti Rahayu (NIP: 19901120201501 2 003) mendekati masa pensiun reguler dalam kurun waktu 60 hari ke depan (Februari 2026). Harap dipersiapkan berkas administrasi pensiun dan mutasi pengganti unit kerja terkait.</p>
                    <div class="pt-2 flex gap-3">
                        <a href="{{ route('data-pegawai') }}" class="text-xs font-semibold text-primary hover:underline font-sans">Tinjau Pegawai</a>
                    </div>
                </div>
            </div>

            {{-- Notif 3 --}}
            <div class="rounded-lg border border-border border-l-4 border-l-info bg-surface p-5 shadow-sm flex gap-4 items-start hover:bg-soft/20 transition-colors">
                <div class="rounded-lg bg-info/10 p-2.5 text-info shrink-0 mt-0.5">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z" />
                    </svg>
                </div>
                <div class="min-w-0 flex-1 space-y-1">
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] font-bold uppercase tracking-wider text-info font-sans">Kontrak PPNPN (H-90)</span>
                        <span class="text-[10px] text-muted font-sans font-mono shrink-0">15 Juni 2026, 09:30</span>
                    </div>
                    <h3 class="text-sm font-bold text-ink font-sans leading-snug">Peninjauan Perpanjangan Kontrak PPNPN Dewi Pertiwi</h3>
                    <p class="text-xs text-muted font-sans leading-relaxed">Masa berlaku kontrak kepegawaian tenaga PPNPN atas nama Dewi Pertiwi akan berakhir dalam 90 hari. Harap dilakukan peninjauan kinerja berkala sebelum penandatanganan addendum perpanjangan kontrak.</p>
                    <div class="pt-2 flex gap-3">
                        <a href="{{ route('data-pegawai') }}" class="text-xs font-semibold text-primary hover:underline font-sans">Kelola Kontrak</a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</x-layouts.app>
