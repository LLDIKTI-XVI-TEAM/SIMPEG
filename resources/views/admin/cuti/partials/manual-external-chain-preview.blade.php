<dialog
    x-ref="chainPreviewDialog"
    x-on:close="$nextTick(() => restorePreviewFocus())"
    x-on:keydown.tab="trapPreviewFocus($event)"
    class="m-auto w-[min(42rem,calc(100%-2rem))] rounded-2xl border border-border bg-surface p-0 text-ink shadow-xl backdrop:bg-ink/50"
    aria-labelledby="manual-chain-preview-title"
    aria-describedby="manual-chain-preview-description"
>
    <div class="max-h-[80vh] overflow-y-auto p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h4 id="manual-chain-preview-title" class="text-base font-semibold text-ink">Pratinjau Rangkaian Saat Ini</h4>
                <p id="manual-chain-preview-description" class="mt-1 text-sm text-muted">
                    Pratinjau ini hanya referensi. Rangkaian baru disalin ke editor setelah Anda memilih tombol gunakan.
                </p>
            </div>
            <button
                type="button"
                x-on:click="$refs.chainPreviewDialog.close()"
                class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-border text-lg font-semibold text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2"
                aria-label="Tutup pratinjau rangkaian"
            >
                <span aria-hidden="true">&times;</span>
            </button>
        </div>

        <template x-if="!preview.available">
            <p class="mt-5 rounded-xl border border-warning/30 bg-warning/5 p-4 text-sm text-ink" x-text="(preview.warnings?.[0] ?? 'Rangkaian aktif belum tersedia.').replaceAll('Chain', 'Rangkaian').replaceAll('chain', 'rangkaian')"></p>
        </template>

        <template x-if="preview.available">
            <div class="mt-5 space-y-3">
                <template x-for="previewStep in preview.steps" :key="previewStep.step_order">
                    <div class="rounded-xl border border-border p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted">
                            Tahap <span x-text="previewStep.step_order"></span>
                        </p>
                        <p class="mt-1 font-semibold text-ink" x-text="previewStep.role_label"></p>
                        <p class="mt-1 text-sm text-muted">
                            <span x-text="previewStep.approver?.nama_lengkap ?? 'Approver tidak tersedia'"></span>
                            <span x-show="previewStep.approver?.nip" x-text="` - NIP ${previewStep.approver.nip}`"></span>
                        </p>
                    </div>
                </template>

                <template x-for="warning in preview.warnings" :key="warning">
                    <p class="rounded-lg border border-warning/30 bg-warning/5 px-3 py-2 text-sm text-ink" x-text="warning.replaceAll('Chain', 'Rangkaian').replaceAll('chain', 'rangkaian')"></p>
                </template>
            </div>
        </template>

        <div class="mt-5 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button
                type="button"
                x-on:click="$refs.chainPreviewDialog.close()"
                class="inline-flex min-h-11 items-center justify-center rounded-lg border border-border px-4 py-2 text-sm font-semibold text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2"
            >
                Batal
            </button>
            <button
                type="button"
                x-bind:disabled="!preview.valid"
                x-on:click="copyPreview()"
                class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
            >
                Gunakan Rangkaian Ini
            </button>
        </div>
    </div>
</dialog>
