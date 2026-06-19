<!doctype html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ $description ?? 'Sistem Informasi Manajemen Kepegawaian LLDIKTI Wilayah XVI' }}">
    <title>{{ $title ?? 'Login' }} — SIMPEG</title>

    <!-- Poppins Font -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="h-full bg-page font-sans">

<div class="flex min-h-screen flex-col items-center justify-center px-4 py-12">

    {{-- Brand Header --}}
    <div class="mb-8 flex flex-col items-center gap-3 text-center">
        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary shadow-sm">
            <span class="text-lg font-bold text-white">S</span>
        </div>
        <div>
            <p class="text-xl font-bold text-primary">SIMPEG</p>
            <p class="text-sm text-muted">{{ $heading ?? 'Sistem Informasi Manajemen Kepegawaian' }}</p>
        </div>
    </div>

    {{-- Card --}}
    <div class="w-full max-w-md">
        <div class="rounded-xl border border-border bg-surface p-8 shadow-sm">
            {{ $slot }}
        </div>
    </div>

    {{-- Footer --}}
    <p class="mt-8 text-xs text-muted">
        &copy; {{ date('Y') }} LLDIKTI Wilayah XVI — Sistem Kepegawaian
    </p>

</div>

@stack('scripts')
</body>
</html>
