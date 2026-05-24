<script setup>
import { ref, computed } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    batch:    Object,
    timeline: Array,
})

// ─── Tab active ───────────────────────────────────────────────────────────
const activeTab = ref('overview')
const tabs = [
    { id: 'overview',    label: 'Ringkasan', icon: '📊' },
    { id: 'timeline',    label: 'Timeline', icon: '⏱' },
    { id: 'checkpoints', label: 'Checkpoint', icon: '📍' },
]

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
                                                📍 {{ cp.location.name }}
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

        </div>
    </AppLayout>
</template>