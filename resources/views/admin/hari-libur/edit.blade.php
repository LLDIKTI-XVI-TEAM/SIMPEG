<x-layouts.app title="Edit Hari Libur">
    <div class="mx-auto max-w-2xl space-y-6">

        <x-admin.page-header title="Edit Hari Libur">
            <x-slot:breadcrumb>
                <a href="{{ route('dashboard') }}" class="transition-colors hover:text-ink font-sans">Dashboard</a>
                <span>/</span>
                <a href="{{ route('hari-libur', ['tahun' => $hariLibur->tahun]) }}" class="transition-colors hover:text-ink font-sans">Hari Libur</a>
                <span>/</span>
                <span class="font-medium text-ink font-sans">Edit - {{ $hariLibur->nama }}</span>
            </x-slot:breadcrumb>
            <x-slot:actions>
                <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-danger shadow-sm">
                    Akses: Khusus Super Admin
                </span>
                <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-info shadow-sm">
                    Audit Trail Aktif
                </span>
            </x-slot:actions>
        </x-admin.page-header>

        @if ($errors->getBag('hariLiburEdit')->any())
            <x-ui.alert variant="danger" title="Perubahan belum tersimpan">
                Periksa kembali kolom yang ditandai pada formulir.
            </x-ui.alert>
        @endif

        {{-- Form Card --}}
        <x-ui.card padding="lg">
            <form action="{{ route('hari-libur.update', $hariLibur) }}" method="POST" class="space-y-6">
                @csrf
                @method('PUT')

                <div class="space-y-4">
                    <x-form.date
                        name="tanggal"
                        label="Tanggal"
                        id="tanggal"
                        :value="$hariLibur->tanggal?->format('Y-m-d')"
                        error-bag="hariLiburEdit"
                        required
                        size="lg"
                    />

                    <x-form.input
                        name="nama"
                        label="Nama Hari Libur"
                        type="text"
                        id="nama"
                        :value="$hariLibur->nama"
                        error-bag="hariLiburEdit"
                        required
                        size="lg"
                    />

                    <x-form.select
                        name="tipe"
                        label="Jenis Libur"
                        id="tipe"
                        :value="$hariLibur->tipe()"
                        error-bag="hariLiburEdit"
                        required
                    >
                        <option value="libur_nasional">Libur Nasional</option>
                        <option value="cuti_bersama">Cuti Bersama</option>
                    </x-form.select>
                </div>

                {{-- Action Buttons --}}
                <div class="border-t border-border pt-6 flex justify-end gap-3">
                    <x-ui.button href="{{ route('hari-libur', ['tahun' => $hariLibur->tahun]) }}" variant="secondary">
                        Batal
                    </x-ui.button>
                    <x-ui.button type="submit" variant="primary">
                        Simpan Perubahan
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.app>
