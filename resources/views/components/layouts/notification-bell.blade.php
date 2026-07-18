<div
    class="relative"
    x-data="{
        open: false,
        loading: false,
        unreadCount: 0,
        notifications: [],
        pollingTimer: null,
        requestInFlight: false,
        authRedirecting: false,
        endpoint: @js(route('api.v1.notifikasi.index')),
        loginEndpoint: @js(route('login')),
        markAllEndpoint: @js(route('api.v1.notifikasi.tandai-semua-dibaca')),
        markEndpointTemplate: @js(route('api.v1.notifikasi.tandai-dibaca', ['notificationId' => '__ID__'])),
        csrf: document.querySelector('meta[name=csrf-token]')?.content ?? '',
        init() {
            this.load();
            this.pollingTimer = setInterval(() => this.load(), 30000);
        },
        stopPolling() {
            clearInterval(this.pollingTimer);
            this.pollingTimer = false;
        },
        redirectToLogin() {
            if (this.authRedirecting) return;

            this.authRedirecting = true;
            this.stopPolling();
            window.location.assign(this.loginEndpoint);
        },
        async load() {
            if (this.requestInFlight || this.authRedirecting || this.pollingTimer === false) return;

            this.requestInFlight = true;
            this.loading = true;
            try {
                const response = await fetch(this.endpoint, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    redirect: 'manual'
                });
                if (response.type === 'opaqueredirect' || response.status === 401) {
                    this.redirectToLogin();
                    return;
                }
                if (!response.ok) return;
                const payload = await response.json();
                this.notifications = (payload.data ?? []).slice(0, 10);
                this.unreadCount = payload.meta?.unread_count ?? 0;
            } finally {
                this.requestInFlight = false;
                this.loading = false;
            }
        },
        markEndpoint(id) {
            return this.markEndpointTemplate.replace('__ID__', id);
        },
        targetUrl(notification) {
            const data = notification.data ?? {};
            return data.url ?? data.link ?? data.redirect_url ?? (data.leave_request_id ? `/dashboard/cuti/${data.leave_request_id}` : @js(route('notifications.index')));
        },
        colorFor(notification) {
            const type = (notification.type ?? '').toLowerCase();
            if (type.includes('pensiun')) return 'warning';
            if (type.includes('dokumen')) return 'danger';
            if (type.includes('cuti')) return 'info';
            if (type.includes('kenaikan_pangkat')) return 'success';
            return 'primary';
        },
        async openNotification(notification) {
            try {
                await fetch(this.markEndpoint(notification.id), {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrf,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                });
            } finally {
                window.location.href = this.targetUrl(notification);
            }
        }
    }"
>
    <button
        @click="open = !open; if (open) load()"
        id="notif-btn"
        class="relative rounded-lg p-2 text-muted transition-colors hover:bg-soft"
        aria-label="Notifikasi"
        type="button"
    >
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
        </svg>
        <span
            x-show="unreadCount > 0"
            x-text="unreadCount > 9 ? '9+' : unreadCount"
            class="absolute -right-1 -top-1 flex h-4 w-4 items-center justify-center rounded-full bg-danger text-[10px] font-bold text-white"
            style="display: none;"
        ></span>
    </button>

    <div
        x-show="open"
        @click.outside="open = false"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute right-0 top-full z-50 mt-2 w-80 origin-top-right rounded-lg border border-border bg-surface shadow-lg"
        style="display: none;"
    >
        <div class="border-b border-border px-4 py-3">
            <p class="text-sm font-semibold text-ink">Notifikasi</p>
        </div>

        <div class="max-h-96 divide-y divide-border overflow-y-auto">
            <template x-for="notification in notifications" :key="notification.id">
                <button
                    type="button"
                    @click="openNotification(notification)"
                    class="flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-soft"
                >
                    <span
                        class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
                        :class="{
                            'bg-warning': colorFor(notification) === 'warning',
                            'bg-danger': colorFor(notification) === 'danger',
                            'bg-info': colorFor(notification) === 'info',
                            'bg-success': colorFor(notification) === 'success',
                            'bg-primary': colorFor(notification) === 'primary'
                        }"
                    ></span>
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium text-ink" x-text="notification.title"></span>
                        <span class="mt-0.5 block line-clamp-2 text-xs text-muted" x-text="notification.body"></span>
                    </span>
                </button>
            </template>

            <div x-show="!loading && notifications.length === 0" class="px-4 py-6 text-center">
                <p class="text-sm text-muted">Belum ada notifikasi.</p>
            </div>
        </div>

        <div class="border-t border-border px-4 py-3">
            <a href="{{ route('notifications.index') }}" class="text-xs font-semibold text-primary hover:underline">Lihat semua notifikasi</a>
        </div>
    </div>
</div>
