<x-layouts.app title="Dokumen Pendukung Kepala Lembaga">
    <div class="space-y-6">
        <header>
            <h1 class="text-2xl font-semibold text-ink">Dokumen Pendukung Kepala Lembaga</h1>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Monitoring Cuti', 'url' => route('cuti')],
                ['label' => 'Dokumen Pendukung Kepala Lembaga'],
            ]" />
        </header>

        @if (session('success'))
            <x-ui.alert variant="success" size="sm">{{ session('success') }}</x-ui.alert>
        @endif

        @if ($selected)
            @if ($kepalaLembaga->count() > 1)
                <form method="GET" action="{{ route('cuti.dokumen-kepala-lembaga.index') }}" class="flex flex-col gap-2 sm:max-w-md">
                    <x-form.select id="employee" name="employee" label="Pegawai Kepala Lembaga" onchange="this.form.submit()">
                        @foreach ($kepalaLembaga as $kepala)
                            <option value="{{ $kepala->id }}" @selected($kepala->id === $selected->id)>{{ $kepala->nama_lengkap }}</option>
                        @endforeach
                    </x-form.select>
                    <noscript>
                        <x-ui.button type="submit" variant="secondary" size="sm">Pilih pegawai</x-ui.button>
                    </noscript>
                </form>
            @endif

            <x-ui.card as="form" action="{{ route('cuti.dokumen-kepala-lembaga.store', $selected) }}" method="POST" enctype="multipart/form-data"
                padding="lg" class="space-y-4">
                @csrf
                <x-form.file-upload id="berkas" name="berkas" label="Berkas dokumen" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                    help="Format PDF, DOC, DOCX, JPG, JPEG, atau PNG. Maksimal 10 MB." />
                <p class="text-sm text-muted">Untuk: <span class="font-semibold text-ink">{{ $selected->nama_lengkap }}</span></p>
                <div class="flex justify-end">
                    <x-ui.button type="submit">Unggah</x-ui.button>
                </div>
            </x-ui.card>

            <x-ui.card padding="none" class="overflow-hidden">
                <div class="overflow-x-auto">
                    <x-ui.table caption="Dokumen pendukung aktif milik {{ $selected->nama_lengkap }}">
                        <x-ui.table-head>
                            <x-ui.table-row>
                                <x-ui.table-th scope="col">Nama berkas</x-ui.table-th>
                                <x-ui.table-th scope="col">Tipe</x-ui.table-th>
                                <x-ui.table-th scope="col">Ukuran</x-ui.table-th>
                                <x-ui.table-th scope="col">Diunggah oleh</x-ui.table-th>
                                <x-ui.table-th scope="col" align="right">Aksi</x-ui.table-th>
                            </x-ui.table-row>
                        </x-ui.table-head>
                        <tbody class="divide-y divide-border bg-surface">
                            @forelse ($documents as $document)
                                <x-ui.table-row>
                                    <x-ui.table-td class="font-medium">{{ $document->original_filename }}</x-ui.table-td>
                                    <x-ui.table-td class="text-muted">{{ $document->mime_type }}</x-ui.table-td>
                                    <x-ui.table-td class="text-muted">{{ number_format(max(1, $document->size_bytes / 1024), 0) }} KB</x-ui.table-td>
                                    <x-ui.table-td class="text-muted">{{ $document->uploader?->name ?? '-' }}</x-ui.table-td>
                                    <x-ui.table-td align="right">
                                        <div class="flex flex-wrap items-center justify-end gap-2" x-data="{ confirming: false }">
                                            <x-ui.button href="{{ route('cuti.dokumen-kepala-lembaga.view', $document) }}" target="_blank" rel="noopener" variant="secondary" size="sm">Lihat</x-ui.button>
                                            <x-ui.button href="{{ route('cuti.dokumen-kepala-lembaga.download', $document) }}" variant="secondary" size="sm">Unduh</x-ui.button>
                                            <x-ui.button type="button" variant="danger-solid" size="sm" x-show="! confirming" @click="confirming = true">Hapus</x-ui.button>
                                            <form x-show="confirming" x-cloak method="POST" action="{{ route('cuti.dokumen-kepala-lembaga.destroy', $document) }}"
                                                class="inline-flex items-center gap-2" @keydown.escape="confirming = false">
                                                @csrf
                                                @method('DELETE')
                                                <span class="text-xs text-muted">Yakin?</span>
                                                <x-ui.button type="submit" variant="danger-solid" size="sm">Ya, hapus</x-ui.button>
                                                <x-ui.button type="button" variant="secondary" size="sm" @click="confirming = false">Batal</x-ui.button>
                                            </form>
                                        </div>
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @empty
                                <x-ui.table-row>
                                    <x-ui.table-td colspan="5" padding="none">
                                        <x-ui.empty-state icon="document" title="Belum ada dokumen pendukung." message="Unggah dokumen untuk Kepala Lembaga yang dipilih." />
                                    </x-ui.table-td>
                                </x-ui.table-row>
                            @endforelse
                        </tbody>
                    </x-ui.table>
                </div>
                @if ($documents->hasPages())
                    <div class="border-t border-border px-4 py-3">
                        {{ $documents->onEachSide(1)->links('vendor.pagination.simpeg') }}
                    </div>
                @endif
            </x-ui.card>
        @else
            <x-ui.card padding="lg" class="text-center text-muted">
                Belum ada pegawai yang ditandai sebagai Kepala Lembaga. Tandai pegawai terlebih dahulu pada data pegawai.
            </x-ui.card>
        @endif
    </div>
</x-layouts.app>
