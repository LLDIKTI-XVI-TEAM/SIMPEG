<x-layouts.app title="Dokumen Pendukung Kepala Lembaga">
    <div class="space-y-6">
        <header>
            <h2 class="text-2xl font-semibold text-ink">Dokumen Pendukung Kepala Lembaga</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Cuti', 'url' => route('cuti')],
                ['label' => 'Dokumen Pendukung Kepala Lembaga'],
            ]" />
        </header>

        @if (session('success'))
            <x-ui.alert variant="success" size="sm">{{ session('success') }}</x-ui.alert>
        @endif

        @if ($selected)
            @if ($kepalaLembaga->count() > 1)
                <form method="GET" action="{{ route('cuti.dokumen-kepala-lembaga.index') }}" class="flex flex-col gap-2 sm:max-w-md">
                    <label for="employee" class="text-sm font-semibold text-ink">Pegawai Kepala Lembaga</label>
                    <select id="employee" name="employee" onchange="this.form.submit()"
                        class="rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20">
                        @foreach ($kepalaLembaga as $kepala)
                            <option value="{{ $kepala->id }}" @selected($kepala->id === $selected->id)>{{ $kepala->nama_lengkap }}</option>
                        @endforeach
                    </select>
                    <noscript>
                        <button type="submit" class="w-fit rounded-lg border border-border px-3 py-2 text-sm font-semibold text-ink">Pilih pegawai</button>
                    </noscript>
                </form>
            @endif

            <form action="{{ route('cuti.dokumen-kepala-lembaga.store', $selected) }}" method="POST" enctype="multipart/form-data"
                class="space-y-4 rounded-xl border border-border bg-surface p-5 shadow-sm">
                @csrf
                <div>
                    <label for="berkas" class="mb-1 block text-sm font-semibold text-ink">Berkas dokumen <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="file" id="berkas" name="berkas" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                        aria-describedby="berkas-help @error('berkas') berkas-error @enderror"
                        @error('berkas') aria-invalid="true" @enderror
                        class="w-full rounded-lg border border-border bg-surface text-sm text-muted file:mr-4 file:rounded-lg file:border-0 file:bg-primary/10 file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-primary hover:file:bg-primary/20">
                    <p id="berkas-help" class="mt-1 text-xs text-muted">Format PDF, DOC, DOCX, JPG, JPEG, atau PNG. Maksimal 10 MB.</p>
                    @error('berkas')
                        <p id="berkas-error" class="mt-1 text-xs font-medium text-danger" role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <p class="text-sm text-muted">Untuk: <span class="font-semibold text-ink">{{ $selected->nama_lengkap }}</span></p>
                <div class="flex justify-end">
                    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm hover:opacity-90">Unggah</button>
                </div>
            </form>

            <x-ui.card padding="none" class="overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-border text-sm">
                        <caption class="sr-only">Dokumen pendukung aktif milik {{ $selected->nama_lengkap }}</caption>
                        <thead class="bg-soft/70">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-left font-semibold text-ink">Nama berkas</th>
                                <th scope="col" class="px-4 py-3 text-left font-semibold text-ink">Tipe</th>
                                <th scope="col" class="px-4 py-3 text-left font-semibold text-ink">Ukuran</th>
                                <th scope="col" class="px-4 py-3 text-left font-semibold text-ink">Diunggah oleh</th>
                                <th scope="col" class="px-4 py-3 text-right font-semibold text-ink">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border bg-surface">
                            @forelse ($documents as $document)
                                <tr>
                                    <td class="px-4 py-3 font-medium text-ink">{{ $document->original_filename }}</td>
                                    <td class="px-4 py-3 text-muted">{{ $document->mime_type }}</td>
                                    <td class="px-4 py-3 text-muted">{{ number_format(max(1, $document->size_bytes / 1024), 0) }} KB</td>
                                    <td class="px-4 py-3 text-muted">{{ $document->uploader?->name ?? '-' }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap items-center justify-end gap-2" x-data="{ confirming: false }">
                                            <a href="{{ route('cuti.dokumen-kepala-lembaga.view', $document) }}" target="_blank" rel="noopener"
                                                class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-ink hover:bg-soft">Lihat</a>
                                            <a href="{{ route('cuti.dokumen-kepala-lembaga.download', $document) }}"
                                                class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-ink hover:bg-soft">Unduh</a>
                                            <button type="button" x-show="! confirming" @click="confirming = true"
                                                class="rounded-lg bg-danger px-3 py-1.5 text-xs font-semibold text-white">Hapus</button>
                                            <form x-show="confirming" x-cloak method="POST" action="{{ route('cuti.dokumen-kepala-lembaga.destroy', $document) }}"
                                                class="inline-flex items-center gap-2" @keydown.escape="confirming = false">
                                                @csrf
                                                @method('DELETE')
                                                <span class="text-xs text-muted">Yakin?</span>
                                                <button type="submit" class="rounded-lg bg-danger px-3 py-1.5 text-xs font-semibold text-white">Ya, hapus</button>
                                                <button type="button" @click="confirming = false" class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-ink">Batal</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-8 text-center text-muted">Belum ada dokumen pendukung untuk Kepala Lembaga.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
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
