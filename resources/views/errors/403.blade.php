<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tidak Mendapatkan Akses — SIMPEG</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="{{ asset('img/dikti16-favicon-blue-150x150.png') }}">
    @vite(['resources/css/app.css'])
</head>
<body class="h-full font-sans bg-gray-50 text-gray-800 antialiased">
    <div class="min-h-full flex flex-col items-center justify-center px-4 py-12">
        <div class="text-center max-w-md">
            <div class="mx-auto mb-6 w-20 h-20 rounded-full bg-red-100 flex items-center justify-center">
                <svg class="w-10 h-10 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-800 mb-3">Tidak Mendapatkan Akses</h1>
            <p class="text-gray-600 mb-6">
                Anda tidak memiliki izin untuk mengakses halaman ini.
                Silakan hubungi administrator jika Anda merasa ini adalah kesalahan.
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                <a href="{{ url('/dashboard') }}"
                   wire:navigate
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                    </svg>
                    Kembali ke Beranda
                </a>
                <button onclick="history.back()"
                        class="inline-flex items-center gap-2 px-5 py-2.5 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3" />
                    </svg>
                    Kembali
                </button>
            </div>
        </div>
    </div>
</body>
</html>
