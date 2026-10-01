<script setup>
defineProps({
    heading:    { type: String, required: true },
    subheading: { type: String, required: true },
    status:     { type: String, default: null },
})

const features = [
    { icon: '⛏', label: 'Traceability Real-time' },
    { icon: '🔬', label: 'Lab Analysis LTJ' },
    { icon: '📦', label: 'Warehouse Management' },
    { icon: '📊', label: 'BIM Dashboard' },
    { icon: '📄', label: 'Laporan Otomatis' },
    { icon: '🔒', label: 'Audit Trail' },
]
</script>

<template>
    <div class="min-h-screen flex overflow-hidden" style="background: #04090f;">

        <!-- ═══════════════════════════════════════════════
             LEFT PANEL — Branding (dipakai bersama semua halaman auth)
             ═══════════════════════════════════════════════ -->
        <div class="hidden lg:flex lg:w-[58%] relative overflow-hidden">

            <div
                class="absolute inset-0 animate-gradient-bg"
                style="background: linear-gradient(135deg,#04090f 0%,#0b1a27 30%,#0d2236 50%,#0b1a27 70%,#04090f 100%); background-size: 400% 400%;"
            />

            <div
                class="absolute inset-0"
                style="background-image: linear-gradient(rgba(62,92,116,0.12) 1px,transparent 1px), linear-gradient(90deg,rgba(62,92,116,0.12) 1px,transparent 1px); background-size: 56px 56px; opacity:0.6;"
            />

            <div class="absolute inset-0 overflow-hidden pointer-events-none">
                <div class="absolute animate-crystal-float" style="--dur:9s; top:6%; right:14%; width:110px; height:110px;">
                    <svg viewBox="0 0 100 100" fill="none">
                        <polygon points="50,5 95,27 95,73 50,95 5,73 5,27" fill="rgba(62,92,116,0.12)" stroke="#5ea1c2" stroke-width="1.5"/>
                        <polygon points="50,22 80,37 80,63 50,78 20,63 20,37" fill="rgba(94,161,194,0.06)" stroke="#5ea1c2" stroke-width="0.8"/>
                        <line x1="50" y1="5" x2="50" y2="95" stroke="#3E5C74" stroke-width="0.5" opacity="0.5"/>
                        <line x1="5" y1="27" x2="95" y2="73" stroke="#3E5C74" stroke-width="0.5" opacity="0.5"/>
                        <line x1="5" y1="73" x2="95" y2="27" stroke="#3E5C74" stroke-width="0.5" opacity="0.5"/>
                    </svg>
                </div>
                <div class="absolute animate-crystal-drift" style="--dur:14s; bottom:18%; left:6%; width:75px; height:75px;">
                    <svg viewBox="0 0 100 100" fill="none">
                        <polygon points="50,0 100,50 50,100 0,50" fill="rgba(62,92,116,0.1)" stroke="#3E5C74" stroke-width="1.5"/>
                        <polygon points="50,22 78,50 50,78 22,50" fill="none" stroke="#5ea1c2" stroke-width="1"/>
                    </svg>
                </div>
                <div class="absolute animate-crystal-float" style="--dur:11s; top:42%; left:18%; width:44px; height:44px; animation-delay:-4s;">
                    <svg viewBox="0 0 100 100" fill="none">
                        <polygon points="50,5 95,27 95,73 50,95 5,73 5,27" fill="none" stroke="#62a8cc" stroke-width="2.5"/>
                    </svg>
                </div>
                <div class="absolute animate-crystal-drift" style="--dur:17s; top:22%; left:38%; width:32px; height:32px; animation-delay:-7s;">
                    <svg viewBox="0 0 100 100" fill="none">
                        <polygon points="50,0 100,50 50,100 0,50" fill="none" stroke="#5ea1c2" stroke-width="3"/>
                    </svg>
                </div>
                <div class="absolute animate-crystal-float" style="--dur:8s; bottom:8%; right:22%; width:58px; height:58px; animation-delay:-2s;">
                    <svg viewBox="0 0 100 100" fill="none">
                        <polygon points="50,5 95,27 95,73 50,95 5,73 5,27" fill="rgba(62,92,116,0.14)" stroke="#3E5C74" stroke-width="1.5"/>
                        <circle cx="50" cy="50" r="14" fill="none" stroke="#5ea1c2" stroke-width="1" opacity="0.5"/>
                    </svg>
                </div>
                <div class="absolute animate-pulse-glow" style="top:50%; left:50%; transform:translate(-50%,-50%); width:420px; height:420px; border-radius:50%; background:radial-gradient(circle, rgba(62,92,116,0.18) 0%, transparent 70%);"/>
            </div>

            <div class="relative z-10 flex flex-col justify-between w-full p-12 xl:p-16">

                <div class="flex items-center gap-3 animate-fade-in">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shadow-lg" style="background: linear-gradient(135deg, #3E5C74, #5ea1c2);">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694 4.125-8.25 4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 2.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125m16.5 5.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-white font-bold text-xl tracking-[0.2em]">MINETRA</p>
                        <p class="text-[11px] tracking-widest uppercase" style="color: #4a7d9c;">Mineral Traceability</p>
                    </div>
                </div>

                <div class="space-y-8 animate-fade-in delay-150">
                    <div class="space-y-4">
                        <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border" style="border-color: rgba(62,92,116,0.5); background: rgba(62,92,116,0.12);">
                            <div class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"/>
                            <span class="text-xs font-semibold tracking-[0.15em] uppercase" style="color: #7ab5cc;">Sistem Aktif</span>
                        </div>

                        <h1 class="text-4xl xl:text-5xl font-bold leading-[1.1]">
                            <span class="text-white">Mineral</span><br>
                            <span style="background: linear-gradient(90deg, #5ea1c2, #8ec5d8, #5fd4e8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;">
                                Traceability
                            </span><br>
                            <span class="text-white">System</span>
                        </h1>

                        <p class="text-base leading-relaxed max-w-xs" style="color: #6a9ab0;">
                            Platform monitoring terpusat untuk ketertelusuran
                            Logam Tanah Jarang dari hulu ke hilir.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <div
                            v-for="f in features"
                            :key="f.label"
                            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-all duration-300 hover:scale-105 cursor-default"
                            style="background: rgba(13,34,54,0.8); border: 1px solid rgba(62,92,116,0.4); color: #8ab5c8;"
                        >
                            <span style="font-size:13px;">{{ f.icon }}</span>
                            {{ f.label }}
                        </div>
                    </div>
                </div>

                <div class="flex items-center gap-4 animate-fade-in delay-300">
                    <div class="h-px flex-1" style="background: linear-gradient(to right, transparent, rgba(62,92,116,0.6), transparent);"/>
                    <div class="flex items-center gap-3 text-xs font-medium" style="color: #3d6478;">
                        <span>BIM</span>
                        <span style="color: #2a4a5e;">×</span>
                        <span>PT Timah Tbk</span>
                    </div>
                    <div class="h-px flex-1" style="background: linear-gradient(to right, transparent, rgba(62,92,116,0.6), transparent);"/>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════
             RIGHT PANEL — Konten spesifik per halaman (slot)
             ═══════════════════════════════════════════════ -->
        <div class="w-full lg:w-[42%] flex items-center justify-center relative" style="background: #080f18;">

            <div class="absolute top-0 left-0 right-0 h-px" style="background: linear-gradient(to right, transparent, rgba(94,161,194,0.5), transparent);"/>

            <div class="w-full max-w-[400px] px-8 py-10 animate-scale-in">

                <div class="flex items-center gap-3 lg:hidden mb-8">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background: linear-gradient(135deg, #3E5C74, #5ea1c2);">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694 4.125-8.25 4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 2.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125m16.5 5.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                        </svg>
                    </div>
                    <span class="font-bold text-lg tracking-widest text-white">MINETRA</span>
                </div>

                <div class="space-y-1.5 mb-8">
                    <h2 class="text-2xl font-bold tracking-tight text-white">{{ heading }}</h2>
                    <p class="text-sm" style="color: #4a7d9c;">{{ subheading }}</p>
                </div>

                <Transition enter-active-class="animate-fade-in" leave-active-class="animate-fade-in-fast">
                    <div
                        v-if="status"
                        class="flex items-center gap-2.5 px-4 py-3 rounded-xl mb-5 border"
                        style="background: rgba(16,185,129,0.1); border-color: rgba(16,185,129,0.3); color: #6ee7b7;"
                    >
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span class="text-sm font-medium">{{ status }}</span>
                    </div>
                </Transition>

                <slot />

                <p class="text-center text-xs mt-6" style="color: #2a4a5e;">
                    Akses terbatas. Hubungi administrator jika mengalami kendala.
                </p>

                <div class="absolute bottom-4 left-0 right-0 text-center">
                    <span class="text-[10px]" style="color: #152432;">
                        MINETRA v1.0 — Mineral Traceability System
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
