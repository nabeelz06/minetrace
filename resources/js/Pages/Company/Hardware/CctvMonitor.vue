<script setup>
/**
 * CctvMonitor — pantau kamera (HLS via MediaMTX) + foto otomatis dari pemindaian RFID.
 * Area video selalu gelap (permukaan video); panel lain memakai token tema. Polling foto sadar-visibilitas.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import Hls from 'hls.js'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/components/app/PageHeader.vue'
import AppIcon from '@/components/app/AppIcon.vue'
import EmptyState from '@/components/shared/EmptyState.vue'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog'
import { http } from '@/lib/http'
import { pushToast } from '@/composables/core/useToast.js'
import { usePolling } from '@/composables/core/usePolling.js'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    cameras:            { type: Array,   default: () => [] },
    hls_base_url:       { type: String,  default: '' },
    has_cctv:           { type: Boolean, default: false },
    simulation:         { type: Boolean, default: false },
    can_manage_cameras: { type: Boolean, default: false },
})

const { t } = useI18n()

// ─── State ────────────────────────────────────────────────────────────────────
const activeCam    = ref(props.cameras[0] ?? null)
const videoEl      = ref(null)
const hlsInstance  = ref(null)
const streamStatus = ref('idle')   // idle | loading | playing | error
const streamError  = ref('')
const showGrid     = ref(props.cameras.length > 1)
const recentSnapshots = ref([])
const lightboxPhoto = ref(null)
let retryTimer = null

// ─── Foto RFID (polling) ──────────────────────────────────────────────────────
const poll = usePolling(async () => {
    const res = await http.get('/api/v1/company/cctv/recent-snapshots')
    if (!res.ok || !Array.isArray(res.data)) return false
    const prev = recentSnapshots.value.length
    recentSnapshots.value = res.data
    if (prev > 0 && res.data.length > prev) pushToast(t('cctv.new_snapshot'), 'info')
    return true
}, { interval: 10_000 })

const SCAN = {
    scan_out:           { key: 'cctv.scan_out', cls: 'bg-warning-soft text-warning' },
    departure_snapshot: { key: 'cctv.scan_out', cls: 'bg-warning-soft text-warning' },
    manual_trigger:     { key: 'cctv.manual',   cls: 'bg-info-soft text-info' },
}
const scanInfo = type => SCAN[type] ?? { key: 'cctv.scan_in', cls: 'bg-success-soft text-success' }
const SNAP = {
    captured: { key: 'cctv.captured', cls: 'bg-success-soft text-success', icon: 'camera' },
    pending:  { key: 'cctv.pending',  cls: 'bg-warning-soft text-warning', icon: 'clock' },
    failed:   { key: 'cctv.failed',   cls: 'bg-danger-soft text-danger',   icon: 'camera-off' },
}

// ─── HLS ──────────────────────────────────────────────────────────────────────
function destroyHls() {
    clearTimeout(retryTimer)
    hlsInstance.value?.destroy()
    hlsInstance.value = null
}

function fail(messageKey) {
    streamStatus.value = 'error'
    streamError.value = t(messageKey)
}

function initStream(camera) {
    if (!camera?.hls_url) return fail('cctv.stream_url_missing')

    destroyHls()
    streamStatus.value = 'loading'
    streamError.value = ''
    const video = videoEl.value
    if (!video) return

    if (Hls.isSupported()) {
        const hls = new Hls({
            enableWorker: true,
            // lowLatencyMode dimatikan: MediaMTX memakai HLS standar; LL-HLS membuat muxer gagal untuk RTSP/H.264 biasa.
            backBufferLength: 10,
            // Cookie sesi MediaMTX (cookieCheck) wajib dikirim agar manifest tidak 500 "muxer instance not available".
            xhrSetup: xhr => { xhr.withCredentials = true },
            manifestLoadingMaxRetry: 6,
            manifestLoadingRetryDelay: 1000,
            manifestLoadingMaxRetryTimeout: 8000,
        })
        hlsInstance.value = hls
        hls.loadSource(camera.hls_url)
        hls.attachMedia(video)
        hls.on(Hls.Events.MANIFEST_PARSED, () => { video.play().catch(() => {}); streamStatus.value = 'playing' })
        hls.on(Hls.Events.ERROR, (_, data) => {
            if (data.fatal) {
                fail('cctv.stream_dropped')
                retryTimer = setTimeout(() => initStream(camera), 5000)
            }
        })
    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = camera.hls_url
        video.addEventListener('loadedmetadata', () => { video.play().catch(() => {}); streamStatus.value = 'playing' })
        video.addEventListener('error', () => fail('cctv.stream_failed'))
    } else {
        fail('cctv.stream_unsupported')
    }
}

function selectCamera(cam) {
    activeCam.value = cam
    nextTick(() => initStream(cam))
}

async function toggleFullscreen() {
    const el = videoEl.value
    if (!el) return
    if (!document.fullscreenElement) await el.requestFullscreen()
    else await document.exitFullscreen()
}

onMounted(() => {
    if (activeCam.value) nextTick(() => initStream(activeCam.value))
    poll.start()
})
onBeforeUnmount(destroyHls)

const STATUS = {
    idle:    { dot: 'bg-neutral',  chip: 'bg-neutral-soft text-neutral' },
    loading: { dot: 'bg-warning',  chip: 'bg-warning-soft text-warning' },
    playing: { dot: 'bg-success',  chip: 'bg-success-soft text-success' },
    error:   { dot: 'bg-danger',   chip: 'bg-danger-soft text-danger' },
}
const timeOf = at => at?.split(' ')[1]?.substring(0, 5) ?? '—'
const lightboxOpen = computed({ get: () => !!lightboxPhoto.value, set: v => { if (!v) lightboxPhoto.value = null } })
</script>

<template>
    <Head :title="t('cctv.title')" />

    <AppLayout>
        <template #header>{{ t('cctv.title') }}</template>

        <PageHeader :title="t('cctv.title')" :description="t('cctv.subtitle')" icon="video">
            <template v-if="simulation" #badge>
                <span class="rounded-md bg-info-soft px-2 py-0.5 text-xs font-bold uppercase text-info">{{ t('cctv.simulation') }}</span>
            </template>
            <template #actions>
                <button v-if="cameras.length > 1" type="button" :aria-pressed="showGrid"
                        class="inline-flex min-h-[44px] items-center gap-2 rounded-lg border px-3 text-sm font-semibold hover:bg-accent"
                        :class="showGrid ? 'border-primary text-primary' : 'border-input'" @click="showGrid = !showGrid">
                    <AppIcon name="layers" :size="18" />{{ t('cctv.grid') }}
                </button>
                <Link v-if="can_manage_cameras" href="/company/hardware/cctv/settings"
                      class="inline-flex min-h-[44px] items-center gap-2 rounded-lg border border-input px-3 text-sm font-semibold hover:bg-accent">
                    <AppIcon name="settings" :size="18" />{{ t('cctv.settings') }}
                </Link>
            </template>
        </PageHeader>

        <EmptyState v-if="!has_cctv" icon="camera-off" :title="t('cctv.not_configured')" :description="t('cctv.not_configured_hint')" />

        <div v-else class="grid gap-4 lg:grid-cols-[11rem_minmax(0,1fr)_16rem]" :class="cameras.length > 1 ? '' : 'lg:grid-cols-[minmax(0,1fr)_16rem]'">
            <!-- Daftar kamera -->
            <nav v-if="cameras.length > 1" :aria-label="t('cctv.cameras')" class="rounded-xl border border-border bg-card p-2">
                <h2 class="px-2 py-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ t('cctv.cameras') }}</h2>
                <ul class="flex gap-1 overflow-x-auto lg:flex-col">
                    <li v-for="cam in cameras" :key="cam.id ?? cam.name" class="flex-shrink-0 lg:w-full">
                        <button type="button" class="flex min-h-[44px] w-full items-center gap-2 rounded-lg px-3 text-left text-sm font-medium"
                                :class="activeCam?.id === cam.id ? 'bg-primary/10 text-primary' : 'text-foreground hover:bg-accent'"
                                :aria-pressed="activeCam?.id === cam.id" @click="selectCamera(cam)">
                            <span class="h-2 w-2 flex-shrink-0 rounded-full" :class="activeCam?.id === cam.id ? STATUS[streamStatus].dot : 'bg-neutral'" />
                            <span class="min-w-0 flex-1"><span class="block truncate">{{ cam.name }}</span>
                                <span v-if="cam.location_key" class="block truncate text-xs font-normal text-muted-foreground">{{ cam.location_key.replace(/_/g, ' ') }}</span></span>
                        </button>
                    </li>
                </ul>
            </nav>

            <!-- Video -->
            <section class="min-w-0 space-y-3" :aria-label="activeCam?.name ?? t('cctv.title')">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full" :class="STATUS[streamStatus].dot" />
                        <h2 class="text-base font-semibold">{{ activeCam?.name ?? t('cctv.title') }}</h2>
                        <span class="rounded-md px-2 py-0.5 font-mono text-xs font-bold" :class="STATUS[streamStatus].chip" role="status">{{ t(`cctv.status_${streamStatus}`) }}</span>
                    </div>
                    <button type="button" class="inline-flex min-h-[44px] items-center gap-2 rounded-lg border border-input px-3 text-sm font-semibold hover:bg-accent" @click="toggleFullscreen">
                        <AppIcon name="monitor" :size="18" />{{ t('cctv.fullscreen') }}
                    </button>
                </div>

                <div class="relative aspect-video overflow-hidden rounded-xl border border-border bg-black text-white">
                    <video ref="videoEl" class="h-full w-full object-contain" controls muted autoplay playsinline :aria-label="activeCam?.name" />

                    <div v-if="streamStatus === 'loading'" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/80" role="status">
                        <AppIcon name="loader" :size="32" spin /><p class="text-sm">{{ t('cctv.connecting') }}</p>
                    </div>
                    <div v-if="streamStatus === 'error'" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/85 p-4 text-center" role="alert">
                        <AppIcon name="camera-off" :size="36" />
                        <p class="text-base font-semibold">{{ t('cctv.stream_unavailable') }}</p>
                        <p class="text-sm opacity-80">{{ streamError || t('cctv.check_mediamtx') }}</p>
                        <button type="button" class="min-h-[44px] rounded-lg bg-white px-4 text-sm font-semibold text-black hover:opacity-90" @click="initStream(activeCam)">{{ t('cctv.retry') }}</button>
                    </div>
                    <div v-if="streamStatus === 'idle'" class="absolute inset-0 flex items-center justify-center bg-black/80 text-sm">{{ t('cctv.pick_camera') }}</div>
                    <span v-if="streamStatus === 'playing'" class="absolute left-3 top-3 rounded bg-black/60 px-2 py-1 text-xs font-bold tracking-widest">LIVE</span>
                </div>

                <div v-if="showGrid && cameras.length > 1">
                    <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ t('cctv.all_cameras') }}</h3>
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
                        <button v-for="cam in cameras" :key="cam.id ?? cam.name" type="button" :aria-pressed="activeCam?.id === cam.id"
                                class="flex aspect-video min-h-[44px] flex-col items-center justify-center gap-1 rounded-lg border bg-card p-2 hover:bg-accent"
                                :class="activeCam?.id === cam.id ? 'border-primary' : 'border-border'" @click="selectCamera(cam)">
                            <AppIcon name="video" :size="22" class="text-muted-foreground" /><span class="max-w-full truncate text-sm">{{ cam.name }}</span>
                        </button>
                    </div>
                </div>
            </section>

            <!-- Foto dari RFID -->
            <aside class="flex flex-col rounded-xl border border-border bg-card" :aria-label="t('cctv.snapshots')">
                <h2 class="border-b border-border px-3 py-2.5 text-sm font-semibold">{{ t('cctv.snapshots') }}</h2>
                <div class="max-h-[34rem] flex-1 space-y-2 overflow-y-auto p-2">
                    <p v-if="!recentSnapshots.length" class="py-6 text-center text-sm text-muted-foreground">{{ t('cctv.snapshots_empty') }}</p>
                    <button v-for="snap in recentSnapshots" :key="snap.id + '-' + snap.captured_at" type="button"
                            class="block w-full overflow-hidden rounded-lg border border-border text-left hover:bg-accent" :disabled="!snap.photo_url"
                            @click="lightboxPhoto = snap.photo_url">
                        <div class="relative aspect-video bg-muted">
                            <img v-if="snap.photo_url" :src="snap.photo_url" :alt="`${t('cctv.photo_alt')} ${snap.batch_code ?? ''}`" loading="lazy" class="h-full w-full object-cover" />
                            <span class="absolute left-1 top-1 rounded px-1.5 py-0.5 text-xs font-bold" :class="scanInfo(snap.scan_type).cls">{{ t(scanInfo(snap.scan_type).key) }}</span>
                        </div>
                        <div class="space-y-1 p-2 text-xs">
                            <p class="truncate font-mono text-sm font-semibold">{{ snap.batch_code }}</p>
                            <p class="truncate text-muted-foreground">{{ snap.camera_name }} · {{ timeOf(snap.captured_at) }}</p>
                            <span v-if="SNAP[snap.snapshot_status]" class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 font-semibold" :class="SNAP[snap.snapshot_status].cls">
                                <AppIcon :name="SNAP[snap.snapshot_status].icon" :size="12" />{{ t(SNAP[snap.snapshot_status].key) }}
                            </span>
                        </div>
                    </button>
                </div>
                <p class="border-t border-border px-3 py-2 text-center text-xs text-muted-foreground">{{ t('cctv.auto_refresh') }}</p>
            </aside>
        </div>

        <p v-if="has_cctv" class="mt-4 flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
            <span>{{ simulation ? t('cctv.footer_sim') : t('cctv.footer_real') }}</span>
            <span class="font-mono">{{ t('cctv.counts', { cams: cameras.length, snaps: recentSnapshots.length }) }}</span>
        </p>

        <Dialog v-model:open="lightboxOpen">
            <DialogContent class="max-w-5xl border-0 bg-black p-2">
                <DialogTitle class="sr-only">{{ t('cctv.photo_alt') }}</DialogTitle>
                <DialogDescription class="sr-only">{{ t('cctv.photo_alt') }}</DialogDescription>
                <img v-if="lightboxPhoto" :src="lightboxPhoto" :alt="t('cctv.photo_alt')" class="max-h-[85vh] w-full rounded-lg object-contain" />
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
