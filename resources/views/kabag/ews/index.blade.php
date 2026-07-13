<x-layouts.app title="EWS Bawahan" subtitle="Peringatan dini aktif untuk bawahan langsung yang Anda kelola.">
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold text-ink">EWS Bawahan</h1>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('kepala-bagian.dashboard')],
                ['label' => 'EWS Bawahan'],
            ]" />
        </div>

        <x-ui.alert variant="info" title="Akses terbatas">
            Peringatan di halaman ini hanya berasal dari bawahan langsung. Tindak lanjut EWS dilakukan oleh Admin Kepegawaian sesuai kewenangannya.
        </x-ui.alert>

        <x-ui.card>
            <form method="GET" action="{{ route('kepala-bagian.ews.index') }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="event" class="mb-1 block text-sm font-medium text-ink">Jenis Event</label>
                    <select id="event" name="event" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                        <option value="">Semua jenis event</option>
                        @foreach ($type_labels as $label)
                            <option value="{{ $label }}" @selected(request('event') === $label)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="status" class="mb-1 block text-sm font-medium text-ink">Status Tindak Lanjut</label>
                    <select id="status" name="status" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                        <option value="">Aktif</option>
                        @foreach ($followup_status_labels as $value => $label)
                            <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2"><button type="submit" class="inline-flex items-center justify-center rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover focus:outline-none focus:ring-2 focus:ring-primary/30">Terapkan Filter</button><a href="{{ route('kepala-bagian.ews.index') }}" class="ml-2 inline-flex items-center justify-center rounded-xl border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary transition hover:bg-soft focus:outline-none focus:ring-2 focus:ring-primary/30">Reset</a></div>
            </form>
        </x-ui.card>

        <x-ui.card padding="none" class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <caption class="sr-only">Daftar peringatan dini untuk bawahan langsung</caption>
                    <thead class="bg-soft text-xs uppercase tracking-wide text-muted">
                        <tr>
                            <th scope="col" class="px-5 py-3">Pegawai</th>
                            <th scope="col" class="px-5 py-3">Jenis Event</th>
                            <th scope="col" class="px-5 py-3">Tanggal Target</th>
                            <th scope="col" class="px-5 py-3">Sisa Hari</th>
                            <th scope="col" class="px-5 py-3">Status Kelayakan</th>
                            <th scope="col" class="px-5 py-3">Status Tindak Lanjut</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($alerts as $alert)
                            @php
                                $eligibility = $alert['is_eligible'] ? ['label' => 'Layak', 'variant' => 'success'] : ['label' => 'Tidak Layak', 'variant' => 'danger'];
                                $remaining = $alert['sisa_hari'] < 0 ? 'Lewat '.abs($alert['sisa_hari']).' hari' : $alert['sisa_hari'].' hari';
                            @endphp
                            <tr class="transition-colors hover:bg-soft/60">
                                <th scope="row" class="px-5 py-3 text-left"><a href="{{ route('kepala-bagian.bawahan.show', $alert['pegawai_id']) }}" class="font-semibold text-ink transition hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary/30">{{ $alert['nama'] }}</a><p class="mt-1 font-mono text-xs font-normal text-muted">{{ $alert['nip'] }}</p></th>
                                <td class="px-5 py-3 text-ink">{{ $alert['jenis_event'] }}<p class="mt-1 text-xs text-muted">{{ $alert['threshold_label'] }}</p></td>
                                <td class="px-5 py-3 text-ink">{{ \Carbon\Carbon::parse($alert['tanggal_target'])->translatedFormat('d M Y') }}</td>
                                <td class="px-5 py-3"><x-ui.badge :variant="$alert['urgency']" size="sm" dot>{{ $remaining }}</x-ui.badge></td>
                                <td class="px-5 py-3"><x-ui.badge :variant="$eligibility['variant']" size="sm" dot>{{ $eligibility['label'] }}</x-ui.badge><p class="mt-1 max-w-xs text-xs text-muted">{{ $alert['eligibility_reason'] }}</p></td>
                                <td class="px-5 py-3"><x-ui.badge variant="info" size="sm" dot>{{ $alert['followup_status_label'] }}</x-ui.badge>@if ($alert['handled_note'])<p class="mt-1 max-w-xs text-xs text-muted">{{ $alert['handled_note'] }}</p>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-10 text-center text-sm text-muted">Tidak ada peringatan EWS yang sesuai dengan filter.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
