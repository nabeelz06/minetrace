<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

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
            var stored = localStorage.getItem('minetra-theme');
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