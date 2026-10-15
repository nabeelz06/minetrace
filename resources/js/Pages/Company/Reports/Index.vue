<script setup>
/**
 * Reports/Index.vue — Generator & Download Laporan.
 *
 * FIX DOWNLOAD (Item 5):
 * Inertia form.post() intercepts HTTP responses termasuk file binary.
 * Solusi: Gunakan window.open() dengan GET request ke /company/reports/download
 * → Browser mendeteksi Content-Disposition: attachment → otomatis download.
 *
 * TIDAK menggunakan router.post() atau useForm.post() untuk download file.
 */
import { ref, computed } from 'vue'
import { Head, usePage }  from '@inertiajs/vue3'
import AppLayout          from '@/Layouts/AppLayout.vue'
import { useI18n }        from '@/composables/core/useI18n.js'

const { lang } = useI18n()
const page      = usePage()

const props = defineProps({
    available_reports: { type: Array, default: () => [] },
    recent_months:     { type: Array, default: () => [] },
    quick_stats:       Object,
})

// ═══════════════════════════════════════════════════════════════════════
// FORM STATE
// ═══════════════════════════════════════════════════════════════════════
const selectedType   = ref('')
const selectedFormat = ref('excel')
const dateFrom       = ref('')
const dateTo         = ref('')
const selectedMonth  = ref('')
const quickPeriod    = ref('')
const isDownloading  = ref(false)
const downloadError  = ref('')

// ─── Auto set date range dari quick period ─────────────────────────────
function setQuickPeriod(period) {
    quickPeriod.value = period
    const now   = new Date()
    const today = now.toISOString().slice(0, 10)

    if (period === 'this_month') {
        const start = new Date(now.getFullYear(), now.getMonth(), 1)
        dateFrom.value = start.toISOString().slice(0, 10)
        dateTo.value   = today
    } else if (period === 'last_month') {
        const start = new Date(now.getFullYear(), now.getMonth() - 1, 1)
        const end   = new Date(now.getFullYear(), now.getMonth(), 0)
        dateFrom.value = start.toISOString().slice(0, 10)
        dateTo.value   = end.toISOString().slice(0, 10)
    } else if (period === '3_months') {
        const start = new Date(now.getFullYear(), now.getMonth() - 2, 1)
        dateFrom.value = start.toISOString().slice(0, 10)
        dateTo.value   = today
    } else if (period === 'this_year') {
        dateFrom.value = `${now.getFullYear()}-01-01`
        dateTo.value   = today
    }
}

// ─── Set dari pilihan bulan ────────────────────────────────────────────
function setFromMonth(monthValue) {
    if (!monthValue) return
    const [year, month] = monthValue.split('-').map(Number)
    const start = new Date(year, month - 1, 1)
    const end   = new Date(year, month, 0)
    dateFrom.value   = start.toISOString().slice(0, 10)
    dateTo.value     = end.toISOString().slice(0, 10)
    quickPeriod.value = ''
}

// ─── Validasi form ─────────────────────────────────────────────────────
const isValid = computed(() =>
    selectedType.value &&
    selectedFormat.value &&
    dateFrom.value &&
    dateTo.value &&
    dateFrom.value <= dateTo.value
)

const selectedReport = computed(() =>
    props.available_reports.find(r => r.id === selectedType.value)
)

// ─── Hitung rentang hari ───────────────────────────────────────────────
const dayRange = computed(() => {
    if (!dateFrom.value || !dateTo.value) return 0
    const from = new Date(dateFrom.value)
    const to   = new Date(dateTo.value)
    return Math.round((to - from) / (1000 * 60 * 60 * 24)) + 1
})

const isRangeValid = computed(() => dayRange.value > 0 && dayRange.value <= 366)

// ─── DOWNLOAD — via window.open() (FIX utama item 5) ──────────────────
async function downloadReport() {
    if (!isValid.value || !isRangeValid.value) return

    downloadError.value = ''
    isDownloading.value = true

    const params = new URLSearchParams({
        report_type: selectedType.value,
        format:      selectedFormat.value,
        date_from:   dateFrom.value,
        date_to:     dateTo.value,
    })

    const url = `/company/reports/download?${params.toString()}`

    try {
        // Menggunakan fetch untuk mengecek response sebelum trigger download
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        })

        if (!response.ok) {
            const text = await response.text()
            let msg = `HTTP ${response.status}`
            try {
                const json = JSON.parse(text)
                msg = json.message ?? json.error ?? msg
            } catch { /* not JSON */ }
            downloadError.value = lang.value === 'en'
                ? `Failed to generate report: ${msg}`
                : `Gagal generate laporan: ${msg}`
            return
        }

        // Trigger download: buat blob dari response
        const blob     = await response.blob()
        const filename = getFilename(response)
        const blobUrl  = URL.createObjectURL(blob)
        const anchor   = document.createElement('a')
        anchor.href    = blobUrl
        anchor.download = filename
        anchor.style.display = 'none'
        document.body.appendChild(anchor)
        anchor.click()
        document.body.removeChild(anchor)
        URL.revokeObjectURL(blobUrl)

    } catch (err) {
        downloadError.value = lang.value === 'en'
            ? `Network error: ${err.message}`
            : `Error jaringan: ${err.message}`
    } finally {
        isDownloading.value = false
    }
}

// ─── Extract filename dari response headers ────────────────────────────
function getFilename(response) {
    const disposition = response.headers.get('Content-Disposition') ?? ''
    const match = disposition.match(/filename[^;=\n]*=([^;\n]*)/)
    if (match) return match[1].replace(/['"]/g, '').trim()
    // Fallback filename
    const ext = selectedFormat.value === 'excel' ? 'xlsx' : 'pdf'
    return `MINETRACE-Report-${selectedType.value}-${dateFrom.value}-${dateTo.value}.${ext}`
}

// ─── Color map per report type ─────────────────────────────────────────
const colorMap = {
    batch_traceability: { bg: 'bg-blue-50 dark:bg-blue-950/20',    text: 'text-blue-700 dark:text-blue-300',    border: 'border-blue-200 dark:border-blue-800/40',    selected: 'border-blue-500 bg-blue-50 dark:bg-blue-950/20' },
    mass_balance:       { bg: 'bg-amber-50 dark:bg-amber-950/20',  text: 'text-amber-700 dark:text-amber-300',  border: 'border-amber-200 dark:border-amber-800/40',  selected: 'border-amber-500 bg-amber-50 dark:bg-amber-950/20' },
    export_summary:     { bg: 'bg-cyan-50 dark:bg-cyan-950/20',    text: 'text-cyan-700 dark:text-cyan-300',    border: 'border-cyan-200 dark:border-cyan-800/40',    selected: 'border-cyan-500 bg-cyan-50 dark:bg-cyan-950/20' },
    lab_analysis:       { bg: 'bg-violet-50 dark:bg-violet-950/20',text: 'text-violet-700 dark:text-violet-300',border: 'border-violet-200 dark:border-violet-800/40',selected: 'border-violet-500 bg-violet-50 dark:bg-violet-950/20' },
    monthly_summary:    { bg: 'bg-emerald-50 dark:bg-emerald-950/20',text:'text-emerald-700 dark:text-emerald-300',border:'border-emerald-200 dark:border-emerald-800/40',selected:'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/20' },
}
function getColor(type, key) {
    return colorMap[type]?.[key] ?? (key === 'bg' ? 'bg-card' : 'text-foreground')
}
</script>

<template>
<AppLayout>
    <Head :title="lang === 'en' ? 'Reports' : 'Laporan'"/>
    <template #header>{{ lang === 'en' ? 'Reports' : 'Laporan' }}</template>

    <div class="space-y-6">

        <!-- ══════════════════════════════════════════════════════════
             HEADER
        ══════════════════════════════════════════════════════════ -->
        <div class="flex items-start justify-between gap-4 animate-fade-in">
            <div>
                <h1 class="font-heading text-2xl font-bold text-foreground tracking-tight">
                    {{ lang === 'en' ? 'Report Generator' : 'Generator Laporan' }}
                </h1>
                <p class="text-sm text-muted-foreground mt-0.5">
                    {{ lang === 'en'
                        ? 'Generate and download reports in Excel or PDF format — industry standard output'
                        : 'Generate dan unduh laporan dalam format Excel atau PDF — output standar industri' }}
                </p>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             QUICK STATS
        ══════════════════════════════════════════════════════════ -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div v-for="(item, i) in [
                { label_id:'Total Batch',       label_en:'Total Batches',      value: quick_stats?.total_batches,       icon:'📦' },
                { label_id:'Total Ekspor',      label_en:'Total Exports',      value: quick_stats?.total_exports,       icon:'🚢' },
                { label_id:'Analisis Lab',      label_en:'Lab Analyses',       value: quick_stats?.total_labs,          icon:'🔬' },
                { label_id:'Batch Bulan Ini',   label_en:'Batches This Month', value: quick_stats?.this_month_batches,  icon:'📊' },
            ]" :key="item.label_id"
                 class="rounded-2xl border border-border bg-card p-4 animate-fade-in"
                 :style="`animation-delay:${i*50}ms`">
                <div class="flex items-start justify-between mb-2">
                    <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider leading-tight">
                        {{ lang === 'en' ? item.label_en : item.label_id }}
                    </p>
                    <span class="text-xl">{{ item.icon }}</span>
                </div>
                <p class="font-heading text-3xl font-bold text-foreground tabular-nums">{{ item.value ?? 0 }}</p>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             MAIN GENERATOR
        ══════════════════════════════════════════════════════════ -->
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

            <!-- LEFT: Report Type Selection (col-span-2) -->
            <div class="xl:col-span-2 space-y-5">

                <!-- STEP 1: Jenis Laporan -->
                <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-100">
                    <div class="px-5 py-4 border-b border-border flex items-center gap-2">
                        <div class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-black text-white"
                             style="background:hsl(var(--primary))">1</div>
                        <h3 class="font-heading text-sm font-bold text-foreground">
                            {{ lang === 'en' ? 'Select Report Type' : 'Pilih Jenis Laporan' }}
                        </h3>
                    </div>
                    <div class="p-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <button
                            v-for="report in available_reports"
                            :key="report.id"
                            type="button"
                            @click="selectedType = report.id"
                            class="flex items-start gap-3 p-4 rounded-xl border-2 text-left transition-all duration-150 hover:-translate-y-0.5 active:scale-[0.98]"
                            :class="selectedType === report.id
                                ? getColor(report.id, 'selected') + ' shadow-sm'
                                : 'border-border bg-card hover:bg-muted/30'"
                        >
                            <span class="text-2xl flex-shrink-0 mt-0.5">{{ report.icon }}</span>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-foreground leading-tight">
                                    {{ lang === 'en' ? report.name_en : report.name }}
                                </p>
                                <p class="text-[11px] text-muted-foreground mt-1 leading-relaxed">
                                    {{ report.description }}
                                </p>
                                <div class="flex items-center gap-1.5 mt-2">
                                    <span v-for="fmt in report.formats" :key="fmt"
                                          class="text-[9px] font-bold px-1.5 py-0.5 rounded uppercase"
                                          :class="fmt === 'excel'
                                              ? 'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300'
                                              : 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300'">
                                        {{ fmt === 'excel' ? '📊 Excel' : '📄 PDF' }}
                                    </span>
                                </div>
                            </div>
                            <!-- Selected indicator -->
                            <div v-if="selectedType === report.id"
                                 class="w-5 h-5 rounded-full flex items-center justify-center flex-shrink-0"
                                 :class="getColor(report.id, 'text')"
                                 :style="`background:${getColor(report.id,'bg')}`">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                        </button>
                    </div>
                </div>

                <!-- STEP 2: Periode -->
                <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-150">
                    <div class="px-5 py-4 border-b border-border flex items-center gap-2">
                        <div class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-black text-white"
                             style="background:hsl(var(--primary))">2</div>
                        <h3 class="font-heading text-sm font-bold text-foreground">
                            {{ lang === 'en' ? 'Select Period' : 'Pilih Periode' }}
                        </h3>
                        <span v-if="dayRange > 0 && isRangeValid"
                              class="ml-auto text-[10px] font-bold px-2 py-0.5 rounded-full"
                              style="background:hsl(var(--primary)/0.1);color:hsl(var(--primary))">
                            {{ dayRange }} {{ lang === 'en' ? 'days' : 'hari' }}
                        </span>
                        <span v-if="dayRange > 366"
                              class="ml-auto text-[10px] font-bold px-2 py-0.5 rounded-full bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300">
                            ⚠ Max 366 {{ lang === 'en' ? 'days' : 'hari' }}
                        </span>
                    </div>

                    <div class="p-5 space-y-5">
                        <!-- Quick select -->
                        <div class="space-y-2">
                            <p class="text-xs font-semibold text-muted-foreground">
                                {{ lang === 'en' ? 'Quick Select' : 'Pilih Cepat' }}
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="p in [
                                    { key:'this_month', id:'Bulan Ini',  en:'This Month' },
                                    { key:'last_month', id:'Bulan Lalu', en:'Last Month' },
                                    { key:'3_months',   id:'3 Bulan',    en:'3 Months' },
                                    { key:'this_year',  id:'Tahun Ini',  en:'This Year' },
                                ]" :key="p.key"
                                        type="button"
                                        @click="setQuickPeriod(p.key)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold border transition-all active:scale-[0.97]"
                                        :class="quickPeriod === p.key
                                            ? 'border-primary bg-primary/10 text-primary'
                                            : 'border-border bg-card text-muted-foreground hover:bg-muted hover:text-foreground'">
                                    {{ lang === 'en' ? p.en : p.id }}
                                </button>
                            </div>
                        </div>

                        <!-- By month -->
                        <div class="space-y-2">
                            <p class="text-xs font-semibold text-muted-foreground">
                                {{ lang === 'en' ? 'By Month' : 'Per Bulan' }}
                            </p>
                            <select v-model="selectedMonth"
                                    @change="setFromMonth(selectedMonth)"
                                    class="w-full h-9 px-3 rounded-xl border border-border bg-background text-sm text-foreground">
                                <option value="">{{ lang === 'en' ? 'Select a month...' : 'Pilih bulan...' }}</option>
                                <option v-for="m in recent_months" :key="m.value" :value="m.value">
                                    {{ lang === 'en' ? m.label_en : m.label }}
                                </option>
                            </select>
                        </div>

                        <!-- Custom range -->
                        <div class="space-y-2">
                            <p class="text-xs font-semibold text-muted-foreground">
                                {{ lang === 'en' ? 'Custom Range' : 'Rentang Kustom' }}
                            </p>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="space-y-1">
                                    <label class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                                        {{ lang === 'en' ? 'From' : 'Dari Tanggal' }}
                                    </label>
                                    <input v-model="dateFrom" type="date"
                                           class="w-full h-9 px-3 rounded-xl border border-border bg-background text-sm text-foreground outline-none focus:border-primary focus:ring-1 focus:ring-primary/20"/>
                                </div>
                                <div class="space-y-1">
                                    <label class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                                        {{ lang === 'en' ? 'To' : 'Hingga Tanggal' }}
                                    </label>
                                    <input v-model="dateTo" type="date"
                                           :min="dateFrom"
                                           class="w-full h-9 px-3 rounded-xl border border-border bg-background text-sm text-foreground outline-none focus:border-primary focus:ring-1 focus:ring-primary/20"/>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 3: Format -->
                <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-200">
                    <div class="px-5 py-4 border-b border-border flex items-center gap-2">
                        <div class="w-6 h-6 rounded-full flex items-center justify-center text-[10px] font-black text-white"
                             style="background:hsl(var(--primary))">3</div>
                        <h3 class="font-heading text-sm font-bold text-foreground">
                            {{ lang === 'en' ? 'Select Format' : 'Pilih Format' }}
                        </h3>
                    </div>
                    <div class="p-4 grid grid-cols-2 gap-3">
                        <!-- Excel -->
                        <button
                            type="button"
                            :disabled="selectedReport && !selectedReport.formats?.includes('excel')"
                            @click="selectedFormat = 'excel'"
                            class="flex items-start gap-3 p-4 rounded-xl border-2 text-left transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                            :class="selectedFormat === 'excel'
                                ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950/20 shadow-sm'
                                : 'border-border hover:bg-muted/30'">
                            <div class="w-10 h-10 rounded-xl bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center flex-shrink-0">
                                <span class="text-xl">📊</span>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-bold text-foreground">Excel (.xlsx)</p>
                                <p class="text-[11px] text-muted-foreground mt-0.5">
                                    {{ lang === 'en' ? 'Best for data analysis & filtering' : 'Terbaik untuk analisis & filter data' }}
                                </p>
                                <div v-if="selectedFormat === 'excel'"
                                     class="mt-1.5 text-[10px] font-bold text-emerald-700 dark:text-emerald-300">
                                    ✓ {{ lang === 'en' ? 'Selected' : 'Dipilih' }}
                                </div>
                            </div>
                        </button>

                        <!-- PDF -->
                        <button
                            type="button"
                            :disabled="selectedReport && !selectedReport.formats?.includes('pdf')"
                            @click="selectedFormat = 'pdf'"
                            class="flex items-start gap-3 p-4 rounded-xl border-2 text-left transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                            :class="selectedFormat === 'pdf'
                                ? 'border-red-500 bg-red-50 dark:bg-red-950/10 shadow-sm'
                                : 'border-border hover:bg-muted/30'">
                            <div class="w-10 h-10 rounded-xl bg-red-100 dark:bg-red-900/30 flex items-center justify-center flex-shrink-0">
                                <span class="text-xl">📄</span>
                            </div>
                            <div class="flex-1">
                                <p class="text-sm font-bold text-foreground">PDF</p>
                                <p class="text-[11px] text-muted-foreground mt-0.5">
                                    {{ lang === 'en' ? 'Best for printing & archiving' : 'Terbaik untuk cetak & arsip' }}
                                </p>
                                <div v-if="selectedFormat === 'pdf'"
                                     class="mt-1.5 text-[10px] font-bold text-red-700 dark:text-red-300">
                                    ✓ {{ lang === 'en' ? 'Selected' : 'Dipilih' }}
                                </div>
                            </div>
                        </button>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Summary + Download -->
            <div class="space-y-5">

                <!-- Report Summary Card -->
                <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-100 sticky top-6">
                    <div class="px-5 py-4 border-b border-border">
                        <h3 class="font-heading text-sm font-bold text-foreground">
                            {{ lang === 'en' ? 'Report Summary' : 'Ringkasan Laporan' }}
                        </h3>
                    </div>

                    <div class="p-5 space-y-4">
                        <!-- Type -->
                        <div class="space-y-1">
                            <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                                {{ lang === 'en' ? 'Type' : 'Jenis' }}
                            </p>
                            <div v-if="selectedReport" class="flex items-center gap-2">
                                <span class="text-base">{{ selectedReport.icon }}</span>
                                <p class="text-sm font-bold text-foreground leading-tight">
                                    {{ lang === 'en' ? selectedReport.name_en : selectedReport.name }}
                                </p>
                            </div>
                            <p v-else class="text-sm text-muted-foreground italic">
                                {{ lang === 'en' ? 'Not selected' : 'Belum dipilih' }}
                            </p>
                        </div>

                        <!-- Period -->
                        <div class="space-y-1">
                            <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                                {{ lang === 'en' ? 'Period' : 'Periode' }}
                            </p>
                            <p v-if="dateFrom && dateTo" class="text-sm font-semibold text-foreground">
                                {{ new Date(dateFrom).toLocaleDateString(lang==='en'?'en-US':'id-ID', {day:'2-digit',month:'short',year:'numeric'}) }}
                                —
                                {{ new Date(dateTo).toLocaleDateString(lang==='en'?'en-US':'id-ID', {day:'2-digit',month:'short',year:'numeric'}) }}
                            </p>
                            <p v-else class="text-sm text-muted-foreground italic">
                                {{ lang === 'en' ? 'Not selected' : 'Belum dipilih' }}
                            </p>
                            <p v-if="dayRange > 0 && isRangeValid" class="text-[11px] text-muted-foreground">
                                {{ dayRange }} {{ lang === 'en' ? 'days of data' : 'hari data' }}
                            </p>
                            <p v-if="dayRange > 366" class="text-[11px] text-red-500 font-semibold">
                                ⚠ {{ lang === 'en' ? 'Range exceeds 1 year maximum' : 'Rentang melebihi maksimum 1 tahun' }}
                            </p>
                        </div>

                        <!-- Format -->
                        <div class="space-y-1">
                            <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                                {{ lang === 'en' ? 'Format' : 'Format' }}
                            </p>
                            <span class="inline-flex items-center gap-1.5 text-sm font-bold"
                                  :class="selectedFormat === 'excel' ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400'">
                                {{ selectedFormat === 'excel' ? '📊 Excel (.xlsx)' : '📄 PDF' }}
                            </span>
                        </div>

                        <!-- Divider -->
                        <div class="border-t border-border"/>

                        <!-- Error message -->
                        <div v-if="downloadError"
                             class="flex items-start gap-2 p-3 rounded-xl border border-red-200 dark:border-red-800/40 bg-red-50 dark:bg-red-950/10">
                            <span class="text-sm flex-shrink-0">⚠️</span>
                            <p class="text-xs text-red-700 dark:text-red-300">{{ downloadError }}</p>
                        </div>

                        <!-- DOWNLOAD BUTTON -->
                        <button
                            type="button"
                            @click="downloadReport"
                            :disabled="!isValid || !isRangeValid || isDownloading"
                            class="w-full h-12 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                            :class="isValid && isRangeValid && !isDownloading
                                ? 'hover:shadow-lg hover:shadow-primary/25 hover:-translate-y-0.5 active:scale-[0.98]'
                                : ''"
                            style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));"
                        >
                            <svg v-if="isDownloading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
                            </svg>
                            {{ isDownloading
                                ? (lang === 'en' ? 'Generating report...' : 'Generating laporan...')
                                : (lang === 'en' ? 'Download Report' : 'Unduh Laporan') }}
                        </button>

                        <!-- Validation hints -->
                        <div class="space-y-1.5">
                            <div v-for="hint in [
                                { check: !!selectedType,                text_id:'✓ Jenis laporan dipilih',    text_en:'✓ Report type selected' },
                                { check: !!dateFrom && !!dateTo,        text_id:'✓ Periode dipilih',          text_en:'✓ Period selected' },
                                { check: !!selectedFormat,              text_id:'✓ Format dipilih',           text_en:'✓ Format selected' },
                                { check: isRangeValid,                  text_id:'✓ Rentang tanggal valid',    text_en:'✓ Date range valid' },
                            ]" :key="hint.text_id"
                                 class="flex items-center gap-2 text-[11px]"
                                 :class="hint.check ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'">
                                <div class="w-3.5 h-3.5 rounded-full flex items-center justify-center flex-shrink-0"
                                     :class="hint.check ? 'bg-emerald-100 dark:bg-emerald-900/30' : 'bg-muted'">
                                    <svg v-if="hint.check" class="w-2.5 h-2.5 text-emerald-600 dark:text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                    <div v-else class="w-1.5 h-1.5 rounded-full bg-muted-foreground/30"/>
                                </div>
                                {{ lang === 'en' ? hint.text_en : hint.text_id }}
                            </div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div class="px-5 py-4 border-t border-border bg-muted/20 space-y-2">
                        <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {{ lang === 'en' ? 'Notes' : 'Catatan' }}
                        </p>
                        <div class="space-y-1.5">
                            <p v-for="note in [
                                lang === 'en' ? '📊 Reports generated in real-time from current data.' : '📊 Laporan di-generate real-time dari data terkini.',
                                lang === 'en' ? '⏱ Large date ranges may take a few seconds.'         : '⏱ Rentang besar mungkin membutuhkan beberapa detik.',
                                lang === 'en' ? '🔒 Report access is logged for audit trail.'         : '🔒 Akses laporan dicatat untuk audit trail.',
                            ]" :key="note" class="text-[11px] text-muted-foreground">{{ note }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</AppLayout>
</template>

<style scoped>
@keyframes fade-in { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
.animate-fade-in { animation: fade-in 0.28s ease-out both; }
.delay-100 { animation-delay: 100ms; }
.delay-150 { animation-delay: 150ms; }
.delay-200 { animation-delay: 200ms; }
</style>