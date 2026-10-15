<script setup>
import { computed }   from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout      from '@/Layouts/AppLayout.vue'
import { useI18n }   from '@/composables/core/useI18n.js'

const { t, lang } = useI18n()

const props = defineProps({
    system_stats:  Object,
    companies:     Array,
    recent_users:  Array,
})

function formatNumber(n) {
    return (n ?? 0).toLocaleString('id-ID')
}
</script>

<template>
    <AppLayout>
        <Head title="System Dashboard" />
        <template #header>System Dashboard</template>

        <div class="space-y-6">

            <!-- Header -->
            <div class="flex items-center justify-between animate-fade-in">
                <div>
                    <h1 class="text-2xl font-bold text-foreground tracking-tight">System Dashboard</h1>
                    <p class="text-sm text-muted-foreground mt-0.5">
                        MINETRACE — Mineral Traceability Platform Overview
                    </p>
                </div>
                <div class="flex items-center gap-2 px-3 py-2 rounded-xl border border-border bg-muted/50">
                    <div class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"/>
                    <span class="text-xs font-semibold text-foreground">System Online</span>
                </div>
            </div>

            <!-- System KPI -->
            <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
                <div
                    v-for="(item, i) in [
                        { label: 'Total Companies', value: system_stats?.total_companies, icon: '🏢', color: 'text-primary' },
                        { label: 'Total Users',     value: system_stats?.total_users,     icon: '👤', color: 'text-blue-500' },
                        { label: 'Total Batches',   value: system_stats?.total_batches,   icon: '📦', color: 'text-emerald-500' },
                        { label: 'Total Exports',   value: system_stats?.total_exports,   icon: '🚢', color: 'text-cyan-500' },
                        { label: 'Lab Analyses',    value: system_stats?.total_labs,      icon: '🔬', color: 'text-violet-500' },
                        { label: 'Active Alerts',   value: system_stats?.active_alerts,   icon: '⚠️', color: system_stats?.active_alerts > 0 ? 'text-red-500' : 'text-muted-foreground' },
                    ]"
                    :key="item.label"
                    class="rounded-2xl border border-border bg-card p-5 animate-fade-in transition-all hover:shadow-md hover:-translate-y-0.5"
                    :style="`animation-delay: ${i * 60}ms`"
                >
                    <div class="text-2xl mb-2">{{ item.icon }}</div>
                    <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1">{{ item.label }}</p>
                    <p class="text-3xl font-bold" :class="item.color">{{ formatNumber(item.value) }}</p>
                </div>
            </div>

            <!-- Companies Overview -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-200">
                <div class="flex items-center justify-between px-5 py-4 border-b border-border">
                    <h3 class="text-sm font-semibold text-foreground">Companies</h3>
                    <Link href="/super-admin/companies"
                          class="text-xs text-primary font-medium hover:underline transition-colors flex items-center gap-1">
                        Manage All
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                    </Link>
                </div>
                <div class="divide-y divide-border">
                    <div v-for="(co, i) in companies" :key="co.id"
                         class="flex items-center gap-4 px-5 py-4 hover:bg-muted/20 transition-colors animate-fade-in"
                         :style="`animation-delay: ${i*40}ms`">
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center text-white text-xs font-bold flex-shrink-0"
                             :style="`background: ${co.color ?? '#3E5C74'};`">
                            {{ co.code?.slice(0,2) }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-foreground truncate">{{ co.name }}</p>
                            <p class="text-xs text-muted-foreground">{{ co.industry }}</p>
                        </div>
                        <div class="flex items-center gap-4 text-xs text-muted-foreground flex-shrink-0">
                            <span><span class="font-semibold text-foreground">{{ co.users_count }}</span> users</span>
                            <span><span class="font-semibold text-foreground">{{ co.batches }}</span> batches</span>
                            <div class="w-2 h-2 rounded-full" :class="co.is_active ? 'bg-emerald-400' : 'bg-gray-400'"/>
                        </div>
                    </div>
                    <div v-if="!companies?.length" class="py-10 text-center text-muted-foreground text-sm">
                        Belum ada company terdaftar.
                    </div>
                </div>
            </div>

            <!-- Recent Users -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-300">
                <div class="flex items-center justify-between px-5 py-4 border-b border-border">
                    <h3 class="text-sm font-semibold text-foreground">Recent Users</h3>
                    <Link href="/super-admin/users"
                          class="text-xs text-primary font-medium hover:underline transition-colors flex items-center gap-1">
                        Manage All
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                    </Link>
                </div>
                <div class="divide-y divide-border">
                    <div v-for="(u, i) in recent_users" :key="u.id"
                         class="flex items-center gap-3 px-5 py-3.5 hover:bg-muted/20 transition-colors animate-fade-in"
                         :style="`animation-delay: ${i*30}ms`">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center text-white text-[10px] font-bold flex-shrink-0"
                             style="background: linear-gradient(135deg, #3E5C74, #5ea1c2);">
                            {{ u.name?.charAt(0)?.toUpperCase() }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-foreground">{{ u.name }}</p>
                            <p class="text-xs text-muted-foreground truncate">{{ u.email }}</p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <span class="text-xs px-2 py-0.5 rounded-lg bg-muted text-muted-foreground font-medium capitalize">
                                {{ u.role?.replace(/_/g, ' ') }}
                            </span>
                            <p class="text-[10px] text-muted-foreground mt-0.5">{{ u.joined }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>