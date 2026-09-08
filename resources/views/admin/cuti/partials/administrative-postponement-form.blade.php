@if ($canAdministrativelyPostpone)
    <section class="space-y-3 rounded-xl border border-warning/30 bg-warning/5 p-4" aria-labelledby="administrative-postponement-title"
        x-data="{ administrativeOpen: {{ $errors->administrativePostponement->has('alasan') ? 'true' : 'false' }}, submitting: false }">
        <h4 id="administrative-postponement-title" class="text-sm font-semibold text-ink">Penangguhan Administratif</h4>
        <p class="text-sm text-muted">Untuk cuti yang sudah disetujui tetapi belum mulai. Keputusan ini menutup seluruh periode cuti dan memperbarui pemakaiannya.</p>
        <x-ui.button type="button" variant="warning" class="min-h-11 w-full sm:w-auto" @click="administrativeOpen = true">Tangguhkan secara Administratif</x-ui.button>

        <x-ui.modal show="administrativeOpen" title="Tangguhkan Cuti secara Administratif" title-id="administrative-confirm-title"
            description-id="administrative-confirm-description" max-width="lg" header-class="[&_button]:min-h-11 [&_button]:min-w-11" close-action="if (!submitting) administrativeOpen = false">
            <form method="POST" action="{{ route('cuti.penangguhan-administratif', $cuti) }}" class="space-y-4"
                @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                @csrf
                <dl id="administrative-target-summary" class="space-y-2 rounded-lg border border-border bg-soft p-3 text-sm">
                    <div><dt class="text-xs text-muted">Pegawai</dt><dd class="break-words font-semibold text-ink">{{ $cuti->employee?->nama_lengkap ?? 'Pegawai tidak tersedia' }}</dd></div>
                    <div><dt class="text-xs text-muted">Periode cuti</dt><dd class="text-ink">{{ $cuti->tanggal_mulai?->translatedFormat('d F Y') }} – {{ $cuti->tanggal_selesai?->translatedFormat('d F Y') }}</dd></div>
                    <div><dt class="text-xs text-muted">Jumlah hari kerja</dt><dd class="font-semibold text-ink">{{ $cuti->jumlah_hari_kerja }} hari kerja</dd></div>
                </dl>
                <p id="administrative-confirm-description" class="text-sm text-ink">Seluruh periode cuti akan dibatalkan dan pemakaian cuti dihitung ulang. Persetujuan dan dokumen lama tetap menjadi riwayat. Tindakan ini tidak dapat dibatalkan.</p>
                <div>
                    <label for="administrative-reason" class="text-sm font-semibold text-ink">Alasan penangguhan <span class="text-danger" aria-hidden="true">*</span></label>
                    <textarea id="administrative-reason" name="alasan" required maxlength="500" rows="4"
                        data-modal-initial-focus="true" data-error-autofocus="{{ $errors->administrativePostponement->has('alasan') ? 'true' : 'false' }}"
                        aria-invalid="{{ $errors->administrativePostponement->has('alasan') ? 'true' : 'false' }}"
                        aria-describedby="administrative-reason-help{{ $errors->administrativePostponement->has('alasan') ? ' administrative-reason-error' : '' }}"
                        class="mt-2 w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus:outline-none focus:ring-2 focus:ring-primary/30">{{ $errors->administrativePostponement->any() && is_string(old('alasan')) ? old('alasan') : '' }}</textarea>
                    <p id="administrative-reason-help" class="mt-2 text-xs text-muted">Wajib, maksimal 500 karakter. Hanya terlihat oleh pemohon dan pengelola yang berwenang. Hindari NIK, kontak, atau detail medis yang tidak diperlukan.</p>
                    @error('alasan', 'administrativePostponement')
                        <p id="administrative-reason-error" class="mt-2 text-sm text-danger" role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <x-ui.button type="button" variant="secondary" class="min-h-11" x-bind:disabled="submitting" @click="administrativeOpen = false">Kembali</x-ui.button>
                    <x-ui.button type="submit" variant="warning" class="min-h-11" x-bind:disabled="submitting" x-bind:aria-busy="submitting.toString()">
                        <span x-show="!submitting">Ya, Tangguhkan Cuti</span>
                        <span x-show="submitting" x-cloak>Menyimpan keputusan…</span>
                    </x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    </section>
@endif
