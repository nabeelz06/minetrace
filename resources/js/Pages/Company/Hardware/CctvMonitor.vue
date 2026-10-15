<script setup>
import { ref, onMounted, onUnmounted, computed, nextTick } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import Hls from 'hls.js'

const props = defineProps({
    cameras:            { type: Array,   default: () => [] },
    hls_base_url:       { type: String,  default: '' },
    has_cctv:           { type: Boolean, default: false },
    simulation:         { type: Boolean, default: false },
    can_manage_cameras: { type: Boolean, default: false },
})

// ─── State ────────────────────────────────────────────────────────────────────
const activeCam    = ref(props.cameras[0] ?? null)
const videoEl      = ref(null)
const hlsInstance  = ref(null)
const streamStatus = ref('idle')   // idle | loading | playing | error
const streamError  = ref('')
const isFullscreen = ref(false)
const showGrid     = ref(props.cameras.length > 1)
const currentTime  = ref('')

// ─── Recent Snapshots (RFID-CCTV synergy) ────────────────────────────────────
const recentSnapshots = ref([])
const snapshotToast   = ref(null)   // { message } saat RFID snapshot diambil
const lightboxPhoto   = ref(null)   // URL foto fullscreen

// ─── Clock ────────────────────────────────────────────────────────────────────
let clockTimer     = null
let snapshotTimer  = null

onMounted(() => {
    clockTimer = setInterval(() => {
        currentTime.value = new Date().toLocaleTimeString('id-ID', {
            hour: '2-digit', minute: '2-digit', second: '2-digit',
        })
    }, 1000)

    if (activeCam.value) nextTick(() => initStream(activeCam.value))

    fetchRecentSnapshots()
    snapshotTimer = setInterval(fetchRecentSnapshots, 10_000)
})

onUnmounted(() => {
    clearInterval(clockTimer)
    clearInterval(snapshotTimer)
    destroyHls()
})

// ─── Recent Snapshots API ─────────────────────────────────────────────────────
async function fetchRecentSnapshots() {
    try {
        const res  = await fetch('/api/v1/company/cctv/recent-snapshots', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        const json = await res.json()
        if (json.success && Array.isArray(json.data)) {
            const prevCount = recentSnapshots.value.length
            recentSnapshots.value = json.data

            // Tunjukkan toast jika ada snapshot baru sejak polling terakhir
            if (prevCount > 0 && json.data.length > prevCount) {
                showSnapshotToast('Snapshot CCTV baru dari RFID scan')
            }
        }
    } catch (_) {
        // Gagal silent — mediamtx mungkin belum ready
    }
}

function showSnapshotToast(message) {
    snapshotToast.value = { message }
    setTimeout(() => { snapshotToast.value = null }, 4000)
}

function openLightbox(url) {
    if (url) lightboxPhoto.value = url
}

function closeLightbox() {
    lightboxPhoto.value = null
}

// ─── Scan type helpers ────────────────────────────────────────────────────────
function scanTypeLabel(type) {
    if (type === 'scan_out' || type === 'departure_snapshot') return 'SCAN OUT'
    if (type === 'manual_trigger') return 'MANUAL'
    return 'SCAN IN'
}

function scanTypeColor(type) {
    if (type === 'scan_out' || type === 'departure_snapshot')
        return 'text-warning border-warning/30 bg-warning-soft'
    if (type === 'manual_trigger')
        return 'text-info border-info/30 bg-info-soft'
    return 'text-success border-success/30 bg-success-soft'
}

function snapshotStatusLabel(status) {
    if (status === 'captured') return 'Captured'
    if (status === 'pending')  return '⏳ Pending'
    if (status === 'failed')   return 'Failed'
    return null
}

function snapshotStatusColor(status) {
    if (status === 'captured') return 'text-success border-success/30 bg-success-soft'
    if (status === 'pending')  return 'text-warning border-warning/30 bg-warning-soft'
    if (status === 'failed')   return 'text-danger border-danger/30 bg-danger-soft'
    return ''
}

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
            enableWorker:     true,
            // lowLatencyMode dihapus: mediamtx dikonfigurasi dengan standard HLS
            // (tanpa hlsPartDuration). LL-HLS menyebabkan muxer gagal initialize
            // untuk stream RTSP/H.264 biasa.
            backBufferLength: 10,
            // withCredentials diperlukan agar browser mengirim cookie sesi mediamtx
            // (Set-Cookie: cookieCheck=1) saat redirect ke ?cookieCheck=1.
            // mediamtx v1.9+ menggunakan cookie-based reader tracking; tanpa cookie
            // ini, request manifest akan menghasilkan 500 "muxer instance not available".
            xhrSetup: (xhr) => {
                xhr.withCredentials = true
            },
            // Retry saat muxer mediamtx belum siap (biasa terjadi koneksi pertama).
            manifestLoadingMaxRetry:      6,
            manifestLoadingRetryDelay:    1000,
            manifestLoadingMaxRetryTimeout: 8000,
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
    idle:    'bg-muted-foreground',
    loading: 'bg-warning animate-pulse',
    playing: 'bg-success animate-pulse',
    error:   'bg-danger',
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

        <!-- ─── Lightbox Modal ─────────────────────────────────────────────── -->
        <Transition name="fade">
            <div v-if="lightboxPhoto"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/90"
                @click.self="closeLightbox">
                <div class="relative max-w-5xl max-h-[90vh] w-full mx-4">
                    <button @click="closeLightbox"
                        class="absolute -top-10 right-0 text-white/70 hover:text-white text-sm font-medium">
                        Tutup
                    </button>
                    <img :src="lightboxPhoto" class="w-full h-auto rounded-xl object-contain max-h-[85vh]" alt="CCTV Snapshot" />
                </div>
            </div>
        </Transition>

        <!-- ─── RFID Snapshot Toast ────────────────────────────────────────── -->
        <Transition name="toast">
            <div v-if="snapshotToast"
                class="fixed bottom-6 right-6 z-40 flex items-center gap-3 px-4 py-3 rounded-2xl border border-info/30 bg-muted-foreground shadow-2xl">
                <span class="text-info text-lg"></span>
                <p class="text-sm font-semibold text-white">{{ snapshotToast.message }}</p>
            </div>
        </Transition>

        <!-- ─── Dark Command Center Shell ──────────────────────────────────── -->
        <div class="min-h-screen bg-muted-foreground text-muted-foreground rounded-2xl overflow-hidden border border-border">

            <!-- ─── Top Bar ──────────────────────────────────────────────── -->
            <div class="flex items-center justify-between px-5 py-3 bg-muted-foreground border-b border-border">
                <div class="flex items-center gap-3">
                    <div class="flex items-center justify-center w-8 h-8 rounded-lg bg-info-soft border border-info/30">
                        <svg class="w-4 h-4 text-info" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-white tracking-wide">CCTV Monitor</p>
                        <p class="text-xs text-muted-foreground">TP-Link Tapo C500 · MediaMTX HLS · RFID Synergy</p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <!-- Simulation mode badge -->
                    <div v-if="simulation"
                        class="hidden sm:flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-info/30 bg-info-soft">
                        <span class="text-xs"></span>
                        <span class="text-xs font-bold text-info tracking-wide">SIMULATION</span>
                    </div>

                    <!-- Live clock -->
                    <div class="hidden sm:flex items-center gap-1.5 text-xs font-mono text-muted-foreground">
                        <div class="w-1.5 h-1.5 rounded-full bg-info animate-pulse" />
                        {{ currentTime }}
                    </div>

                    <!-- Grid toggle -->
                    <button v-if="cameras.length > 1"
                        @click="showGrid = !showGrid"
                        :class="showGrid ? 'text-info border-info/30' : 'text-muted-foreground border-border'"
                        class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg border text-xs font-medium transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                        </svg>
                        Grid
                    </button>

                    <!-- Pengaturan kamera — company_admin only -->
                    <Link v-if="can_manage_cameras"
                        href="/company/hardware/cctv/settings"
                        class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-border text-muted-foreground hover:text-white hover:border-border text-xs font-medium transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        Pengaturan
                    </Link>
                </div>
            </div>

            <!-- ─── No CCTV configured ───────────────────────────────────── -->
            <div v-if="!has_cctv" class="flex flex-col items-center justify-center py-24 gap-4">
                <div class="w-16 h-16 rounded-2xl bg-muted-foreground border border-border flex items-center justify-center">
                    <svg class="w-8 h-8 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M12 18.75H4.5a2.25 2.25 0 01-2.25-2.25V9m12.841 9.091L16.5 19.5m-1.409-1.409c.407-.407.659-.97.659-1.591v-9a2.25 2.25 0 00-2.25-2.25h-9c-.621 0-1.184.252-1.591.659m12.181 12.181L2.909 5.909M1.5 4.5l1.409 1.409" />
                    </svg>
                </div>
                <p class="text-sm text-muted-foreground text-center">
                    CCTV belum dikonfigurasi.<br>
                    Set <code class="text-info font-mono text-xs">CCTV_HLS_BASE_URL</code> di file <code class="text-info font-mono text-xs">.env</code>.
                </p>
            </div>

            <!-- ─── Main Content ─────────────────────────────────────────── -->
            <div v-else class="flex flex-col lg:flex-row gap-0">

                <!-- ─── Camera Sidebar ─────────────────────────────────── -->
                <div v-if="cameras.length > 1"
                    class="w-full lg:w-44 flex-shrink-0 border-b lg:border-b-0 lg:border-r border-border bg-muted-foreground">
                    <p class="px-3 py-2 text-xs font-semibold text-muted-foreground uppercase tracking-widest">
                        Kamera
                    </p>
                    <div class="flex lg:flex-col gap-1 px-2 pb-3 overflow-x-auto lg:overflow-x-visible">
                        <button
                            v-for="cam in cameras"
                            :key="cam.id ?? cam.name"
                            @click="selectCamera(cam)"
                            :class="activeCam?.id === cam.id ? 'bg-info-soft border-info/30 text-white' : 'border-transparent text-muted-foreground hover:bg-muted-foreground hover:text-muted-foreground'"
                            class="flex-shrink-0 lg:w-full text-left px-3 py-2.5 rounded-lg border text-xs font-medium transition-all duration-150"
                        >
                            <div class="flex items-center gap-2">
                                <div class="w-1.5 h-1.5 rounded-full flex-shrink-0"
                                    :class="activeCam?.id === cam.id ? 'bg-success animate-pulse' : 'bg-muted-foreground'" />
                                <span class="truncate">{{ cam.name }}</span>
                            </div>
                            <p v-if="cam.location_key" class="mt-0.5 pl-3.5 text-muted-foreground text-xs truncate hidden lg:block">
                                {{ cam.location_key.replace(/_/g, ' ') }}
                            </p>
                        </button>
                    </div>
                </div>

                <!-- ─── Video + Snapshots ──────────────────────────────── -->
                <div class="flex-1 flex flex-col lg:flex-row min-w-0">

                    <!-- ─── Video Area ─────────────────────────────────── -->
                    <div class="flex-1 p-4">
                        <div class="space-y-3">
                            <!-- Camera label + status -->
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div :class="statusDot" class="w-2 h-2 rounded-full" />
                                    <span class="text-sm font-semibold text-white">
                                        {{ activeCam?.name ?? 'CCTV Utama' }}
                                    </span>
                                    <span class="text-xs px-2 py-0.5 rounded-full font-mono font-bold tracking-wider"
                                        :class="{ 'bg-success-soft text-success border border-success/30': streamStatus === 'playing', 'bg-warning-soft text-warning border border-warning/30': streamStatus === 'loading', 'bg-danger-soft text-danger border border-danger/30': streamStatus === 'error', 'bg-muted-foreground text-muted-foreground border border-border': streamStatus === 'idle', }">
                                        {{ statusLabel }}
                                    </span>
                                </div>
                                <button @click="toggleFullscreen"
                                    class="flex items-center gap-1 px-2.5 py-1 rounded-lg border border-border text-muted-foreground hover:text-white hover:border-border text-xs transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />
                                    </svg>
                                    Fullscreen
                                </button>
                            </div>

                            <!-- Video element -->
                            <div class="relative rounded-xl overflow-hidden bg-black border border-border aspect-video">
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
                                    class="absolute inset-0 flex flex-col items-center justify-center bg-muted-foreground gap-3">
                                    <div class="w-10 h-10 rounded-full border-2 border-info/30 border-t-transparent animate-spin" />
                                    <p class="text-xs text-muted-foreground">Menghubungkan ke stream...</p>
                                </div>

                                <!-- Error overlay -->
                                <div v-if="streamStatus === 'error'"
                                    class="absolute inset-0 flex flex-col items-center justify-center bg-muted-foreground gap-4">
                                    <div class="w-12 h-12 rounded-full bg-danger-soft border border-danger/30 flex items-center justify-center">
                                        <svg class="w-6 h-6 text-danger" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                        </svg>
                                    </div>
                                    <div class="text-center">
                                        <p class="text-sm font-semibold text-danger">Stream Tidak Tersedia</p>
                                        <p class="text-xs text-muted-foreground mt-1">{{ streamError || 'Periksa koneksi mediamtx.' }}</p>
                                    </div>
                                    <button @click="retryStream"
                                        class="px-4 py-1.5 rounded-lg bg-info hover:bg-info text-background text-xs font-semibold transition-colors">
                                        Coba Lagi
                                    </button>
                                </div>

                                <!-- Idle overlay -->
                                <div v-if="streamStatus === 'idle'"
                                    class="absolute inset-0 flex flex-col items-center justify-center bg-muted-foreground gap-4">
                                    <div class="w-12 h-12 rounded-full bg-muted-foreground flex items-center justify-center">
                                        <svg class="w-6 h-6 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" />
                                        </svg>
                                    </div>
                                    <p class="text-xs text-muted-foreground">Pilih kamera untuk memulai stream</p>
                                </div>

                                <!-- LIVE watermark -->
                                <div v-if="streamStatus === 'playing'"
                                    class="absolute top-3 left-3 flex items-center gap-1.5 px-2 py-1 rounded-md bg-black/50 backdrop-blur-sm">
                                    <div class="w-1.5 h-1.5 rounded-full bg-danger animate-pulse" />
                                    <span class="text-xs font-bold text-white tracking-widest">LIVE</span>
                                </div>

                                <!-- Simulation mode watermark -->
                                <div v-if="simulation && streamStatus === 'playing'"
                                    class="absolute bottom-3 right-3 flex items-center gap-1 px-2 py-0.5 rounded bg-info backdrop-blur-sm">
                                    <span class="text-xs"></span>
                                    <span class="text-xs font-bold text-white">SIM</span>
                                </div>
                            </div>

                            <!-- Stream info bar -->
                            <div v-if="activeCam?.hls_url" class="flex items-center gap-2 text-xs text-muted-foreground font-mono">
                                <svg class="w-3 h-3 text-info" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" />
                                </svg>
                                <span class="truncate max-w-xs">{{ activeCam.hls_url }}</span>
                            </div>
                        </div>

                        <!-- Grid view (all cameras, small) -->
                        <div v-if="showGrid && cameras.length > 1" class="mt-6">
                            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-widest mb-3">
                                Semua Kamera
                            </p>
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                                <button
                                    v-for="cam in cameras"
                                    :key="cam.id ?? cam.name"
                                    @click="selectCamera(cam)"
                                    :class="activeCam?.id === cam.id ? 'border-info/30 bg-info-soft' : 'border-border bg-muted-foreground hover:border-border'"
                                    class="relative rounded-lg border overflow-hidden aspect-video transition-all duration-150 group"
                                >
                                    <div class="absolute inset-0 flex flex-col items-center justify-center gap-1">
                                        <svg class="w-5 h-5 text-muted-foreground group-hover:text-muted-foreground transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" />
                                        </svg>
                                        <span class="text-xs text-muted-foreground font-medium truncate px-2">{{ cam.name }}</span>
                                    </div>
                                    <div v-if="activeCam?.id === cam.id"
                                        class="absolute top-1 right-1 flex items-center gap-1 px-1.5 py-0.5 rounded bg-black/60">
                                        <div class="w-1 h-1 rounded-full bg-success animate-pulse" />
                                        <span class="text-xs text-success font-bold">LIVE</span>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ─── Recent RFID Snapshots Panel ────────────────── -->
                    <div class="w-full lg:w-56 flex-shrink-0 border-t lg:border-t-0 lg:border-l border-border bg-muted-foreground flex flex-col">
                        <div class="px-3 py-2.5 border-b border-border flex items-center justify-between">
                            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-widest">
                                Snapshot RFID
                            </p>
                            <div class="flex items-center gap-1">
                                <div class="w-1.5 h-1.5 rounded-full bg-info animate-pulse" />
                                <span class="text-xs text-muted-foreground">Live</span>
                            </div>
                        </div>

                        <!-- Snapshot list -->
                        <div class="flex-1 overflow-y-auto p-2 space-y-2">
                            <!-- Empty state -->
                            <div v-if="recentSnapshots.length === 0"
                                class="flex flex-col items-center justify-center py-8 gap-2 text-center">
                                <div class="w-10 h-10 rounded-xl bg-muted-foreground flex items-center justify-center">
                                    <span class="text-lg"></span>
                                </div>
                                <p class="text-xs text-muted-foreground leading-relaxed">
                                    Belum ada snapshot.<br>
                                    Akan muncul setelah RFID scan.
                                </p>
                            </div>

                            <!-- Snapshot thumbnails -->
                            <div
                                v-for="snap in recentSnapshots"
                                :key="snap.id + '-' + snap.captured_at"
                                class="rounded-lg overflow-hidden border border-border hover:border-border transition-colors cursor-pointer group"
                                @click="openLightbox(snap.photo_url)"
                            >
                                <!-- Thumbnail -->
                                <div class="aspect-video bg-muted-foreground relative overflow-hidden">
                                    <img
                                        v-if="snap.photo_url"
                                        :src="snap.photo_url"
                                        class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-200"
                                        :alt="snap.camera_name"
                                        loading="lazy"
                                    />
                                    <div v-else class="absolute inset-0 flex items-center justify-center">
                                        <span class="text-2xl"></span>
                                    </div>

                                    <!-- Scan type badge -->
                                    <div class="absolute top-1 left-1">
                                        <span class="text-xs font-bold px-1.5 py-0.5 rounded border"
                                            :class="scanTypeColor(snap.scan_type)">
                                            {{ scanTypeLabel(snap.scan_type) }}
                                        </span>
                                    </div>

                                    <!-- Zoom hint -->
                                    <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity bg-black/30">
                                        <span class="text-xs text-white font-semibold">Lihat</span>
                                    </div>
                                </div>

                                <!-- Info -->
                                <div class="p-2 space-y-1">
                                    <p class="text-xs font-bold text-white truncate">{{ snap.batch_code }}</p>
                                    <p class="text-xs text-muted-foreground truncate">{{ snap.camera_name }}</p>
                                    <div class="flex items-center justify-between">
                                        <span v-if="snap.stage"
                                            class="text-xs text-info font-medium">
                                            {{ snap.stage.replace(/_/g, ' ') }}
                                        </span>
                                        <span class="text-xs text-muted-foreground font-mono">
                                            {{ snap.captured_at?.split(' ')[1]?.substring(0, 5) ?? '—' }}
                                        </span>
                                    </div>
                                    <!-- snapshot_status badge -->
                                    <span v-if="snapshotStatusLabel(snap.snapshot_status)"
                                        class="inline-block text-xs font-bold px-1.5 py-0.5 rounded border"
                                        :class="snapshotStatusColor(snap.snapshot_status)">
                                        {{ snapshotStatusLabel(snap.snapshot_status) }}
                                    </span>
                                    <p v-if="snap.rfid_uid"
                                        class="text-xs font-mono text-muted-foreground truncate">
                                        UID: {{ snap.rfid_uid }}
                                    </p>
                                    <!-- SHA-256 integrity (first 8 chars) -->
                                    <p v-if="snap.sha256"
                                        class="text-xs font-mono text-muted-foreground truncate"
                                        :title="'SHA-256: ' + snap.sha256">
                                        SHA: {{ snap.sha256.substring(0, 8) }}…
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Panel footer -->
                        <div class="px-3 py-2 border-t border-border text-center">
                            <p class="text-xs text-muted-foreground">Auto-refresh setiap 10 detik</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Footer ───────────────────────────────────────────────── -->
            <div class="px-5 py-2.5 bg-muted-foreground border-t border-border flex items-center justify-between text-xs text-muted-foreground">
                <span class="flex items-center gap-2">
                    <span v-if="simulation" class="text-info">Simulation Mode Active</span>
                    <span v-else>TP-Link Tapo C500 · MediaMTX HLS · Snapshot via ffmpeg</span>
                </span>
                <span class="font-mono">{{ cameras.length }} kamera · {{ recentSnapshots.length }} snapshot</span>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to       { opacity: 0; }

.toast-enter-active { transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
.toast-leave-active { transition: all 0.18s ease; }
.toast-enter-from   { opacity: 0; transform: translateY(20px) scale(0.9); }
.toast-leave-to     { opacity: 0; transform: translateY(10px); }
</style>
