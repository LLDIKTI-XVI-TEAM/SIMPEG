<x-layouts.app title="Semua Notifikasi">


    {{-- PAGE HEADER --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h2 class="text-2xl font-semibold text-ink">Pusat Notifikasi & Peringatan</h2>
            <x-ui.breadcrumb :items="[
                ['label' => 'Dashboard', 'url' => route('dashboard')],
                ['label' => 'Notifikasi']
            ]" />
        </div>
        <button
            type="button"
            x-data="{
                unreadCount: @js($unreadCount),
                csrf: document.querySelector('meta[name=csrf-token]')?.content ?? '',
                async markAll() {
                    const response = await fetch(@js(route('api.v1.notifikasi.tandai-semua-dibaca')), {
                        method: 'PATCH',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        credentials: 'same-origin'
                    });
                    if (response.ok) window.location.reload();
                }
            }"
            @click="markAll()"
            x-show="unreadCount > 0"
            class="inline-flex items-center justify-center rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:opacity-90"
        >
            Tandai semua dibaca
        </button>
    </div>


        {{-- Log Cards --}}
        <div
            class="space-y-4"
            x-data="{
                csrf: document.querySelector('meta[name=csrf-token]')?.content ?? '',
                markEndpointTemplate: @js(route('api.v1.notifikasi.tandai-dibaca', ['notificationId' => '__ID__'])),
                endpoint(id) {
                    return this.markEndpointTemplate.replace('__ID__', id);
                },
                async openNotification(id, target) {
                    try {
                        await fetch(this.endpoint(id), {
                            method: 'PATCH',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': this.csrf,
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            credentials: 'same-origin'
                        });
                    } finally {
                        window.location.href = target;
                    }
                }
            }"
        >
            @forelse($notifications as $notif)
                @php
                    $typeLower = strtolower($notif->type);
                    $color = match(true) {
                        str_contains($typeLower, 'pensiun') => 'warning',
                        str_contains($typeLower, 'dokumen') => 'danger',
                        str_contains($typeLower, 'cuti') => 'info',
                        str_contains($typeLower, 'kenaikan_pangkat') => 'success',
                        default => 'primary',
                    };
                    $subText = $notif->data['label'] ?? 'Notifikasi Sistem';
                    $targetUrl = $notif->data['url']
                        ?? $notif->data['link']
                        ?? $notif->data['redirect_url']
                        ?? (isset($notif->data['leave_request_id']) ? route('cuti.show', $notif->data['leave_request_id']) : route('notifications.index'));
                @endphp
                <button
                    type="button"
                    @click="openNotification(@js($notif->id), @js($targetUrl))"
                    class="flex w-full items-start gap-4 rounded-lg border border-border border-l-4 border-l-{{ $color }} bg-surface p-5 text-left shadow-sm transition-colors hover:bg-soft/20"
                >
                    <div class="rounded-lg bg-{{ $color }}/10 p-2.5 text-{{ $color }} shrink-0 mt-0.5">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-{{ $color }} font-sans">{{ $subText }}</span>
                            <span class="text-[10px] text-muted font-sans font-mono shrink-0">{{ $notif->created_at->format('d F Y, H:i') }}</span>
                        </div>
                        <h3 class="text-sm font-bold text-ink font-sans leading-snug">{{ $notif->title }}</h3>
                        <p class="text-xs text-muted font-sans leading-relaxed">{{ $notif->body }}</p>
                    </div>
                </button>
            @empty
                <div class="rounded-lg border border-border bg-surface p-8 text-center shadow-sm">
                    <p class="text-sm text-muted">Belum ada notifikasi.</p>
                </div>
            @endforelse


            {{-- Pagination --}}
            <div class="mt-6">
                {{ $notifications->onEachSide(1)->links('vendor.pagination.simpeg') }}
            </div>
        </div>

</x-layouts.app>
