<script setup>
import { ref, onMounted, onUnmounted, computed, nextTick } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Hls from 'hls.js'

const props = defineProps({
    cameras:      { type: Array,  default: () => [] },
    hls_base_url: { type: String, default: '' },
    has_cctv:     { type: Boolean, default: false },
})

// ─── State ────────────────────────────────────────────────────────────────────
const activeCam     = ref(props.cameras[0] ?? null)
const videoEl       = ref(null)
const hlsInstance   = ref(null)
const streamStatus  = ref('idle')   // idle | loading | playing | error
const streamError   = ref('')
const isFullscreen  = ref(false)
const showGrid      = ref(props.cameras.length > 1)
const currentTime   = ref('')

// ─── Clock ────────────────────────────────────────────────────────────────────
let clockTimer = null
onMounted(() => {
    clockTimer = setInterval(() => {
        currentTime.value = new Date().toLocaleTimeString('id-ID', {
            hour: '2-digit', minute: '2-digit', second: '2-digit',
        })
    }, 1000)
    if (activeCam.value) nextTick(() => initStream(activeCam.value))
})
onUnmounted(() => {
    clearInterval(clockTimer)
    destroyHls()
})

// ─── HLS stream ───────────────────────────────────────────────────────────────
function destroyHls() {
    if (hlsInstance.value) {
        hlsInstance.value.destroy()
        hlsInstance.value = null
    }
}

function initStream(camera) {
    if (!camera?.hls_url) {
        streamStatus.value = 'error'
        streamError.value  = 'URL stream tidak tersedia.'
        return
    }

    destroyHls()
    streamStatus.value = 'loading'
    streamError.value  = ''

    const video = videoEl.value
    if (!video) return

    if (Hls.isSupported()) {
        const hls = new Hls({
            enableWorker:       true,
            lowLatencyMode:     true,
            backBufferLength:   10,
        })
        hlsInstance.value = hls
        hls.loadSource(camera.hls_url)
        hls.attachMedia(video)

        hls.on(Hls.Events.MANIFEST_PARSED, () => {
            video.play().catch(() => {})
            streamStatus.value = 'playing'
        })

        hls.on(Hls.Events.ERROR, (_, data) => {
            if (data.fatal) {
                streamStatus.value = 'error'
                streamError.value  = 'Stream terputus. Mencoba ulang...'
                setTimeout(() => initStream(camera), 5000)
            }
        })
    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
        // Safari native HLS
        video.src = camera.hls_url
        video.addEventListener('loadedmetadata', () => {
            video.play().catch(() => {})
            streamStatus.value = 'playing'
        })
        video.addEventListener('error', () => {
            streamStatus.value = 'error'
            streamError.value  = 'Gagal memuat stream.'
        })
    } else {
        streamStatus.value = 'error'
        streamError.value  = 'Browser tidak mendukung HLS. Gunakan Chrome/Firefox.'
    }
}

function selectCamera(cam) {
    activeCam.value = cam
    nextTick(() => initStream(cam))
}

function retryStream() {
    if (activeCam.value) initStream(activeCam.value)
}

// ─── Fullscreen ───────────────────────────────────────────────────────────────
async function toggleFullscreen() {
    const el = videoEl.value
    if (!el) return
    if (!document.fullscreenElement) {
        await el.requestFullscreen()
        isFullscreen.value = true
    } else {
        await document.exitFullscreen()
        isFullscreen.value = false
    }
}

// ─── Status label helpers ─────────────────────────────────────────────────────
const statusDot = computed(() => ({
    idle:    'bg-gray-500',
    loading: 'bg-amber-400 animate-pulse',
    playing: 'bg-emerald-400 animate-pulse',
    error:   'bg-red-500',
}[streamStatus.value]))

const statusLabel = computed(() => ({
    idle:    'Standby',
    loading: 'Menghubungkan...',
    playing: 'LIVE',
    error:   'Offline',
}[streamStatus.value]))
</script>

<template>
    <AppLayout>
        <Head title="CCTV Monitor" />
        <template #header>CCTV Monitor</template>

        <!-- ─── Dark Command Center Shell ──────────────────────────────────── -->
        <div class="min-h-screen bg-gray-950 text-gray-100 rounded-2xl overflow-hidden border border-gray-800">

            <!-- ─── Top Bar ──────────────────────────────────────────────── -->
            <div class="flex items-center justify-between px-5 py-3 bg-gray-900 border-b border-gray-800">
                <div class="flex items-center gap-3">
                    <!-- Camera Icon -->
                    <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-sky-600/20 border border-sky-500/30">
                        <svg class="w-4 h-4 text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-white tracking-wide">CCTV Monitor</p>
                        <p class="text-xs text-gray-500">TP-Link Tapo C500 · MediaMTX HLS</p>
                    </div>
                </div>

                <div class="flex items-center gap-4">
                    <!-- Live clock -->
                    <div class="hidden sm:flex items-center gap-1.5 text-xs font-mono text-gray-400">
                        <div class="w-1.5 h-1.5 rounded-full bg-sky-400 animate-pulse" />
                        {{ currentTime }}
                    </div>

                    <!-- Grid toggle -->
                    <button v-if="cameras.length > 1"
                        @click="showGrid = !showGrid"
                        :class="showGrid ? 'text-sky-400 border-sky-500/50' : 'text-gray-500 border-gray-700'"
                        class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg border text-xs font-medium transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                        </svg>
                        Grid
                    </button>
                </div>
            </div>

            <!-- ─── No CCTV configured ───────────────────────────────────── -->
            <div v-if="!has_cctv" class="flex flex-col items-center justify-center py-24 gap-4">
                <div class="w-16 h-16 rounded-2xl bg-gray-800 border border-gray-700 flex items-center justify-center">
                    <svg class="w-8 h-8 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M12 18.75H4.5a2.25 2.25 0 01-2.25-2.25V9m12.841 9.091L16.5 19.5m-1.409-1.409c.407-.407.659-.97.659-1.591v-9a2.25 2.25 0 00-2.25-2.25h-9c-.621 0-1.184.252-1.591.659m12.181 12.181L2.909 5.909M1.5 4.5l1.409 1.409" />
                    </svg>
                </div>
                <p class="text-sm text-gray-500 text-center">
                    CCTV belum dikonfigurasi.<br>
                    Set <code class="text-sky-400 font-mono text-xs">CCTV_HLS_BASE_URL</code> di file <code class="text-sky-400 font-mono text-xs">.env</code>.
                </p>
            </div>

            <!-- ─── Main Content ─────────────────────────────────────────── -->
            <div v-else class="flex gap-0">

                <!-- ─── Camera Sidebar ─────────────────────────────────── -->
                <div v-if="cameras.length > 1"
                    class="w-44 flex-shrink-0 border-r border-gray-800 bg-gray-900/50">
                    <p class="px-3 py-2 text-xs font-semibold text-gray-500 uppercase tracking-widest">
                        Kamera
                    </p>
                    <div class="space-y-0.5 px-2 pb-4">
                        <button
                            v-for="cam in cameras"
                            :key="cam.id ?? cam.name"
                            @click="selectCamera(cam)"
                            :class="activeCam?.id === cam.id
                                ? 'bg-sky-600/20 border-sky-500/50 text-white'
                                : 'border-transparent text-gray-400 hover:bg-gray-800 hover:text-gray-200'"
                            class="w-full text-left px-3 py-2.5 rounded-lg border text-xs font-medium transition-all duration-150"
                        >
                            <div class="flex items-center gap-2">
                                <div class="w-1.5 h-1.5 rounded-full flex-shrink-0"
                                    :class="activeCam?.id === cam.id ? 'bg-emerald-400 animate-pulse' : 'bg-gray-600'" />
                                <span class="truncate">{{ cam.name }}</span>
                            </div>
                            <p v-if="cam.location_key" class="mt-0.5 pl-3.5 text-gray-600 text-xs truncate">
                                {{ cam.location_key.replace(/_/g, ' ') }}
                            </p>
                        </button>
                    </div>
                </div>

                <!-- ─── Video Grid / Single View ───────────────────────── -->
                <div class="flex-1 p-4">

                    <!-- Single focused stream -->
                    <div class="space-y-3">
                        <!-- Camera label + status -->
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div :class="statusDot" class="w-2 h-2 rounded-full" />
                                <span class="text-sm font-semibold text-white">
                                    {{ activeCam?.name ?? 'CCTV Utama' }}
                                </span>
                                <span class="text-xs px-2 py-0.5 rounded-full font-mono font-bold tracking-wider"
                                    :class="{
                                        'bg-emerald-500/20 text-emerald-400 border border-emerald-500/30': streamStatus === 'playing',
                                        'bg-amber-500/20 text-amber-400 border border-amber-500/30':   streamStatus === 'loading',
                                        'bg-red-500/20 text-red-400 border border-red-500/30':         streamStatus === 'error',
                                        'bg-gray-700 text-gray-500 border border-gray-700':            streamStatus === 'idle',
                                    }">
                                    {{ statusLabel }}
                                </span>
                            </div>
                            <!-- Fullscreen button -->
                            <button @click="toggleFullscreen"
                                class="flex items-center gap-1 px-2.5 py-1 rounded-lg border border-gray-700 text-gray-400 hover:text-white hover:border-gray-500 text-xs transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />
                                </svg>
                                Fullscreen
                            </button>
                        </div>

                        <!-- Video element -->
                        <div class="relative rounded-xl overflow-hidden bg-black border border-gray-800 aspect-video">
                            <video
                                ref="videoEl"
                                class="w-full h-full object-contain"
                                controls
                                muted
                                autoplay
                                playsinline
                            />

                            <!-- Loading overlay -->
                            <div v-if="streamStatus === 'loading'"
                                class="absolute inset-0 flex flex-col items-center justify-center bg-gray-950/80 gap-3">
                                <div class="w-10 h-10 rounded-full border-2 border-sky-500 border-t-transparent animate-spin" />
                                <p class="text-xs text-gray-400">Menghubungkan ke stream...</p>
                            </div>

                            <!-- Error overlay -->
                            <div v-if="streamStatus === 'error'"
                                class="absolute inset-0 flex flex-col items-center justify-center bg-gray-950/90 gap-4">
                                <div class="w-12 h-12 rounded-full bg-red-500/20 border border-red-500/30 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                    </svg>
                                </div>
                                <div class="text-center">
                                    <p class="text-sm font-semibold text-red-400">Stream Tidak Tersedia</p>
                                    <p class="text-xs text-gray-500 mt-1">{{ streamError || 'Periksa koneksi mediamtx.' }}</p>
                                </div>
                                <button @click="retryStream"
                                    class="px-4 py-1.5 rounded-lg bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold transition-colors">
                                    Coba Lagi
                                </button>
                            </div>

                            <!-- Idle overlay -->
                            <div v-if="streamStatus === 'idle'"
                                class="absolute inset-0 flex flex-col items-center justify-center bg-gray-950 gap-4">
                                <div class="w-12 h-12 rounded-full bg-gray-800 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" />
                                    </svg>
                                </div>
                                <p class="text-xs text-gray-500">Pilih kamera untuk memulai stream</p>
                            </div>

                            <!-- LIVE watermark -->
                            <div v-if="streamStatus === 'playing'"
                                class="absolute top-3 left-3 flex items-center gap-1.5 px-2 py-1 rounded-md bg-black/50 backdrop-blur-sm">
                                <div class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse" />
                                <span class="text-xs font-bold text-white tracking-widest">LIVE</span>
                            </div>

                            <!-- Scan/snapshot indicator overlay (future) -->
                        </div>

                        <!-- Stream info bar -->
                        <div v-if="activeCam?.hls_url" class="flex items-center gap-2 text-xs text-gray-600 font-mono">
                            <svg class="w-3 h-3 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                            </svg>
                            <span class="truncate max-w-xs">{{ activeCam.hls_url }}</span>
                        </div>

                    </div>

                    <!-- Grid view (all cameras, small) -->
                    <div v-if="showGrid && cameras.length > 1" class="mt-6">
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-widest mb-3">
                            Semua Kamera
                        </p>
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                            <button
                                v-for="cam in cameras"
                                :key="cam.id ?? cam.name"
                                @click="selectCamera(cam)"
                                :class="activeCam?.id === cam.id
                                    ? 'border-sky-500/60 bg-sky-600/10'
                                    : 'border-gray-800 bg-gray-900 hover:border-gray-700'"
                                class="relative rounded-lg border overflow-hidden aspect-video transition-all duration-150 group"
                            >
                                <div class="absolute inset-0 flex flex-col items-center justify-center gap-1">
                                    <svg class="w-5 h-5 text-gray-700 group-hover:text-gray-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" />
                                    </svg>
                                    <span class="text-xs text-gray-600 font-medium truncate px-2">{{ cam.name }}</span>
                                </div>
                                <div v-if="activeCam?.id === cam.id"
                                    class="absolute top-1 right-1 flex items-center gap-1 px-1.5 py-0.5 rounded bg-black/60">
                                    <div class="w-1 h-1 rounded-full bg-emerald-400 animate-pulse" />
                                    <span class="text-xs text-emerald-400 font-bold">LIVE</span>
                                </div>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Footer ───────────────────────────────────────────────── -->
            <div class="px-5 py-2.5 bg-gray-900/70 border-t border-gray-800 flex items-center justify-between text-xs text-gray-600">
                <span>TP-Link Tapo C500 · MediaMTX HLS · Snapshot via ffmpeg</span>
                <span class="font-mono">{{ cameras.length }} kamera terdaftar</span>
            </div>
        </div>
    </AppLayout>
</template>
