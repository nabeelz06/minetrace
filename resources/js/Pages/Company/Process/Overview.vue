<script setup>
import { computed }              from 'vue'
import { Head, Link }            from '@inertiajs/vue3'
import AppLayout                 from '@/Layouts/AppLayout.vue'
import WeightDisplay             from '@/components/shared/WeightDisplay.vue'
import { useI18n }               from '@/composables/core/useI18n.js'

const { lang } = useI18n()

const props = defineProps({
    stage_summaries:    Array,
    recent_checkpoints: Array,
    global_stats:       Object,
})

const colorMap = {
    violet:  { bg: 'bg-violet-50 dark:bg-violet-950/20',  border: 'border-violet-200 dark:border-violet-800/50',  text: 'text-violet-600 dark:text-violet-400',  dot: 'bg-violet-500' },
    blue:    { bg: 'bg-blue-50 dark:bg-blue-950/20',      border: 'border-blue-200 dark:border-blue-800/50',      text: 'text-blue-600 dark:text-blue-400',      dot: 'bg-blue-500' },
    cyan:    { bg: 'bg-cyan-50 dark:bg-cyan-950/20',      border: 'border-cyan-200 dark:border-cyan-800/50',      text: 'text-cyan-600 dark:text-cyan-400',      dot: 'bg-cyan-500' },
    amber:   { bg: 'bg-amber-50 dark:bg-amber-950/20',    border: 'border-amber-200 dark:border-amber-800/50',    text: 'text-amber-600 dark:text-amber-400',    dot: 'bg-amber-500' },
    emerald: { bg: 'bg-emerald-50 dark:bg-emerald-950/20',border: 'border-emerald-200 dark:border-emerald-800/50',text: 'text-emerald-600 dark:text-emerald-400',dot: 'bg-emerald-500' },
    teal:    { bg: 'bg-teal-50 dark:bg-teal-950/20',      border: 'border-teal-200 dark:border-teal-800/50',      text: 'text-teal-600 dark:text-teal-400',      dot: 'bg-teal-500' },
}

function getColor(color) {
    return colorMap[color] ?? colorMap.blue
}

function stageLabel(stage) {
    const labels = {
        lab_kimia: 'Lab Kimia', receiving: 'Receiving', wet_process: 'Wet Process',
        dry_process: 'Dry Process', project_plant: 'Project Plant', warehouse: 'Warehouse',
    }
    return labels[stage] ?? stage
}
</script>

<template>
    <AppLayout>
        <Head :title="lang === 'en' ? 'Production Process' : 'Proses Produksi'" />
        <template #header>{{ lang === 'en' ? 'Production Process Overview' : 'Overview Proses Produksi' }}</template>

        <div class="space-y-6">

            <!-- Header -->
            <div class="animate-fade-in">
                <h1 class="text-2xl font-bold text-foreground tracking-tight">
                    {{ lang === 'en' ? 'Production Process' : 'Proses Produksi' }}
                </h1>
                <p class="text-sm text-muted-foreground mt-0.5">
                    {{ lang === 'en'
                        ? 'Real-time overview of all production stages'
                        : 'Pantau semua tahap proses produksi secara real-time' }}
                </p>
            </div>

            <!-- Global KPI -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div v-for="(item, i) in [
                    { label: lang === 'en' ? 'Active Batches' : 'Batch Aktif',     value: global_stats?.total_active, icon: '📦', color: 'text-primary' },
                    { label: lang === 'en' ? 'Total Weight' : 'Total Berat',       value: null, weight: global_stats?.total_weight, icon: '⚖️', color: 'text-foreground' },
                    { label: lang === 'en' ? 'Active Stages' : 'Stage Aktif',      value: global_stats?.stage_count, icon: '🔄', color: 'text-emerald-600 dark:text-emerald-400' },
                    { label: lang === 'en' ? 'Alert Batches' : 'Batch Alert',      value: global_stats?.total_alerts, icon: '⚠️', color: global_stats?.total_alerts > 0 ? 'text-red-500' : 'text-muted-foreground' },
                ]" :key="item.label"
                     class="rounded-2xl border border-border bg-card p-5 animate-fade-in"
                     :style="`animation-delay: ${i*60}ms`">
                    <div class="flex items-start justify-between mb-2">
                        <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">{{ item.label }}</p>
                        <span class="text-xl">{{ item.icon }}</span>
                    </div>
                    <p class="text-3xl font-bold" :class="item.color">
                        <template v-if="item.weight !== undefined">
                            <WeightDisplay :kg="item.weight" size="lg"/>
                        </template>
                        <template v-else>{{ item.value ?? 0 }}</template>
                    </p>
                </div>
            </div>

            <!-- Pipeline Flow Visualization -->
            <div class="rounded-2xl border border-border bg-card p-5 overflow-x-auto animate-fade-in delay-150">
                <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-4">
                    {{ lang === 'en' ? 'Pipeline Flow' : 'Alur Pipeline' }}
                </p>
                <div class="flex items-center gap-1 min-w-max">
                    <template v-for="(stage, i) in stage_summaries" :key="stage.stage">
                        <!-- Stage bubble -->
                        <Link :href="stage.href"
                              class="flex flex-col items-center gap-1.5 group min-w-[72px]">
                            <div class="relative">
                                <div class="w-14 h-14 rounded-2xl flex flex-col items-center justify-center transition-all duration-200 group-hover:scale-110 group-hover:shadow-md border"
                                     :class="[getColor(stage.color).bg, getColor(stage.color).border]">
                                    <span class="text-xl leading-none">{{ stage.icon }}</span>
                                    <span class="text-xs font-bold mt-0.5" :class="getColor(stage.color).text">{{ stage.count }}</span>
                                </div>
                                <!-- Alert badge -->
                                <div v-if="stage.alert_count > 0"
                                     class="absolute -top-1.5 -right-1.5 min-w-[18px] h-[18px] rounded-full bg-red-500 flex items-center justify-center">
                                    <span class="text-[10px] font-bold text-white px-1">{{ stage.alert_count }}</span>
                                </div>
                                <!-- Active pulse -->
                                <div v-if="stage.count > 0"
                                     class="absolute -bottom-1 left-1/2 -translate-x-1/2 w-1.5 h-1.5 rounded-full animate-pulse"
                                     :class="getColor(stage.color).dot"/>
                            </div>
                            <p class="text-[10px] font-medium text-center text-muted-foreground leading-tight max-w-[72px]">
                                {{ lang === 'en' ? stage.label_en : stage.label }}
                            </p>
                        </Link>
                        <!-- Arrow connector -->
                        <div v-if="i < stage_summaries.length - 1" class="flex-shrink-0 flex flex-col items-center pb-4">
                            <svg class="w-6 h-4 text-border" fill="none" viewBox="0 0 24 16">
                                <path d="M2 8h16M14 4l6 4-6 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <!-- Export terminal -->
                        <template v-if="i === stage_summaries.length - 1">
                            <div class="flex-shrink-0 flex flex-col items-center pb-4">
                                <svg class="w-6 h-4 text-border" fill="none" viewBox="0 0 24 16">
                                    <path d="M2 8h16M14 4l6 4-6 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <div class="flex flex-col items-center gap-1.5 min-w-[72px]">
                                <div class="w-14 h-14 rounded-2xl flex flex-col items-center justify-center border border-dashed border-border bg-muted/30">
                                    <span class="text-xl leading-none">🚢</span>
                                    <span class="text-xs font-bold mt-0.5 text-muted-foreground">
                                        {{ global_stats?.total_exported ?? 0 }}
                                    </span>
                                </div>
                                <p class="text-[10px] font-medium text-center text-muted-foreground">
                                    {{ lang === 'en' ? 'Exported' : 'Diekspor' }}
                                </p>
                            </div>
                        </template>
                    </template>
                </div>
            </div>

            <!-- Stage Cards Grid -->
            <div>
                <h2 class="text-sm font-semibold text-foreground mb-3 flex items-center gap-2">
                    <span>{{ lang === 'en' ? 'Processing Stages' : 'Tahap Proses' }}</span>
                    <span class="text-xs text-muted-foreground font-normal">
                        {{ lang === 'en' ? '— click to view details' : '— klik untuk detail' }}
                    </span>
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <Link
                        v-for="(stage, i) in stage_summaries"
                        :key="stage.stage"
                        :href="stage.href"
                        class="rounded-2xl border p-5 transition-all duration-200 hover:shadow-md hover:-translate-y-0.5 animate-fade-in group"
                        :class="[getColor(stage.color).bg, getColor(stage.color).border]"
                        :style="`animation-delay: ${i*70}ms`"
                    >
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-center gap-2.5">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center text-xl"
                                     :class="getColor(stage.color).bg">
                                    {{ stage.icon }}
                                </div>
                                <div>
                                    <p class="text-sm font-bold text-foreground">
                                        {{ lang === 'en' ? stage.label_en : stage.label }}
                                    </p>
                                    <div class="flex items-center gap-1.5 mt-0.5">
                                        <div class="w-1.5 h-1.5 rounded-full animate-pulse" :class="getColor(stage.color).dot"/>
                                        <span class="text-xs text-muted-foreground">
                                            {{ stage.count }} {{ lang === 'en' ? 'batch' : 'batch' }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div v-if="stage.alert_count > 0"
                                 class="flex items-center gap-1 px-2 py-0.5 rounded-lg bg-red-100 dark:bg-red-900/30">
                                <span class="text-xs font-bold text-red-600 dark:text-red-400">⚠ {{ stage.alert_count }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="rounded-xl bg-white/60 dark:bg-black/20 p-3">
                                <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wide mb-1">
                                    {{ lang === 'en' ? 'Batches' : 'Batch' }}
                                </p>
                                <p class="text-xl font-bold" :class="getColor(stage.color).text">{{ stage.count }}</p>
                            </div>
                            <div class="rounded-xl bg-white/60 dark:bg-black/20 p-3">
                                <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wide mb-1">
                                    {{ lang === 'en' ? 'Weight' : 'Berat' }}
                                </p>
                                <WeightDisplay :kg="stage.total_weight" size="sm" :class="getColor(stage.color).text"/>
                            </div>
                        </div>

                        <div class="mt-3 flex items-center justify-between">
                            <span class="text-xs text-muted-foreground">
                                {{ lang === 'en' ? 'View stage details →' : 'Lihat detail stage →' }}
                            </span>
                            <svg class="w-4 h-4 text-muted-foreground group-hover:translate-x-1 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                            </svg>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- Recent Activity Log -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-300">
                <div class="flex items-center justify-between px-5 py-4 border-b border-border">
                    <h3 class="text-sm font-semibold text-foreground">
                        {{ lang === 'en' ? 'Recent Activity' : 'Aktivitas Terbaru' }}
                    </h3>
                    <span class="text-xs text-muted-foreground">{{ lang === 'en' ? 'Last 30 events' : '30 event terakhir' }}</span>
                </div>

                <div v-if="!recent_checkpoints?.length" class="py-10 text-center text-sm text-muted-foreground">
                    {{ lang === 'en' ? 'No activity recorded yet.' : 'Belum ada aktivitas tercatat.' }}
                </div>

                <div v-else class="divide-y divide-border">
                    <div v-for="(log, i) in recent_checkpoints" :key="log.id"
                         class="flex items-start gap-3 px-5 py-3.5 hover:bg-muted/20 transition-colors animate-fade-in"
                         :style="`animation-delay: ${i*20}ms`">

                        <!-- Icon -->
                        <div class="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5"
                             :class="log.has_discrepancy ? 'bg-red-100 dark:bg-red-900/30' : 'bg-primary/10'">
                            <span class="text-xs">{{ log.has_discrepancy ? '⚠️' : '✅' }}</span>
                        </div>

                        <!-- Content -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <Link :href="`/company/batches/${log.batch_id}`"
                                      class="font-mono text-xs font-bold text-foreground hover:text-primary transition-colors">
                                    {{ log.batch_code }}
                                </Link>
                                <span class="text-xs px-1.5 py-0.5 rounded bg-muted text-muted-foreground">
                                    {{ log.product }}
                                </span>
                                <span class="text-xs text-muted-foreground">
                                    {{ stageLabel(log.stage) }}
                                </span>
                                <span v-if="log.has_discrepancy" class="text-xs font-semibold text-red-600 dark:text-red-400">
                                    ⚠ Susut {{ log.weight_loss_pct }}%
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground mt-0.5">
                                {{ log.location ?? '—' }} · {{ log.recorded_by ?? '—' }} · {{ log.recorded_at_diff }}
                            </p>
                        </div>

                        <!-- Weight -->
                        <div class="text-right flex-shrink-0">
                            <WeightDisplay :kg="log.weight_recorded" size="xs"/>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>