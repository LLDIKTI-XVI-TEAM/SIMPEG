@component('components.layouts.app', ['title' => 'Tidak Mendapatkan Akses', 'description' => 'Anda tidak memiliki akses ke halaman ini.'])

<div class="flex flex-col items-center justify-center min-h-[60vh] px-4">
    <div class="text-center max-w-md">
        <div class="mx-auto mb-6 w-20 h-20 rounded-full bg-red-100 flex items-center justify-center">
            <svg class="w-10 h-10 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-gray-800 mb-3">Tidak Mendapatkan Akses</h1>
        <p class="text-gray-600">
            Anda tidak memiliki izin untuk mengakses halaman ini.
            Silakan hubungi administrator jika Anda merasa ini adalah kesalahan.
        </p>
    </div>
</div>

@endcomponent
