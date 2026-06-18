<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'SIMPEG') }}</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <main class="min-h-screen bg-page">
            <header class="border-b border-primary/10 bg-surface">
                <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-5 lg:px-8">
                    <a href="{{ url('/') }}" class="flex items-center gap-3">
                        <span class="flex h-11 w-11 items-center justify-center rounded-lg bg-primary text-sm font-bold text-white shadow-sm">
                            SP
                        </span>
                        <span>
                            <span class="block text-base font-semibold text-primary">SIMPEG</span>
                            <span class="block text-xs font-medium text-muted">LLDIKTI</span>
                        </span>
                    </a>

                    @if (Route::has('login'))
                        <nav class="flex items-center gap-3 text-sm font-semibold">
                            @auth
                                <a
                                    href="{{ url('/dashboard') }}"
                                    class="rounded-lg bg-primary px-4 py-2.5 text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2"
                                >
                                    Dashboard
                                </a>
                            @else
                                <a
                                    href="{{ route('login') }}"
                                    class="rounded-lg px-4 py-2.5 text-primary transition hover:bg-primary-50 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2"
                                >
                                    Masuk
                                </a>

                                @if (Route::has('register'))
                                    <a
                                        href="{{ route('register') }}"
                                        class="rounded-lg bg-primary px-4 py-2.5 text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2"
                                    >
                                        Daftar
                                    </a>
                                @endif
                            @endauth
                        </nav>
                    @endif
                </div>
            </header>

            <section class="mx-auto grid max-w-7xl gap-10 px-6 py-14 lg:grid-cols-[1.05fr_0.95fr] lg:items-center lg:px-8 lg:py-20">
                <div>
                    <p class="mb-4 inline-flex rounded-full bg-secondary-100 px-4 py-2 text-sm font-semibold text-secondary-700">
                        Tema terang LLDIKTI SIMPEG
                    </p>
                    <h1 class="max-w-3xl text-4xl font-bold leading-tight text-primary sm:text-5xl">
                        Hanya Test informasi kepegawaian yang rapi, ringan, dan konsisten.
                    </h1>
                    <p class="mt-5 max-w-2xl text-base leading-8 text-ink sm:text-lg">
                        Fondasi antarmuka sudah memakai Tailwind CSS, font Poppins, serta palet utama biru dan aksen emas agar setiap halaman bisa mengikuti bahasa visual yang sama.
                    </p>
                    <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                        <a
                            href="{{ Route::has('login') ? route('login') : '#' }}"
                            class="inline-flex items-center justify-center rounded-lg bg-primary px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2"
                        >
                            Mulai Sekarang
                        </a>
                        <a
                            href="https://laravel.com/docs"
                            class="inline-flex items-center justify-center rounded-lg border border-primary/15 bg-surface px-5 py-3 text-sm font-semibold text-primary transition hover:border-primary/30 hover:bg-primary-50 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:ring-offset-2"
                        >
                            Dokumentasi Laravel
                        </a>
                    </div>
                </div>

                <div class="rounded-lg border border-primary/10 bg-surface p-6 shadow-sm">
                    <div class="flex items-center justify-between border-b border-primary/10 pb-5">
                        <div>
                            <p class="text-sm font-semibold text-primary">Ringkasan Tema</p>
                            <p class="mt-1 text-sm text-muted">Token desain aktif untuk Blade.</p>
                        </div>
                        <span class="rounded-full bg-secondary-100 px-3 py-1 text-xs font-semibold text-secondary-700">
                            Light
                        </span>
                    </div>

                    <dl class="mt-6 grid gap-4">
                        <div class="rounded-lg bg-primary-50 p-4">
                            <dt class="text-sm font-semibold text-primary">Primary</dt>
                            <dd class="mt-2 flex items-center justify-between text-sm text-muted">
                                <span>#122E92</span>
                                <span class="h-8 w-8 rounded-md bg-primary shadow-sm"></span>
                            </dd>
                        </div>
                        <div class="rounded-lg bg-secondary-50 p-4">
                            <dt class="text-sm font-semibold text-secondary-700">Secondary</dt>
                            <dd class="mt-2 flex items-center justify-between text-sm text-muted">
                                <span>#D6AC48</span>
                                <span class="h-8 w-8 rounded-md bg-secondary shadow-sm"></span>
                            </dd>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-4">
                            <dt class="text-sm font-semibold text-ink">Typography</dt>
                            <dd class="mt-2 text-sm text-muted">Poppins untuk seluruh tampilan.</dd>
                        </div>
                    </dl>
                </div>
            </section>
        </main>
    </body>
</html>
