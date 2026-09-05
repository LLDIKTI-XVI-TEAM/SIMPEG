<x-layouts.app title="Konfigurasi Channel Notifikasi">
    @php
        $inAppChannel = collect($channels)->firstWhere('code', 'in_app');
        $hasAddChannelError = $errors->has('code') || ($errors->has('name') && session()->hasOldInput('code'));
        $hasWaError = $errors->hasAny([
            'access_token',
            'clear_access_token',
            'channel_integration_id',
            'clear_channel_integration_id',
            'base_url',
            'canonical_url',
            'template_configuration',
        ]) || session()->hasOldInput('base_url') || session()->hasOldInput('canonical_url') || session()->hasOldInput('template_configuration');
        $hasRenameError = $errors->has('name') && !$hasAddChannelError && !$hasWaError;
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-ink">Konfigurasi Channel Notifikasi</h2>
                <x-ui.breadcrumb :items="[
                    ['label' => 'Dashboard', 'url' => route('dashboard')],
                    ['label' => 'Channel Notifikasi']
                ]" />
            </div>
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

        <section aria-labelledby="add-channel-title" class="rounded-2xl border border-border bg-surface p-5 shadow-sm" x-data="{ showAddForm: {{ $hasAddChannelError ? 'true' : 'false' }} }">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 id="add-channel-title" class="text-base font-semibold text-ink">Tambah channel</h2>
                    <p class="mt-1 text-xs leading-relaxed text-muted">Channel baru selalu dibuat nonaktif. Konfigurasi integrasi dikelola sesuai kebijakan masing-masing channel.</p>
                </div>
                <x-ui.button
                    type="button"
                    variant="secondary"
                    size="sm"
                    @click="showAddForm = !showAddForm"
                    x-bind:aria-expanded="showAddForm.toString()"
                    aria-controls="add-channel-panel"
                    class="shrink-0"
                >
                    <svg x-show="!showAddForm" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <svg x-show="showAddForm" x-cloak class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                    <span x-text="showAddForm ? 'Tutup form' : 'Tambah channel baru'">Tambah channel baru</span>
                </x-ui.button>
            </div>

            <!-- Expandable Form (Concept 2) -->
            <div id="add-channel-panel" x-show="showAddForm" x-cloak x-transition class="mt-4 border-t border-border pt-4">
                <form action="{{ route('data-master.channel-notifikasi.store') }}" method="POST" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_auto]" x-data="{ submitting: false }" @submit="submitting = true">
                    @csrf
                    <div>
                        <label for="new-channel-code" class="mb-1 block text-xs font-semibold text-ink">Kode channel</label>
                        <input id="new-channel-code" name="code" value="{{ old('code') }}" maxlength="50" required pattern="[a-z0-9_]+" autocomplete="off" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary font-mono" placeholder="contoh_gateway">
                    </div>
                    <div>
                        <label for="new-channel-name" class="mb-1 block text-xs font-semibold text-ink">Nama channel</label>
                        <input id="new-channel-name" name="name" value="{{ old('name') }}" maxlength="100" required autocomplete="off" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" placeholder="Nama channel">
                    </div>
                    <x-ui.button type="submit" class="self-end" ::disabled="submitting">
                        <span x-show="!submitting">Tambah</span>
                        <span x-show="submitting" style="display: none;">Menyimpan...</span>
                    </x-ui.button>
                </form>
            </div>
        </section>

        <section aria-labelledby="master-channel-title" class="space-y-3" x-data x-init="setTimeout(() => { if (typeof sessionStorage !== 'undefined') sessionStorage.removeItem('channel-notifikasi.rename-channel-id'); }, 0)">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 id="master-channel-title" class="text-lg font-semibold text-ink">Master channel</h2>
                    <p class="mt-1 text-xs text-muted">Master channel adalah kill-switch global. Policy event tetap tersimpan ketika master dinonaktifkan.</p>
                </div>
            </div>

            <div class="rounded-2xl border border-border bg-surface shadow-sm overflow-hidden divide-y divide-border">
                @forelse($channels as $channel)
                    <article data-channel-code="{{ $channel['code'] }}" data-channel-enabled="{{ $channel['is_enabled'] ? 'true' : 'false' }}" data-adapter-available="{{ $channel['adapter_available'] ? 'true' : 'false' }}" class="p-5 hover:bg-soft/20 transition-colors" x-data="{ openRename: {{ $hasRenameError ? "((typeof sessionStorage !== 'undefined' && sessionStorage.getItem('channel-notifikasi.rename-channel-id') === '" . $channel['id'] . "') ? true : false)" : 'false' }}, openWaConfig: {{ ($channel['code'] === 'whatsapp_business' && $hasWaError) ? 'true' : 'false' }} }">
                        <!-- Main Horizontal Row -->
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <!-- Left: Channel Icon & Identity -->
                            <div class="flex items-center gap-4 min-w-0">
                                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl {{ $channel['code'] === 'in_app' ? 'bg-primary/10 text-primary' : ($channel['code'] === 'email' ? 'bg-info/10 text-info' : ($channel['code'] === 'whatsapp_business' ? 'bg-emerald-50 text-emerald-600' : 'bg-soft text-ink')) }}">
                                    @if($channel['code'] === 'in_app')
                                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" /></svg>
                                    @elseif($channel['code'] === 'email')
                                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                                    @elseif($channel['code'] === 'whatsapp_business')
                                        <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z"/></svg>
                                    @else
                                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75" /></svg>
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="truncate text-base font-semibold text-ink">{{ $channel['name'] }}</h3>
                                        <code class="rounded bg-soft px-2 py-0.5 text-xs font-mono text-muted">{{ $channel['code'] }}</code>
                                        @if($channel['is_core'])
                                            <span class="rounded bg-primary/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-primary">Inti</span>
                                        @endif
                                    </div>
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                                        @if(!$channel['adapter_available'])
                                            <span class="inline-flex items-center gap-1 font-semibold text-ink">
                                                <span class="h-1.5 w-1.5 rounded-full bg-warning"></span> Runtime belum tersedia
                                            </span>
                                        @elseif($channel['is_enabled'])
                                            <span class="inline-flex items-center gap-1 font-semibold text-success">
                                                <span class="h-1.5 w-1.5 rounded-full bg-success"></span> Master Aktif
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 font-semibold text-muted">
                                                <span class="h-1.5 w-1.5 rounded-full bg-border"></span> Master Nonaktif
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            <!-- Right: Status Badge, Action Controls & Switch -->
                            <div class="flex flex-wrap items-center justify-between md:justify-end gap-3 border-t md:border-t-0 pt-3 md:pt-0 border-border">
                                <button
                                    type="button"
                                    @click="openRename = !openRename"
                                    :aria-expanded="openRename.toString()"
                                    aria-controls="rename-panel-{{ $channel['id'] }}"
                                    class="inline-flex items-center gap-1 rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-medium text-muted hover:border-ink hover:text-ink transition-colors"
                                >
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" /></svg>
                                    <span x-text="openRename ? 'Tutup' : 'Ubah nama'">Ubah nama</span>
                                </button>

                                @if($channel['code'] === 'whatsapp_business' && $channel['whatsapp_config'] !== null)
                                    <button
                                        type="button"
                                        @click="openWaConfig = !openWaConfig"
                                        :aria-expanded="openWaConfig.toString()"
                                        aria-controls="whatsapp-config-panel-{{ $channel['id'] }}"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-600/40 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-800 hover:bg-emerald-100 transition-colors"
                                    >
                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                        <span x-text="openWaConfig ? 'Tutup konfigurasi' : 'Konfigurasi WhatsApp'">Konfigurasi WhatsApp</span>
                                    </button>
                                @endif

                                @if(!$channel['adapter_available'])
                                    <x-ui.button type="button" variant="muted" size="sm" disabled aria-describedby="unavailable-{{ $channel['id'] }}">Belum tersedia</x-ui.button>
                                    <span id="unavailable-{{ $channel['id'] }}" class="text-xs text-muted sr-only">Adapter runtime belum tersedia.</span>
                                @elseif($channel['code'] === 'in_app' && $channel['is_enabled'])
                                    <x-ui.button type="button" variant="danger" size="sm" aria-haspopup="dialog" aria-controls="in-app-disable-dialog" onclick="document.getElementById('in-app-disable-dialog').showModal()">Nonaktifkan</x-ui.button>
                                @else
                                    <form action="{{ route('data-master.channel-notifikasi.status', $channel['id']) }}" method="POST" x-data="{ submitting: false }" @submit="submitting = true" class="inline-flex">
                                        @csrf
                                        <input type="hidden" name="is_enabled" value="{{ $channel['is_enabled'] ? '0' : '1' }}">
                                        <x-ui.button type="submit" variant="{{ $channel['is_enabled'] ? 'danger' : 'success' }}" size="sm" ::disabled="submitting">
                                            {{ $channel['is_enabled'] ? 'Nonaktifkan' : 'Aktifkan' }}
                                        </x-ui.button>
                                    </form>
                                @endif

                                @if(!$channel['is_core'])
                                    <form action="{{ route('data-master.channel-notifikasi.destroy', $channel['id']) }}" method="POST" class="inline-flex" x-data="{ submitting: false }" @submit="if (!confirm('Hapus channel yang belum dipakai ini?')) { $event.preventDefault(); return; } submitting = true">
                                        @csrf
                                        <x-ui.button type="submit" variant="danger" size="sm" ::disabled="submitting">Hapus</x-ui.button>
                                    </form>
                                @endif
                            </div>
                        </div>

                        <!-- Expandable Inline Rename Form (Concept 2) -->
                        <div id="rename-panel-{{ $channel['id'] }}" x-show="openRename" x-cloak x-transition class="mt-4 rounded-xl border border-border/80 bg-soft/40 p-4">
                            <form action="{{ route('data-master.channel-notifikasi.update', $channel['id']) }}" method="POST" class="flex flex-col sm:flex-row gap-3 sm:items-end" x-data="{ submitting: false }" @submit="if (typeof sessionStorage !== 'undefined') { sessionStorage.setItem('channel-notifikasi.rename-channel-id', '{{ $channel['id'] }}'); } submitting = true">
                                @csrf
                                <div class="flex-1">
                                    <label for="channel-name-{{ $channel['id'] }}" class="mb-1 block text-xs font-semibold text-ink">Nama tampilan</label>
                                    <input id="channel-name-{{ $channel['id'] }}" name="name" value="{{ $channel['name'] }}" maxlength="100" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                </div>
                                <div class="flex gap-2">
                                    <x-ui.button type="submit" size="sm" ::disabled="submitting">Simpan perubahan</x-ui.button>
                                    <x-ui.button type="button" variant="secondary" size="sm" @click="openRename = false">Batal</x-ui.button>
                                </div>
                            </form>
                        </div>

                        <!-- Expandable Full-Width WhatsApp Business Configuration Panel (Concept 2) -->
                        @if($channel['code'] === 'whatsapp_business' && $channel['whatsapp_config'] !== null)
                            <div id="whatsapp-config-panel-{{ $channel['id'] }}" x-show="openWaConfig" x-cloak x-transition class="mt-4 rounded-xl border border-border/90 bg-soft/50 p-5">
                                <div class="flex items-center justify-between border-b border-border/80 pb-3">
                                    <div>
                                        <h4 class="text-sm font-bold text-ink">Konfigurasi WhatsApp Business (Qontak API)</h4>
                                        <p class="mt-1 text-xs text-muted leading-relaxed">
                                            Access token disimpan terenkripsi. Access token dan Channel Integration ID bersifat write-only sehingga nilai tersimpan tidak pernah ditampilkan kembali. Menyimpan konfigurasi tidak mengaktifkan pengiriman; readiness, status channel, dan kebijakan event tetap harus terpenuhi.
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        @click="openWaConfig = false"
                                        class="rounded-lg p-1.5 text-muted hover:bg-surface hover:text-ink"
                                        aria-label="Tutup konfigurasi WhatsApp"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </div>

                                <form action="{{ route('data-master.channel-notifikasi.whatsapp-config', $channel['id']) }}" method="POST" class="mt-4 space-y-4" x-data="{ submitting: false }" @submit="submitting = true">
                                    @csrf
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <div class="mb-1 flex items-center justify-between gap-3">
                                                <label for="wa-access-token-{{ $channel['id'] }}" class="block text-xs font-semibold text-ink">Access token Qontak</label>
                                                <span class="text-[11px] font-medium {{ $channel['whatsapp_config']['access_token_configured'] ? 'text-success' : 'text-muted' }}">
                                                    {{ $channel['whatsapp_config']['access_token_configured'] ? 'Token akses tersimpan' : 'Token akses belum tersimpan' }}
                                                </span>
                                            </div>
                                            <input id="wa-access-token-{{ $channel['id'] }}" type="password" name="access_token" value="" maxlength="10000" autocomplete="new-password" placeholder="{{ $channel['whatsapp_config']['access_token_configured'] ? 'Kosongkan untuk mempertahankan token saat ini' : 'Masukkan access token Qontak' }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                            @if($channel['whatsapp_config']['access_token_configured'])
                                                <label class="mt-2 flex items-start gap-2 text-xs text-muted">
                                                    <input type="checkbox" name="clear_access_token" value="1" class="mt-0.5 rounded border-border text-danger focus:ring-danger">
                                                    <span>Hapus access token tersimpan saat konfigurasi ini disimpan.</span>
                                                </label>
                                            @endif
                                        </div>

                                        <div>
                                            <div class="mb-1 flex items-center justify-between gap-3">
                                                <label for="wa-channel-integration-id-{{ $channel['id'] }}" class="block text-xs font-semibold text-ink">Channel Integration ID</label>
                                                <span class="text-[11px] font-medium {{ $channel['whatsapp_config']['channel_integration_id_configured'] ? 'text-success' : 'text-muted' }}">
                                                    {{ $channel['whatsapp_config']['channel_integration_id_configured'] ? 'Channel ID tersimpan' : 'Channel ID belum tersimpan' }}
                                                </span>
                                            </div>
                                            <input id="wa-channel-integration-id-{{ $channel['id'] }}" type="password" name="channel_integration_id" value="" maxlength="36" autocomplete="new-password" placeholder="{{ $channel['whatsapp_config']['channel_integration_id_configured'] ? 'Kosongkan untuk mempertahankan Channel ID saat ini' : 'Masukkan UUID Channel Integration ID' }}" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                            @if($channel['whatsapp_config']['channel_integration_id_configured'])
                                                <label class="mt-2 flex items-start gap-2 text-xs text-muted">
                                                    <input type="checkbox" name="clear_channel_integration_id" value="1" class="mt-0.5 rounded border-border text-danger focus:ring-danger">
                                                    <span>Hapus Channel Integration ID tersimpan saat konfigurasi ini disimpan.</span>
                                                </label>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <label for="wa-base-url-{{ $channel['id'] }}" class="mb-1 block text-xs font-semibold text-ink">Base URL API</label>
                                            <input id="wa-base-url-{{ $channel['id'] }}" name="base_url" value="{{ old('base_url', $channel['whatsapp_config']['base_url']) }}" maxlength="255" autocomplete="off" placeholder="https://service-chat.qontak.com/api/open/v1" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                        </div>
                                        <div>
                                            <label for="wa-canonical-url-{{ $channel['id'] }}" class="mb-1 block text-xs font-semibold text-ink">Domain resmi (canonical URL)</label>
                                            <input id="wa-canonical-url-{{ $channel['id'] }}" name="canonical_url" value="{{ old('canonical_url', $channel['whatsapp_config']['canonical_url']) }}" maxlength="255" autocomplete="off" placeholder="https://simpeg.lldiktiwil16.id" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                        </div>
                                    </div>

                                    <div>
                                        <label for="wa-template-config-{{ $channel['id'] }}" class="mb-1 block text-xs font-semibold text-ink">Kontrak template resmi (JSON)</label>
                                        <textarea id="wa-template-config-{{ $channel['id'] }}" name="template_configuration" rows="5" spellcheck="false" placeholder='{"event_templates":{...},"templates":{...}}' class="w-full rounded-lg border border-border bg-surface px-3 py-2 font-mono text-xs text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary">{{ old('template_configuration', $channel['whatsapp_config']['template_configuration']) }}</textarea>
                                        <p class="mt-1 text-xs leading-relaxed text-muted">Untuk tombol URL berbentuk <code>https://domain/@{{1}}</code>, gunakan <code>"value_format":"path_without_leading_slash"</code> agar nilai yang dikirim menjadi <code>dashboard/...</code>. Gunakan format lain hanya sesuai kontrak tertulis dari provider.</p>
                                    </div>

                                    <div class="flex items-center justify-end gap-2 pt-2">
                                        <x-ui.button type="button" variant="secondary" size="sm" @click="openWaConfig = false">Tutup</x-ui.button>
                                        <button type="submit" :disabled="submitting" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:cursor-wait disabled:opacity-60">
                                            <span x-show="!submitting">Simpan konfigurasi</span>
                                            <span x-show="submitting" style="display: none;">Menyimpan...</span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="p-8 text-center text-sm text-muted">
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
                    <x-ui.button type="button" variant="secondary" class="focus-visible:ring-2" onclick="this.closest('dialog').close()">Batal</x-ui.button>
                    <x-ui.button type="submit" variant="danger-solid" class="focus-visible:ring-2" ::disabled="submitting">Ya, nonaktifkan</x-ui.button>
                </div>
            </form>
        </dialog>
    @endif
</x-layouts.app>
