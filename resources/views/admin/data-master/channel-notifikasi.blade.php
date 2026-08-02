<x-layouts.app title="Konfigurasi Channel Notifikasi">
    @php
        $inAppChannel = collect($channels)->firstWhere('code', 'in_app');
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <nav class="mb-1 flex items-center gap-1.5 text-xs text-muted" aria-label="Breadcrumb">
                    <a href="{{ route('dashboard') }}" class="rounded-sm transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">Dashboard</a>
                    <span aria-hidden="true">/</span>
                    <a href="{{ route('data-master') }}" class="rounded-sm transition-colors hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">Data Master</a>
                    <span aria-hidden="true">/</span>
                    <span aria-current="page" class="font-medium text-ink">Channel Notifikasi</span>
                </nav>
                <h1 class="text-2xl font-semibold text-ink">Konfigurasi Channel Notifikasi</h1>
                <p class="mt-1 max-w-3xl text-sm leading-relaxed text-muted">
                    Kelola master channel dan kebijakan delivery untuk setiap event. Status efektif membutuhkan master channel dan policy event sama-sama aktif.
                </p>
            </div>
            <span class="inline-flex w-fit items-center rounded-full border border-border bg-surface px-3 py-1.5 text-xs font-semibold text-muted">
                Khusus Super Admin
            </span>
        </div>

        @if(session('success'))
            <div role="status" aria-live="polite" class="rounded-lg border border-success/25 bg-success/5 px-4 py-3 text-sm font-medium text-success">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div role="alert" class="rounded-lg border border-danger/25 bg-danger/5 px-4 py-3 text-sm text-danger">
                <p class="font-semibold">Perubahan belum dapat disimpan.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section aria-labelledby="add-channel-title" class="rounded-xl border border-border bg-surface p-5 shadow-sm">
            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] lg:items-end">
                <div>
                    <h2 id="add-channel-title" class="text-base font-semibold text-ink">Tambah channel</h2>
                    <p class="mt-1 text-xs leading-relaxed text-muted">Channel baru selalu dibuat nonaktif. Credential tetap dikelola melalui environment atau secret manager.</p>
                </div>
                <form action="{{ route('data-master.channel-notifikasi.store') }}" method="POST" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_auto]" x-data="{ submitting: false }" @submit="submitting = true">
                    @csrf
                    <div>
                        <label for="new-channel-code" class="mb-1 block text-xs font-semibold text-ink">Kode channel</label>
                        <input id="new-channel-code" name="code" value="{{ old('code') }}" maxlength="50" required pattern="[a-z0-9_]+" autocomplete="off" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" placeholder="contoh_gateway">
                    </div>
                    <div>
                        <label for="new-channel-name" class="mb-1 block text-xs font-semibold text-ink">Nama channel</label>
                        <input id="new-channel-name" name="name" value="{{ old('name') }}" maxlength="100" required autocomplete="off" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" placeholder="Nama channel">
                    </div>
                    <button type="submit" :disabled="submitting" class="self-end rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60">
                        <span x-show="!submitting">Tambah</span>
                        <span x-show="submitting" style="display: none;">Menyimpan...</span>
                    </button>
                </form>
            </div>
        </section>

        <section aria-labelledby="master-channel-title" class="space-y-3">
            <div>
                <h2 id="master-channel-title" class="text-lg font-semibold text-ink">Master channel</h2>
                <p class="mt-1 text-xs text-muted">Master channel adalah kill-switch global. Policy event tetap tersimpan ketika master dinonaktifkan.</p>
            </div>

            <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
                @forelse($channels as $channel)
                    <article data-channel-code="{{ $channel['code'] }}" data-channel-enabled="{{ $channel['is_enabled'] ? 'true' : 'false' }}" data-adapter-available="{{ $channel['adapter_available'] ? 'true' : 'false' }}" class="rounded-xl border border-border bg-surface p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h3 class="truncate text-base font-semibold text-ink">{{ $channel['name'] }}</h3>
                                <code class="mt-1 block truncate text-xs text-muted">{{ $channel['code'] }}</code>
                            </div>
                            @if(!$channel['adapter_available'])
                                <span class="shrink-0 rounded-full border border-warning/30 bg-warning/10 px-2.5 py-1 text-[11px] font-semibold text-ink">Belum tersedia</span>
                            @elseif($channel['is_enabled'])
                                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-success/30 bg-success/10 px-2.5 py-1 text-[11px] font-semibold text-success">
                                    <span aria-hidden="true">✓</span> Aktif
                                </span>
                            @else
                                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-border bg-soft px-2.5 py-1 text-[11px] font-semibold text-muted">
                                    <span aria-hidden="true">—</span> Nonaktif
                                </span>
                            @endif
                        </div>

                        <form action="{{ route('data-master.channel-notifikasi.update', $channel['id']) }}" method="POST" class="mt-4" x-data="{ submitting: false }" @submit="submitting = true">
                            @csrf
                            <label for="channel-name-{{ $channel['id'] }}" class="mb-1 block text-xs font-semibold text-ink">Nama tampilan</label>
                            <div class="flex gap-2">
                                <input id="channel-name-{{ $channel['id'] }}" name="name" value="{{ $channel['name'] }}" maxlength="100" required class="min-w-0 flex-1 rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                <button type="submit" :disabled="submitting" class="rounded-lg border border-border px-3 py-2 text-xs font-semibold text-ink hover:bg-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:cursor-wait disabled:opacity-60">Simpan</button>
                            </div>
                        </form>

                        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-border pt-4">
                            @if(!$channel['adapter_available'])
                                <button type="button" disabled aria-describedby="unavailable-{{ $channel['id'] }}" class="cursor-not-allowed rounded-lg border border-border bg-soft px-3 py-2 text-xs font-semibold text-muted opacity-70">Belum tersedia</button>
                                <span id="unavailable-{{ $channel['id'] }}" class="text-xs text-muted">Adapter runtime belum tersedia.</span>
                            @elseif($channel['code'] === 'in_app' && $channel['is_enabled'])
                                <button type="button" aria-haspopup="dialog" aria-controls="in-app-disable-dialog" onclick="document.getElementById('in-app-disable-dialog').showModal()" class="rounded-lg border border-danger/30 px-3 py-2 text-xs font-semibold text-danger hover:bg-danger/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger">Nonaktifkan</button>
                            @else
                                <form action="{{ route('data-master.channel-notifikasi.status', $channel['id']) }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true">
                                    @csrf
                                    <input type="hidden" name="is_enabled" value="{{ $channel['is_enabled'] ? '0' : '1' }}">
                                    <button type="submit" :disabled="submitting" class="rounded-lg border px-3 py-2 text-xs font-semibold focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:cursor-wait disabled:opacity-60 {{ $channel['is_enabled'] ? 'border-danger/30 text-danger hover:bg-danger/5' : 'border-success/30 text-success hover:bg-success/5' }}">
                                        {{ $channel['is_enabled'] ? 'Nonaktifkan' : 'Aktifkan' }}
                                    </button>
                                </form>
                            @endif

                            @if(!$channel['is_core'])
                                <form action="{{ route('data-master.channel-notifikasi.destroy', $channel['id']) }}" method="POST" class="ml-auto" x-data="{ submitting: false }" @submit="if (!confirm('Hapus channel yang belum dipakai ini?')) { $event.preventDefault(); return; } submitting = true">
                                    @csrf
                                    <button type="submit" :disabled="submitting" class="rounded-lg px-3 py-2 text-xs font-semibold text-danger hover:bg-danger/5 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger disabled:cursor-wait disabled:opacity-60">Hapus</button>
                                </form>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="rounded-xl border border-dashed border-border bg-surface p-8 text-center text-sm text-muted lg:col-span-2 xl:col-span-3">
                        Belum ada channel notifikasi.
                    </div>
                @endforelse
            </div>

            @if($channelPaginator->hasPages())
                <div class="pt-2" aria-label="Navigasi halaman channel notifikasi">
                    {{ $channelPaginator->onEachSide(1)->links() }}
                </div>
            @endif
        </section>

        <section aria-labelledby="event-matrix-title" class="rounded-xl border border-border bg-surface shadow-sm">
            <div class="border-b border-border px-5 py-4">
                <h2 id="event-matrix-title" class="text-lg font-semibold text-ink">Kebijakan channel per event</h2>
                <p class="mt-1 text-xs leading-relaxed text-muted">Pilihan tersimpan dan status efektif ditampilkan terpisah. Gunakan tombol pada setiap sel untuk menyimpan desired state.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full border-collapse text-left text-sm">
                    <thead class="bg-soft/60 text-xs text-muted">
                        <tr>
                            <th scope="col" class="sticky left-0 z-10 min-w-64 border-b border-r border-border bg-soft px-4 py-3 font-semibold">Event</th>
                            @foreach($channels as $channel)
                                <th scope="col" class="min-w-52 border-b border-border px-4 py-3 font-semibold">
                                    <span class="block text-ink">{{ $channel['name'] }}</span>
                                    <code class="mt-0.5 block text-[10px] font-normal text-muted">{{ $channel['code'] }}</code>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach($eventGroups as $groupName => $events)
                            <tr>
                                <th scope="rowgroup" colspan="{{ count($channels) + 1 }}" class="bg-primary/5 px-4 py-2 text-xs font-bold uppercase tracking-wider text-primary">{{ $groupName }}</th>
                            </tr>
                            @foreach($events as $event)
                                <tr data-event-key="{{ $event['key'] }}" class="align-top hover:bg-soft/30">
                                    <th scope="row" class="sticky left-0 z-10 border-r border-border bg-surface px-4 py-3 font-medium text-ink">
                                        <span class="block">{{ $event['label'] }}</span>
                                        <code class="mt-1 block text-[10px] font-normal text-muted">{{ $event['key'] }}</code>
                                    </th>
                                    @foreach($channels as $channel)
                                        @php($policy = $channel['policies'][$event['key']])
                                        <td data-policy-event="{{ $event['key'] }}" data-policy-channel="{{ $channel['code'] }}" data-policy-raw="{{ $policy['raw_enabled'] ? 'enabled' : 'disabled' }}" data-policy-effective="{{ $policy['effective_enabled'] ? 'enabled' : 'disabled' }}" class="px-4 py-3">
                                            @if(!$channel['adapter_available'])
                                                <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted"><span aria-hidden="true">○</span> Belum tersedia</span>
                                            @elseif(!$policy['supported'])
                                                <span data-policy-unsupported="{{ $event['key'] }}:{{ $channel['code'] }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-muted"><span aria-hidden="true">—</span> Tidak didukung</span>
                                            @else
                                                @php($policyStatusId = 'policy-status-'.$channel['id'].'-'.str_replace(['.', '_'], '-', $event['key']))
                                                @php($policyStatusReason = $policy['effective_enabled'] ? 'Policy dan master aktif.' : ($policy['raw_enabled'] ? 'Master channel nonaktif.' : 'Policy tidak dipilih.'))
                                                <form data-policy-control="{{ $channel['code'] }}" action="{{ route('data-master.channel-notifikasi.policy', $channel['id']) }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true">
                                                    @csrf
                                                    <input type="hidden" name="event_key" value="{{ $event['key'] }}">
                                                    <input type="hidden" name="is_enabled" value="{{ $policy['raw_enabled'] ? '0' : '1' }}">
                                                    <span id="{{ $policyStatusId }}" class="sr-only">Policy tersimpan {{ $policy['raw_enabled'] ? 'aktif' : 'nonaktif' }}. Status efektif {{ $policy['effective_enabled'] ? 'aktif' : 'nonaktif' }}. {{ $policyStatusReason }}</span>
                                                    <button type="submit" :disabled="submitting" aria-label="{{ $policy['raw_enabled'] ? 'Nonaktifkan' : 'Aktifkan' }} {{ $channel['name'] }} untuk {{ $event['label'] }}" aria-describedby="{{ $policyStatusId }}" class="w-full rounded-lg border px-3 py-2 text-left transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary disabled:cursor-wait disabled:opacity-60 {{ $policy['effective_enabled'] ? 'border-success/30 bg-success/5 text-success' : ($policy['raw_enabled'] ? 'border-warning/30 bg-warning/5 text-ink' : 'border-border bg-surface text-muted hover:bg-soft') }}">
                                                        @if($policy['effective_enabled'])
                                                            <span class="block text-xs font-semibold"><span aria-hidden="true">✓</span> Aktif</span>
                                                            <span class="mt-0.5 block text-[10px]">Policy dan master aktif</span>
                                                        @elseif($policy['raw_enabled'])
                                                            <span class="block text-xs font-semibold"><span aria-hidden="true">!</span> Dipilih, belum efektif</span>
                                                            <span class="mt-0.5 block text-[10px]">Master channel nonaktif</span>
                                                        @else
                                                            <span class="block text-xs font-semibold"><span aria-hidden="true">○</span> Nonaktif</span>
                                                            <span class="mt-0.5 block text-[10px]">Policy tidak dipilih</span>
                                                        @endif
                                                    </button>
                                                </form>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @if($inAppChannel && $inAppChannel['is_enabled'])
        <dialog id="in-app-disable-dialog" aria-labelledby="in-app-disable-title" aria-describedby="in-app-disable-warning" class="w-[min(32rem,calc(100%-2rem))] rounded-xl border border-border bg-surface p-0 text-ink shadow-xl backdrop:bg-ink/60">
            <form action="{{ route('data-master.channel-notifikasi.status', $inAppChannel['id']) }}" method="POST" class="p-6" x-data="{ submitting: false }" @submit="submitting = true">
                @csrf
                <input type="hidden" name="is_enabled" value="0">
                <h2 id="in-app-disable-title" class="text-lg font-semibold text-ink">Nonaktifkan kanal In-App?</h2>
                <p id="in-app-disable-warning" class="mt-3 text-sm leading-relaxed text-muted">Menonaktifkan kanal In-App akan menghentikan pembuatan notifikasi di dalam aplikasi. Pada alur saat ini, sebagian pengiriman email bergantung pada notifikasi In-App sehingga email terkait juga dapat tidak terkirim. Konfigurasi per event tetap disimpan dan akan berlaku kembali ketika kanal diaktifkan. Lanjutkan?</p>
                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                    <button type="button" onclick="this.closest('dialog').close()" class="rounded-lg border border-border px-4 py-2 text-sm font-semibold text-ink hover:bg-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">Batal</button>
                    <button type="submit" :disabled="submitting" class="rounded-lg bg-danger px-4 py-2 text-sm font-semibold text-white hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-danger focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60">Ya, nonaktifkan</button>
                </div>
            </form>
        </dialog>
    @endif
</x-layouts.app>
