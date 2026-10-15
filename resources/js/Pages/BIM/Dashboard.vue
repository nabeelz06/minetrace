<script setup>
import AppIcon from '@/components/app/AppIcon.vue'
import { computed }     from 'vue'
import { Head, Link }   from '@inertiajs/vue3'
import AppLayout        from '@/Layouts/AppLayout.vue'
import { useI18n }      from '@/composables/core/useI18n.js'

const { t, lang } = useI18n()

const props = defineProps({
    overview:          Object,
    company_statuses:  Array,
    combined_trend:    Array,
    export_monitor:    Array,
    alert_monitor:     Array,
})

function formatWeight(kg) {
    if (!kg && kg !== 0) return '—'
    if (kg >= 1000000) return `${(kg/1000000).toFixed(2)} kT`
    if (kg >= 1000)    return `${(kg/1000).toFixed(2)} ton`
    return `${parseFloat(kg).toLocaleString('id-ID', { maximumFractionDigits: 0 })} kg`
}

function stageIcon(s) {
    const m = { lab_kimia:'flask-conical', receiving:'package-open', wet_process:'droplets', dry_process:'flame', project_plant:'microscope', warehouse:'warehouse', smelter:'zap', exported:'ship' }
    return m[s] ?? 'package'
}

// Chart: Combined trend
const chartWidth = 600, chartHeight = 140
const chartPadL = 36, chartPadR = 16, chartPadT = 12, chartPadB = 24

function getTrendPath(key) {
    const data = props.combined_trend ?? []
    if (!data.length) return ''
    const maxVal = Math.max(...data.map(d => d.total ?? 0), 1)
    const w = chartWidth - chartPadL - chartPadR
    const h = chartHeight - chartPadT - chartPadB
    const stepX = w / (data.length - 1)

    return data.map((d, i) => {
        const x = chartPadL + i * stepX
        const y = chartPadT + h - ((d[key] ?? d.total ?? 0) / maxVal) * h
        return `${i === 0 ? 'M' : 'L'} ${x} ${y}`
    }).join(' ')
}

const companies = computed(() =>
    props.company_statuses?.map(c => ({
        ...c,
        key: c.code.toLowerCase(),
    })) ?? []
)

const lineColors = ['hsl(var(--primary))', 'hsl(var(--success))', 'hsl(var(--info))', 'hsl(var(--warning))', 'hsl(var(--info))']
</script>

<template>
    <AppLayout>
        <Head :title="lang === 'en' ? 'BIM Monitoring Dashboard' : 'Dashboard Monitoring BIM'" />
        <template #header>{{ lang === 'en' ? 'BIM Monitoring Dashboard' : 'Dashboard Monitoring BIM' }}</template>

        <div class="space-y-6">

            <!-- ─── Header ────────────────────────────────────────────────── -->
            <div class="flex items-center justify-between animate-fade-in">
                <div>
                    <h1 class="text-2xl font-bold text-foreground tracking-tight">
                        {{ lang === 'en' ? 'National LTJ Monitoring' : 'Monitoring LTJ Nasional' }}
                    </h1>
                    <p class="text-sm text-muted-foreground mt-0.5">
                        {{ lang === 'en'
                            ? 'Real-time oversight of all active mining companies'
                            : 'Pengawasan real-time semua perusahaan pertambangan aktif' }}
                    </p>
                </div>
                <div class="flex items-center gap-2 px-3 py-2 rounded-xl border"
                     style="border-color: hsl(var(--border)); background: hsl(var(--muted)/0.5);">
                    <div class="w-2 h-2 rounded-full bg-success animate-pulse"/>
                    <span class="text-xs font-semibold text-foreground">
                        {{ lang === 'en' ? 'Live Data' : 'Data Real-time' }}
                    </span>
                </div>
            </div>

            <!-- ─── System Overview KPIs ──────────────────────────────────── -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
                <div v-for="(item, i) in [
                    { key: 'total_companies',  label: lang==='en'?'Companies':'Perusahaan', value: overview?.total_companies, icon: 'building-2', color: 'text-primary' },
                    { key: 'total_batches',    label: lang==='en'?'Total Batches':'Total Batch', value: overview?.total_batches, icon: 'package', color: 'text-info' },
                    { key: 'active_batches',   label: lang==='en'?'Active Batches':'Batch Aktif', value: overview?.active_batches, icon: 'zap', color: 'text-success' },
                    { key: 'total_exports',    label: lang==='en'?'Total Exports':'Total Ekspor', value: overview?.total_exports, icon: 'ship', color: 'text-info' },
                    { key: 'active_alerts',    label: lang==='en'?'Alerts':'Alert', value: overview?.active_alerts, icon: 'triangle-alert', color: 'text-danger' },
                    { key: 'total_weight',     label: lang==='en'?'Active Weight':'Tonase Aktif', value: formatWeight(overview?.total_weight_kg), icon: 'scale', color: 'text-warning', isFormatted: true },
                ]" :key="item.key"
                     class="rounded-xl border border-border bg-card p-4 text-center animate-fade-in"
                     :style="`animation-delay: ${i*60}ms`">
                    <AppIcon :name="item.icon || 'circle'" :size="28" />
                    <p class="text-xs font-medium text-muted-foreground mb-1">{{ item.label }}</p>
                    <p class="text-xl font-bold" :class="item.color">
                        {{ item.isFormatted ? item.value : (item.value ?? 0).toLocaleString() }}
                    </p>
                </div>
            </div>

            <!-- ─── Company Status Cards ──────────────────────────────────── -->
            <div>
                <h3 class="text-sm font-semibold text-foreground mb-3 animate-fade-in">
                    {{ lang === 'en' ? 'Company Status' : 'Status Perusahaan' }}
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <div
                        v-for="(co, i) in company_statuses"
                        :key="co.id"
                        class="rounded-2xl border border-border bg-card p-5 transition-all duration-200 hover:shadow-md hover:-translate-y-0.5 animate-fade-in"
                        :style="`animation-delay: ${i*80}ms`"
                    >
                        <!-- Header -->
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center text-white text-sm font-bold shadow-sm flex-shrink-0"
                                     :style="`background: ${co.primary_color ?? 'hsl(var(--primary))'};`">
                                    {{ co.code?.slice(0,2) }}
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-foreground truncate">{{ co.name }}</p>
                                    <p class="text-xs text-muted-foreground">{{ co.industry }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <div class="w-2 h-2 rounded-full" :class="co.is_active ? 'bg-success animate-pulse' : 'bg-muted-foreground'"/>
                                <span class="text-xs font-medium" :class="co.is_active ? 'text-success ' : 'text-muted-foreground'">
                                    {{ co.is_active ? (lang==='en'?'Active':'Aktif') : (lang==='en'?'Inactive':'Nonaktif') }}
                                </span>
                            </div>
                        </div>

                        <!-- Stats grid -->
                        <div class="grid grid-cols-3 gap-3 mb-4">
                            <div class="text-center p-2 rounded-lg bg-muted/40">
                                <p class="text-lg font-bold text-foreground">{{ co.active_batches }}</p>
                                <p class="text-xs text-muted-foreground uppercase tracking-wider">Batch</p>
                            </div>
                            <div class="text-center p-2 rounded-lg bg-muted/40">
                                <p class="text-lg font-bold text-foreground">{{ co.exports_month }}</p>
                                <p class="text-xs text-muted-foreground uppercase tracking-wider">
                                    {{ lang === 'en' ? 'Export' : 'Ekspor' }}
                                </p>
                            </div>
                            <div class="text-center p-2 rounded-lg"
                                 :class="co.alerts > 0 ? 'bg-danger-soft ' : 'bg-muted/40'">
                                <p class="text-lg font-bold" :class="co.alerts > 0 ? 'text-danger ' : 'text-foreground'">
                                    {{ co.alerts }}
                                </p>
                                <p class="text-xs text-muted-foreground uppercase tracking-wider">Alert</p>
                            </div>
                        </div>

                        <!-- Weight bar -->
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-muted-foreground">{{ lang === 'en' ? 'Active Weight' : 'Tonase Aktif' }}</span>
                                <span class="font-semibold text-foreground">{{ formatWeight(co.total_weight_kg) }}</span>
                            </div>
                        </div>

                        <!-- Last activity -->
                        <div class="flex items-center justify-between mt-3 pt-3 border-t border-border">
                            <p class="text-xs text-muted-foreground">
                                {{ lang === 'en' ? 'Last activity' : 'Aktivitas terakhir' }}
                            </p>
                            <p class="text-xs font-medium text-foreground">{{ co.last_activity ?? '—' }}</p>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div v-if="!company_statuses?.length"
                         class="rounded-2xl border border-dashed border-border p-8 text-center text-muted-foreground col-span-full">
                        <div class="text-4xl mb-3"></div>
                        <p class="text-sm font-medium">{{ lang === 'en' ? 'No companies onboarded yet.' : 'Belum ada perusahaan terdaftar.' }}</p>
                    </div>
                </div>
            </div>

            <!-- ─── Combined Production Trend ─────────────────────────────── -->
            <div class="rounded-2xl border border-border bg-card p-5 animate-fade-in delay-200">
                <div class="flex items-center justify-between mb-5">
                    <div>
                        <h3 class="text-sm font-semibold text-foreground">
                            {{ lang === 'en' ? 'Combined Production Trend' : 'Tren Produksi Gabungan' }}
                        </h3>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ lang === 'en' ? 'All companies — batch creation per day (30 days)' : 'Semua perusahaan — batch dibuat per hari (30 hari)' }}
                        </p>
                    </div>
                    <!-- Legend -->
                    <div class="flex items-center gap-3 flex-wrap justify-end">
                        <div v-for="(co, i) in companies.slice(0,3)" :key="co.code" class="flex items-center gap-1.5">
                            <div class="w-3 h-0.5 rounded-full" :style="`background: ${lineColors[i]}`"/>
                            <span class="text-xs text-muted-foreground">{{ co.code }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="combined_trend?.length">
                    <svg :viewBox="`0 0 ${chartWidth} ${chartHeight}`" class="w-full" style="height: 140px;">
                        <!-- Grid -->
                        <line v-for="i in 3" :key="i"
                              :x1="chartPadL" :x2="chartWidth - chartPadR"
                              :y1="chartPadT + (chartHeight - chartPadT - chartPadB) / 3 * i"
                              :y2="chartPadT + (chartHeight - chartPadT - chartPadB) / 3 * i"
                              stroke="hsl(var(--border))" stroke-width="1" stroke-dasharray="4,4"/>

                        <!-- Lines per company -->
                        <path v-for="(co, i) in companies.slice(0,3)" :key="co.key"
                              :d="getTrendPath(co.key)"
                              fill="none"
                              :stroke="lineColors[i]"
                              stroke-width="2"
                              stroke-linecap="round"
                              stroke-linejoin="round"
                              opacity="0.85"/>

                        <!-- Total line (dashed) -->
                        <path :d="getTrendPath('total')"
                              fill="none" stroke="hsl(var(--muted-foreground))"
                              stroke-width="1.5" stroke-dasharray="4,3" opacity="0.4"/>

                        <!-- X labels -->
                        <template v-for="(point, i) in combined_trend" :key="`x${i}`">
                            <text v-if="i % 7 === 0"
                                  :x="chartPadL + i * (chartWidth - chartPadL - chartPadR) / (combined_trend.length - 1)"
                                  :y="chartHeight - 4"
                                  text-anchor="middle" font-size="8" fill="hsl(var(--muted-foreground))">
                                {{ point.label }}
                            </text>
                        </template>
                    </svg>
                </div>

                <div v-else class="h-36 flex items-center justify-center text-muted-foreground text-sm">
                    {{ lang === 'en' ? 'No trend data available.' : 'Belum ada data tren.' }}
                </div>
            </div>

            <!-- ─── Row: Export Monitor + Alert Monitor ───────────────────── -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

                <!-- Export Monitor -->
                <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-300">
                    <div class="px-5 py-4 border-b border-border">
                        <h3 class="text-sm font-semibold text-foreground">
                            {{ lang === 'en' ? 'Export Monitor' : 'Monitor Ekspor' }}
                        </h3>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ lang === 'en' ? 'Recent confirmed exports (all companies)' : 'Ekspor terkonfirmasi terbaru (semua perusahaan)' }}
                        </p>
                    </div>

                    <div v-if="!export_monitor?.length" class="py-10 text-center text-muted-foreground text-sm">
                        {{ lang === 'en' ? 'No exports recorded.' : 'Belum ada ekspor tercatat.' }}
                    </div>

                    <div v-else class="divide-y divide-border max-h-72 overflow-y-auto">
                        <div
                            v-for="(exp, i) in export_monitor"
                            :key="exp.id"
                            class="flex items-center gap-3 px-5 py-3.5 hover:bg-muted/20 transition-colors animate-fade-in"
                            :style="`animation-delay: ${i*40}ms`"
                        >
                            <div class="text-lg flex-shrink-0">
                                {{ exp.type === 'international' ? 'globe' : 'warehouse' }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-semibold text-foreground truncate">{{ exp.destination }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ exp.company_code }} · {{ exp.country ?? 'Domestik' }} · {{ exp.batch_count }} batch
                                </p>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <p class="text-xs font-bold text-foreground">{{ formatWeight(exp.weight_kg) }}</p>
                                <p class="text-xs text-muted-foreground">{{ exp.time_diff }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alert Monitor -->
                <div class="rounded-2xl border bg-card overflow-hidden animate-fade-in delay-300"
                     :class="alert_monitor?.length ? 'border-danger/30 ' : 'border-border'">
                    <div class="px-5 py-4 border-b"
                         :class="alert_monitor?.length ? 'border-danger/30 bg-danger-soft ' : 'border-border'">
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-semibold" :class="alert_monitor?.length ? 'text-danger ' : 'text-foreground'">
                                {{ lang === 'en' ? 'Alert Monitor' : 'Monitor Alert' }}
                            </h3>
                            <span v-if="alert_monitor?.length"
                                  class="text-xs px-1.5 py-0.5 rounded font-bold bg-danger-soft text-danger">
                                {{ alert_monitor.length }}
                            </span>
                        </div>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ lang === 'en' ? 'Mass balance discrepancies (all companies)' : 'Discrepancy mass balance (semua perusahaan)' }}
                        </p>
                    </div>

                    <div v-if="!alert_monitor?.length" class="py-10 text-center space-y-2">
                        <div class="text-3xl"></div>
                        <p class="text-sm font-medium text-success">
                            {{ lang === 'en' ? 'No active alerts!' : 'Tidak ada alert aktif!' }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ lang === 'en' ? 'All mass balances are within threshold.' : 'Semua mass balance dalam batas normal.' }}
                        </p>
                    </div>

                    <div v-else class="divide-y divide-danger/30 max-h-72 overflow-y-auto">
                        <div
                            v-for="(alert, i) in alert_monitor"
                            :key="alert.id"
                            class="flex items-center gap-3 px-5 py-3.5 hover:bg-danger-soft transition-colors"
                        >
                            <div class="w-6 h-6 rounded-lg flex items-center justify-center flex-shrink-0 bg-danger-soft">
                                <svg class="w-3.5 h-3.5 text-danger animate-bounce-subtle" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-1.5">
                                    <p class="font-mono text-xs font-semibold text-foreground">{{ alert.batch_code }}</p>
                                    <span class="text-xs text-muted-foreground">· {{ alert.company }}</span>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    {{ stageIcon(alert.stage) }} {{ alert.stage?.replace(/_/g,' ') }} · {{ alert.product }}
                                </p>
                            </div>
                            <div class="text-right flex-shrink-0">
                                <p class="text-xs font-bold text-danger">-{{ alert.loss_pct }}%</p>
                                <p class="text-xs text-muted-foreground">{{ alert.updated_at }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>