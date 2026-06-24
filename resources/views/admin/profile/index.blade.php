<x-layouts.app title="Profil Saya">
    <div class="max-w-2xl mx-auto space-y-6">

        {{-- Profile Info Card --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6">
            <div class="border-b border-border pb-4 flex items-center gap-4">
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 text-xl font-bold text-primary">
                    {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
                </div>
                <div>
                    <h2 class="text-xl font-bold text-ink font-sans leading-tight">{{ auth()->user()->name ?? 'Pengguna' }}</h2>
                    <p class="text-xs text-muted font-sans font-mono">{{ auth()->user()->email ?? '' }}</p>
                    <span class="inline-block mt-1.5 rounded-full bg-primary/10 text-primary px-2.5 py-0.5 text-xs font-bold font-sans">Administrator</span>
                </div>
            </div>

            <div class="space-y-4">
                <h3 class="text-xs font-bold text-ink uppercase tracking-wider font-sans">Informasi Akun</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Nama Lengkap</span>
                        <p class="text-sm font-medium text-ink font-sans">{{ auth()->user()->name ?? '-' }}</p>
                    </div>
                    <div class="space-y-1">
                        <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Alamat Email</span>
                        <p class="text-sm font-medium text-ink font-sans">{{ auth()->user()->email ?? '-' }}</p>
                    </div>
                    <div class="space-y-1">
                        <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Level Akses</span>
                        <p class="text-sm font-medium text-ink font-sans">Administrator</p>
                    </div>
                    <div class="space-y-1">
                        <span class="text-[10px] font-bold text-muted uppercase tracking-wider font-sans">Status SSO Keycloak</span>
                        <p class="text-sm font-medium text-success font-sans">Terhubung</p>
                    </div>
                </div>
            </div>

            @if(auth()->user()?->role === 'super_admin')
                <div class="border-t border-border pt-6 flex justify-end">
                    <a href="{{ route('pengaturan') }}" class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-5 py-3 text-sm font-semibold text-primary transition-colors hover:bg-soft">
                        Kelola Pengaturan
                    </a>
                </div>
            @endif
        </div>

        {{-- Change Password Card --}}
        <div class="rounded-lg border border-border bg-surface p-6 shadow-sm space-y-6">
            <div class="border-b border-border pb-4">
                <h3 class="text-base font-bold text-ink font-sans leading-tight">Ubah Kata Sandi</h3>
                <p class="text-xs text-muted font-sans mt-0.5">Amankan akun Anda dengan melakukan pembaharuan kata sandi secara berkala.</p>
            </div>

            <form action="{{ route('profile.password.update') }}" method="POST" class="space-y-4">
                @csrf
                <div class="space-y-1">
                    <label for="current_password" class="text-xs font-semibold text-ink font-sans">Kata Sandi Saat Ini</label>
                    <input id="current_password" name="current_password" type="password" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="space-y-1">
                        <label for="new_password" class="text-xs font-semibold text-ink font-sans">Kata Sandi Baru</label>
                        <input id="new_password" name="new_password" type="password" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>
                    <div class="space-y-1">
                        <label for="new_password_confirmation" class="text-xs font-semibold text-ink font-sans">Konfirmasi Kata Sandi Baru</label>
                        <input id="new_password_confirmation" name="new_password_confirmation" type="password" required class="w-full rounded-lg border border-border bg-surface px-4 py-2.5 text-sm text-ink shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary font-sans">
                    </div>
                </div>

                <div class="pt-2 flex justify-end">
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 font-sans">
                        Simpan Kata Sandi Baru
                    </button>
                </div>
            </form>
        </div>

        {{-- Direct Logout Card --}}
        <div class="rounded-lg border border-danger/10 bg-danger/5 p-6 shadow-sm space-y-4 flex flex-col sm:flex-row sm:items-center sm:justify-between">
            <div class="space-y-0.5">
                <h4 class="text-sm font-bold text-danger font-sans leading-tight">Keluar dari Sistem</h4>
                <p class="text-xs text-muted font-sans leading-normal">Mengakhiri sesi operasional Anda saat ini di komputer/perangkat ini.</p>
            </div>
            <div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-danger px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:opacity-90 font-sans cursor-pointer focus:outline-none w-full sm:w-auto">
                        Keluar Sekarang
                    </button>
                </form>
            </div>
        </div>

    </div>
</x-layouts.app>
