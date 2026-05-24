<script setup>
/**
 * Lab/Index.vue — Halaman Lab Analisis LTJ.
 *
 * Menampilkan dua tab utama:
 * 1. "Daftar Analisis"  — tabel semua analisis dengan % dan kg per elemen
 * 2. "Stok Unsur LTJ"  — agregasi total stok La/Ce/Pr/Nd/Y dalam kg
 *    dari semua analisis tervalidasi
 *
 * Ini menjawab pertanyaan: "Di mana saya bisa melihat data storage unsur LTJ?"
 * → Tab "Stok Unsur LTJ" menampilkan total berat setiap unsur dari seluruh
 *   batch MON yang sudah dianalisis dan divalidasi.
 */
import { ref, computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout     from '@/Layouts/AppLayout.vue'
import StatusBadge   from '@/components/shared/StatusBadge.vue'
import WeightDisplay from '@/components/shared/WeightDisplay.vue'
import EmptyState    from '@/components/shared/EmptyState.vue'
import { useI18n }   from '@/composables/core/useI18n.js'

const { lang } = useI18n()

const props = defineProps({
    analyses:          Object,
    stats:             Object,
    ltj_aggregate:     Object,
    ltj_element_stock: { type: Array, default: () => [] },
    monthly_trend:     { type: Array, default: () => [] },
    filters:           Object,
    user_role:         String,
    current_time:      String,
})

// ─── Tab navigation ────────────────────────────────────────────────────────
const activeTab = ref('analyses') // 'analyses' | 'stock'

// ─── Filters ───────────────────────────────────────────────────────────────
const search    = ref(props.filters?.search    ?? '')
const validated = ref(props.filters?.validated ?? '')

let timeout = null
function applyFilters() {
    router.get('/company/lab', {
        search:    search.value || undefined,
        validated: validated.value !== '' ? validated.value : undefined,
    }, { preserveState: true, replace: true })
}
function onSearch() { clearTimeout(timeout); timeout = setTimeout(applyFilters, 400) }

// ─── Role capabilities ─────────────────────────────────────────────────────
const canValidate       = computed(() => ['company_admin', 'operator_lab_ltj'].includes(props.user_role))
const canSendWarehouse  = computed(() => ['company_admin', 'operator_lab_ltj'].includes(props.user_role))

// ─── Actions ───────────────────────────────────────────────────────────────
function validateAnalysis(id) {
    router.patch(`/company/lab/${id}/validate`, {}, {
        preserveScroll: true,
    })
}

function sendToWarehouse(batchId) {
    router.post(`/company/batches/${batchId}/send-to-warehouse`, {}, {
        preserveScroll: true,
    })
}

// ─── Format helpers ────────────────────────────────────────────────────────
function fmtKg(val) {
    if (val === null || val === undefined) return '—'
    const n = parseFloat(val)
    if (isNaN(n)) return '—'
    return n.toFixed(3) + ' kg'
}

function fmtPct(val) {
    if (val === null || val === undefined) return '—'
    const n = parseFloat(val)
    if (isNaN(n)) return '—'
    return n.toFixed(2) + '%'
}

// ─── Total LTJ kg formatted ────────────────────────────────────────────────
const totalLtjKg = computed(() => {
    const t = props.ltj_aggregate?.total_ltj_kg ?? 0
    if (t >= 1000) return (t / 1000).toFixed(3) + ' ton'
    return t.toFixed(3) + ' kg'
})

// ─── Tabel kolom elemen LTJ ───────────────────────────────────────────────
const elementColumns = [
    { key: 'la_pct', kgKey: 'la_kg', label: 'La', full: 'Lantanum' },
    { key: 'ce_pct', kgKey: 'ce_kg', label: 'Ce', full: 'Serium' },
    { key: 'pr_pct', kgKey: 'pr_kg', label: 'Pr', full: 'Praseodimium' },
    { key: 'nd_pct', kgKey: 'nd_kg', label: 'Nd', full: 'Neodimium' },
    { key: 'y_pct',  kgKey: 'y_kg',  label: 'Y',  full: 'Itrium' },
]
</script>

<template>
<AppLayout>
    <Head :title="lang === 'en' ? 'LTJ Lab Analysis' : 'Lab Analisis LTJ'"/>
    <template #header>{{ lang === 'en' ? 'LTJ Lab Analysis' : 'Lab Analisis LTJ' }}</template>

    <div class="space-y-5">

        <!-- ══════════════════════════════════════════════════════════════
             HEADER
        ══════════════════════════════════════════════════════════════ -->
        <div class="flex items-start justify-between gap-4 animate-fade-in">
            <div>
                <h1 class="font-heading text-2xl font-bold text-foreground tracking-tight">
                    {{ lang === 'en' ? 'LTJ Lab Analysis' : 'Lab Analisis LTJ' }}
                </h1>
                <p class="text-sm text-muted-foreground mt-0.5">
                    {{ lang === 'en'
                        ? 'Chemical analysis results for La, Ce, Pr, Nd, Y in Monazite concentrates'
                        : 'Hasil analisis kimia La, Ce, Pr, Nd, Y dari konsentrat Monasit (MON)' }}
                </p>
            </div>
            <div class="flex-shrink-0 hidden md:block">
                <div class="rounded-xl border border-border bg-card px-4 py-2 text-right">
                    <p class="text-[10px] text-muted-foreground uppercase tracking-wider">
                        {{ lang === 'en' ? 'System Time' : 'Waktu Sistem' }}
                    </p>
                    <p class="font-mono text-sm font-bold text-foreground">{{ current_time }}</p>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             KPI STATS
        ══════════════════════════════════════════════════════════════ -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div v-for="(item, i) in [
                {
                    label_id: 'Total Analisis', label_en: 'Total Analyses',
                    value: stats?.total, suffix: '',
                    color: 'text-foreground',
                    bg: '',
                    icon: '🔬',
                },
                {
                    label_id: 'Tervalidasi', label_en: 'Validated',
                    value: stats?.validated, suffix: '',
                    color: 'text-emerald-600 dark:text-emerald-400',
                    bg: 'bg-emerald-50/60 dark:bg-emerald-950/20',
                    icon: '✅',
                },
                {
                    label_id: 'Pending Validasi', label_en: 'Pending Validation',
                    value: stats?.pending, suffix: '',
                    color: 'text-amber-600 dark:text-amber-400',
                    bg: 'bg-amber-50/60 dark:bg-amber-950/20',
                    icon: '⏳',
                },
                {
                    label_id: 'Rata-rata REO', label_en: 'Average REO',
                    value: stats?.avg_reo_pct, suffix: '%',
                    color: 'text-primary',
                    bg: 'bg-primary/5',
                    icon: '📊',
                },
            ]" :key="item.label_id"
                 class="rounded-2xl border border-border p-5 animate-fade-in"
                 :class="item.bg"
                 :style="`animation-delay: ${i*60}ms`">
                <div class="flex items-start justify-between mb-2">
                    <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider leading-tight">
                        {{ lang === 'en' ? item.label_en : item.label_id }}
                    </p>
                    <span class="text-xl">{{ item.icon }}</span>
                </div>
                <p class="font-heading text-3xl font-bold tabular-nums" :class="item.color">
                    {{ (item.value ?? 0) }}{{ item.suffix }}
                </p>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             LTJ AGGREGATE — Ringkasan total stok LTJ
        ══════════════════════════════════════════════════════════════ -->
        <div class="rounded-2xl border border-primary/20 bg-primary/4 p-5 animate-fade-in delay-100">
            <div class="flex items-center gap-2 mb-4">
                <span class="text-xl">⚗️</span>
                <div>
                    <h3 class="font-heading text-sm font-bold text-foreground">
                        {{ lang === 'en' ? 'LTJ Element Stock Summary' : 'Ringkasan Stok Unsur LTJ' }}
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        {{ lang === 'en'
                            ? `Aggregated from ${ltj_aggregate?.batch_count ?? 0} validated analyses · Average REO: ${ltj_aggregate?.avg_reo_pct ?? 0}%`
                            : `Agregasi dari ${ltj_aggregate?.batch_count ?? 0} analisis tervalidasi · Rata-rata REO: ${ltj_aggregate?.avg_reo_pct ?? 0}%` }}
                    </p>
                </div>
                <div class="ml-auto text-right">
                    <p class="text-xs text-muted-foreground">{{ lang === 'en' ? 'Total LTJ' : 'Total LTJ' }}</p>
                    <p class="font-heading text-xl font-bold text-primary">{{ totalLtjKg }}</p>
                </div>
            </div>

            <!-- 5 element cards -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                <div v-for="el in ltj_element_stock" :key="el.element"
                     class="rounded-xl border border-border bg-card p-3 space-y-1.5">
                    <!-- Element symbol + name -->
                    <div class="flex items-center gap-2">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center text-sm font-black text-white flex-shrink-0"
                             :style="`background: ${el.color}`">
                            {{ el.element }}
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-foreground leading-tight">{{ el.element }}</p>
                            <p class="text-[9px] text-muted-foreground leading-tight truncate">
                                {{ lang === 'en' ? el.name_en : el.name_id }}
                            </p>
                        </div>
                    </div>

                    <!-- Total kg — highlight utama -->
                    <div class="rounded-lg p-2 text-center" :style="`background: ${el.bg}`">
                        <p class="text-[10px] font-semibold text-muted-foreground">Total Stok</p>
                        <p class="text-base font-black tabular-nums" :style="`color: ${el.color}`">
                            {{ el.total_kg.toFixed(3) }}
                            <span class="text-xs font-semibold">kg</span>
                        </p>
                    </div>

                    <!-- Avg % -->
                    <div class="flex items-center justify-between">
                        <span class="text-[10px] text-muted-foreground">Avg %</span>
                        <span class="text-xs font-bold tabular-nums" :style="`color: ${el.color}`">
                            {{ el.avg_pct.toFixed(2) }}%
                        </span>
                    </div>

                    <!-- Use case -->
                    <p class="text-[9px] text-muted-foreground leading-tight border-t border-border pt-1.5">
                        {{ lang === 'en' ? el.use_en : el.use }}
                    </p>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             TAB NAVIGATION
        ══════════════════════════════════════════════════════════════ -->
        <div class="flex gap-1 p-1 rounded-2xl border border-border bg-muted/30 w-fit animate-fade-in delay-150">
            <button @click="activeTab = 'analyses'"
                    class="px-4 py-2 rounded-xl text-xs font-semibold"
                    :style="activeTab === 'analyses'
                        ? 'background: hsl(var(--card)); color: hsl(var(--foreground)); box-shadow: 0 1px 3px rgba(0,0,0,0.1);'
                        : 'color: hsl(var(--muted-foreground));'">
                🔬 {{ lang === 'en' ? 'Analysis List' : 'Daftar Analisis' }}
                <span v-if="stats?.total"
                      class="ml-1 text-[9px] font-bold px-1.5 py-0.5 rounded-full text-white"
                      style="background:hsl(var(--primary))">
                    {{ stats.total }}
                </span>
            </button>
            <button @click="activeTab = 'stock'"
                    class="px-4 py-2 rounded-xl text-xs font-semibold"
                    :style="activeTab === 'stock'
                        ? 'background: hsl(var(--card)); color: hsl(var(--foreground)); box-shadow: 0 1px 3px rgba(0,0,0,0.1);'
                        : 'color: hsl(var(--muted-foreground));'">
                🏭 {{ lang === 'en' ? 'LTJ Element Stock' : 'Stok Unsur LTJ' }}
            </button>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             TAB 1: DAFTAR ANALISIS
        ══════════════════════════════════════════════════════════════ -->
        <div v-show="activeTab === 'analyses'" class="space-y-4">

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative flex-1 min-w-[200px] max-w-xs">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-muted-foreground"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                    </svg>
                    <input v-model="search" @input="onSearch" type="text"
                           :placeholder="lang === 'en' ? 'Search batch code...' : 'Cari kode batch...'"
                           class="w-full pl-9 pr-4 h-9 rounded-xl text-sm bg-card border border-border text-foreground outline-none focus:ring-2 focus:ring-primary/20"/>
                </div>
                <select v-model="validated" @change="applyFilters"
                        class="h-9 px-3 rounded-xl text-sm bg-card border border-border text-foreground">
                    <option value="">{{ lang === 'en' ? 'All Status' : 'Semua Status' }}</option>
                    <option value="1">{{ lang === 'en' ? 'Validated' : 'Tervalidasi' }}</option>
                    <option value="0">{{ lang === 'en' ? 'Pending' : 'Pending Validasi' }}</option>
                </select>
            </div>

            <!-- Table -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden">
                <EmptyState v-if="!analyses?.data?.length"
                    icon="🔬"
                    :title="lang === 'en' ? 'No lab analyses yet' : 'Belum ada analisis lab'"
                    :description="lang === 'en'
                        ? 'Open a batch in project_plant stage and click \'Add Lab Analysis\'.'
                        : 'Buka batch di stage project_plant dan klik \'Tambah Analisis Lab\'.'"
                />

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border bg-muted/30">
                                <th class="text-left px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground whitespace-nowrap">
                                    {{ lang === 'en' ? 'Batch' : 'Batch' }}
                                </th>
                                <!-- Element headers dengan % dan kg -->
                                <th v-for="el in elementColumns" :key="el.key"
                                    class="text-center px-3 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground whitespace-nowrap">
                                    {{ el.label }}<br>
                                    <span class="text-[9px] font-normal opacity-60">% / kg</span>
                                </th>
                                <th class="text-center px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground whitespace-nowrap">
                                    REO Total
                                </th>
                                <th class="text-left px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground whitespace-nowrap">
                                    {{ lang === 'en' ? 'Method' : 'Metode' }}
                                </th>
                                <th class="text-center px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground whitespace-nowrap">
                                    {{ lang === 'en' ? 'Status' : 'Status' }}
                                </th>
                                <th class="text-left px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground whitespace-nowrap">
                                    {{ lang === 'en' ? 'Date' : 'Tanggal' }}
                                </th>
                                <th class="px-4 py-3.5 w-36"/>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="(analysis, i) in analyses.data" :key="analysis.id"
                                class="group hover:bg-muted/20"
                                :style="`animation: fade-in 0.28s ease-out ${i*35}ms both`">

                                <!-- Batch info -->
                                <td class="px-5 py-4">
                                    <Link :href="`/company/batches/${analysis.batch_id}`"
                                          class="font-mono text-xs font-bold text-foreground hover:text-primary">
                                        {{ analysis.batch_code ?? '—' }}
                                    </Link>
                                    <p class="text-[10px] text-muted-foreground">
                                        {{ analysis.product_code }}
                                        <span v-if="analysis.batch_weight_kg">
                                            · {{ parseFloat(analysis.batch_weight_kg).toFixed(1) }} kg
                                        </span>
                                    </p>
                                    <!-- Stage indicator -->
                                    <span v-if="analysis.is_sent_to_warehouse"
                                          class="text-[9px] font-bold px-1.5 py-0.5 rounded"
                                          style="background:rgba(20,184,166,0.12);color:#0d9488">
                                        ✓ Di Warehouse
                                    </span>
                                </td>

                                <!-- Element columns: % dan kg -->
                                <td v-for="el in elementColumns" :key="el.key"
                                    class="px-3 py-4 text-center">
                                    <div v-if="analysis[el.key] !== null && analysis[el.key] !== undefined">
                                        <p class="text-xs font-semibold text-foreground tabular-nums">
                                            {{ fmtPct(analysis[el.key]) }}
                                        </p>
                                        <p class="text-[10px] text-muted-foreground tabular-nums">
                                            {{ fmtKg(analysis[el.kgKey]) }}
                                        </p>
                                    </div>
                                    <span v-else class="text-xs text-muted-foreground">—</span>
                                </td>

                                <!-- Total REO -->
                                <td class="px-4 py-4 text-center">
                                    <p class="text-sm font-black text-primary tabular-nums">
                                        {{ fmtPct(analysis.total_reo_pct) }}
                                    </p>
                                    <p class="text-[10px] text-muted-foreground tabular-nums">
                                        {{ fmtKg(analysis.total_reo_kg) }}
                                    </p>
                                </td>

                                <!-- Method -->
                                <td class="px-4 py-4">
                                    <span class="text-xs text-muted-foreground">{{ analysis.lab_method ?? '—' }}</span>
                                </td>

                                <!-- Status -->
                                <td class="px-4 py-4 text-center">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-[10px] font-bold"
                                          :class="analysis.is_validated
                                              ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300'
                                              : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300'">
                                        {{ analysis.is_validated
                                            ? (lang === 'en' ? '✓ Validated' : '✓ Tervalidasi')
                                            : (lang === 'en' ? '⏳ Pending' : '⏳ Pending') }}
                                    </span>
                                    <p v-if="analysis.validated_at" class="text-[9px] text-muted-foreground mt-0.5">
                                        {{ analysis.validated_at_diff }}
                                    </p>
                                </td>

                                <!-- Date + analyst -->
                                <td class="px-4 py-4">
                                    <p class="text-[11px] font-semibold text-foreground">{{ analysis.analyzed_at }}</p>
                                    <p class="text-[10px] text-muted-foreground">{{ analysis.analyzed_by }}</p>
                                </td>

                                <!-- Actions -->
                                <td class="px-4 py-4">
                                    <div class="flex flex-col gap-1 items-end">
                                        <!-- Validasi -->
                                        <button
                                            v-if="!analysis.is_validated && canValidate"
                                            @click="validateAnalysis(analysis.id)"
                                            class="px-2.5 py-1.5 rounded-lg text-[10px] font-bold text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 active:scale-95"
                                        >
                                            {{ lang === 'en' ? '✓ Validate' : '✓ Validasi' }}
                                        </button>

                                        <!-- Kirim ke Warehouse (setelah validasi) -->
                                        <button
                                            v-if="analysis.is_validated && !analysis.is_sent_to_warehouse && canSendWarehouse"
                                            @click="sendToWarehouse(analysis.batch_id)"
                                            class="px-2.5 py-1.5 rounded-lg text-[10px] font-bold text-white active:scale-95"
                                            style="background: hsl(var(--primary));"
                                        >
                                            {{ lang === 'en' ? '→ Warehouse' : '→ Warehouse' }}
                                        </button>

                                        <!-- Lihat Batch -->
                                        <Link :href="`/company/batches/${analysis.batch_id}`"
                                              class="px-2.5 py-1.5 rounded-lg text-[10px] font-semibold text-muted-foreground hover:bg-muted hover:text-foreground active:scale-95">
                                            {{ lang === 'en' ? 'View Batch' : 'Lihat Batch' }}
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="analyses?.last_page > 1"
                     class="flex items-center justify-between px-5 py-4 border-t border-border">
                    <p class="text-sm text-muted-foreground">
                        {{ lang === 'en' ? 'Showing' : 'Menampilkan' }}
                        <span class="font-semibold text-foreground">{{ analyses.from }}–{{ analyses.to }}</span>
                        {{ lang === 'en' ? 'of' : 'dari' }}
                        <span class="font-semibold text-foreground">{{ analyses.total }}</span>
                    </p>
                    <div class="flex gap-1">
                        <Link v-if="analyses.prev_page_url" :href="analyses.prev_page_url"
                              class="px-3 py-1.5 rounded-lg text-xs border border-border hover:bg-muted preserve-scroll">
                            ←
                        </Link>
                        <Link v-if="analyses.next_page_url" :href="analyses.next_page_url"
                              class="px-3 py-1.5 rounded-lg text-xs border border-border hover:bg-muted preserve-scroll">
                            →
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════════
             TAB 2: STOK UNSUR LTJ — Jawaban "di mana data storage LTJ?"
        ══════════════════════════════════════════════════════════════ -->
        <div v-show="activeTab === 'stock'" class="space-y-5">

            <!-- Info banner -->
            <div class="rounded-2xl border border-primary/20 p-4"
                 style="background: hsl(var(--primary)/0.04)">
                <div class="flex items-start gap-3">
                    <span class="text-xl flex-shrink-0">ℹ️</span>
                    <div>
                        <p class="text-sm font-bold text-foreground">
                            {{ lang === 'en' ? 'About LTJ Element Storage' : 'Tentang Penyimpanan Unsur LTJ' }}
                        </p>
                        <p class="text-xs text-muted-foreground mt-1 leading-relaxed">
                            {{ lang === 'en'
                                ? 'LTJ elements (La, Ce, Pr, Nd, Y) are not stored as separate physical batches. They remain within the Monazite (MON) batch in the Warehouse. This page shows the calculated content (in kg) of each element across all validated analyses.'
                                : 'Unsur LTJ (La, Ce, Pr, Nd, Y) tidak disimpan sebagai batch fisik terpisah. Unsur-unsur ini tetap berada dalam batch Monasit (MON) di Warehouse. Halaman ini menampilkan kandungan terhitung (dalam kg) dari setiap unsur di semua analisis tervalidasi.' }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- 5 element detail cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                <div v-for="el in ltj_element_stock" :key="el.element"
                     class="rounded-2xl border border-border bg-card p-5 space-y-4">

                    <!-- Header -->
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-xl flex flex-col items-center justify-center text-white flex-shrink-0"
                             :style="`background: ${el.color}`">
                            <span class="text-xs font-black leading-none">{{ el.element }}</span>
                            <span class="text-[8px] leading-none opacity-70">{{ el.atomic_number }}</span>
                        </div>
                        <div>
                            <p class="font-heading text-sm font-bold text-foreground">
                                {{ lang === 'en' ? el.name_en : el.name_id }}
                            </p>
                            <p class="text-xs text-muted-foreground">{{ el.element }} · No. Atom {{ el.atomic_number }}</p>
                        </div>
                    </div>

                    <!-- Total kg — main metric -->
                    <div class="rounded-xl p-4 text-center" :style="`background: ${el.bg}`">
                        <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1">
                            {{ lang === 'en' ? 'Total Stock' : 'Total Stok' }}
                        </p>
                        <p class="font-heading text-3xl font-black tabular-nums" :style="`color: ${el.color}`">
                            {{ el.total_kg.toFixed(3) }}
                        </p>
                        <p class="text-sm font-semibold text-muted-foreground">kg</p>
                    </div>

                    <!-- Stats baris -->
                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-xl border border-border bg-muted/20 p-3 text-center">
                            <p class="text-[10px] text-muted-foreground">Avg %</p>
                            <p class="text-sm font-bold tabular-nums text-foreground">{{ el.avg_pct.toFixed(2) }}%</p>
                        </div>
                        <div class="rounded-xl border border-border bg-muted/20 p-3 text-center">
                            <p class="text-[10px] text-muted-foreground">{{ lang === 'en' ? 'From' : 'Dari' }}</p>
                            <p class="text-sm font-bold text-foreground">{{ ltj_aggregate?.batch_count ?? 0 }} MON</p>
                        </div>
                    </div>

                    <!-- Application info -->
                    <div class="border-t border-border pt-3">
                        <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider mb-1">
                            {{ lang === 'en' ? 'Applications' : 'Aplikasi Industri' }}
                        </p>
                        <p class="text-xs text-muted-foreground leading-relaxed">
                            {{ lang === 'en' ? el.use_en : el.use }}
                        </p>
                    </div>
                </div>

                <!-- Total summary card -->
                <div class="rounded-2xl border-2 border-primary/30 bg-primary/4 p-5 space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center text-2xl"
                             style="background: hsl(var(--primary)/0.15)">
                            ⚗️
                        </div>
                        <div>
                            <p class="font-heading text-sm font-bold text-foreground">
                                {{ lang === 'en' ? 'Total LTJ' : 'Total LTJ' }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                La + Ce + Pr + Nd + Y
                            </p>
                        </div>
                    </div>

                    <div class="rounded-xl p-4 text-center"
                         style="background: hsl(var(--primary)/0.08)">
                        <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1">
                            {{ lang === 'en' ? 'Grand Total' : 'Total Keseluruhan' }}
                        </p>
                        <p class="font-heading text-3xl font-black text-primary tabular-nums">
                            {{ totalLtjKg }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="rounded-xl border border-primary/20 bg-card p-3 text-center">
                            <p class="text-[10px] text-muted-foreground">Avg REO%</p>
                            <p class="text-sm font-bold text-primary">{{ ltj_aggregate?.avg_reo_pct ?? 0 }}%</p>
                        </div>
                        <div class="rounded-xl border border-primary/20 bg-card p-3 text-center">
                            <p class="text-[10px] text-muted-foreground">Batch MON</p>
                            <p class="text-sm font-bold text-foreground">{{ ltj_aggregate?.batch_count ?? 0 }}</p>
                        </div>
                    </div>

                    <!-- Breakdown mini -->
                    <div class="space-y-1.5 border-t border-primary/20 pt-3">
                        <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {{ lang === 'en' ? 'Breakdown' : 'Rincian' }}
                        </p>
                        <div v-for="el in ltj_element_stock" :key="el.element"
                             class="flex items-center justify-between text-xs">
                            <div class="flex items-center gap-1.5">
                                <div class="w-2 h-2 rounded-full flex-shrink-0" :style="`background: ${el.color}`"/>
                                <span class="text-muted-foreground">{{ el.element }} ({{ lang === 'en' ? el.name_en : el.name_id }})</span>
                            </div>
                            <span class="font-bold tabular-nums" :style="`color: ${el.color}`">
                                {{ el.total_kg.toFixed(3) }} kg
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Monthly trend table -->
            <div v-if="monthly_trend?.length > 0"
                 class="rounded-2xl border border-border bg-card overflow-hidden">
                <div class="px-5 py-4 border-b border-border">
                    <h3 class="font-heading text-sm font-bold text-foreground">
                        {{ lang === 'en' ? 'Monthly Analysis Trend (Last 6 Months)' : 'Tren Analisis Bulanan (6 Bulan Terakhir)' }}
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border bg-muted/30">
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ lang === 'en' ? 'Month' : 'Bulan' }}
                                </th>
                                <th class="text-center px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ lang === 'en' ? 'Analyses' : 'Analisis' }}
                                </th>
                                <th class="text-right px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ lang === 'en' ? 'Total LTJ (kg)' : 'Total LTJ (kg)' }}
                                </th>
                                <th class="text-right px-5 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ lang === 'en' ? 'Avg REO%' : 'Avg REO%' }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="(row, i) in monthly_trend" :key="row.month"
                                class="hover:bg-muted/20"
                                :style="`animation: fade-in 0.22s ease-out ${i*30}ms both`">
                                <td class="px-5 py-3 text-sm font-semibold text-foreground">{{ row.month }}</td>
                                <td class="px-4 py-3 text-center text-sm text-foreground">{{ row.count }}</td>
                                <td class="px-4 py-3 text-right font-mono text-sm font-bold text-primary">
                                    {{ row.total_ltj_kg.toFixed(2) }} kg
                                </td>
                                <td class="px-5 py-3 text-right font-mono text-sm text-muted-foreground">
                                    {{ row.avg_reo_pct.toFixed(2) }}%
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Empty state jika belum ada analisis tervalidasi -->
            <div v-if="!ltj_element_stock?.some(e => e.total_kg > 0)"
                 class="rounded-2xl border border-border bg-card p-12 text-center">
                <div class="text-4xl mb-3">⚗️</div>
                <p class="font-heading text-base font-bold text-foreground mb-1">
                    {{ lang === 'en' ? 'No validated LTJ analyses yet' : 'Belum ada analisis LTJ yang tervalidasi' }}
                </p>
                <p class="text-sm text-muted-foreground">
                    {{ lang === 'en'
                        ? 'Stock data will appear after Monazite (MON) batches have been analyzed and validated.'
                        : 'Data stok akan muncul setelah batch Monasit (MON) dianalisis dan divalidasi.' }}
                </p>
            </div>
        </div>

    </div>
</AppLayout>
</template>

<style scoped>
@keyframes fade-in {
    from { opacity: 0; transform: translateY(5px); }
    to   { opacity: 1; transform: translateY(0); }
}
.animate-fade-in { animation: fade-in 0.28s ease-out both; }
.delay-100 { animation-delay: 100ms; }
.delay-150 { animation-delay: 150ms; }
</style>