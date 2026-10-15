<script setup>
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout              from '@/Layouts/AppLayout.vue'
import EmptyState             from '@/components/shared/EmptyState.vue'

const props = defineProps({
    companies: Array,
    stats:     Object,
})

function toggleActive(id) {
    router.patch(`/super-admin/companies/${id}/toggle-active`, {}, { preserveScroll: true })
}
</script>

<template>
    <AppLayout>
        <Head title="Company Management" />
        <template #header>Company Management</template>

        <div class="space-y-6">

            <!-- Header -->
            <div class="flex items-center justify-between animate-fade-in">
                <div>
                    <h1 class="text-2xl font-bold text-foreground tracking-tight">Company Management</h1>
                    <p class="text-sm text-muted-foreground mt-0.5">Kelola semua perusahaan dalam ekosistem MINETRACE</p>
                </div>
                <Link
                    href="/super-admin/companies/create"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition-all duration-200 hover:shadow-lg hover:shadow-primary/25 hover:-translate-y-0.5 active:scale-[0.97]"
                    style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    Onboard Company Baru
                </Link>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div v-for="(item, i) in [
                    { label: 'Total Company', value: stats?.total, color: 'text-foreground' },
                    { label: 'Aktif',         value: stats?.active, color: 'text-emerald-600 dark:text-emerald-400' },
                    { label: 'Regulator',     value: stats?.regulator, color: 'text-red-600 dark:text-red-400' },
                    { label: 'Onboarding',    value: stats?.onboarding, color: 'text-amber-600 dark:text-amber-400' },
                ]" :key="item.label"
                     class="rounded-2xl border border-border bg-card p-5 animate-fade-in"
                     :style="`animation-delay: ${i*60}ms`">
                    <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-2">{{ item.label }}</p>
                    <p class="text-3xl font-bold" :class="item.color">{{ item.value ?? 0 }}</p>
                </div>
            </div>

            <!-- Company Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 animate-fade-in delay-200">

                <EmptyState v-if="!companies?.length"
                    class="col-span-full"
                    icon="🏢"
                    title="Belum ada company terdaftar"
                    description="Mulai onboarding company pertama."
                    action-label="Onboard Company"
                    action-href="/super-admin/companies/create"
                />

                <div
                    v-for="(co, i) in companies"
                    :key="co.id"
                    class="rounded-2xl border bg-card overflow-hidden transition-all duration-200 hover:shadow-md hover:-translate-y-0.5 animate-fade-in"
                    :class="co.is_deleted ? 'opacity-50 border-border' : co.is_active ? 'border-border' : 'border-amber-200 dark:border-amber-900/50'"
                    :style="`animation-delay: ${i*70}ms`"
                >
                    <!-- Color bar -->
                    <div class="h-1.5 w-full" :style="`background: ${co.primary_color ?? '#3E5C74'};`"/>

                    <div class="p-5 space-y-4">
                        <!-- Company header -->
                        <div class="flex items-start gap-3">
                            <div class="w-11 h-11 rounded-xl flex items-center justify-center text-white text-sm font-bold flex-shrink-0 shadow-sm"
                                 :style="`background: ${co.primary_color ?? '#3E5C74'};`">
                                {{ co.code?.slice(0,2) }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-sm font-bold text-foreground">{{ co.name }}</p>
                                    <span v-if="co.is_regulator"
                                          class="text-[10px] px-1.5 py-0.5 rounded font-semibold bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">
                                        Regulator
                                    </span>
                                    <span v-if="!co.is_fully_onboarded && !co.is_regulator"
                                          class="text-[10px] px-1.5 py-0.5 rounded font-semibold bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                        Onboarding
                                    </span>
                                </div>
                                <p class="text-xs text-muted-foreground truncate">{{ co.industry }}</p>
                            </div>
                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                <div class="w-2 h-2 rounded-full"
                                     :class="co.is_active ? 'bg-emerald-400 animate-pulse' : 'bg-gray-400'"/>
                            </div>
                        </div>

                        <!-- Stats -->
                        <div class="grid grid-cols-3 gap-2">
                            <div class="text-center p-2 rounded-lg bg-muted/40">
                                <p class="text-base font-bold text-foreground">{{ co.users_count ?? 0 }}</p>
                                <p class="text-[10px] text-muted-foreground uppercase tracking-wide">Users</p>
                            </div>
                            <div class="text-center p-2 rounded-lg bg-muted/40">
                                <p class="text-base font-bold text-foreground">{{ co.batches_count ?? 0 }}</p>
                                <p class="text-[10px] text-muted-foreground uppercase tracking-wide">Batch</p>
                            </div>
                            <div class="text-center p-2 rounded-lg bg-muted/40">
                                <p class="text-[10px] font-bold text-foreground">{{ co.onboarded_at ?? '—' }}</p>
                                <p class="text-[10px] text-muted-foreground uppercase tracking-wide">Join</p>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-2 pt-1 border-t border-border">
                            <Link :href="`/super-admin/companies/${co.id}`"
                                  class="flex-1 h-8 rounded-lg text-xs font-semibold flex items-center justify-center gap-1 transition-all active:scale-95"
                                  style="background: hsl(var(--primary)/0.1); color: hsl(var(--primary));">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                Detail
                            </Link>
                            <button
                                @click="toggleActive(co.id)"
                                :disabled="co.is_regulator"
                                class="h-8 px-3 rounded-lg text-xs font-semibold transition-all active:scale-95 disabled:opacity-40 disabled:cursor-not-allowed"
                                :class="co.is_active
                                    ? 'bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-900/20 dark:text-amber-400'
                                    : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-900/20 dark:text-emerald-400'"
                            >
                                {{ co.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>