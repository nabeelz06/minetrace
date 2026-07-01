<script setup>
import { ref, computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    batch:    Object,
    timeline: Array,
})

// ─── CCTV Photo modal ─────────────────────────────────────────────────────────
const photoModal = ref(null)
function openPhoto(url) { photoModal.value = url }
function closePhoto()   { photoModal.value = null }

// Kumpulkan semua foto dari semua checkpoint
const allPhotos = computed(() => {
    const items = []
    for (const cp of props.batch?.checkpoints ?? []) {
        for (const photo of cp.photos ?? []) {
            if (!photo?.url) continue
            items.push({
                url:             photo.url,
                stage:           photo.stage || cp.checkpoint_stage,
                stage_label:     cp.stage_label || cp.checkpoint_stage?.replace(/_/g, ' '),
                date:            photo.captured_at || cp.recorded_at,
                scan_type:       photo.scan_type,
                camera_name:     photo.camera_name,
                filesize_kb:     photo.filesize_kb,
                sha256:          photo.sha256,
                rfid_uid:        photo.rfid_uid,
                snapshot_status: cp.snapshot_status,
            })
        }
    }
    return items
})

function snapshotStatusBadge(status) {
    if (status === 'captured') return { label: '✓ Captured', cls: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' }
    if (status === 'pending')  return { label: '⏳ Pending',  cls: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' }
    if (status === 'failed')   return { label: '✕ Failed',   cls: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400' }
    return null
}

function scanTypeLabel(type) {
    if (type === 'scan_out' || type === 'departure_snapshot') return 'SCAN OUT'
    if (type === 'manual_trigger') return 'MANUAL'
    return 'SCAN IN'
}

// ─── Tab active ───────────────────────────────────────────────────────────
const activeTab = ref('overview')
const tabs = computed(() => {
    const base = [
        { id: 'overview',    label: 'Ringkasan',   icon: '📊' },
        { id: 'timeline',    label: 'Timeline',     icon: '⏱' },
        { id: 'checkpoints', label: 'Checkpoint',   icon: '📍' },
        { id: 'photos',      label: 'Galeri Foto',  icon: '📷' },
    ]
    if (props.batch?.lab_analyses?.length) {
        base.push({ id: 'lab',    label: 'Lab Analisis', icon: '🧬' })
    }
    if (props.batch?.parent_batch?.checkpoints?.length) {
        base.push({ id: 'parent', label: 'Batch Induk',  icon: '🔗' })
    }
    return base
})

function rfidOriginBadge(origin) {
    if (origin === 'real_esp32')  return { label: 'ESP32',       cls: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' }
    if (origin === 'simulation')  return { label: 'Simulasi',    cls: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' }
    if (origin === 'api')         return { label: 'API',         cls: 'bg-muted text-muted-foreground' }
    return null
}

// ─── Status & Stage helpers ───────────────────────────────────────────────
function statusClass(s) {
    const m = {
        active:      'badge-active', in_transit: 'badge-transit',
        in_process:  'badge-process', in_warehouse: 'badge-warehouse',
        completed:   'badge-completed', split: 'badge-split',
        transformed: 'badge-transformed', exported: 'badge-exported',
        terminated:  'badge-terminated', draft: 'badge-completed',
    }
    return m[s] ?? 'badge-completed'
}

function stageIcon(s) {
    const m = {
        lab_kimia:'🧪', receiving:'📥', wet_process:'💧',
        dry_process:'🔥', project_plant:'⚗️', warehouse:'🏭',
        smelter:'⚡', exported:'🚢',
    }
    return m[s] ?? '📦'
}

function statusLabel(s) {
    const m = {
        active:'Aktif', in_transit:'Transit', in_process:'Diproses',
        in_warehouse:'Di Gudang', completed:'Selesai', split:'Di-split',
        transformed:'Ditransformasi', exported:'Diekspor', terminated:'Terminal',
    }
    return m[s] ?? s
}

function stageLabel(s) {
    const m = {
        lab_kimia:'Lab Kimia', receiving:'Receiving Area', wet_process:'Wet Process',
        dry_process:'Dry Process', project_plant:'Project Plant',
        warehouse:'LTJ Warehouse', smelter:'Smelter', exported:'Diekspor',
    }
    return m[s] ?? s
}

// ─── Timeline helpers ─────────────────────────────────────────────────────
function timelineColor(item) {
    if (item.has_alert) return 'bg-red-500'
    if (item.type === 'created') return 'bg-primary'
    return 'bg-emerald-500'
}

function formatDate(d) {
    if (!d) return '—'
    return new Date(d).toLocaleString('id-ID', {
        day:'2-digit', month:'short', year:'numeric',
        hour:'2-digit', minute:'2-digit'
    })
}

function formatWeight(kg) {
    if (!kg) return '—'
    if (kg >= 1000) return `${(kg/1000).toFixed(2)} ton`
    return `${parseFloat(kg).toLocaleString('id-ID', {maximumFractionDigits:1})} kg`
}

// ─── Hitung persentase susut ──────────────────────────────────────────────
const weightRetentionPct = computed(() => {
    if (!props.batch?.initial_weight || !props.batch?.current_weight) return 100
    return Math.min(100, (props.batch.current_weight / props.batch.initial_weight) * 100)
})
</script>

<template>
    <AppLayout>
        <Head :title="`Batch ${batch.batch_code}`" />
        <template #header>Detail Batch</template>

        <div class="space-y-6">

            <!-- ─── Breadcrumb ──────────────────────────────────────────── -->
            <div class="flex items-center gap-2 text-sm text-muted-foreground animate-fade-in">
                <Link href="/company/batches" class="hover:text-foreground transition-colors">
                    Manajemen Batch
                </Link>
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                </svg>
                <span class="font-mono font-semibold text-foreground text-xs">{{ batch.batch_code }}</span>
            </div>

            <!-- ─── Header Card ─────────────────────────────────────────── -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden animate-scale-in">
                <!-- Top accent bar -->
                <div class="h-1 w-full" style="background: linear-gradient(to right, hsl(var(--primary)), hsl(207 40% 60%), hsl(180 50% 55%));"/>

                <div class="p-6">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="space-y-2">
                            <!-- Batch code + status -->
                            <div class="flex items-center gap-3 flex-wrap">
                                <div class="flex items-center gap-2">
                                    <span style="font-size:24px;">{{ stageIcon(batch.process_stage) }}</span>
                                    <h1 class="text-2xl font-bold font-mono tracking-wide text-foreground">
                                        {{ batch.batch_code }}
                                    </h1>
                                </div>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold" :class="statusClass(batch.status)">
                                    <div v-if="batch.status === 'active'" class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"/>
                                    {{ statusLabel(batch.status) }}
                                </span>
                                <span v-if="batch.has_discrepancy"
                                      class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-semibold badge-terminated">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                    </svg>
                                    Mass Balance Alert
                                </span>
                            </div>

                            <!-- Product & Location -->
                            <div class="flex flex-wrap items-center gap-4 text-sm">
                                <div class="flex items-center gap-1.5">
                                    <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                                    </svg>
                                    <span class="font-semibold text-foreground">{{ batch.product_code?.code }}</span>
                                    <span class="text-muted-foreground">{{ batch.product_code?.name }}</span>
                                </div>
                                <div v-if="batch.current_location" class="flex items-center gap-1.5">
                                    <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                                    </svg>
                                    <span class="text-muted-foreground">{{ batch.current_location.name }}</span>
                                </div>
                                <div class="flex items-center gap-1.5">
                                    <svg class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                                    </svg>
                                    <span class="text-muted-foreground text-xs">{{ formatDate(batch.created_at) }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Hazard + Export Block badges -->
                        <div class="flex flex-col items-end gap-2">
                            <span v-if="batch.product_code?.is_hazardous"
                                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold badge-radioactive">
                                <span>☢</span> Material Radioaktif
                            </span>
                            <span v-if="batch.product_code?.is_export_blocked"
                                  class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold badge-export-blocked">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                </svg>
                                Ekspor Diblokir
                            </span>
                        </div>
                    </div>

                    <!-- ─── Weight Progress Bar ─────────────────────────── -->
                    <div class="mt-6 p-4 rounded-xl bg-muted/40 space-y-3">
                        <div class="flex items-center justify-between text-sm">
                            <div>
                                <span class="text-muted-foreground">Berat awal: </span>
                                <span class="font-semibold text-foreground">{{ formatWeight(batch.initial_weight) }}</span>
                            </div>
                            <div class="text-right">
                                <span class="text-muted-foreground">Berat saat ini: </span>
                                <span class="text-xl font-bold text-foreground">{{ formatWeight(batch.current_weight) }}</span>
                                <span v-if="batch.mass_loss_pct !== null"
                                      class="ml-2 text-xs font-semibold"
                                      :class="batch.has_discrepancy ? 'text-red-500' : 'text-emerald-500'">
                                    -{{ batch.mass_loss_pct }}%
                                </span>
                            </div>
                        </div>
                        <div class="h-3 rounded-full bg-muted overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all duration-700"
                                :style="`width: ${weightRetentionPct}%; background: linear-gradient(to right, hsl(var(--primary)), hsl(207 40% 60%));`"
                            />
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-muted-foreground">Retensi: {{ weightRetentionPct.toFixed(1) }}%</span>
                            <span class="text-xs font-medium" :class="batch.has_discrepancy ? 'text-red-500' : 'text-muted-foreground'">
                                Susut: {{ formatWeight((batch.initial_weight - batch.current_weight)) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Tabs ────────────────────────────────────────────────── -->
            <div class="flex gap-1 p-1 rounded-xl bg-muted/50 border border-border w-fit animate-fade-in delay-150">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    :class="activeTab === tab.id
                        ? 'text-white shadow-sm'
                        : 'text-muted-foreground hover:text-foreground hover:bg-muted'"
                    class="flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-semibold transition-all duration-200"
                    :style="activeTab === tab.id ? 'background: hsl(var(--primary));' : ''"
                >
                    <span style="font-size:14px;">{{ tab.icon }}</span>
                    {{ tab.label }}
                </button>
            </div>

            <!-- ─── Tab: Overview ───────────────────────────────────────── -->
            <div v-if="activeTab === 'overview'" class="grid grid-cols-1 md:grid-cols-2 gap-4 animate-fade-in">

                <!-- Info Batch -->
                <div class="rounded-2xl border border-border bg-card p-5 space-y-4">
                    <h3 class="text-sm font-semibold text-foreground">Informasi Batch</h3>
                    <div class="space-y-3">
                        <div v-for="info in [
                            { label: 'Kode Batch', value: batch.batch_code, mono: true },
                            { label: 'Lot Number', value: batch.lot_number || '—', mono: true },
                            { label: 'Stage', value: `${stageIcon(batch.process_stage)} ${stageLabel(batch.process_stage)}` },
                            { label: 'Status', value: statusLabel(batch.status) },
                            { label: 'Lokasi', value: batch.current_location?.name || '—' },
                            { label: 'Dibuat Oleh', value: batch.created_by_user?.name || '—' },
                        ]" :key="info.label"
                             class="flex items-start justify-between gap-4 py-2 border-b border-border/50 last:border-0"
                        >
                            <span class="text-xs text-muted-foreground flex-shrink-0">{{ info.label }}</span>
                            <span class="text-xs font-semibold text-foreground text-right" :class="info.mono ? 'font-mono' : ''">
                                {{ info.value }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Info Produk -->
                <div class="rounded-2xl border border-border bg-card p-5 space-y-4">
                    <h3 class="text-sm font-semibold text-foreground">Informasi Produk</h3>
                    <div class="space-y-3">
                        <div v-for="info in [
                            { label: 'Kode Produk', value: batch.product_code?.code, mono: true },
                            { label: 'Nama', value: batch.product_code?.name },
                            { label: 'Kategori', value: batch.product_code?.category },
                            { label: 'Simbol Kimia', value: batch.product_code?.chemical_symbol || '—' },
                            { label: 'Radioaktif', value: batch.product_code?.radioactive_level === 'none' ? 'Tidak' : batch.product_code?.radioactive_level },
                            { label: 'Status Ekspor', value: batch.product_code?.is_export_blocked ? '🚫 Diblokir' : '✅ Dapat Diekspor' },
                        ]" :key="info.label"
                             class="flex items-start justify-between gap-4 py-2 border-b border-border/50 last:border-0"
                        >
                            <span class="text-xs text-muted-foreground flex-shrink-0">{{ info.label }}</span>
                            <span class="text-xs font-semibold text-foreground text-right" :class="info.mono ? 'font-mono' : ''">
                                {{ info.value }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Child batches (jika ada) -->
                <div v-if="batch.child_batches?.length" class="md:col-span-2 rounded-2xl border border-border bg-card p-5 space-y-4">
                    <h3 class="text-sm font-semibold text-foreground flex items-center gap-2">
                        Batch Turunan
                        <span class="text-xs px-2 py-0.5 rounded-full bg-muted text-muted-foreground font-medium">
                            {{ batch.child_batches.length }}
                        </span>
                    </h3>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                        <Link
                            v-for="child in batch.child_batches"
                            :key="child.id"
                            :href="`/company/batches/${child.id}`"
                            class="flex items-center gap-2.5 p-3 rounded-xl border border-border hover:border-primary/30 hover:bg-muted/30 transition-all duration-150 group"
                        >
                            <span style="font-size:16px;">{{ stageIcon(child.process_stage) }}</span>
                            <div class="min-w-0">
                                <p class="text-xs font-mono font-semibold text-foreground truncate">{{ child.batch_code }}</p>
                                <p class="text-xs text-muted-foreground">{{ child.product_code?.code }} · {{ formatWeight(child.current_weight) }}</p>
                            </div>
                            <svg class="w-3.5 h-3.5 text-muted-foreground ml-auto opacity-0 group-hover:opacity-100 transition-opacity" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                            </svg>
                        </Link>
                    </div>
                </div>
            </div>

            <!-- ─── Tab: Timeline ───────────────────────────────────────── -->
            <div v-if="activeTab === 'timeline'" class="animate-fade-in">
                <div class="rounded-2xl border border-border bg-card p-6">
                    <h3 class="text-sm font-semibold text-foreground mb-6">Riwayat Perjalanan Batch</h3>

                    <div v-if="!timeline?.length" class="text-center py-8 text-muted-foreground text-sm">
                        Belum ada riwayat timeline.
                    </div>

                    <div v-else class="relative">
                        <!-- Vertical line -->
                        <div class="absolute left-4 top-3 bottom-3 w-0.5 bg-border"/>

                        <div class="space-y-6">
                            <div
                                v-for="(item, i) in timeline"
                                :key="i"
                                class="relative flex gap-4 animate-slide-in-left"
                                :style="`animation-delay: ${i * 60}ms;`"
                            >
                                <!-- Dot -->
                                <div class="relative z-10 w-8 h-8 rounded-full flex items-center justify-center flex-shrink-0 ring-2 ring-background"
                                     :class="timelineColor(item)">
                                    <svg v-if="item.type === 'created'" class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                    </svg>
                                    <svg v-else-if="item.has_alert" class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                    </svg>
                                    <svg v-else class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>

                                <!-- Content -->
                                <div class="flex-1 pb-2">
                                    <div class="flex items-start justify-between gap-2">
                                        <div>
                                            <p class="text-sm font-semibold text-foreground">{{ item.title }}</p>
                                            <p v-if="item.description" class="text-xs text-muted-foreground mt-0.5">
                                                {{ item.description }}
                                            </p>
                                            <div v-if="item.weight" class="flex items-center gap-3 mt-1.5">
                                                <span class="text-xs font-semibold text-foreground">
                                                    {{ formatWeight(item.weight) }}
                                                </span>
                                                <span v-if="item.loss_pct !== null && item.loss_pct !== undefined"
                                                      class="text-xs font-semibold"
                                                      :class="item.has_alert ? 'text-red-500' : 'text-muted-foreground'">
                                                    -{{ item.loss_pct }}% susut
                                                </span>
                                            </div>
                                        </div>
                                        <div class="text-right flex-shrink-0">
                                            <p class="text-xs text-muted-foreground">{{ formatDate(item.timestamp) }}</p>
                                            <p class="text-xs font-medium text-foreground mt-0.5">{{ item.user }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Tab: Checkpoints ────────────────────────────────────── -->
            <div v-if="activeTab === 'checkpoints'" class="animate-fade-in">
                <div class="rounded-2xl border border-border bg-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-border bg-muted/20">
                        <h3 class="text-sm font-semibold text-foreground">
                            Riwayat Checkpoint
                            <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-muted text-muted-foreground font-medium">
                                {{ batch.checkpoints?.length ?? 0 }}
                            </span>
                        </h3>
                    </div>

                    <div v-if="!batch.checkpoints?.length" class="py-12 text-center text-muted-foreground text-sm">
                        Belum ada checkpoint tercatat.
                    </div>

                    <div v-else class="divide-y divide-border">
                        <div
                            v-for="(cp, i) in batch.checkpoints"
                            :key="cp.id"
                            class="px-5 py-4 hover:bg-muted/20 transition-colors"
                            :class="cp.has_discrepancy ? 'bg-red-50/40 dark:bg-red-950/10' : ''"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-start gap-3">
                                    <div class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 text-xs font-bold mt-0.5"
                                         :class="cp.has_discrepancy
                                             ? 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'
                                             : 'bg-primary/10 text-primary'">
                                        {{ i + 1 }}
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-foreground">
                                            {{ cp.stage_label || cp.checkpoint_stage.replace(/_/g, ' ') }}
                                        </p>
                                        <p v-if="cp.notes" class="text-xs text-muted-foreground mt-0.5">
                                            {{ cp.notes }}
                                        </p>
                                        <div class="flex items-center gap-3 mt-1.5 flex-wrap">
                                            <span v-if="cp.weight_recorded" class="text-xs font-semibold text-foreground">
                                                {{ formatWeight(cp.weight_recorded) }}
                                            </span>
                                            <span v-if="cp.weight_loss_pct !== null && cp.weight_loss_pct !== undefined"
                                                  class="text-xs font-semibold"
                                                  :class="cp.has_discrepancy ? 'text-red-500 dark:text-red-400' : 'text-muted-foreground'">
                                                -{{ cp.weight_loss_pct }}%
                                            </span>
                                            <span v-if="cp.is_verified"
                                                  class="text-xs font-semibold px-1.5 py-0.5 rounded badge-active">
                                                ✓ Terverifikasi
                                            </span>
                                            <span v-if="cp.location"
                                                  class="text-xs text-muted-foreground">
                                                📍 {{ cp.location }}
                                            </span>
                                            <!-- CCTV snapshot status badge -->
                                            <span v-if="snapshotStatusBadge(cp.snapshot_status)"
                                                  class="text-xs font-semibold px-1.5 py-0.5 rounded"
                                                  :class="snapshotStatusBadge(cp.snapshot_status)?.cls">
                                                📷 {{ snapshotStatusBadge(cp.snapshot_status)?.label }}
                                            </span>
                                            <!-- RFID origin badge -->
                                            <span v-if="rfidOriginBadge(cp.rfid_origin)"
                                                  class="text-xs font-semibold px-1.5 py-0.5 rounded"
                                                  :class="rfidOriginBadge(cp.rfid_origin)?.cls">
                                                📡 {{ rfidOriginBadge(cp.rfid_origin)?.label }}
                                            </span>
                                            <!-- Capture failed indicator -->
                                            <span v-if="cp.capture_attempted && !cp.capture_succeeded"
                                                  class="text-xs font-semibold px-1.5 py-0.5 rounded bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400">
                                                ⚠ Capture Gagal
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="text-xs text-muted-foreground">
                                        {{ formatDate(cp.recorded_at) }}
                                    </p>
                                    <p class="text-xs font-medium text-foreground mt-0.5">
                                        {{ cp.recorded_by_user?.name ?? '—' }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Tab: Foto CCTV ──────────────────────────────────────── -->
            <div v-if="activeTab === 'photos'" class="animate-fade-in">
                <div class="rounded-2xl border border-border bg-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-border bg-muted/20 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-foreground">
                            Foto CCTV
                            <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-muted text-muted-foreground font-medium">
                                {{ allPhotos.length }}
                            </span>
                        </h3>
                        <span class="text-xs text-muted-foreground">Otomatis diambil saat RFID scan</span>
                    </div>

                    <div v-if="!allPhotos.length" class="py-16 text-center">
                        <div class="inline-flex flex-col items-center gap-3">
                            <div class="w-12 h-12 rounded-xl bg-muted/40 flex items-center justify-center">
                                <svg class="w-6 h-6 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z" />
                                </svg>
                            </div>
                            <p class="text-sm text-muted-foreground">Belum ada foto CCTV.</p>
                            <p class="text-xs text-muted-foreground/70">Foto akan otomatis tersimpan saat RFID scan.</p>
                        </div>
                    </div>

                    <div v-else class="p-5 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                        <button
                            v-for="(photo, i) in allPhotos"
                            :key="i"
                            @click="openPhoto(photo.url)"
                            class="group relative rounded-xl overflow-hidden border border-border hover:border-primary/40 transition-all duration-150 bg-muted"
                        >
                            <div class="aspect-video relative overflow-hidden">
                                <img
                                    :src="photo.url"
                                    :alt="`Foto ${photo.stage_label}`"
                                    class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                                    loading="lazy"
                                />
                                <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-transparent to-transparent" />

                                <!-- Scan type badge -->
                                <div class="absolute top-1.5 left-1.5">
                                    <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-black/60 text-white">
                                        {{ scanTypeLabel(photo.scan_type) }}
                                    </span>
                                </div>

                                <!-- Zoom icon -->
                                <div class="absolute top-1.5 right-1.5 w-6 h-6 rounded-full bg-black/50 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                    <svg class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round"
                                            d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607zM10.5 7.5v6m3-3h-6" />
                                    </svg>
                                </div>

                                <!-- Stage + time overlay -->
                                <div class="absolute bottom-0 left-0 right-0 p-2">
                                    <p class="text-xs text-white font-medium truncate">{{ photo.stage_label }}</p>
                                    <p class="text-[10px] text-gray-300">{{ formatDate(photo.date) }}</p>
                                </div>
                            </div>

                            <!-- Metadata row -->
                            <div class="px-2 py-1.5 space-y-0.5 text-left">
                                <div class="flex items-center justify-between gap-1">
                                    <span class="text-[10px] text-muted-foreground truncate">{{ photo.camera_name ?? '—' }}</span>
                                    <span v-if="snapshotStatusBadge(photo.snapshot_status)"
                                          class="text-[9px] font-bold px-1 py-0.5 rounded flex-shrink-0"
                                          :class="snapshotStatusBadge(photo.snapshot_status)?.cls">
                                        {{ snapshotStatusBadge(photo.snapshot_status)?.label }}
                                    </span>
                                </div>
                                <p v-if="photo.sha256"
                                   class="text-[9px] font-mono text-muted-foreground/60 truncate"
                                   :title="'SHA-256: ' + photo.sha256">
                                    SHA: {{ photo.sha256.substring(0, 8) }}…
                                </p>
                                <p v-if="photo.rfid_uid" class="text-[9px] font-mono text-muted-foreground/60 truncate">
                                    UID: {{ photo.rfid_uid }}
                                </p>
                            </div>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ─── Tab: Lab Analisis ─────────────────────────────────────── -->
            <div v-if="activeTab === 'lab'" class="animate-fade-in">
                <div class="rounded-2xl border border-border bg-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-border bg-muted/20 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-foreground">
                            Hasil Analisis Lab LTJ
                            <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-muted text-muted-foreground font-medium">
                                {{ batch.lab_analyses?.length ?? 0 }}
                            </span>
                        </h3>
                        <span class="text-xs text-muted-foreground">Analisis komposisi REO per batch</span>
                    </div>
                    <div class="divide-y divide-border">
                        <div v-for="(la, i) in batch.lab_analyses" :key="la.id"
                             class="p-5 space-y-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold text-muted-foreground bg-muted px-2 py-0.5 rounded">
                                        #{{ i + 1 }}
                                    </span>
                                    <span v-if="la.is_validated"
                                          class="text-xs font-semibold px-2 py-0.5 rounded badge-active">
                                        ✓ Divalidasi
                                    </span>
                                    <span v-else class="text-xs px-2 py-0.5 rounded bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 font-semibold">
                                        Belum Divalidasi
                                    </span>
                                    <span v-if="la.lab_method" class="text-xs text-muted-foreground">
                                        Metode: {{ la.lab_method }}
                                    </span>
                                </div>
                                <div class="text-right text-xs text-muted-foreground">
                                    <p>{{ la.analyzed_by ?? '—' }}</p>
                                    <p>{{ la.analyzed_at ?? '—' }}</p>
                                    <p v-if="la.validated_at" class="text-emerald-600 dark:text-emerald-400 mt-0.5">✓ {{ la.validated_at }}</p>
                                </div>
                            </div>

                            <!-- REO composition grid -->
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div v-for="elem in [
                                    { key: 'la', label: 'La' },
                                    { key: 'ce', label: 'Ce' },
                                    { key: 'pr', label: 'Pr' },
                                    { key: 'nd', label: 'Nd' },
                                    { key: 'y',  label: 'Y' },
                                ]" :key="elem.key"
                                     class="rounded-xl bg-muted/40 p-3 space-y-1"
                                >
                                    <p class="text-[10px] text-muted-foreground font-medium">{{ elem.label }}</p>
                                    <p class="text-sm font-bold text-foreground font-mono">
                                        {{ la[elem.key + '_pct'] != null ? la[elem.key + '_pct'].toFixed(3) + '%' : '—' }}
                                    </p>
                                    <p class="text-[10px] text-muted-foreground">
                                        {{ la[elem.key + '_kg'] != null ? formatWeight(la[elem.key + '_kg']) : '' }}
                                    </p>
                                </div>

                                <!-- Radioactive elements -->
                                <div class="rounded-xl bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-900/30 p-3 space-y-1">
                                    <p class="text-[10px] text-red-600 dark:text-red-400 font-semibold">ThO₂ ☢</p>
                                    <p class="text-sm font-bold text-red-700 dark:text-red-400 font-mono">
                                        {{ la.tho2_pct != null ? la.tho2_pct.toFixed(4) + '%' : '—' }}
                                    </p>
                                </div>
                                <div class="rounded-xl bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-900/30 p-3 space-y-1">
                                    <p class="text-[10px] text-red-600 dark:text-red-400 font-semibold">U₃O₈ ☢</p>
                                    <p class="text-sm font-bold text-red-700 dark:text-red-400 font-mono">
                                        {{ la.u3o8_pct != null ? la.u3o8_pct.toFixed(4) + '%' : '—' }}
                                    </p>
                                </div>
                                <!-- Total REO -->
                                <div class="rounded-xl border-2 border-primary/20 bg-primary/5 p-3 space-y-1 col-span-2 sm:col-span-2">
                                    <p class="text-[10px] text-primary font-semibold">Total REO</p>
                                    <p class="text-lg font-extrabold text-primary font-mono">
                                        {{ la.total_reo_pct != null ? la.total_reo_pct.toFixed(3) + '%' : '—' }}
                                    </p>
                                </div>
                            </div>

                            <p v-if="la.notes" class="text-xs text-muted-foreground italic border-l-2 border-border pl-3">
                                {{ la.notes }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Tab: Batch Induk ─────────────────────────────────────── -->
            <div v-if="activeTab === 'parent'" class="animate-fade-in">
                <div class="rounded-2xl border border-border bg-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-border bg-muted/20 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <h3 class="text-sm font-semibold text-foreground">
                                Riwayat Batch Induk
                            </h3>
                            <Link
                                :href="`/company/batches/${batch.parent_batch?.id}`"
                                class="text-xs font-mono font-semibold text-primary hover:underline flex items-center gap-1"
                            >
                                {{ batch.parent_batch?.batch_code }}
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 19.5l15-15m0 0H8.25m11.25 0v11.25" />
                                </svg>
                            </Link>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-muted text-muted-foreground">
                            {{ batch.parent_batch?.checkpoints?.length ?? 0 }} checkpoint
                        </span>
                    </div>

                    <div v-if="!batch.parent_batch?.checkpoints?.length" class="py-12 text-center text-muted-foreground text-sm">
                        Tidak ada checkpoint dari batch induk.
                    </div>

                    <div v-else class="divide-y divide-border">
                        <div
                            v-for="(cp, i) in batch.parent_batch.checkpoints"
                            :key="cp.id"
                            class="px-5 py-4 hover:bg-muted/20 transition-colors"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex items-start gap-3">
                                    <div class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 text-xs font-bold mt-0.5 bg-muted text-muted-foreground">
                                        {{ i + 1 }}
                                    </div>
                                    <div>
                                        <p class="text-sm font-semibold text-foreground">
                                            {{ cp.stage_label || cp.checkpoint_stage?.replace(/_/g, ' ') }}
                                        </p>
                                        <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                                            <span v-if="cp.weight_recorded" class="text-xs font-semibold text-foreground">
                                                {{ formatWeight(cp.weight_recorded) }}
                                            </span>
                                            <span v-if="cp.location" class="text-xs text-muted-foreground">
                                                📍 {{ cp.location }}
                                            </span>
                                            <!-- RFID origin badge -->
                                            <span v-if="rfidOriginBadge(cp.rfid_origin)"
                                                  class="text-xs font-semibold px-1.5 py-0.5 rounded"
                                                  :class="rfidOriginBadge(cp.rfid_origin)?.cls">
                                                📡 {{ rfidOriginBadge(cp.rfid_origin)?.label }}
                                            </span>
                                            <!-- Snapshot status -->
                                            <span v-if="snapshotStatusBadge(cp.snapshot_status)"
                                                  class="text-xs font-semibold px-1.5 py-0.5 rounded"
                                                  :class="snapshotStatusBadge(cp.snapshot_status)?.cls">
                                                📷 {{ snapshotStatusBadge(cp.snapshot_status)?.label }}
                                            </span>
                                        </div>
                                        <!-- Thumbnail row for parent checkpoint photos -->
                                        <div v-if="cp.photos?.length" class="flex gap-2 mt-2">
                                            <button
                                                v-for="(ph, pi) in cp.photos.slice(0, 4)"
                                                :key="pi"
                                                @click="openPhoto(ph.url)"
                                                class="w-14 h-10 rounded-lg overflow-hidden border border-border hover:border-primary/40 transition-all flex-shrink-0"
                                                :title="ph.camera_name"
                                            >
                                                <img :src="ph.url" class="w-full h-full object-cover" loading="lazy" />
                                            </button>
                                            <span v-if="cp.photos.length > 4"
                                                  class="w-14 h-10 rounded-lg border border-border bg-muted flex items-center justify-center text-xs text-muted-foreground font-semibold">
                                                +{{ cp.photos.length - 4 }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="text-xs text-muted-foreground">{{ cp.recorded_at }}</p>
                                    <p v-if="cp.recorded_at_diff" class="text-[10px] text-muted-foreground/70 mt-0.5">{{ cp.recorded_at_diff }}</p>
                                    <p v-if="cp.rfid_scan_code" class="text-[10px] font-mono text-muted-foreground/60 mt-0.5">
                                        UID: {{ cp.rfid_scan_code }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ─── Photo Modal ─────────────────────────────────────────────── -->
        <Teleport to="body">
            <Transition name="fade">
                <div v-if="photoModal"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-4"
                    @click.self="closePhoto"
                >
                    <div class="relative max-w-5xl w-full max-h-[90vh]">
                        <img
                            :src="photoModal"
                            alt="Foto CCTV"
                            class="w-full h-full object-contain rounded-xl"
                        />
                        <button
                            @click="closePhoto"
                            class="absolute top-3 right-3 w-9 h-9 rounded-full bg-black/60 hover:bg-black/80 flex items-center justify-center transition-colors"
                        >
                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>