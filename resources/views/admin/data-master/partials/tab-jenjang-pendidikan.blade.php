{{-- Tab jenjang pendidikan: data nyata dari database. Riwayat pendidikan lama
     mencocokkan jenjang berdasarkan nama, sehingga nama dijaga unik. --}}
<div x-show="activeTab === 'jenjang_pendidikan'"
    x-data="{ showTambah: {{ $errors->any() && old('tab') === 'jenjang_pendidikan' ? 'true' : 'false' }}, editId: null }"
    class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
    <div class="pb-4 flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Jenjang Pendidikan</h2>
            <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Data referensi jenjang pendidikan.
                Item yang sudah dipakai riwayat pendidikan hanya dapat dinonaktifkan.</p>
        </div>
        <button type="button" @click="showTambah = !showTambah"
            class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
            Tambah
        </button>
    </div>

    <form method="POST" action="{{ route('data-master.jenjang-pendidikan.store') }}" x-show="showTambah" style="display: none;"
        class="grid gap-3 sm:grid-cols-3 items-end rounded-lg border border-border bg-soft/30 p-4">
        @csrf
        <input type="hidden" name="tab" value="jenjang_pendidikan">
        <x-form.input name="nama" label="Nama Jenjang" required placeholder="cth: S1" />
        <x-form.input name="urutan" type="number" label="Urutan" min="0" placeholder="0" />
        <button type="submit"
            class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
            Simpan
        </button>
    </form>

    <div class="rounded-lg overflow-x-auto">
        <x-ui.table>
            <x-ui.table-head>
                <x-ui.table-row>
                    <x-ui.table-th>Nama Jenjang</x-ui.table-th>
                    <x-ui.table-th align="center">Urutan</x-ui.table-th>
                    <x-ui.table-th align="center">Status</x-ui.table-th>
                    <x-ui.table-th align="center">Pemakaian</x-ui.table-th>
                    <x-ui.table-th align="right">Aksi</x-ui.table-th>
                </x-ui.table-row>
            </x-ui.table-head>
            <x-ui.table-body>
                @forelse ($jenjangPendidikan as $item)
                    @php $dipakai = $jenjangPendidikanUsage[$item->id] ?? 0; @endphp
                    <x-ui.table-row>
                        <x-ui.table-td padding="sm" class="text-sm font-medium">{{ $item->nama }}</x-ui.table-td>
                        <x-ui.table-td align="center" padding="sm" class="text-sm text-muted">{{ $item->urutan }}</x-ui.table-td>
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
                                <form method="POST" action="{{ route('data-master.jenjang-pendidikan.toggle', $item) }}">
                                    @csrf
                                    <input type="hidden" name="tab" value="jenjang_pendidikan">
                                    <button type="submit"
                                        class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold {{ $item->is_active ? 'text-warning' : 'text-success' }} transition-colors hover:bg-soft">
                                        {{ $item->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                    </button>
                                </form>
                                @if ($dipakai === 0)
                                    <form method="POST" action="{{ route('data-master.jenjang-pendidikan.destroy', $item) }}"
                                        onsubmit="return confirm('Hapus item ini secara permanen? Tindakan tercatat di audit log.')">
                                        @csrf
                                        <input type="hidden" name="tab" value="jenjang_pendidikan">
                                        <button type="submit"
                                            class="rounded-lg border border-danger/30 bg-surface px-2.5 py-1.5 text-xs font-semibold text-danger transition-colors hover:bg-danger/10">Hapus</button>
                                    </form>
                                @endif
                            </div>
                        </x-ui.table-td>
                    </x-ui.table-row>
                    <tr x-show="editId === '{{ $item->id }}'" style="display: none;">
                        <td colspan="5" class="bg-soft/30 px-4 py-4">
                            <form method="POST" action="{{ route('data-master.jenjang-pendidikan.update', $item) }}"
                                class="grid gap-3 sm:grid-cols-3 items-end">
                                @csrf
                                <input type="hidden" name="tab" value="jenjang_pendidikan">
                                <div class="space-y-1">
                                    <label class="text-[10px] font-bold text-muted uppercase tracking-wide font-sans">Nama Jenjang</label>
                                    <input name="nama" value="{{ $item->nama }}" required
                                        class="w-full rounded-xl border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[10px] font-bold text-muted uppercase tracking-wide font-sans">Urutan</label>
                                    <input name="urutan" type="number" min="0" value="{{ $item->urutan }}"
                                        class="w-full rounded-xl border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <button type="submit"
                                    class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">
                                    Perbarui
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-muted">Belum ada data jenjang pendidikan.</td>
                    </tr>
                @endforelse
            </x-ui.table-body>
        </x-ui.table>
    </div>
</div>
