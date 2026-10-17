<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#f6f5f2" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#14181e" media="(prefers-color-scheme: dark)">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <title inertia>{{ config('app.name', 'MINETRACE') }}</title>

    <!--
        Font: Inter Variable di-self-host (resources/fonts) — tanpa CDN pihak ketiga, aman untuk jaringan lemah.
        Preload hanya berkas kritis (subset Latin); font-display: swap di app.css.
    -->
    <link rel="preload" href="{{ Vite::asset('resources/fonts/inter-latin-wght-normal.woff2') }}" as="font" type="font/woff2" crossorigin>

    <!-- ─── Dark mode init — prevent FOUC ───────────────────────────── -->
    <!--
        Script ini dijalankan SEBELUM render pertama untuk mencegah
        "Flash of Unstyled Content" (FOUC) saat dark mode aktif.
        Tidak bisa dimasukkan ke Vue karena Vue belum mount saat ini.
    -->
    <script>
        (function() {
            var stored = null;
            try {
                // Kunci baru; bila kosong, migrasi dari kunci lama (pra-rebrand).
                stored = localStorage.getItem('minetrace-theme');
                if (stored === null) {
                    var old = localStorage.getItem('minetra-theme');
                    if (old !== null) { stored = old; localStorage.setItem('minetrace-theme', old); }
                }
            } catch (e) {}
            var sysDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored === 'dark' || ((!stored || stored === 'system') && sysDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full antialiased font-sans bg-background text-foreground">
    @inertia
</body>
</html>