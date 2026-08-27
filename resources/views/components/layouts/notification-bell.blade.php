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
        destroy() {
            this.stopPolling();
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
                // Polling AJAX tidak boleh menimpa URL sebelumnya yang dipakai redirect validasi Laravel.
                const response = await fetch(this.endpoint, {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
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
        async markSingleAsRead(notification, e) {
            if (e) e.stopPropagation();
            const isUnread = !notification.read_at && !notification.is_read;
            if (!isUnread) return;

            notification.is_read = true;
            notification.read_at = new Date().toISOString();
            this.unreadCount = Math.max(0, this.unreadCount - 1);

            try {
                const response = await fetch(this.markEndpoint(notification.id), {
                    method: 'PATCH',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrf,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                });
                if (response.ok) {
                    window.dispatchEvent(new CustomEvent('notification-marked-read', { detail: { id: notification.id } }));
                } else {
                    this.load();
                }
            } catch (err) {
                this.load();
            }
        },
        async openNotification(notification) {
            const isUnread = !notification.read_at && !notification.is_read;
            if (isUnread) {
                notification.is_read = true;
                notification.read_at = new Date().toISOString();
                this.unreadCount = Math.max(0, this.unreadCount - 1);
            }
            try {
                if (isUnread) {
                    const response = await fetch(this.markEndpoint(notification.id), {
                        method: 'PATCH',
                        headers: {
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': this.csrf,
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        credentials: 'same-origin'
                    });
                    if (response.ok) {
                        window.dispatchEvent(new CustomEvent('notification-marked-read', { detail: { id: notification.id } }));
                    }
                }
            } finally {
                window.location.href = this.targetUrl(notification);
            }
        }
    }"
    @notification-marked-read.window="load()"
    @notification-read.window="load()"
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
            x-text="unreadCount > 99 ? '99+' : unreadCount"
            class="absolute top-0 right-0 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-bold text-white leading-none"
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
                <div
                    class="group relative flex w-full items-start justify-between gap-2 px-4 py-3 text-left transition-colors hover:bg-soft cursor-pointer"
                    :class="{ 'opacity-70': notification.read_at || notification.is_read }"
                    @click="openNotification(notification)"
                >
                    <div class="flex items-start gap-3 min-w-0 flex-1">
                        <span
                            class="mt-1.5 h-2 w-2 shrink-0 rounded-full transition-colors"
                            :class="(notification.read_at || notification.is_read) ? 'bg-border' : 'bg-primary'"
                        ></span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-sm"
                                :class="(notification.read_at || notification.is_read) ? 'font-normal text-muted' : 'font-semibold text-ink'"
                                x-text="notification.title"
                            ></span>
                            <span class="mt-0.5 block line-clamp-2 text-xs text-muted" x-text="notification.body"></span>
                        </span>
                    </div>

                    <button
                        x-show="!notification.read_at && !notification.is_read"
                        @click.stop="markSingleAsRead(notification, $event)"
                        type="button"
                        class="shrink-0 rounded p-1 text-muted hover:bg-surface hover:text-primary transition-colors mt-0.5"
                        title="Tandai dibaca"
                        aria-label="Tandai dibaca"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                        </svg>
                    </button>
                </div>
            </template>

            <div x-show="!loading && notifications.length === 0" class="px-4 py-6 text-center">
                <p class="text-sm text-muted">Belum ada notifikasi.</p>
            </div>
        </div>

        <div class="border-t border-border px-4 py-3">
            <a href="{{ route('notifications.index') }}" wire:navigate class="text-xs font-semibold text-primary hover:underline">Lihat semua notifikasi</a>
        </div>
    </div>
</div>
