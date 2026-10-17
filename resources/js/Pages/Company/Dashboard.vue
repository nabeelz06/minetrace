<script setup>
import AppIcon from '@/components/app/AppIcon.vue'
import StatCard from '@/components/app/StatCard.vue'
import { formatWeightKg } from '@/lib/format'
/**
 * Company/Dashboard.vue — Dashboard kontekstual per role.
 *
 * page_context menentukan tampilan:
 *   'lab_station'  → Operator Lab Kimia Stasiun
 *   'wet_process'  → Operator Wet Process
 *   'dry_process'  → Operator Dry Process
 *   'lab_ltj'      → Operator Lab LTJ Analisis
 *   'warehouse'    → Operator Warehouse
 *   'admin'        → Company Admin (full overview)
 *   'auditor'      → Company Auditor (read-only)
 */
import { ref, computed }        from 'vue'
import { Head, Link, router }   from '@inertiajs/vue3'
import { usePage }              from '@inertiajs/vue3'
import AppLayout                from '@/Layouts/AppLayout.vue'
import StatusBadge              from '@/components/shared/StatusBadge.vue'
import WeightDisplay            from '@/components/shared/WeightDisplay.vue'
import EmptyState               from '@/components/shared/EmptyState.vue'
import { useI18n }              from '@/composables/core/useI18n.js'

const { t, lang, loc, formatWeight } = useI18n()
const page = usePage()

/** Nama tahap resmi dari ProcessFlow (props Inertia `stages`), bukan teks mentah "lab_kimia". */
function stageLabel(key) {
    const s = (page.props.stages ?? []).find(x => x.key === key)
    return s ? (lang.value === 'en' ? s.label_en : s.label) : String(key ?? '').replace(/_/g, ' ')
}
const can  = computed(() => page.props.can ?? {})

const props = defineProps({
    // Context
    page_context:   String,
    page_title:     String,
    page_subtitle:  String,
    role:           String,
    company:        Object,
    current_time:   String,
    timestamp_wib:  String,

    // Lab Station
    stats:              Object,
    today_batches:      Array,
    recent_activity:    Array,
    available_products: Array,
    locations:          Array,
    can_create:         Boolean,

    // Wet Process
    waiting_receiving:   Array,
    in_wet_process:      Array,
    recent_checkpoints:  Array,

    // Dry Process
    ready_for_dry:    Array,
    in_dry_process:   Array,
    output_today:     Array,
    output_products:  Array,

    // Lab LTJ
    pending_monazite:  Array,
    in_project_plant:  Array,
    recent_analyses:   Array,

    // Warehouse
    in_warehouse:        Array,
    warehouse_breakdown: Array,
    ready_for_export:    Array,
    ready_for_ltj_lab:   Array,
    recent_exports:      Array,

    // Admin
    kpi_cards:            Object,
    stage_distribution:   Array,
    production_trend:     Array,
    mass_balance_summary: Array,
    recent_batches:       Array,
    export_summary:       Object,
    warehouse_capacity:   Array,
    recent_activity_admin:Array,
})

// ─── Batch context menu ────────────────────────────────────────────────────
const expandedBatch = ref(null)

function toggleBatch(id) {
    expandedBatch.value = expandedBatch.value === id ? null : id
}

// ─── Stage color mapping ───────────────────────────────────────────────────
const stageColors = {
    lab_kimia:     { bg: 'bg-info-soft ', text: 'text-info ', dot: 'bg-info' },
    receiving:     { bg: 'bg-info-soft ',     text: 'text-info ',     dot: 'bg-info' },
    wet_process:   { bg: 'bg-info-soft ',     text: 'text-info ',     dot: 'bg-info' },
    dry_process:   { bg: 'bg-warning-soft ',   text: 'text-warning ',   dot: 'bg-warning' },
    project_plant: { bg: 'bg-success-soft ',text:'text-success ',dot:'bg-success' },
    warehouse:     { bg: 'bg-success-soft ',     text: 'text-success ',     dot: 'bg-success' },
    exported:      { bg: 'bg-muted ',   text: 'text-muted-foreground ',   dot: 'bg-muted-foreground' },
}

function stageColor(stage) {
    return stageColors[stage] ?? stageColors.lab_kimia
}

// ─── Context icon ──────────────────────────────────────────────────────────
const contextIcon = computed(() => ({
    lab_station:  'flask-conical',
    wet_process:  'droplets',
    dry_process:  'flame',
    lab_ltj:      'microscope',
    warehouse:    'warehouse',
    admin:        'gauge',
    auditor:      'eye',
}[props.page_context] ?? 'package'))

const contextBadgeStyle = computed(() => ({
    lab_station:  'background:hsl(var(--success-soft));color:hsl(var(--success))',
    wet_process:  'background:hsl(var(--info-soft));color:hsl(var(--info))',
    dry_process:  'background:hsl(var(--warning-soft));color:hsl(var(--warning))',
    lab_ltj:      'background:hsl(var(--info-soft));color:hsl(var(--info))',
    warehouse:    'background:hsl(var(--success-soft));color:hsl(var(--success))',
    admin:        'background:hsl(var(--primary)/0.18);color:hsl(var(--primary))',
    auditor:      'background:hsl(var(--neutral-soft));color:hsl(var(--neutral))',
}[props.page_context] ?? ''))
</script>

<template>
    <AppLayout>
        <Head title="Dashboard" />
        <template #header>
            {{ page_title ?? (lang === 'en' ? 'Dashboard' : 'Dashboard') }}
        </template>

        <div class="space-y-6">

            <!-- ════════════════════════════════════════════════════════════
                 HEADER — Timestamp + Context Badge
            ════════════════════════════════════════════════════════════ -->
            <div class="flex items-start justify-between gap-4 animate-fade-in">
                <div class="space-y-1.5">
                    <div class="flex items-center gap-2 flex-wrap">
                        <AppIcon :name="contextIcon" :size="28" class="text-muted-foreground" />
                        <h1 class="font-heading text-2xl font-bold text-foreground tracking-tight">
                            {{ page_title }}
                        </h1>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-lg text-xs font-bold uppercase tracking-wider"
                              :style="contextBadgeStyle">
                            {{ role?.replace(/_/g,' ') }}
                        </span>
                    </div>
                    <p class="text-sm text-muted-foreground">{{ page_subtitle }}</p>
                </div>

                <!-- Timestamp Card -->
                <div class="flex-shrink-0 hidden md:block">
                    <div class="rounded-2xl border border-border bg-card px-4 py-3 text-right space-y-1 shadow-sm">
                        <div class="flex items-center justify-end gap-1.5">
                            <div class="w-1.5 h-1.5 rounded-full bg-success animate-pulse"/>
                            <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                {{ lang === 'en' ? 'System Time' : 'Waktu Sistem' }}
                            </span>
                        </div>
                        <p class="font-mono text-sm font-bold text-foreground leading-tight">
                            {{ timestamp_wib }}
                        </p>
                        <p class="text-xs text-muted-foreground leading-tight">
                            {{ company?.name }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- ════════════════════════════════════════════════════════════
                 CONTEXT: LAB STATION (operator_lab_station)
            ════════════════════════════════════════════════════════════ -->
            <template v-if="page_context === 'lab_station'">

                <!-- KPI Stats -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div v-for="(item, i) in [
                        { label: lang==='en'?'Created Today':'Dibuat Hari Ini',     value: stats?.today_created,      color:'text-primary',                               icon:'package' },
                        { label: lang==='en'?'This Month':'Bulan Ini',              value: stats?.month_created,      color:'text-success ',     icon:'gauge' },
                        { label: lang==='en'?'Total Weight Today':'Berat Hari Ini', value: null, weight: stats?.total_today_weight, color:'text-foreground',             icon:'scale' },
                        { label: lang==='en'?'My Total Batches':'Total Batch Saya', value: stats?.my_total_batches,   color:'text-muted-foreground',                     icon:'layers' },
                    ]" :key="item.label"
                         class="rounded-2xl border border-border bg-card p-5 animate-fade-in"
                         :style="`animation-delay:${i*60}ms`">
                        <div class="flex items-start justify-between mb-3">
                            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider leading-tight">{{ item.label }}</p>
                            <AppIcon :name="item.icon || 'circle'" :size="22" />
                        </div>
                        <p class="font-heading text-3xl font-bold tabular-nums" :class="item.color">
                            <template v-if="item.weight !== undefined">
                                <WeightDisplay :kg="item.weight" size="lg"/>
                            </template>
                            <template v-else>{{ item.value ?? 0 }}</template>
                        </p>
                    </div>
                </div>

                <!-- Quick Action: Buat Batch -->
                <div v-if="can.create_batch"
                     class="rounded-2xl border-2 p-5 flex items-center justify-between gap-4 animate-fade-in delay-100"
                     style="border-color: hsl(var(--primary)/0.25); background: hsl(var(--primary)/0.04);">
                    <div>
                        <p class="font-heading text-sm font-bold text-foreground">
                            {{ lang === 'en' ? 'Create New Batch (Lab Kimia)' : 'Buat Batch Baru (Lab Kimia)' }}
                        </p>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ lang === 'en'
                                ? 'Register a new mineral concentrate batch. Include weight, product code, and location.'
                                : 'Daftarkan batch mineral ikutan baru. Masukkan berat, kode produk, dan lokasi.' }}
                        </p>
                    </div>
                    <Link href="/company/batches/create"
                          class="flex-shrink-0 inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold text-white hover:shadow-lg hover:-translate-y-0.5 active:scale-[0.97]"
                          style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                        </svg>
                        {{ lang === 'en' ? 'New Batch' : 'Buat Batch' }}
                    </Link>
                </div>

                <!-- Today's Batches -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                    <!-- Batch hari ini -->
                    <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-150">
                        <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                            <h3 class="font-heading text-sm font-bold text-foreground">
                                {{ lang === 'en' ? "Today's Batches" : "Batch Hari Ini" }}
                            </h3>
                            <span class="text-xs text-muted-foreground">
                                {{ today_batches?.length ?? 0 }} batch
                            </span>
                        </div>
                        <EmptyState v-if="!today_batches?.length"
                            icon='package' size="sm"
                            :title="lang === 'en' ? 'No batches today yet' : 'Belum ada batch hari ini'"
                            :description="lang === 'en' ? 'Create your first batch of the day.' : 'Buat batch pertama hari ini.'"
                        />
                        <div v-else class="divide-y divide-border max-h-[380px] overflow-y-auto">
                            <div v-for="(batch, i) in today_batches" :key="batch.id"
                                 class="px-5 py-3.5 hover:bg-muted/20 transition-colors cursor-pointer"
                                 :style="`animation: fade-in 0.28s ease-out ${i*40}ms both`"
                                 @click="toggleBatch(batch.id)">
                                <div class="flex items-start gap-3">
                                    <div class="w-2 h-2 rounded-full mt-1.5 flex-shrink-0"
                                         :class="stageColor(batch.process_stage).dot"/>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <Link :href="`/company/batches/${batch.id}`"
                                                  class="font-mono text-xs font-bold text-foreground hover:text-primary transition-colors"
                                                  @click.stop>
                                                {{ batch.batch_code }}
                                            </Link>
                                            <span class="text-xs font-bold px-1.5 py-0.5 rounded"
                                                  :class="[stageColor(batch.process_stage).bg, stageColor(batch.process_stage).text]">
                                                {{ batch.product?.code }}
                                            </span>
                                            <StatusBadge :status="batch.status" size="xs"/>
                                        </div>
                                        <div class="flex items-center gap-3 mt-1">
                                            <WeightDisplay :kg="batch.initial_weight" size="xs"/>
                                            <span class="text-muted-foreground text-xs">{{ batch.location?.name ?? '—' }}</span>
                                        </div>
                                    </div>
                                    <!-- Timestamp detail -->
                                    <div class="text-right flex-shrink-0">
                                        <p class="text-xs font-semibold text-foreground">{{ batch.created_at_time }}</p>
                                        <p class="text-xs text-muted-foreground">{{ batch.created_at_diff }}</p>
                                    </div>
                                </div>

                                <!-- Expanded detail -->
                                <Transition name="accord">
                                    <div v-if="expandedBatch === batch.id"
                                         class="mt-2 pl-5 pt-2 border-t border-border space-y-1">
                                        <p class="text-xs text-muted-foreground">
                                            <span class="font-semibold text-foreground">{{ lang === 'en' ? 'Batch Code:' : 'Kode Batch:' }}</span>
                                            {{ batch.batch_code }}
                                        </p>
                                        <p class="text-xs text-muted-foreground">
                                            <span class="font-semibold text-foreground">{{ lang === 'en' ? 'Lot Number:' : 'Lot Number:' }}</span>
                                            {{ batch.lot_number ?? '—' }}
                                        </p>
                                        <p class="text-xs text-muted-foreground">
                                            <span class="font-semibold text-foreground">{{ lang === 'en' ? 'Created:' : 'Dibuat:' }}</span>
                                            {{ batch.created_at }}
                                        </p>
                                        <p v-if="batch.product?.is_hazardous" class="text-xs text-warning font-bold">
                                            {{ lang === 'en' ? 'Hazardous / Radioactive Material' : 'Material Berbahaya / Radioaktif' }}
                                        </p>
                                    </div>
                                </Transition>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Activity -->
                    <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-200">
                        <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                            <h3 class="font-heading text-sm font-bold text-foreground">
                                {{ lang === 'en' ? 'My Recent Batches' : 'Riwayat Batch Saya' }}
                            </h3>
                            <Link href="/company/batches"
                                  class="text-xs text-primary font-semibold hover:underline">
                                {{ lang === 'en' ? 'View All →' : 'Lihat Semua →' }}
                            </Link>
                        </div>
                        <EmptyState v-if="!recent_activity?.length"
                            icon='clipboard-list' size="sm"
                            :title="lang === 'en' ? 'No recent activity' : 'Belum ada riwayat'"
                        />
                        <div v-else class="divide-y divide-border max-h-[380px] overflow-y-auto">
                            <div v-for="(item, i) in recent_activity" :key="item.id"
                                 class="flex items-center gap-3 px-5 py-3 hover:bg-muted/20 transition-colors"
                                 :style="`animation: fade-in 0.28s ease-out ${i*30}ms both`">
                                <div class="w-7 h-7 rounded-xl flex items-center justify-center text-xs flex-shrink-0"
                                     :class="stageColor(item.stage).bg">
                                                                    </div>
                                <div class="flex-1 min-w-0">
                                    <Link :href="`/company/batches/${item.id}`"
                                          class="font-mono text-xs font-bold text-foreground hover:text-primary transition-colors">
                                        {{ item.batch_code }}
                                    </Link>
                                    <p class="text-xs text-muted-foreground">
                                        {{ item.product }} · {{ item.weight_kg ? item.weight_kg.toLocaleString('id-ID') + ' kg' : '—' }}
                                    </p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="text-xs font-semibold text-foreground">{{ item.created_at_diff }}</p>
                                    <p class="text-xs text-muted-foreground">{{ item.created_at }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ════════════════════════════════════════════════════════════
                 CONTEXT: WET PROCESS (operator_wet)
            ════════════════════════════════════════════════════════════ -->
            <template v-if="page_context === 'wet_process'">

                <!-- Stats -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div v-for="(item, i) in [
                        { label: lang==='en'?'Waiting at Receiving':'Menunggu di Receiving', value: stats?.waiting_receiving, color:'text-warning ', icon:'package-open', urgent: stats?.waiting_receiving > 0 },
                        { label: lang==='en'?'In Wet Process':'Di Wet Process',          value: stats?.in_wet_process,    color:'text-info ',   icon:'droplets' },
                        { label: lang==='en'?'Total Weight Active':'Berat Aktif',        value: null, weight: stats?.total_weight_active, color:'text-foreground', icon:'scale' },
                        { label: lang==='en'?'Processed Today':'Diproses Hari Ini',      value: stats?.processed_today,   color:'text-success ', icon:'circle-check' },
                    ]" :key="item.label"
                         class="rounded-2xl border p-5 animate-fade-in"
                         :class="item.urgent ? 'border-warning/30 bg-warning-soft ' : 'border-border bg-card'"
                         :style="`animation-delay:${i*60}ms`">
                        <div class="flex items-start justify-between mb-3">
                            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider leading-tight">{{ item.label }}</p>
                            <AppIcon :name="item.icon || 'circle'" :size="22" />
                        </div>
                        <p class="font-heading text-3xl font-bold tabular-nums" :class="item.color">
                            <template v-if="item.weight !== undefined"><WeightDisplay :kg="item.weight" size="lg"/></template>
                            <template v-else>{{ item.value ?? 0 }}</template>
                        </p>
                        <p v-if="item.urgent" class="text-xs font-semibold text-warning mt-1">
                            {{ lang === 'en' ? 'Requires attention' : 'Perlu diproses' }}
                        </p>
                    </div>
                </div>

                <!-- Workflow: Receiving → Wet -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

                    <!-- Waiting at Receiving -->
                    <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-150">
                        <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="w-2 h-2 rounded-full bg-warning animate-pulse"/>
                                <h3 class="font-heading text-sm font-bold text-foreground">
                                    {{ lang === 'en' ? 'Waiting at Receiving Area' : 'Menunggu di Receiving Area' }}
                                </h3>
                            </div>
                            <span class="text-xs font-bold text-warning">
                                {{ waiting_receiving?.length ?? 0 }} batch
                            </span>
                        </div>
                        <EmptyState v-if="!waiting_receiving?.length" icon='package-open' size="sm"
                            :title="lang === 'en' ? 'No batches waiting' : 'Tidak ada batch menunggu'"
                            :description="lang === 'en' ? 'All batches have been received.' : 'Semua batch telah diterima.'"
                        />
                        <div v-else class="divide-y divide-border max-h-[320px] overflow-y-auto">
                            <div v-for="(batch, i) in waiting_receiving" :key="batch.id"
                                 class="flex items-start gap-3 px-5 py-3.5 hover:bg-muted/20 transition-colors"
                                 :style="`animation: fade-in 0.28s ease-out ${i*40}ms both`">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <Link :href="`/company/batches/${batch.id}`"
                                              class="font-mono text-xs font-bold text-foreground hover:text-primary transition-colors">
                                            {{ batch.batch_code }}
                                        </Link>
                                        <span class="text-xs font-bold px-1.5 py-0.5 rounded bg-info-soft text-info">
                                            {{ batch.product?.code }}
                                        </span>
                                    </div>
                                    <div class="flex items-center gap-3 mt-1">
                                        <WeightDisplay :kg="batch.initial_weight" size="xs"/>
                                        <span class="text-muted-foreground text-xs">{{ batch.created_at_diff }}</span>
                                    </div>
                                </div>
                                <Link :href="`/company/process/receiving`"
                                      class="flex-shrink-0 px-2.5 py-1.5 rounded-lg text-xs font-bold text-white transition-all active:scale-95"
                                      style="background: hsl(var(--primary));">
                                    {{ lang === 'en' ? 'Receive →' : 'Terima →' }}
                                </Link>
                            </div>
                        </div>
                    </div>

                    <!-- In Wet Process -->
                    <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-200">
                        <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="w-2 h-2 rounded-full bg-info animate-pulse"/>
                                <h3 class="font-heading text-sm font-bold text-foreground">
                                    {{ lang === 'en' ? 'Active in Wet Process' : 'Aktif di Wet Process' }}
                                </h3>
                            </div>
                            <span class="text-xs font-bold text-info">
                                {{ in_wet_process?.length ?? 0 }} batch
                            </span>
                        </div>
                        <EmptyState v-if="!in_wet_process?.length" icon='droplets' size="sm"
                            :title="lang === 'en' ? 'No active batches' : 'Tidak ada batch aktif'"
                        />
                        <div v-else class="divide-y divide-border max-h-[320px] overflow-y-auto">
                            <div v-for="(batch, i) in in_wet_process" :key="batch.id"
                                 class="flex items-start gap-3 px-5 py-3.5 hover:bg-muted/20 transition-colors"
                                 :style="`animation: fade-in 0.28s ease-out ${i*40}ms both`">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <Link :href="`/company/batches/${batch.id}`"
                                              class="font-mono text-xs font-bold text-foreground hover:text-primary transition-colors">
                                            {{ batch.batch_code }}
                                        </Link>
                                        <StatusBadge :status="batch.status" size="xs"/>
                                        <span v-if="batch.has_discrepancy" class="text-xs font-bold text-danger"></span>
                                    </div>
                                    <div class="flex items-center gap-3 mt-1">
                                        <WeightDisplay :kg="batch.current_weight" size="xs"/>
                                        <span class="text-muted-foreground text-xs">{{ batch.updated_at_diff }}</span>
                                    </div>
                                </div>
                                <Link :href="`/company/process/wet_process`"
                                      class="flex-shrink-0 px-2.5 py-1.5 rounded-lg text-xs font-bold transition-all active:scale-95"
                                      style="background:hsl(var(--primary)/0.1);color:hsl(var(--primary))">
                                    {{ lang === 'en' ? 'Process' : 'Proses' }}
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity Log -->
                <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-250">
                    <div class="px-5 py-4 border-b border-border">
                        <h3 class="font-heading text-sm font-bold text-foreground">
                            {{ lang === 'en' ? 'Recent Checkpoint Activity' : 'Riwayat Checkpoint Terbaru' }}
                        </h3>
                    </div>
                    <div v-if="!recent_checkpoints?.length" class="py-8 text-center text-sm text-muted-foreground">
                        {{ lang === 'en' ? 'No activity recorded yet.' : 'Belum ada aktivitas tercatat.' }}
                    </div>
                    <div v-else class="divide-y divide-border">
                        <div v-for="(cp, i) in recent_checkpoints" :key="cp.id"
                             class="flex items-start gap-3 px-5 py-3 hover:bg-muted/20 transition-colors"
                             :style="`animation: fade-in 0.28s ease-out ${i*25}ms both`">
                            <div class="w-6 h-6 rounded-lg flex items-center justify-center text-xs flex-shrink-0 mt-0.5"
                                 :class="cp.has_discrepancy ? 'bg-danger-soft ' : 'bg-success-soft '">
                                <AppIcon :name="cp.has_discrepancy ? 'triangle-alert' : 'check'" :size="14"
                                         :class="cp.has_discrepancy ? 'text-danger' : 'text-success'" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <Link :href="`/company/batches/${cp.batch_id}`"
                                          class="font-mono text-xs font-bold text-foreground hover:text-primary transition-colors">
                                        {{ cp.batch_code }}
                                    </Link>
                                    <span class="text-xs text-muted-foreground">{{ cp.stage?.replace(/_/g,' ') }}</span>
                                    <span v-if="cp.weight_loss_pct" class="text-xs font-bold"
                                          :class="parseFloat(cp.weight_loss_pct)>5?'text-danger':'text-muted-foreground'">
                                        Susut {{ cp.weight_loss_pct }}%
                                    </span>
                                </div>
                                <p class="text-xs text-muted-foreground">{{ cp.location }} · {{ cp.recorded_by }}</p>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <p class="text-xs font-semibold text-foreground">{{ cp.recorded_at_diff }}</p>
                                <p class="text-xs text-muted-foreground">{{ cp.recorded_at }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ════════════════════════════════════════════════════════════
                 CONTEXT: DRY PROCESS (operator_dry)
            ════════════════════════════════════════════════════════════ -->
            <template v-if="page_context === 'dry_process'">

                <!-- Stats -->
                <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
                    <div v-for="(item, i) in [
                        { label: lang==='en'?'Ready for Dry':'Siap Diproses',  value: stats?.ready_for_dry,   color:'text-warning ', icon:'⏳' },
                        { label: lang==='en'?'In Dry Process':'Di Dry Process',value: stats?.in_dry_process,  color:'text-foreground', icon:'flame' },
                        { label: lang==='en'?'Output Today':'Output Hari Ini', value: stats?.output_today,    color:'text-success ', icon:'arrow-up-from-line' },
                        { label: 'MON (kg)',  value: null, weight: stats?.mon_today_kg, color:'text-danger',    icon:'pickaxe' },
                        { label: 'ZIR (kg)',  value: null, weight: stats?.zir_today_kg, color:'text-info',   icon:'gem' },
                        { label: 'ILM (kg)',  value: null, weight: stats?.ilm_today_kg, color:'text-warning',  icon:'mountain' },
                    ]" :key="item.label"
                         class="rounded-2xl border border-border bg-card p-4 animate-fade-in"
                         :style="`animation-delay:${i*50}ms`">
                        <div class="flex items-start justify-between mb-2">
                            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider leading-tight">{{ item.label }}</p>
                            <AppIcon :name="item.icon || 'circle'" :size="20" />
                        </div>
                        <p class="font-heading text-2xl font-bold tabular-nums" :class="item.color">
                            <template v-if="item.weight !== undefined"><WeightDisplay :kg="item.weight" size="sm"/></template>
                            <template v-else>{{ item.value ?? 0 }}</template>
                        </p>
                    </div>
                </div>

                <!-- Workflow: Dari Wet → Dry Transform -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

                    <!-- Batch ready for dry -->
                    <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-150">
                        <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                            <h3 class="font-heading text-sm font-bold text-foreground">
                                {{ lang === 'en' ? 'From Wet Process (Ready)' : 'Dari Wet Process (Siap)' }}
                            </h3>
                            <span class="text-xs font-bold text-warning">{{ ready_for_dry?.length ?? 0 }}</span>
                        </div>
                        <EmptyState v-if="!ready_for_dry?.length" icon="⏳" size="sm"
                            :title="lang === 'en' ? 'No batches from Wet Process' : 'Belum ada batch dari Wet Process'"
                        />
                        <div v-else class="divide-y divide-border max-h-[320px] overflow-y-auto">
                            <div v-for="(batch, i) in ready_for_dry" :key="batch.id"
                                 class="flex items-start gap-3 px-5 py-3.5 hover:bg-muted/20"
                                 :style="`animation: fade-in 0.28s ease-out ${i*40}ms both`">
                                <div class="flex-1 min-w-0">
                                    <Link :href="`/company/batches/${batch.id}`"
                                          class="font-mono text-xs font-bold text-foreground hover:text-primary transition-colors">
                                        {{ batch.batch_code }}
                                    </Link>
                                    <div class="flex items-center gap-2 mt-1">
                                        <WeightDisplay :kg="batch.current_weight" size="xs"/>
                                        <span class="text-xs text-muted-foreground">{{ batch.updated_at_diff }}</span>
                                    </div>
                                    <!-- Timestamp -->
                                    <p class="text-xs text-muted-foreground mt-0.5">{{ batch.updated_at }}</p>
                                </div>
                                <Link :href="`/company/process/dry_process`"
                                      class="flex-shrink-0 px-2.5 py-1.5 rounded-lg text-xs font-bold text-white active:scale-95"
                                      style="background: hsl(var(--primary));">
                                    {{ lang === 'en' ? 'Process →' : 'Proses →' }}
                                </Link>
                            </div>
                        </div>
                    </div>

                    <!-- Output ZIR+ILM+MON hari ini -->
                    <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-200">
                        <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                            <h3 class="font-heading text-sm font-bold text-foreground">
                                {{ lang === 'en' ? "Today's Output (ZIR + ILM + MON)" : "Output Hari Ini (ZIR + ILM + MON)" }}
                            </h3>
                            <span class="text-xs font-bold text-success">{{ output_today?.length ?? 0 }}</span>
                        </div>
                        <EmptyState v-if="!output_today?.length" icon='arrow-up-from-line' size="sm"
                            :title="lang === 'en' ? 'No output today' : 'Belum ada output hari ini'"
                        />
                        <div v-else class="divide-y divide-border max-h-[320px] overflow-y-auto">
                            <div v-for="(batch, i) in output_today" :key="batch.id"
                                 class="flex items-center gap-3 px-5 py-3.5 hover:bg-muted/20"
                                 :style="`animation: fade-in 0.28s ease-out ${i*40}ms both`">
                                <div class="w-8 h-8 rounded-xl flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                                     :style="batch.product?.code==='MON'?'background:hsl(var(--danger))':batch.product?.code==='ZIR'?'background:hsl(var(--info))':'background:hsl(var(--warning))'">
                                    {{ batch.product?.code?.slice(0,3) }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <Link :href="`/company/batches/${batch.id}`"
                                          class="font-mono text-xs font-bold text-foreground hover:text-primary">
                                        {{ batch.batch_code }}
                                    </Link>
                                    <p class="text-xs text-muted-foreground">{{ batch.created_at_diff }}</p>
                                </div>
                                <WeightDisplay :kg="batch.current_weight" size="xs"/>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transformasi instruction box -->
                <div class="rounded-2xl border border-warning/30 p-5 animate-fade-in delay-300"
                     style="background: hsl(var(--warning-soft));">
                    <div class="flex items-start gap-3">
                        <span class="text-2xl flex-shrink-0"></span>
                        <div>
                            <p class="text-sm font-bold text-foreground">
                                {{ lang === 'en' ? 'Dry Process — Magnetic Separator' : 'Dry Process — Magnetic Separator' }}
                            </p>
                            <p class="text-xs text-muted-foreground mt-1 leading-relaxed">
                                {{ lang === 'en'
                                    ? 'Process incoming MIK batches through the magnetic separator to separate into 3 mineral concentrates: Monazite (MON), Zircon (ZIR), and Ilmenite (ILM). Input the weight and percentage of each output concentrate.'
                                    : 'Proses batch MIK yang masuk melalui magnetic separator untuk memisahkan menjadi 3 konsentrat: Monasit (MON), Zirkon (ZIR), dan Ilmenit (ILM). Input berat dan persentase masing-masing output konsentrat.' }}
                            </p>
                            <Link href="/company/process/dry_process"
                                  class="inline-flex items-center gap-1.5 mt-2.5 px-3 py-1.5 rounded-lg text-xs font-bold text-warning hover:bg-warning-soft transition-colors">
                                {{ lang === 'en' ? 'Go to Dry Process Stage →' : 'Ke Halaman Dry Process →' }}
                            </Link>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ════════════════════════════════════════════════════════════
                 CONTEXT: LAB LTJ (operator_lab_ltj)
            ════════════════════════════════════════════════════════════ -->
            <template v-if="page_context === 'lab_ltj'">

                <!-- Stats -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <div v-for="(item, i) in [
                        { label: lang==='en'?'Monazite Pending':'MON Pending Analisis', value: stats?.pending_monazite, color:'text-danger ', icon:'pickaxe', urgent: stats?.pending_monazite > 0 },
                        { label: lang==='en'?'In Project Plant':'Di Project Plant',     value: stats?.in_project_plant, color:'text-success ', icon:'microscope' },
                        { label: lang==='en'?'Analyzed This Month':'Dianalisis Bulan Ini', value: stats?.analyzed_month, color:'text-primary', icon:'microscope' },
                        { label: lang==='en'?'Avg REO (%)':'Rata-rata REO (%)',         value: `${stats?.avg_reo_month ?? 0}%`, color:'text-info ', icon:'gauge' },
                    ]" :key="item.label"
                         class="rounded-2xl border p-5 animate-fade-in"
                         :class="item.urgent ? 'border-danger/30 bg-danger-soft ' : 'border-border bg-card'"
                         :style="`animation-delay:${i*60}ms`">
                        <div class="flex items-start justify-between mb-3">
                            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider leading-tight">{{ item.label }}</p>
                            <AppIcon :name="item.icon || 'circle'" :size="22" />
                        </div>
                        <p class="font-heading text-3xl font-bold tabular-nums" :class="item.color">{{ item.value ?? 0 }}</p>
                        <p v-if="item.urgent" class="text-xs font-semibold text-danger mt-1">
                            {{ lang === 'en' ? 'Requires analysis' : 'Perlu dianalisis' }}
                        </p>
                    </div>
                </div>

                <!-- Monazite pending + In project plant -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">

                    <!-- Monazite pending analysis -->
                    <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-150">
                        <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <div class="w-2 h-2 rounded-full bg-danger animate-pulse"/>
                                <h3 class="font-heading text-sm font-bold text-foreground">
                                    {{ lang === 'en' ? 'Monazite — Pending LTJ Analysis' : 'Monasit — Pending Analisis LTJ' }}
                                </h3>
                            </div>
                        </div>
                        <EmptyState v-if="!pending_monazite?.length" icon='microscope' size="sm"
                            :title="lang === 'en' ? 'No Monazite batches pending' : 'Tidak ada batch Monasit pending'"
                            :description="lang === 'en' ? 'All Monazite batches have been analyzed.' : 'Semua batch Monasit sudah dianalisis.'"
                        />
                        <div v-else class="divide-y divide-border max-h-[320px] overflow-y-auto">
                            <div v-for="(batch, i) in pending_monazite" :key="batch.id"
                                 class="flex items-start gap-3 px-5 py-3.5 hover:bg-muted/20"
                                 :style="`animation: fade-in 0.28s ease-out ${i*40}ms both`">
                                <div class="w-8 h-8 rounded-xl bg-danger-soft flex items-center justify-center text-xs font-bold text-danger flex-shrink-0">
                                    MON
                                </div>
                                <div class="flex-1 min-w-0">
                                    <Link :href="`/company/batches/${batch.id}`"
                                          class="font-mono text-xs font-bold text-foreground hover:text-primary">
                                        {{ batch.batch_code }}
                                    </Link>
                                    <div class="flex items-center gap-2 mt-1">
                                        <WeightDisplay :kg="batch.current_weight" size="xs"/>
                                        <span class="text-xs text-muted-foreground">{{ batch.location?.name }}</span>
                                    </div>
                                    <p class="text-xs text-muted-foreground">{{ batch.updated_at }}</p>
                                </div>
                                <Link :href="`/company/batches/${batch.id}/lab/create`"
                                      class="flex-shrink-0 px-2.5 py-1.5 rounded-lg text-xs font-bold text-white active:scale-95"
                                      style="background: hsl(var(--primary));">
                                    {{ lang === 'en' ? 'Analyze →' : 'Analisis →' }}
                                </Link>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Analyses -->
                    <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-200">
                        <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                            <h3 class="font-heading text-sm font-bold text-foreground">
                                {{ lang === 'en' ? 'Recent LTJ Analyses' : 'Analisis LTJ Terbaru' }}
                            </h3>
                            <Link href="/company/lab" class="text-xs text-primary font-semibold hover:underline">
                                {{ lang === 'en' ? 'View All →' : 'Lihat Semua →' }}
                            </Link>
                        </div>
                        <EmptyState v-if="!recent_analyses?.length" icon='microscope' size="sm"
                            :title="lang === 'en' ? 'No analyses this month' : 'Belum ada analisis bulan ini'"
                        />
                        <div v-else class="divide-y divide-border max-h-[320px] overflow-y-auto">
                            <div v-for="(la, i) in recent_analyses" :key="la.id"
                                 class="px-5 py-3.5 hover:bg-muted/20"
                                 :style="`animation: fade-in 0.28s ease-out ${i*30}ms both`">
                                <div class="flex items-start gap-3">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <Link :href="`/company/batches/${la.batch_id}`"
                                                  class="font-mono text-xs font-bold text-foreground hover:text-primary">
                                                {{ la.batch_code }}
                                            </Link>
                                            <span :class="la.is_validated ? 'badge-active' : 'badge-transit'"
                                                  class="text-xs font-bold px-1.5 py-0.5 rounded-md">
                                                {{ la.is_validated ? (lang==='en'?'Validated':'Tervalidasi') : (lang==='en'?'Pending':'Pending') }}
                                            </span>
                                        </div>
                                        <!-- REO breakdown -->
                                        <div class="flex items-center gap-2 mt-1 flex-wrap">
                                            <span class="text-xs font-bold text-info">
                                                REO {{ la.total_reo_pct ?? '—' }}%
                                            </span>
                                            <span class="text-xs text-muted-foreground">La {{ la.la_pct ?? '—' }}%</span>
                                            <span class="text-xs text-muted-foreground">Ce {{ la.ce_pct ?? '—' }}%</span>
                                            <span class="text-xs text-muted-foreground">Nd {{ la.nd_pct ?? '—' }}%</span>
                                        </div>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <p class="text-xs font-semibold text-foreground">{{ la.analyzed_at_diff }}</p>
                                        <p class="text-xs text-muted-foreground">{{ la.analyzed_at }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Instruksi workflow -->
                <div class="rounded-2xl border border-info/30 p-5 animate-fade-in delay-300"
                     style="background: hsl(var(--info-soft));">
                    <div class="flex items-start gap-3">
                        <span class="text-2xl flex-shrink-0"></span>
                        <div>
                            <p class="text-sm font-bold text-foreground">
                                {{ lang === 'en' ? 'LTJ Analysis Workflow' : 'Alur Kerja Lab LTJ Analisis' }}
                            </p>
                            <ol class="text-xs text-muted-foreground mt-2 space-y-1 list-decimal ml-4 leading-relaxed">
                                <li>{{ lang === 'en' ? 'Request Monazite (MON) from Warehouse.' : 'Minta batch Monasit (MON) dari Gudang.' }}</li>
                                <li>{{ lang === 'en' ? 'Perform chemical analysis (XRF/ICP-MS): record La, Ce, Pr, Nd, Y, ThO₂, U₃O₈ percentages.' : 'Lakukan analisis kimia (XRF/ICP-MS): catat persentase La, Ce, Pr, Nd, Y, ThO₂, U₃O₈.' }}</li>
                                <li>{{ lang === 'en' ? 'Validate analysis results with Senior Analyst.' : 'Validasi hasil analisis dengan Senior Analis.' }}</li>
                                <li>{{ lang === 'en' ? 'Send processed LTJ results back to Warehouse.' : 'Kirim hasil olahan LTJ kembali ke Gudang.' }}</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ════════════════════════════════════════════════════════════
                 CONTEXT: WAREHOUSE (operator_warehouse)
            ════════════════════════════════════════════════════════════ -->
            <template v-if="page_context === 'warehouse'">

                <!-- Warehouse Stats -->
                <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-7 gap-3">
                    <div v-for="(item, i) in [
                        { label: lang==='en'?'Total Stock':'Total Stok',      value: stats?.total_in_warehouse, color:'text-foreground',                               icon:'package', span:'col-span-2' },
                        { label: lang==='en'?'Ready Export':'Siap Ekspor',    value: stats?.ready_for_export,   color:'text-success ',         icon:'ship' },
                        { label: lang==='en'?'Send to Lab LTJ':'Ke Lab LTJ', value: stats?.ready_for_ltj_lab, color:'text-info ',            icon:'microscope' },
                        { label: 'MON (batch)',                                value: stats?.mon_count,          color:'text-danger',                                  icon:'pickaxe' },
                        { label: 'ZIR (batch)',                                value: stats?.zir_count,          color:'text-info',                                 icon:'gem' },
                        { label: 'ILM (batch)',                                value: stats?.ilm_count,          color:'text-warning',                                icon:'mountain' },
                    ]" :key="item.label"
                         class="rounded-2xl border border-border bg-card p-4 animate-fade-in"
                         :class="item.span ?? ''"
                         :style="`animation-delay:${i*50}ms`">
                        <div class="flex items-start justify-between mb-2">
                            <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider leading-tight">{{ item.label }}</p>
                            <AppIcon :name="item.icon || 'circle'" :size="20" />
                        </div>
                        <p class="font-heading text-2xl font-bold tabular-nums" :class="item.color">{{ item.value ?? 0 }}</p>
                    </div>
                </div>

                <!-- Warehouse breakdown + Actions -->
                <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

                    <!-- Breakdown per produk -->
                    <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-100">
                        <div class="px-5 py-4 border-b border-border">
                            <h3 class="font-heading text-sm font-bold text-foreground">
                                {{ lang === 'en' ? 'Stock by Product' : 'Stok per Produk' }}
                            </h3>
                        </div>
                        <div v-if="!warehouse_breakdown?.length" class="py-8 text-center text-sm text-muted-foreground">
                            {{ lang === 'en' ? 'Warehouse is empty.' : 'Gudang kosong.' }}
                        </div>
                        <div v-else class="p-4 space-y-3">
                            <div v-for="(item, i) in warehouse_breakdown" :key="item.code"
                                 class="flex items-center gap-3 animate-fade-in"
                                 :style="`animation-delay:${i*50}ms`">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                                     :style="item.code==='MON'?'background:hsl(var(--danger))':item.code==='ZIR'?'background:hsl(var(--info))':item.code==='ILM'?'background:hsl(var(--warning))':'background:hsl(var(--neutral))'">
                                    {{ item.code?.slice(0,3) }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between">
                                        <p class="text-xs font-bold text-foreground">{{ item.code }}</p>
                                        <div class="flex items-center gap-1">
                                            <span v-if="item.is_blocked"
                                                  class="text-xs font-bold px-1.5 py-0.5 rounded bg-danger-soft text-danger">
                                                {{ lang === 'en' ? 'BLOCKED' : 'DIBLOKIR' }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex items-center justify-between mt-0.5">
                                        <span class="text-xs text-muted-foreground">{{ item.count }} batch</span>
                                        <WeightDisplay :kg="item.weight_kg" size="xs"/>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ready for Export -->
                    <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-150">
                        <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                            <h3 class="font-heading text-sm font-bold text-foreground">
                                {{ lang === 'en' ? 'Ready for Export' : 'Siap untuk Ekspor' }}
                            </h3>
                            <Link v-if="can.create_export" href="/company/exports/create"
                                  class="text-xs font-bold text-primary hover:underline">
                                {{ lang === 'en' ? '+ Export' : '+ Ekspor' }}
                            </Link>
                        </div>
                        <EmptyState v-if="!ready_for_export?.length" icon='ship' size="sm"
                            :title="lang === 'en' ? 'No batches ready for export' : 'Tidak ada batch siap ekspor'"
                        />
                        <div v-else class="divide-y divide-border max-h-[280px] overflow-y-auto">
                            <div v-for="(batch, i) in ready_for_export" :key="batch.id"
                                 class="flex items-center gap-3 px-5 py-3 hover:bg-muted/20"
                                 :style="`animation: fade-in 0.28s ease-out ${i*30}ms both`">
                                <div class="w-7 h-7 rounded-lg text-xs font-bold text-white flex items-center justify-center flex-shrink-0"
                                     :style="batch.product?.code==='ZIR'?'background:hsl(var(--info))':batch.product?.code==='ILM'?'background:hsl(var(--warning))':'background:hsl(var(--neutral))'">
                                    {{ batch.product?.code?.slice(0,3) }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <Link :href="`/company/batches/${batch.id}`"
                                          class="font-mono text-xs font-bold text-foreground hover:text-primary">
                                        {{ batch.batch_code }}
                                    </Link>
                                    <div class="flex items-center gap-1 mt-0.5">
                                        <WeightDisplay :kg="batch.current_weight" size="xs"/>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Send MON to Lab LTJ -->
                    <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-200">
                        <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                            <h3 class="font-heading text-sm font-bold text-foreground">
                                {{ lang === 'en' ? 'MON → Send to Lab LTJ' : 'MON → Kirim ke Lab LTJ' }}
                            </h3>
                            <span class="text-xs font-bold text-info">
                                {{ ready_for_ltj_lab?.length ?? 0 }} batch
                            </span>
                        </div>
                        <EmptyState v-if="!ready_for_ltj_lab?.length" icon='microscope' size="sm"
                            :title="lang === 'en' ? 'No Monazite batches' : 'Tidak ada batch Monasit'"
                            :description="lang === 'en' ? 'All Monazite is in LTJ analysis or already processed.' : 'Semua Monasit sedang dianalisis atau sudah diproses.'"
                        />
                        <div v-else class="divide-y divide-border max-h-[280px] overflow-y-auto">
                            <div v-for="(batch, i) in ready_for_ltj_lab" :key="batch.id"
                                 class="flex items-center gap-3 px-5 py-3 hover:bg-muted/20"
                                 :style="`animation: fade-in 0.28s ease-out ${i*30}ms both`">
                                <div class="w-7 h-7 rounded-lg bg-danger-soft text-xs font-bold text-danger flex items-center justify-center flex-shrink-0">
                                    MON
                                </div>
                                <div class="flex-1 min-w-0">
                                    <Link :href="`/company/batches/${batch.id}`"
                                          class="font-mono text-xs font-bold text-foreground hover:text-primary">
                                        {{ batch.batch_code }}
                                    </Link>
                                    <WeightDisplay :kg="batch.current_weight" size="xs"/>
                                </div>
                                <Link :href="`/company/process/warehouse`"
                                      class="px-2 py-1 rounded-lg text-xs font-bold text-white flex-shrink-0 active:scale-95"
                                      style="background: hsl(207 40% 45%);">
                                    {{ lang === 'en' ? 'Send' : 'Kirim' }}
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Exports -->
                <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-300">
                    <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                        <h3 class="font-heading text-sm font-bold text-foreground">
                            {{ lang === 'en' ? 'Recent Exports' : 'Ekspor Terbaru' }}
                        </h3>
                        <Link href="/company/exports" class="text-xs text-primary font-semibold hover:underline">
                            {{ lang === 'en' ? 'View All →' : 'Lihat Semua →' }}
                        </Link>
                    </div>
                    <div v-if="!recent_exports?.length" class="py-6 text-center text-sm text-muted-foreground">
                        {{ lang === 'en' ? 'No exports yet.' : 'Belum ada ekspor.' }}
                    </div>
                    <div v-else class="divide-y divide-border">
                        <div v-for="(exp, i) in recent_exports" :key="exp.id"
                             class="flex items-center gap-4 px-5 py-3.5 hover:bg-muted/20"
                             :style="`animation: fade-in 0.28s ease-out ${i*30}ms both`">
                            <div class="w-8 h-8 rounded-xl flex items-center justify-center text-base flex-shrink-0">
                                <AppIcon :name="exp.export_type === 'international' ? 'globe' : 'warehouse'" :size="18" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-foreground truncate">{{ exp.destination }}</p>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <WeightDisplay :kg="exp.weight_kg" size="xs"/>
                                    <span class="text-xs text-muted-foreground capitalize">{{ exp.status }}</span>
                                </div>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <p class="text-xs font-semibold text-foreground">#{{ String(exp.id).padStart(6,'0') }}</p>
                                <p class="text-xs text-muted-foreground">{{ exp.exported_at }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ════════════════════════════════════════════════════════════
                 CONTEXT: ADMIN & AUDITOR — fallback ke overview umum
            ════════════════════════════════════════════════════════════ -->
            <template v-if="page_context === 'admin' || page_context === 'auditor'">

                <!-- KPI Cards (Admin/Auditor) -->
                <div v-if="kpi_cards" class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                    <StatCard v-for="(card, key) in kpi_cards" :key="key" :label="loc(card)"
                              :value="card.unit === 'kg' ? formatWeightKg(card.value, lang) : (card.value ?? 0)"
                              :hint="card.subtitle" :tone="key === 'active_alerts' && card.value > 0 ? 'danger' : 'neutral'" />
                </div>

                <!-- Recent Batches (Admin/Auditor) -->
                <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-200">
                    <div class="px-5 py-4 border-b border-border flex items-center justify-between">
                        <h3 class="font-heading text-sm font-bold text-foreground">
                            {{ lang === 'en' ? 'Recent Batches' : 'Batch Terbaru' }}
                        </h3>
                        <Link href="/company/batches" class="text-xs text-primary font-semibold hover:underline">
                            {{ lang === 'en' ? 'View All →' : 'Lihat Semua →' }}
                        </Link>
                    </div>
                    <EmptyState v-if="!recent_batches?.length" icon='package' size="sm"
                        :title="lang === 'en' ? 'No batches yet' : 'Belum ada batch'"
                    />
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-border bg-muted/30">
                                    <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Batch</th>
                                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Stage</th>
                                    <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Berat</th>
                                    <th class="text-center px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                    <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Dibuat</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="(batch, i) in recent_batches" :key="batch.id"
                                    class="hover:bg-muted/20 transition-colors group"
                                    :style="`animation: fade-in 0.28s ease-out ${i*40}ms both`">
                                    <td class="px-5 py-3.5">
                                        <div class="flex items-center gap-2">
                                            <div v-if="batch.has_discrepancy" class="w-1.5 h-1.5 rounded-full bg-danger animate-pulse flex-shrink-0"/>
                                            <div>
                                                <p class="font-mono text-xs font-bold text-foreground">{{ batch.batch_code }}</p>
                                                <p class="text-xs text-muted-foreground">{{ batch.product?.code }}</p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <span class="text-xs font-medium text-muted-foreground">
                                            {{ stageLabel(batch.process_stage) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5 text-right">
                                        <WeightDisplay :kg="batch.current_weight" size="xs"/>
                                    </td>
                                    <td class="px-4 py-3.5 text-center">
                                        <StatusBadge :status="batch.status" size="xs"/>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <p class="text-xs font-semibold text-foreground">{{ batch.created_at_time }}</p>
                                        <p class="text-xs text-muted-foreground">{{ batch.created_at_date }}</p>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <Link :href="`/company/batches/${batch.id}`"
                                              class="opacity-0 group-hover:opacity-100 transition-opacity px-2.5 py-1 rounded-lg text-xs font-bold"
                                              style="background:hsl(var(--primary)/0.1);color:hsl(var(--primary))">
                                            Detail
                                        </Link>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>

        </div>
    </AppLayout>
</template>

<style scoped>
@keyframes fade-in {
    from { opacity: 0; transform: translateY(5px); }
    to   { opacity: 1; transform: translateY(0); }
}
.accord-enter-active,
.accord-leave-active { transition: max-height 0.2s ease, opacity 0.15s ease; overflow: hidden; }
.accord-enter-from,
.accord-leave-to     { max-height: 0; opacity: 0; }
.accord-enter-to,
.accord-leave-from   { max-height: 200px; opacity: 1; }
</style>