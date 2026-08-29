{{--
    Tab Jabatan mengikuti kontrak RefJabatan. Render tetap aman sebelum slice
    backend CRUD tersedia: data disuplai controller, dan seluruh form hanya
    ditampilkan setelah route Jabatan resmi terdaftar.
--}}
@php
    $jabatanItems = collect($jabatan ?? []);
    $jabatanUsageMap = $jabatanUsage ?? [];
    $jenisJabatanOptions = collect($jenisJabatan ?? []);
    $eselonOptions = collect($eselon ?? []);
    $jabatanCrudReady = \Illuminate\Support\Facades\Route::has('data-master.jabatan.store')
        && \Illuminate\Support\Facades\Route::has('data-master.jabatan.update')
        && \Illuminate\Support\Facades\Route::has('data-master.jabatan.toggle')
        && \Illuminate\Support\Facades\Route::has('data-master.jabatan.destroy');
    // Bedakan kegagalan validasi tambah dan edit agar nilai old() tidak
    // memindahkan pengguna dari form edit ke form tambah.
    $jabatanFormContext = old('form_context');
    $jabatanCreateFailed = $jabatanCrudReady
        && $errors->any()
        && old('tab') === 'jabatan'
        && $jabatanFormContext === 'create';
    $jabatanEditId = $jabatanCrudReady
        && $errors->any()
        && old('tab') === 'jabatan'
        && is_string($jabatanFormContext)
        && $jabatanFormContext !== 'create'
            ? $jabatanFormContext
            : null;
@endphp

<div
    x-show="activeTab === 'jabatan'"
    x-data="{ showTambah: {{ $jabatanCreateFailed ? 'true' : 'false' }}, editId: @js($jabatanEditId) }"
    class="rounded-lg bg-surface p-6 shadow-sm space-y-6"
    style="display: none;"
>
    <div class="flex flex-col gap-4 border-b border-border pb-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold leading-tight text-ink">Jabatan</h2>
            <p class="mt-0.5 max-w-2xl text-[11px] leading-normal text-muted">
                Referensi jabatan untuk riwayat kepegawaian, kategori jenis jabatan, eselon, dan batas usia pensiun.
                Jabatan yang sudah dipakai hanya dapat dinonaktifkan.
            </p>
        </div>

        @if ($jabatanCrudReady)
            <x-ui.button
                type="button"
                @click="showTambah = !showTambah"
                x-bind:aria-expanded="showTambah.toString()"
                aria-controls="form-tambah-jabatan"
                variant="primary"
                class="shrink-0"
            >
                Tambah
            </x-ui.button>
        @endif
    </div>

    @if ($jabatanCrudReady)
        <form
            id="form-tambah-jabatan"
            method="POST"
            action="{{ route('data-master.jabatan.store') }}"
            x-show="showTambah"
            style="display: none;"
            class="grid gap-3 rounded-lg border border-border bg-soft/30 p-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            @csrf
            <input type="hidden" name="tab" value="jabatan">
            <input type="hidden" name="form_context" value="create">

            <x-form.input name="nama" label="Nama Jabatan" required placeholder="cth: Analis Kepegawaian"
                :value="$jabatanCreateFailed ? old('nama') : null"
                :error-key="$jabatanCreateFailed ? 'nama' : 'jabatan_create_nama'" />

            @php
                $jabatanCreateJenisError = $jabatanCreateFailed && $errors->has('jenis_jabatan_id');
                $jabatanCreateEselonError = $jabatanCreateFailed && $errors->has('eselon_id');
                $jabatanCreateKeteranganError = $jabatanCreateFailed && $errors->has('keterangan');
            @endphp

            <div class="space-y-1">
                <label for="jabatan-jenis-jabatan" class="mb-1 block text-sm font-semibold text-ink">Jenis Jabatan</label>
                <select id="jabatan-jenis-jabatan" name="jenis_jabatan_id"
                    @if ($jabatanCreateJenisError) aria-invalid="true" aria-describedby="jabatan-jenis-jabatan-error" @endif
                    class="w-full rounded-lg border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 @if ($jabatanCreateJenisError) border-danger focus:border-danger focus:ring-danger/20 @else border-border focus:border-primary focus:ring-primary/20 @endif">
                    <option value="">Tidak ditentukan</option>
                    @foreach ($jenisJabatanOptions->where('is_active', true) as $jenis)
                        <option value="{{ $jenis->id }}" @selected($jabatanCreateFailed && (string) old('jenis_jabatan_id') === (string) $jenis->id)>{{ $jenis->nama }}</option>
                    @endforeach
                </select>
                @if ($jabatanCreateFailed)
                    @error('jenis_jabatan_id')
                        <p id="jabatan-jenis-jabatan-error" class="text-[11px] font-semibold text-danger">{{ $message }}</p>
                    @enderror
                @endif
            </div>

            <div class="space-y-1">
                <label for="jabatan-eselon" class="mb-1 block text-sm font-semibold text-ink">Eselon</label>
                <select id="jabatan-eselon" name="eselon_id"
                    @if ($jabatanCreateEselonError) aria-invalid="true" aria-describedby="jabatan-eselon-error" @endif
                    class="w-full rounded-lg border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 @if ($jabatanCreateEselonError) border-danger focus:border-danger focus:ring-danger/20 @else border-border focus:border-primary focus:ring-primary/20 @endif">
                    <option value="">Tidak ditentukan</option>
                    @foreach ($eselonOptions->where('is_active', true) as $eselonItem)
                        <option value="{{ $eselonItem->id }}" @selected($jabatanCreateFailed && (string) old('eselon_id') === (string) $eselonItem->id)>{{ $eselonItem->kode }} — {{ $eselonItem->nama }}</option>
                    @endforeach
                </select>
                @if ($jabatanCreateFailed)
                    @error('eselon_id')
                        <p id="jabatan-eselon-error" class="text-[11px] font-semibold text-danger">{{ $message }}</p>
                    @enderror
                @endif
            </div>

            <x-form.input name="default_bup" type="number" label="BUP Default" min="50" max="70" placeholder="cth: 60"
                :value="$jabatanCreateFailed ? old('default_bup') : null"
                :error-key="$jabatanCreateFailed ? 'default_bup' : 'jabatan_create_default_bup'" />

            <div class="space-y-1">
                <label for="jabatan-keterangan" class="mb-1 block text-sm font-semibold text-ink">Keterangan</label>
                <input id="jabatan-keterangan" name="keterangan" type="text" maxlength="255" placeholder="Opsional"
                    value="{{ $jabatanCreateFailed ? old('keterangan') : '' }}"
                    @if ($jabatanCreateKeteranganError) aria-invalid="true" aria-describedby="jabatan-keterangan-error" @endif
                    class="w-full rounded-lg border bg-surface px-3 py-2 text-sm text-ink placeholder:text-muted focus:outline-none focus:ring-2 @if ($jabatanCreateKeteranganError) border-danger focus:border-danger focus:ring-danger/20 @else border-border focus:border-primary focus:ring-primary/20 @endif">
                @if ($jabatanCreateFailed)
                    @error('keterangan')
                        <p id="jabatan-keterangan-error" class="text-[11px] font-semibold text-danger">{{ $message }}</p>
                    @enderror
                @endif
            </div>

            <div class="flex items-end">
                <x-ui.button type="submit" :full-width="true">
                    Simpan Jabatan
                </x-ui.button>
            </div>
        </form>
    @endif

    <div class="overflow-x-auto rounded-lg">
        <x-ui.table>
            <x-ui.table-head>
                <x-ui.table-row>
                    <x-ui.table-th>Nama Jabatan</x-ui.table-th>
                    <x-ui.table-th>Jenis Jabatan</x-ui.table-th>
                    <x-ui.table-th>Eselon</x-ui.table-th>
                    <x-ui.table-th align="center">BUP Default</x-ui.table-th>
                    <x-ui.table-th align="center">Status</x-ui.table-th>
                    <x-ui.table-th align="center">Pemakaian</x-ui.table-th>
                    @if ($jabatanCrudReady)
                        <x-ui.table-th>Aksi</x-ui.table-th>
                    @endif
                </x-ui.table-row>
            </x-ui.table-head>
            <x-ui.table-body>
                @forelse ($jabatanItems as $item)
                    @php
                        $dipakai = $jabatanUsageMap[$item->id] ?? 0;
                        $jenisAktif = $jenisJabatanOptions->firstWhere('id', $item->jenis_jabatan_id);
                        $eselonAktif = $eselonOptions->firstWhere('id', $item->eselon_id);
                    @endphp
                    <x-ui.table-row>
                        <x-ui.table-td padding="sm" class="text-sm font-medium text-ink">{{ $item->nama }}</x-ui.table-td>
                        <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $jenisAktif?->nama ?? '—' }}</x-ui.table-td>
                        <x-ui.table-td padding="sm" class="text-sm text-muted">{{ $eselonAktif ? $eselonAktif->kode.' — '.$eselonAktif->nama : '—' }}</x-ui.table-td>
                        <x-ui.table-td align="center" padding="sm" class="text-sm text-muted">
                            {{ $item->default_bup === null ? 'Mengikuti jenis' : $item->default_bup.' tahun' }}
                        </x-ui.table-td>
                        <x-ui.table-td align="center" padding="sm">
                            <x-ui.badge :variant="$item->is_active ? 'success' : 'danger'" size="sm" dot>
                                {{ $item->is_active ? 'Aktif' : 'Nonaktif' }}
                            </x-ui.badge>
                        </x-ui.table-td>
                        <x-ui.table-td align="center" padding="sm" class="text-sm text-muted">{{ $dipakai }} pemakai</x-ui.table-td>
                        @if ($jabatanCrudReady)
                            <x-ui.table-td padding="sm" class="whitespace-nowrap">
                                <div class="flex flex-nowrap items-center justify-start gap-1.5">
                                    <x-ui.button
                                        type="button"
                                        title="Ubah {{ $item->nama }}"
                                        tooltip-position="top-end"
                                        aria-label="Ubah {{ $item->nama }}"
                                        @click="editId = editId === '{{ $item->id }}' ? null : '{{ $item->id }}'"
                                        variant="secondary"
                                        size="icon"
                                    >
                                        <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                                        </svg>
                                    </x-ui.button>
                                    <form method="POST" action="{{ route('data-master.jabatan.toggle', $item) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="tab" value="jabatan">
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
                                        <form method="POST" action="{{ route('data-master.jabatan.destroy', $item) }}" class="inline" onsubmit="return confirm('Hapus jabatan ini secara permanen? Tindakan tercatat di audit log.')">
                                            @csrf
                                            <input type="hidden" name="tab" value="jabatan">
                                            <x-ui.button
                                                type="submit"
                                                variant="danger"
                                                size="icon"
                                                title="Hapus {{ $item->nama }}"
                                                tooltip-position="top-end"
                                                aria-label="Hapus {{ $item->nama }}"
                                            >
                                                <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                                                </svg>
                                            </x-ui.button>
                                        </form>
                                    @endif
                                </div>
                            </x-ui.table-td>
                        @endif
                    </x-ui.table-row>

                    @if ($jabatanCrudReady)
                        <tr x-show="editId === '{{ $item->id }}'" style="display: none;">
                            <td colspan="7" class="bg-soft/30 px-4 py-4">
                                <form method="POST" action="{{ route('data-master.jabatan.update', $item) }}" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                    @csrf
                                    <input type="hidden" name="tab" value="jabatan">
                                    <input type="hidden" name="form_context" value="{{ $item->id }}">

                                    <x-form.input name="nama" label="Nama Jabatan"
                                        :value="$jabatanEditId === $item->id ? old('nama', $item->nama) : $item->nama"
                                        :error-key="$jabatanEditId === $item->id ? 'nama' : 'jabatan_edit_'.$item->id.'_nama'"
                                        required />

                                    @php
                                        $jabatanEditFailed = $jabatanEditId === $item->id;
                                        $jabatanEditJenisError = $jabatanEditFailed && $errors->has('jenis_jabatan_id');
                                        $jabatanEditEselonError = $jabatanEditFailed && $errors->has('eselon_id');
                                        $jabatanEditKeteranganError = $jabatanEditFailed && $errors->has('keterangan');
                                    @endphp

                                    <div class="space-y-1">
                                        <label for="jabatan-jenis-{{ $item->id }}" class="mb-1 block text-sm font-semibold text-ink">Jenis Jabatan</label>
                                        <select id="jabatan-jenis-{{ $item->id }}" name="jenis_jabatan_id"
                                            @if ($jabatanEditJenisError) aria-invalid="true" aria-describedby="jabatan-jenis-{{ $item->id }}-error" @endif
                                            class="w-full rounded-lg border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 @if ($jabatanEditJenisError) border-danger focus:border-danger focus:ring-danger/20 @else border-border focus:border-primary focus:ring-primary/20 @endif">
                                            <option value="">Tidak ditentukan</option>
                                            @foreach ($jenisJabatanOptions->filter(fn ($jenis) => $jenis->is_active || $jenis->id === $item->jenis_jabatan_id) as $jenis)
                                                <option value="{{ $jenis->id }}" @selected((string) $jenis->id === (string) ($jabatanEditId === $item->id ? old('jenis_jabatan_id', $item->jenis_jabatan_id) : $item->jenis_jabatan_id))>{{ $jenis->nama }}{{ $jenis->is_active ? '' : ' (Nonaktif)' }}</option>
                                            @endforeach
                                        </select>
                                        @if ($jabatanEditFailed)
                                            @error('jenis_jabatan_id')
                                                <p id="jabatan-jenis-{{ $item->id }}-error" class="text-[11px] font-semibold text-danger">{{ $message }}</p>
                                            @enderror
                                        @endif
                                    </div>

                                    <div class="space-y-1">
                                        <label for="jabatan-eselon-{{ $item->id }}" class="mb-1 block text-sm font-semibold text-ink">Eselon</label>
                                        <select id="jabatan-eselon-{{ $item->id }}" name="eselon_id"
                                            @if ($jabatanEditEselonError) aria-invalid="true" aria-describedby="jabatan-eselon-{{ $item->id }}-error" @endif
                                            class="w-full rounded-lg border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 @if ($jabatanEditEselonError) border-danger focus:border-danger focus:ring-danger/20 @else border-border focus:border-primary focus:ring-primary/20 @endif">
                                            <option value="">Tidak ditentukan</option>
                                            @foreach ($eselonOptions->filter(fn ($eselonItem) => $eselonItem->is_active || $eselonItem->id === $item->eselon_id) as $eselonItem)
                                                <option value="{{ $eselonItem->id }}" @selected((string) $eselonItem->id === (string) ($jabatanEditId === $item->id ? old('eselon_id', $item->eselon_id) : $item->eselon_id))>{{ $eselonItem->kode }} — {{ $eselonItem->nama }}{{ $eselonItem->is_active ? '' : ' (Nonaktif)' }}</option>
                                            @endforeach
                                        </select>
                                        @if ($jabatanEditFailed)
                                            @error('eselon_id')
                                                <p id="jabatan-eselon-{{ $item->id }}-error" class="text-[11px] font-semibold text-danger">{{ $message }}</p>
                                            @enderror
                                        @endif
                                    </div>

                                    <x-form.input name="default_bup" type="number" label="BUP Default" min="50" max="70"
                                        :value="$jabatanEditId === $item->id ? old('default_bup', $item->default_bup) : $item->default_bup"
                                        :error-key="$jabatanEditId === $item->id ? 'default_bup' : 'jabatan_edit_'.$item->id.'_default_bup'" />

                                    <div class="space-y-1">
                                        <label for="jabatan-keterangan-{{ $item->id }}" class="mb-1 block text-sm font-semibold text-ink">Keterangan</label>
                                        <input id="jabatan-keterangan-{{ $item->id }}" name="keterangan" type="text" maxlength="255"
                                            value="{{ $jabatanEditId === $item->id ? old('keterangan', $item->keterangan) : $item->keterangan }}"
                                            @if ($jabatanEditKeteranganError) aria-invalid="true" aria-describedby="jabatan-keterangan-{{ $item->id }}-error" @endif
                                            class="w-full rounded-lg border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 @if ($jabatanEditKeteranganError) border-danger focus:border-danger focus:ring-danger/20 @else border-border focus:border-primary focus:ring-primary/20 @endif">
                                        @if ($jabatanEditFailed)
                                            @error('keterangan')
                                                <p id="jabatan-keterangan-{{ $item->id }}-error" class="text-[11px] font-semibold text-danger">{{ $message }}</p>
                                            @enderror
                                        @endif
                                    </div>

                                    <div class="flex items-end gap-2">
                                        <x-ui.button type="submit" class="flex-1">
                                            Perbarui
                                        </x-ui.button>
                                        <x-ui.button type="button" variant="secondary" size="sm" @click="editId = null">
                                            Batal
                                        </x-ui.button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="{{ $jabatanCrudReady ? 7 : 6 }}" class="px-4 py-8 text-center text-sm text-muted">
                            {{ $jabatanCrudReady ? 'Belum ada data jabatan.' : 'Data jabatan belum dimuat oleh backend.' }}
                        </td>
                    </tr>
                @endforelse
            </x-ui.table-body>
        </x-ui.table>
    </div>

    @if (! $jabatanCrudReady)
        <p class="text-xs text-muted">
            Form pengelolaan akan aktif otomatis setelah endpoint Jabatan tersedia.
        </p>
    @endif
</div>
