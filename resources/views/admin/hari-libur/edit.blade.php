<x-layouts.app title="Edit Hari Libur">
    <div class="mx-auto max-w-2xl space-y-6">
        
        <x-admin.page-header title="Edit Hari Libur">
            <x-slot:breadcrumb>
                    <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink font-sans">Dashboard</a>
                    <span>/</span>
                    <a href="{{ route('hari-libur') }}" class="transition-colors hover:text-ink font-sans">Hari Libur</a>
                    <span>/</span>
                    <span class="font-medium text-ink font-sans">Edit - {{ $hl['nama'] }}</span>
            </x-slot:breadcrumb>
            <x-slot:actions>
                <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-danger shadow-sm">
                    ⚠️ Akses: Khusus Super Admin
                </span>
                <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-info shadow-sm">
                    🔒 Audit Trail Aktif
                </span>
            </x-slot:actions>
        </x-admin.page-header>

        {{-- Form Card --}}
        <x-ui.card padding="lg">
            <form action="{{ route('hari-libur.update', $hl['id']) }}" method="POST" class="space-y-6">
                @csrf

                <div class="space-y-4">
                    {{-- Tanggal --}}
                    <x-form.date
    name="tanggal"
    label="Tanggal"
    id="tanggal"
    value="{{ $hl['tanggal'] }}"
    required
    size="lg"
/>

                    {{-- Nama Hari Libur --}}
                    <x-form.input
    name="nama"
    label="Nama Hari Libur"
    type="text"
    id="nama"
    value="{{ $hl['nama'] }}"
    required
    size="lg"
/>

                    {{-- Jenis Libur --}}
                    <x-form.select
                        name="tipe"
                        label="Jenis Libur"
                        id="tipe"
                        required
                    >
                        <option value="libur_nasional" {{ $hl['tipe'] === 'libur_nasional' ? 'selected' : '' }}>Libur Nasional</option>
                        <option value="cuti_bersama" {{ $hl['tipe'] === 'cuti_bersama' ? 'selected' : '' }}>Cuti Bersama</option>
                    </x-form.select>
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
        </x-ui.card>
    </div>
</x-layouts.app>
