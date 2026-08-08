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
                isSubmitting: false,
                csrf: document.querySelector('meta[name=csrf-token]')?.content ?? '',
                async markAll() {
                    if (this.isSubmitting) return;
                    this.isSubmitting = true;
                    try {
                        const response = await fetch(@js(route('api.v1.notifikasi.tandai-semua-dibaca')), {
                            method: 'PATCH',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': this.csrf,
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            credentials: 'same-origin'
                        });
                        if (response.ok) {
                            window.dispatchEvent(new CustomEvent('notification-marked-read'));
                            window.location.reload();
                        }
                    } finally {
                        this.isSubmitting = false;
                    }
                }
            }"
            @click="markAll()"
            x-show="unreadCount > 0"
            :disabled="isSubmitting"
            class="inline-flex items-center gap-2 rounded-lg border border-border bg-surface px-4 py-2 text-sm font-semibold text-primary shadow-sm transition hover:bg-soft disabled:cursor-not-allowed disabled:opacity-50 font-sans cursor-pointer"
        >
            <template x-if="isSubmitting">
                <svg class="h-4 w-4 animate-spin text-primary shrink-0" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
            </template>
            <template x-if="!isSubmitting">
                <svg class="h-4 w-4 text-primary shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </template>
            <span x-text="isSubmitting ? 'Memproses...' : 'Tandai semua dibaca'"></span>
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
                        window.dispatchEvent(new CustomEvent('notification-marked-read', { detail: { id } }));
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
                    $isUnread = is_null($notif->read_at);
                    $subText = $notif->data['label'] ?? 'Notifikasi Sistem';
                    $targetUrl = $notif->data['url']
                        ?? $notif->data['link']
                        ?? $notif->data['redirect_url']
                        ?? (isset($notif->data['leave_request_id']) ? route('cuti.show', $notif->data['leave_request_id']) : route('notifications.index'));
                @endphp
                <button
                    type="button"
                    @click="openNotification(@js($notif->id), @js($targetUrl))"
                    class="flex w-full items-start gap-4 rounded-lg p-5 text-left shadow-sm transition-colors hover:bg-soft/50 {{ $isUnread ? 'border border-border border-l-4 border-l-primary bg-primary/[0.03]' : 'border border-border bg-surface opacity-80' }}"
                >
                    <div class="rounded-lg p-2.5 shrink-0 mt-0.5 {{ $isUnread ? 'bg-danger/10 text-danger' : 'bg-soft text-muted' }}">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                        </svg>
                    </div>
                    <div class="min-w-0 flex-1 space-y-1">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-bold uppercase tracking-wider font-sans {{ $isUnread ? 'text-primary' : 'text-muted' }}">{{ $subText }}</span>
                                @if($isUnread)
                                    <span class="inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-[9px] font-bold text-primary font-sans">Belum Dibaca</span>
                                @endif
                            </div>
                            <span class="text-[10px] text-muted font-sans shrink-0">{{ $notif->created_at->format('d F Y, H:i') }}</span>
                        </div>
                        <h3 class="text-sm font-sans leading-snug {{ $isUnread ? 'font-bold text-ink' : 'font-medium text-muted' }}">{{ $notif->title }}</h3>
                        <p class="text-xs font-sans leading-relaxed {{ $isUnread ? 'text-ink/80' : 'text-muted' }}">{{ $notif->body }}</p>
                    </div>
                </button>
            @empty
                <x-ui.card class="py-8">
                    <x-ui.empty-state
                        icon="bell"
                        title="Belum Ada Notifikasi"
                        message="Anda belum memiliki notifikasi atau seluruh notifikasi telah ditandai dibaca."
                    />
                </x-ui.card>
            @endforelse


            {{-- Pagination --}}
            <div class="mt-6">
                {{ $notifications->onEachSide(1)->links('vendor.pagination.simpeg') }}
            </div>
        </div>

</x-layouts.app>
