<x-layouts.app title="Daftar Bawahan" subtitle="Kelola dan pantau seluruh pegawai di bawah naungan Anda.">

    @php
        // DUMMY DATA UNTUK UI
        $daftarBawahan = [
            ['id' => 1, 'nama' => 'Ahmad Fauzi', 'jabatan' => 'Analis Kepegawaian Ahli Muda', 'status' => 'Aktif'],
            ['id' => 2, 'nama' => 'Siti Rahayu', 'jabatan' => 'Pranata Komputer Ahli Pertama', 'status' => 'Cuti Tahunan'],
            ['id' => 3, 'nama' => 'Budi Santoso', 'jabatan' => 'Pengelola Keuangan', 'status' => 'Dinas Luar'],
            ['id' => 4, 'nama' => 'Dewi Pertiwi', 'jabatan' => 'Arsiparis Terampil', 'status' => 'Aktif'],
            ['id' => 5, 'nama' => 'Rudi Hermawan', 'jabatan' => 'Pranata Humas Ahli Muda', 'status' => 'Aktif'],
            ['id' => 6, 'nama' => 'Nadia Kusuma', 'jabatan' => 'Analis Hukum Ahli Pertama', 'status' => 'Cuti Sakit'],
        ];
    @endphp

    <x-ui.filter-bar searchPlaceholder="Cari nama atau NIP..." searchCols="lg:col-span-2 sm:col-span-1">
        <div class="flex items-center gap-2">
            <x-ui.button variant="secondary" size="md">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 4.5h14.25M3 9h9.75M3 13.5h9.75m4.5-4.5v12m0 0l-3.75-3.75M17.25 21L21 17.25" /></svg>
                Filter
            </x-ui.button>
        </div>
    </x-ui.filter-bar>

    <x-ui.card padding="none" class="overflow-hidden">
        <div class="overflow-x-auto">
            <x-ui.table>
                <x-ui.table-head>
                    <x-ui.table-row>
                        <x-ui.table-th padding="lg">Nama & NIP</x-ui.table-th>
                        <x-ui.table-th padding="lg">Jabatan Terakhir</x-ui.table-th>
                        <x-ui.table-th padding="lg">Status</x-ui.table-th>
                        <x-ui.table-th align="right" padding="lg">Aksi</x-ui.table-th>
                    </x-ui.table-row>
                </x-ui.table-head>
                <x-ui.table-body>
                    @foreach($daftarBawahan as $bawahan)
                    <x-ui.table-row :interactive="true" class="cursor-pointer" onclick="window.location='{{ route('kabag.bawahan.show', ['id' => $bawahan['id']]) }}'">
                        <x-ui.table-td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10">
                                    <span class="text-sm font-bold text-primary">{{ substr($bawahan['nama'], 0, 1) }}</span>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-ink font-sans leading-tight">{{ $bawahan['nama'] }}</p>
                                    <p class="text-xs text-muted font-sans leading-none mt-1">NIP. 19800{{ $bawahan['id'] }}01 20000{{ $bawahan['id'] }} 1 00{{ $bawahan['id'] }}</p>
                                </div>
                            </div>
                        </x-ui.table-td>
                        <x-ui.table-td class="px-6 py-4 font-medium text-muted">
                            {{ $bawahan['jabatan'] }}
                        </x-ui.table-td>
                        <x-ui.table-td class="px-6 py-4">
                            @if($bawahan['status'] === 'Aktif')
                                <x-ui.badge variant="success" size="md">{{ $bawahan['status'] }}</x-ui.badge>
                            @elseif(str_contains($bawahan['status'], 'Cuti'))
                                <x-ui.badge variant="warning" size="md">{{ $bawahan['status'] }}</x-ui.badge>
                            @else
                                <x-ui.badge variant="info" size="md">{{ $bawahan['status'] }}</x-ui.badge>
                            @endif
                        </x-ui.table-td>
                        <x-ui.table-td align="right" class="px-6 py-4">
                            <x-ui.button href="{{ route('kabag.bawahan.show', ['id' => $bawahan['id']]) }}" variant="secondary" size="sm">
                                Detail
                            </x-ui.button>
                        </x-ui.table-td>
                    </x-ui.table-row>
                    @endforeach
                </x-ui.table-body>
            </x-ui.table>
        </div>
        
        <div class="border-t border-border px-6 py-4 bg-surface flex items-center justify-between">
            <p class="text-xs text-muted font-sans">Menampilkan 1 hingga 6 dari 12 data</p>
            <x-ui.pagination current="1" total="2" />
        </div>
    </x-ui.card>

</x-layouts.app>
