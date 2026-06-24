<x-layouts.app title="Edit Hari Libur">
    <div class="mx-auto max-w-2xl space-y-6">
        
        {{-- Breadcrumbs & Title --}}
        <div class="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex flex-col gap-1">
                <h2 class="text-2xl font-bold text-primary font-sans">Edit Hari Libur</h2>
                <nav class="flex items-center gap-1.5 text-xs text-muted font-sans">
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink font-sans">Dashboard</a>
                    <span>/</span>
                    <a href="{{ route('hari-libur') }}" class="transition-colors hover:text-ink font-sans">Hari Libur</a>
                    <span>/</span>
                    <span class="font-medium text-ink font-sans">Edit - {{ $hl['nama'] }}</span>
                </nav>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-danger shadow-sm">
                    ⚠️ Akses: Khusus Super Admin
                </span>
                <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-info shadow-sm">
                    🔒 Audit Trail Aktif
                </span>
            </div>
        </div>

        {{-- Form Card --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm">
            <form action="{{ route('hari-libur.update', $hl['id']) }}" method="POST" class="space-y-6">
                @csrf

                <div class="space-y-4">
                    {{-- Tanggal --}}
                    <div class="space-y-1">
                        <label for="tanggal" class="text-sm font-semibold text-ink font-sans">Tanggal <span class="text-danger">*</span></label>
                        <input id="tanggal" name="tanggal" type="date" required value="{{ $hl['tanggal'] }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    {{-- Nama Hari Libur --}}
                    <div class="space-y-1">
                        <label for="nama" class="text-sm font-semibold text-ink font-sans">Nama Hari Libur <span class="text-danger">*</span></label>
                        <input id="nama" name="nama" type="text" required value="{{ $hl['nama'] }}" class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>

                    {{-- Jenis Libur --}}
                    <div class="space-y-1">
                        <label for="tipe" class="text-sm font-semibold text-ink font-sans">Jenis Libur <span class="text-danger">*</span></label>
                        <select id="tipe" name="tipe" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                            <option value="libur_nasional" {{ $hl['tipe'] === 'libur_nasional' ? 'selected' : '' }}>Libur Nasional</option>
                            <option value="cuti_bersama" {{ $hl['tipe'] === 'cuti_bersama' ? 'selected' : '' }}>Cuti Bersama</option>
                        </select>
                    </div>
                </div>

                {{-- Action Buttons --}}
                <div class="border-t border-border pt-6 flex justify-end gap-3">
                    <a href="{{ route('hari-libur') }}" class="inline-flex items-center justify-center rounded-lg border border-border bg-surface px-5 py-2.5 text-sm font-semibold text-primary transition hover:border-primary/30 hover:bg-primary/5 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2">
                        Batal
                    </a>
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-layouts.app>
