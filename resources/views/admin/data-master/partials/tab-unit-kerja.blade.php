{{-- Tab unit kerja: data nyata dari database, disusun depth-first di controller.
     Self-FK parent_id bersifat nullOnDelete sehingga penghapusan induk dijaga
     guard aplikasi (kolom pemakaian menghitung sub-unit), bukan database. --}}
<div x-show="activeTab === 'unit_kerja'"
    x-data="{ showTambah: {{ $errors->any() && old('tab') === 'unit_kerja' ? 'true' : 'false' }}, editId: null }"
    class="rounded-lg bg-surface p-6 shadow-sm space-y-6" style="display: none;">
    <div class="pb-4 flex items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-primary font-sans leading-tight">Unit Kerja</h2>
            <p class="text-[11px] text-muted mt-0.5 font-sans leading-normal">Struktur organisasi berjenjang. Unit yang
                sudah dipakai riwayat jabatan atau masih memiliki sub-unit hanya dapat dinonaktifkan.</p>
        </div>
        <button type="button" @click="showTambah = !showTambah"
            :aria-expanded="showTambah ? 'true' : 'false'" aria-controls="unit-kerja-form-tambah"
            class="inline-flex shrink-0 items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
            Tambah
        </button>
    </div>

    <form id="unit-kerja-form-tambah" method="POST" action="{{ route('data-master.unit-kerja.store') }}" x-show="showTambah" style="display: none;"
        class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 items-end rounded-lg border border-border bg-soft/30 p-4">
        @csrf
        <input type="hidden" name="tab" value="unit_kerja">
        <x-form.input name="nama" label="Nama Unit Kerja" required placeholder="cth: Urusan Keuangan" />
        <div class="space-y-1">
            <label for="unit-kerja-jenis-baru" class="text-[10px] font-bold text-muted uppercase tracking-wide font-sans">Jenis Unit</label>
            <select id="unit-kerja-jenis-baru" name="jenis_unit" required
                class="w-full rounded-xl border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                @foreach ($unitKerjaJenisOptions as $nilai => $label)
                    <option value="{{ $nilai }}" @selected(old('jenis_unit') === $nilai)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="space-y-1">
            <label for="unit-kerja-induk-baru" class="text-[10px] font-bold text-muted uppercase tracking-wide font-sans">Unit Induk</label>
            <select id="unit-kerja-induk-baru" name="parent_id"
                class="w-full rounded-xl border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                <option value="">Tanpa induk (unit tertinggi)</option>
                @foreach ($unitKerja as $induk)
                    <option value="{{ $induk->id }}" @selected(old('parent_id') === $induk->id)>
                        {{ str_repeat('— ', $induk->level).$induk->nama }}
                    </option>
                @endforeach
            </select>
        </div>
        <button type="submit"
            class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
            Simpan
        </button>
    </form>

    <div class="rounded-lg overflow-x-auto">
        <x-ui.table>
            <x-ui.table-head>
                <x-ui.table-row>
                    <x-ui.table-th>Nama Unit Kerja</x-ui.table-th>
                    <x-ui.table-th>Jenis Unit</x-ui.table-th>
                    <x-ui.table-th align="center">Status</x-ui.table-th>
                    <x-ui.table-th align="center">Pemakaian</x-ui.table-th>
                    <x-ui.table-th align="right">Aksi</x-ui.table-th>
                </x-ui.table-row>
            </x-ui.table-head>
            <x-ui.table-body>
                @forelse ($unitKerja as $item)
                    @php
                        $dipakai = $unitKerjaUsage[$item->id] ?? 0;
                        $jenisLabel = $unitKerjaJenisOptions[$item->jenis_unit] ?? $item->jenis_unit;
                    @endphp
                    <x-ui.table-row>
                        <x-ui.table-td padding="sm" class="text-sm font-medium">
                            <span class="inline-flex items-center gap-1.5" @style(['padding-left: '.($item->level * 16).'px'])>
                                @if ($item->level > 0)
                                    <span aria-hidden="true" class="text-muted">└</span>
                                @endif
                                {{ $item->nama }}
                            </span>
                        </x-ui.table-td>
                        <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $jenisLabel }}</x-ui.table-td>
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
                                    :aria-expanded="editId === '{{ $item->id }}' ? 'true' : 'false'"
                                    aria-controls="unit-kerja-edit-{{ $item->id }}"
                                    class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold text-primary transition-colors hover:bg-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">Edit</button>
                                <form method="POST" action="{{ route('data-master.unit-kerja.toggle', $item) }}">
                                    @csrf
                                    <input type="hidden" name="tab" value="unit_kerja">
                                    <button type="submit"
                                        class="rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-semibold {{ $item->is_active ? 'text-warning' : 'text-success' }} transition-colors hover:bg-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                                        {{ $item->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                    </button>
                                </form>
                                @if ($dipakai === 0)
                                    <form method="POST" action="{{ route('data-master.unit-kerja.destroy', $item) }}"
                                        onsubmit="return confirm('Hapus unit ini secara permanen? Tindakan tercatat di audit log.')">
                                        @csrf
                                        <input type="hidden" name="tab" value="unit_kerja">
                                        <button type="submit"
                                            class="rounded-lg border border-danger/30 bg-surface px-2.5 py-1.5 text-xs font-semibold text-danger transition-colors hover:bg-danger/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger/40">Hapus</button>
                                    </form>
                                @endif
                            </div>
                        </x-ui.table-td>
                    </x-ui.table-row>
                    <tr id="unit-kerja-edit-{{ $item->id }}" x-show="editId === '{{ $item->id }}'" style="display: none;">
                        <td colspan="5" class="bg-soft/30 px-4 py-4">
                            <form method="POST" action="{{ route('data-master.unit-kerja.update', $item) }}"
                                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 items-end">
                                @csrf
                                <input type="hidden" name="tab" value="unit_kerja">
                                <div class="space-y-1">
                                    <label for="unit-kerja-nama-{{ $item->id }}" class="text-[10px] font-bold text-muted uppercase tracking-wide font-sans">Nama Unit Kerja</label>
                                    <input id="unit-kerja-nama-{{ $item->id }}" name="nama" value="{{ $item->nama }}" required
                                        class="w-full rounded-xl border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                </div>
                                <div class="space-y-1">
                                    <label for="unit-kerja-jenis-{{ $item->id }}" class="text-[10px] font-bold text-muted uppercase tracking-wide font-sans">Jenis Unit</label>
                                    <select id="unit-kerja-jenis-{{ $item->id }}" name="jenis_unit" required
                                        class="w-full rounded-xl border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                        @foreach ($unitKerjaJenisOptions as $nilai => $label)
                                            <option value="{{ $nilai }}" @selected($item->jenis_unit === $nilai)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="space-y-1">
                                    <label for="unit-kerja-induk-{{ $item->id }}" class="text-[10px] font-bold text-muted uppercase tracking-wide font-sans">Unit Induk</label>
                                    <select id="unit-kerja-induk-{{ $item->id }}" name="parent_id"
                                        class="w-full rounded-xl border border-border bg-surface px-3 py-2 text-xs text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                                        <option value="">Tanpa induk (unit tertinggi)</option>
                                        {{-- Diri sendiri dan sub-unitnya dikeluarkan agar pilihan yang membentuk siklus tidak pernah tampil. --}}
                                        @foreach ($unitKerja as $kandidat)
                                            @continue(in_array($kandidat->id, $unitKerjaCycleGuard[$item->id] ?? [], true))
                                            <option value="{{ $kandidat->id }}" @selected($item->parent_id === $kandidat->id)>
                                                {{ str_repeat('— ', $kandidat->level).$kandidat->nama }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <button type="submit"
                                    class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
                                    Perbarui
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-muted">Belum ada data unit kerja.</td>
                    </tr>
                @endforelse
            </x-ui.table-body>
        </x-ui.table>
    </div>
</div>
