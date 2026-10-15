<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#f4f5f7" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#14181f" media="(prefers-color-scheme: dark)">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <title inertia>{{ config('app.name', 'MINETRACE') }}</title>

    <!-- ─── Preconnect Google Fonts ─────────────────────────────────── -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!--
        Font stack:
        - Inter      : UI teks, angka, label (variable font, sangat sharp)
        - Plus Jakarta Sans : Heading/judul (modern, premium feel)
        Fallback: system-ui → sans-serif
    -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,300..800;1,14..32,300..700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

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
            if (stored === 'dark' || (!stored && sysDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    @routes
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="h-full antialiased font-sans bg-background text-foreground">
    @inertia
</body>
</html>