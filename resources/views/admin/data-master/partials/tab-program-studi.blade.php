<div x-show="activeTab === 'program_studi'" class="space-y-5" style="display: none;" x-data="{ editId: null }">
    <div>
        <h2 class="text-lg font-semibold text-ink">Program Studi</h2>
        <p class="mt-1 text-sm text-muted">Kelola pilihan program studi yang digunakan pada pendidikan pegawai.</p>
    </div>

    <form method="POST" action="{{ route('data-master.program-studi.store') }}" class="grid items-end gap-3 rounded-lg border border-border bg-soft/30 p-4 sm:grid-cols-[minmax(0,1fr)_auto]">
        @csrf
        <input type="hidden" name="tab" value="program_studi">
        <x-form.input name="nama" label="Nama Program Studi" required placeholder="cth: Teknik Informatika" />
        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">Simpan</button>
    </form>

    <div class="overflow-x-auto rounded-lg">
        <x-ui.table>
            <x-ui.table-head>
                <x-ui.table-row>
                    <x-ui.table-th>Nama Program Studi</x-ui.table-th>
                    <x-ui.table-th align="center">Status</x-ui.table-th>
                    <x-ui.table-th align="center">Pemakaian</x-ui.table-th>
                    <x-ui.table-th align="right">Aksi</x-ui.table-th>
                </x-ui.table-row>
            </x-ui.table-head>
            <x-ui.table-body>
                @forelse ($programStudi as $item)
                    @php $dipakai = $programStudiUsage[$item->id] ?? 0; @endphp
                    <x-ui.table-row>
                        <x-ui.table-td padding="sm" class="text-sm font-medium">{{ $item->nama }}</x-ui.table-td>
                        <x-ui.table-td align="center" padding="sm">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $item->is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger' }}">{{ $item->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                        </x-ui.table-td>
                        <x-ui.table-td align="center" padding="sm" class="text-sm text-muted">{{ $dipakai }} pemakai</x-ui.table-td>
                        <x-ui.table-td align="right" padding="sm">
                            <div class="flex items-center justify-end gap-1.5">
                                <button type="button" @click="editId = editId === '{{ $item->id }}' ? null : '{{ $item->id }}'" class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-primary transition-colors hover:bg-soft">Edit</button>
                                <form method="POST" action="{{ route('data-master.program-studi.toggle', $item) }}">
                                    @csrf
                                    <input type="hidden" name="tab" value="program_studi">
                                    <button type="submit" class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold {{ $item->is_active ? 'text-warning' : 'text-success' }} transition-colors hover:bg-soft">{{ $item->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                </form>
                                @if ($dipakai === 0)
                                    <form method="POST" action="{{ route('data-master.program-studi.destroy', $item) }}" onsubmit="return confirm('Hapus program studi ini secara permanen? Tindakan tercatat di audit log.')">
                                        @csrf
                                        <input type="hidden" name="tab" value="program_studi">
                                        <button type="submit" class="rounded-lg border border-danger/30 bg-surface px-2.5 py-1.5 text-xs font-semibold text-danger transition-colors hover:bg-danger/10">Hapus</button>
                                    </form>
                                @endif
                            </div>
                        </x-ui.table-td>
                    </x-ui.table-row>
                    <tr x-show="editId === '{{ $item->id }}'" style="display: none;">
                        <td colspan="4" class="bg-soft/30 px-4 py-4">
                            <form method="POST" action="{{ route('data-master.program-studi.update', $item) }}" class="grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
                                @csrf
                                <input type="hidden" name="tab" value="program_studi">
                                <x-form.input name="nama" label="Nama Program Studi" :value="$item->nama" required />
                                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90">Perbarui</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-muted">Belum ada data program studi.</td></tr>
                @endforelse
            </x-ui.table-body>
        </x-ui.table>
    </div>
</div>
