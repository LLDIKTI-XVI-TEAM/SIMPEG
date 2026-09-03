{{-- Tab jenis jabatan: data nyata dari database. Nilai maks usia pensiun
     menjadi fallback BUP EWS, jadi item terpakai hanya boleh dinonaktifkan. --}}
<div x-show="activeTab === 'jenis_jabatan'"
    x-data="{ showTambah: {{ $errors->any() && old('tab') === 'jenis_jabatan' ? 'true' : 'false' }}, editId: null }"
    class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
    <div class="border-b border-border pb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-ink font-sans leading-tight">Jenis Jabatan</h2>
            <p class="mt-0.5 max-w-2xl text-[11px] text-muted font-sans leading-normal">Data referensi jenis jabatan dan maksimal
                usia pensiun. Dipakai riwayat jabatan dan referensi jabatan.</p>
        </div>
        <x-ui.button type="button" @click="showTambah = !showTambah" variant="secondary" class="shrink-0">
            Tambah
        </x-ui.button>
    </div>

    <form method="POST" action="{{ route('data-master.jenis-jabatan.store') }}" x-show="showTambah" style="display: none;"
        class="grid gap-3 sm:grid-cols-4 items-end rounded-lg border border-border bg-soft/30 p-4">
        @csrf
        <input type="hidden" name="tab" value="jenis_jabatan">
        <x-form.input name="nama" label="Nama Jenis Jabatan" required placeholder="cth: Struktural" />
        <x-form.input name="maks_usia_pensiun" type="number" label="Maks Usia Pensiun" required min="1" max="100" placeholder="cth: 58" />
        <x-form.input name="catatan" label="Catatan" placeholder="Opsional" />
        <x-ui.button type="submit" variant="primary">
            Simpan
        </x-ui.button>
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
                    <x-ui.table-th>Aksi</x-ui.table-th>
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
                            <x-ui.badge :variant="$item->is_active ? 'success' : 'danger'" size="sm" pill>
                                {{ $item->is_active ? 'Aktif' : 'Nonaktif' }}
                            </x-ui.badge>
                        </x-ui.table-td>
                        <x-ui.table-td align="center" padding="sm" class="text-sm text-muted">{{ $dipakai }} pemakai</x-ui.table-td>
                        <x-ui.table-td padding="sm" class="whitespace-nowrap">
                            <div class="flex items-center justify-start gap-1.5">
                                <x-ui.button
                                    type="button"
                                    @click="editId = editId === '{{ $item->id }}' ? null : '{{ $item->id }}'"
                                    variant="secondary"
                                    size="icon"
                                    title="Edit"
                                    tooltip-position="top-end"
                                    aria-label="Edit"
                                >
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                    </svg>
                                </x-ui.button>
                                <form method="POST" action="{{ route('data-master.jenis-jabatan.toggle', $item) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="tab" value="jenis_jabatan">
                                    <x-ui.button
                                        type="submit"
                                        variant="{{ $item->is_active ? 'warning' : 'success' }}"
                                        size="icon"
                                        title="{{ $item->is_active ? 'Nonaktifkan' : 'Aktifkan' }}"
                                        tooltip-position="top-end"
                                        aria-label="{{ $item->is_active ? 'Nonaktifkan' : 'Aktifkan' }}"
                                    >
                                        @if ($item->is_active)
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636" />
                                            </svg>
                                        @else
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z" />
                                            </svg>
                                        @endif
                                    </x-ui.button>
                                </form>
                                @if ($dipakai === 0)
                                    <form method="POST" action="{{ route('data-master.jenis-jabatan.destroy', $item) }}" class="inline"
                                        onsubmit="return confirm('Hapus item ini secara permanen? Tindakan tercatat di audit log.')">
                                        @csrf
                                        <input type="hidden" name="tab" value="jenis_jabatan">
                                        <x-ui.button
                                            type="submit"
                                            variant="danger"
                                            size="icon"
                                            title="Hapus"
                                            tooltip-position="top-end"
                                            aria-label="Hapus"
                                        >
                                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                            </svg>
                                        </x-ui.button>
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
                                <x-ui.button type="submit" variant="primary">
                                    Perbarui
                                </x-ui.button>
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
