{{-- Tab jenis jabatan: data nyata dari database. Nilai maks usia pensiun
     menjadi fallback BUP EWS, jadi item terpakai hanya boleh dinonaktifkan. --}}
<div x-show="activeTab === 'jenis_jabatan'"
    x-data="{ showTambah: {{ $errors->any() && old('tab') === 'jenis_jabatan' ? 'true' : 'false' }}, editId: null }"
    class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
    <div class="pb-4 flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Jenis Jabatan</h2>
            <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi jenis jabatan dan maksimal
                usia pensiun. Dipakai riwayat jabatan dan referensi jabatan.</p>
        </div>
        <button type="button" @click="showTambah = !showTambah"
            class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
            Tambah
        </button>
    </div>

    <form method="POST" action="{{ route('data-master.jenis-jabatan.store') }}" x-show="showTambah" style="display: none;"
        class="grid gap-3 sm:grid-cols-4 items-end rounded-lg border border-border bg-soft/30 p-4">
        @csrf
        <input type="hidden" name="tab" value="jenis_jabatan">
        <x-form.input name="nama" label="Nama Jenis Jabatan" required placeholder="cth: Struktural" />
        <x-form.input name="maks_usia_pensiun" type="number" label="Maks Usia Pensiun" required min="1" max="100" placeholder="cth: 58" />
        <x-form.input name="catatan" label="Catatan" placeholder="Opsional" />
        <button type="submit"
            class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
            Simpan
        </button>
    </form>

    <div class="rounded-lg overflow-x-auto">
        <x-ui.table>
            <x-ui.table-head>
                <x-ui.table-row>
                    <x-ui.table-th>Nama Jenis Jabatan</x-ui.table-th>
                    <x-ui.table-th align="center">Maks Usia Pensiun</x-ui.table-th>
                    <x-ui.table-th>Catatan</x-ui.table-th>
                    <x-ui.table-th align="center">Status</x-ui.table-th>
                    <x-ui.table-th align="center">Pemakaian</x-ui.table-th>
                    <x-ui.table-th align="right">Aksi</x-ui.table-th>
                </x-ui.table-row>
            </x-ui.table-head>
            <x-ui.table-body>
                @forelse ($jenisJabatan as $item)
                    @php $dipakai = $jenisJabatanUsage[$item->id] ?? 0; @endphp
                    <x-ui.table-row>
                        <x-ui.table-td padding="sm" class="text-sm font-medium">{{ $item->nama }}</x-ui.table-td>
                        <x-ui.table-td align="center" padding="sm" class="text-sm font-medium text-warning">{{ $item->maks_usia_pensiun }}</x-ui.table-td>
                        <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $item->catatan ?? '—' }}</x-ui.table-td>
                        <x-ui.table-td align="center" padding="sm">
                            @if ($item->is_active)
                                <span class="inline-flex items-center rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-semibold text-success">Aktif</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-danger/10 px-2 py-0.5 text-[11px] font-semibold text-danger">Nonaktif</span>
                            @endif
                        </x-ui.table-td>
                        <x-ui.table-td align="center" padding="sm" class="text-sm text-muted">{{ $dipakai }} pemakai</x-ui.table-td>
                        <x-ui.table-td align="right" padding="sm">
                            <div class="flex items-center justify-end gap-1.5">
                                <button type="button" @click="editId = editId === '{{ $item->id }}' ? null : '{{ $item->id }}'"
                                    class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-primary transition-colors hover:bg-soft">Edit</button>
                                <form method="POST" action="{{ route('data-master.jenis-jabatan.toggle', $item) }}">
                                    @csrf
                                    <input type="hidden" name="tab" value="jenis_jabatan">
                                    <button type="submit"
                                        class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold {{ $item->is_active ? 'text-warning' : 'text-success' }} transition-colors hover:bg-soft">
                                        {{ $item->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                    </button>
                                </form>
                                @if ($dipakai === 0)
                                    <form method="POST" action="{{ route('data-master.jenis-jabatan.destroy', $item) }}"
                                        onsubmit="return confirm('Hapus item ini secara permanen? Tindakan tercatat di audit log.')">
                                        @csrf
                                        <input type="hidden" name="tab" value="jenis_jabatan">
                                        <button type="submit"
                                            class="rounded-lg border border-danger/30 bg-surface px-2.5 py-1.5 text-xs font-semibold text-danger transition-colors hover:bg-danger/10">Hapus</button>
                                    </form>
                                @endif
                            </div>
                        </x-ui.table-td>
                    </x-ui.table-row>
                    <tr x-show="editId === '{{ $item->id }}'" style="display: none;">
                        <td colspan="6" class="bg-soft/30 px-4 py-4">
                            <form method="POST" action="{{ route('data-master.jenis-jabatan.update', $item) }}"
                                class="grid gap-3 sm:grid-cols-4 items-end">
                                @csrf
                                <input type="hidden" name="tab" value="jenis_jabatan">
                                <div class="space-y-1">
                                    <label class="text-[10px] font-bold text-muted uppercase tracking-wide font-sans">Nama Jenis Jabatan</label>
                                    <input name="nama" value="{{ $item->nama }}" required
                                        class="w-full rounded-xl border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[10px] font-bold text-muted uppercase tracking-wide font-sans">Maks Usia Pensiun</label>
                                    <input name="maks_usia_pensiun" type="number" min="1" max="100" value="{{ $item->maks_usia_pensiun }}" required
                                        class="w-full rounded-xl border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[10px] font-bold text-muted uppercase tracking-wide font-sans">Catatan</label>
                                    <input name="catatan" value="{{ $item->catatan }}"
                                        class="w-full rounded-xl border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <button type="submit"
                                    class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                                    Perbarui
                                </button>
                            </form>
                            <p class="mt-2 text-[11px] text-muted font-sans">Perubahan maks usia pensiun hanya berlaku
                                untuk perhitungan berikutnya; tanggal pensiun pegawai yang sudah terisi tidak dihitung
                                ulang otomatis.</p>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-muted">Belum ada data jenis jabatan.</td>
                    </tr>
                @endforelse
            </x-ui.table-body>
        </x-ui.table>
    </div>
</div>
