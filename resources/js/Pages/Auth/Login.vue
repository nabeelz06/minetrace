<script setup>
import { ref, computed } from 'vue'
import { useForm, Head } from '@inertiajs/vue3'

defineProps({
    status: String,
    canResetPassword: Boolean,
})

const form = useForm({
    email:    '',
    password: '',
    remember: false,
})

const showPassword = ref(false)
const isHovered    = ref(false)

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    })
}

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
    <Head title="Login — MINETRA" />

    <div class="min-h-screen flex overflow-hidden" style="background: #04090f;">

        <!-- ═══════════════════════════════════════════════
             LEFT PANEL — Branding
             ═══════════════════════════════════════════════ -->
        <div class="hidden lg:flex lg:w-[58%] relative overflow-hidden">

            <!-- Animated Background -->
            <div
                class="absolute inset-0 animate-gradient-bg"
                style="background: linear-gradient(135deg,#04090f 0%,#0b1a27 30%,#0d2236 50%,#0b1a27 70%,#04090f 100%); background-size: 400% 400%;"
            />

            <!-- Grid Pattern -->
            <div
                class="absolute inset-0"
                style="background-image: linear-gradient(rgba(62,92,116,0.12) 1px,transparent 1px), linear-gradient(90deg,rgba(62,92,116,0.12) 1px,transparent 1px); background-size: 56px 56px; opacity:0.6;"
            />

            <!-- Floating Crystals -->
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
                <!-- Central glow -->
                <div class="absolute animate-pulse-glow" style="top:50%; left:50%; transform:translate(-50%,-50%); width:420px; height:420px; border-radius:50%; background:radial-gradient(circle, rgba(62,92,116,0.18) 0%, transparent 70%);"/>
            </div>

            <!-- Content -->
            <div class="relative z-10 flex flex-col justify-between w-full p-12 xl:p-16">

                <!-- Logo -->
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

                <!-- Main Content -->
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

                    <!-- Feature pills -->
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

                <!-- Footer -->
                <div class="flex items-center gap-4 animate-fade-in delay-300">
                    <div class="h-px flex-1" style="background: linear-gradient(to right, transparent, rgba(62,92,116,0.6), transparent);"/>
                    <div class="flex items-center gap-3 text-xs font-medium" style="color: #3d6478;">
                        <span>BIM</span>
                        <span style="color: #2a4a5e;">×</span>
                        <span>PT Timah Tbk</span>
                        <!-- <span style="color: #2a4a5e;">×</span>
                        <span>PT Vale</span> -->
                    </div>
                    <div class="h-px flex-1" style="background: linear-gradient(to right, transparent, rgba(62,92,116,0.6), transparent);"/>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════
             RIGHT PANEL — Login Form
             ═══════════════════════════════════════════════ -->
        <div class="w-full lg:w-[42%] flex items-center justify-center relative" style="background: #080f18;">

            <!-- Top accent line -->
            <div class="absolute top-0 left-0 right-0 h-px" style="background: linear-gradient(to right, transparent, rgba(94,161,194,0.5), transparent);"/>

            <!-- Form Container -->
            <div class="w-full max-w-[400px] px-8 py-10 animate-scale-in">

                <!-- Mobile Logo -->
                <div class="flex items-center gap-3 lg:hidden mb-8">
                    <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background: linear-gradient(135deg, #3E5C74, #5ea1c2);">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694 4.125-8.25 4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 2.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125m16.5 5.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" />
                        </svg>
                    </div>
                    <span class="font-bold text-lg tracking-widest text-white">MINETRA</span>
                </div>

                <!-- Header -->
                <div class="space-y-1.5 mb-8">
                    <h2 class="text-2xl font-bold tracking-tight text-white">
                        Selamat Datang
                    </h2>
                    <p class="text-sm" style="color: #4a7d9c;">
                        Masuk menggunakan akun yang telah didaftarkan
                    </p>
                </div>

                <!-- Status -->
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

                <!-- Form -->
                <form @submit.prevent="submit" class="space-y-5">

                    <!-- Email -->
                    <div class="space-y-2">
                        <label for="email" class="block text-sm font-semibold" style="color: #a8ccd8;">
                            Alamat Email
                        </label>
                        <div class="relative">
                            <div class="absolute left-3.5 top-1/2 -translate-y-1/2" style="color: #4a7d9c;">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                </svg>
                            </div>
                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                autocomplete="username"
                                placeholder="nama@perusahaan.co.id"
                                class="w-full pl-10 pr-4 h-11 rounded-xl text-sm font-medium transition-all duration-200 outline-none"
                                :class="form.errors.email
                                    ? 'border-2 border-red-500 focus:border-red-400'
                                    : 'border border-transparent focus:border-[#3E5C74] focus:ring-2 focus:ring-[#3E5C74]/25'"
                                style="background: #0d1e2c; color: #e2edf3; caret-color: #5ea1c2;"
                                required
                            />
                        </div>
                        <Transition enter-active-class="animate-slide-up">
                            <p v-if="form.errors.email" class="text-xs font-medium flex items-center gap-1.5" style="color: #f87171;">
                                <svg class="w-3 h-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                </svg>
                                {{ form.errors.email }}
                            </p>
                        </Transition>
                    </div>

                    <!-- Password -->
                    <div class="space-y-2">
                        <label for="password" class="block text-sm font-semibold" style="color: #a8ccd8;">
                            Password
                        </label>
                        <div class="relative">
                            <div class="absolute left-3.5 top-1/2 -translate-y-1/2" style="color: #4a7d9c;">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                            </div>
                            <input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                autocomplete="current-password"
                                placeholder="••••••••••"
                                class="w-full pl-10 pr-12 h-11 rounded-xl text-sm font-medium transition-all duration-200 outline-none"
                                :class="form.errors.password
                                    ? 'border-2 border-red-500 focus:border-red-400'
                                    : 'border border-transparent focus:border-[#3E5C74] focus:ring-2 focus:ring-[#3E5C74]/25'"
                                style="background: #0d1e2c; color: #e2edf3; caret-color: #5ea1c2; letter-spacing: 0.05em;"
                                required
                            />
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute right-3.5 top-1/2 -translate-y-1/2 transition-all duration-200 hover:scale-110"
                                style="color: #4a7d9c;"
                            >
                                <svg v-if="!showPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                </svg>
                            </button>
                        </div>
                        <Transition enter-active-class="animate-slide-up">
                            <p v-if="form.errors.password" class="text-xs font-medium flex items-center gap-1.5" style="color: #f87171;">
                                <svg class="w-3 h-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                </svg>
                                {{ form.errors.password }}
                            </p>
                        </Transition>
                    </div>

                    <!-- Remember Me -->
                    <div class="flex items-center gap-2.5">
                        <button
                            type="button"
                            @click="form.remember = !form.remember"
                            :class="form.remember ? 'border-[#3E5C74]' : 'border-[#1e3a4a]'"
                            class="w-4 h-4 rounded border-2 flex items-center justify-center transition-all duration-200 flex-shrink-0"
                            :style="form.remember ? 'background: #3E5C74;' : 'background: transparent;'"
                        >
                            <svg v-if="form.remember" class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </button>
                        <span
                            class="text-sm cursor-pointer select-none"
                            style="color: #5a8a9c;"
                            @click="form.remember = !form.remember"
                        >
                            Ingat saya selama 30 hari
                        </span>
                    </div>

                    <!-- Submit Button -->
                    <button
                        type="submit"
                        :disabled="form.processing"
                        @mouseover="isHovered = true"
                        @mouseleave="isHovered = false"
                        class="w-full h-11 rounded-xl font-semibold text-sm text-white flex items-center justify-center gap-2.5 transition-all duration-300 relative overflow-hidden mt-2"
                        :class="form.processing ? 'opacity-70 cursor-not-allowed' : 'hover:shadow-lg hover:shadow-[#3E5C74]/30 active:scale-[0.98]'"
                        style="background: linear-gradient(135deg, #3E5C74 0%, #2a4052 100%);"
                    >
                        <!-- Shimmer overlay -->
                        <div
                            class="absolute inset-0 transition-opacity duration-300"
                            :class="isHovered && !form.processing ? 'opacity-100' : 'opacity-0'"
                            style="background: linear-gradient(90deg, transparent, rgba(255,255,255,0.08), transparent); transform: skewX(-20deg);"
                        />

                        <template v-if="form.processing">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                            </svg>
                            <span>Memverifikasi...</span>
                        </template>
                        <template v-else>
                            <span>Masuk ke Sistem</span>
                            <svg class="w-4 h-4 transition-transform duration-300" :class="isHovered ? 'translate-x-0.5' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                            </svg>
                        </template>
                    </button>
                </form>

                <!-- Footer info -->
                <p class="text-center text-xs mt-6" style="color: #2a4a5e;">
                    Akses terbatas. Hubungi administrator jika mengalami kendala.
                </p>

                <!-- Version -->
                <div class="absolute bottom-4 left-0 right-0 text-center">
                    <span class="text-[10px]" style="color: #152432;">
                        MINETRA v1.0 — Mineral Traceability System
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>