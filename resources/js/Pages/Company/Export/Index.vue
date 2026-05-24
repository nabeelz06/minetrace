<script setup>
import { ref }              from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout            from '@/Layouts/AppLayout.vue'
import StatusBadge          from '@/components/shared/StatusBadge.vue'
import WeightDisplay        from '@/components/shared/WeightDisplay.vue'
import EmptyState           from '@/components/shared/EmptyState.vue'
import { useI18n }          from '@/composables/core/useI18n.js'

const { lang } = useI18n()

const props = defineProps({
    exports: Object,
    stats:   Object,
    filters: Object,
})

const type   = ref(props.filters?.type   ?? '')
const status = ref(props.filters?.status ?? '')

function applyFilters() {
    router.get('/company/exports', {
        type:   type.value   || undefined,
        status: status.value || undefined,
    }, { preserveState: true, replace: true })
}

function formatDate(d) {
    if (!d) return '—'
    return new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}

function formatValue(usd) {
    if (!usd) return '—'
    return 'USD ' + parseFloat(usd).toLocaleString('en-US', { maximumFractionDigits: 0 })
}
</script>

<template>
    <AppLayout>
        <Head :title="lang === 'en' ? 'Export Management' : 'Manajemen Ekspor'" />
        <template #header>{{ lang === 'en' ? 'Export Management' : 'Manajemen Ekspor' }}</template>

        <div class="space-y-6">

            <!-- Header -->
            <div class="flex items-center justify-between animate-fade-in">
                <div>
                    <h1 class="text-2xl font-bold text-foreground tracking-tight">
                        {{ lang === 'en' ? 'Export Management' : 'Manajemen Ekspor' }}
                    </h1>
                    <p class="text-sm text-muted-foreground mt-0.5">
                        {{ lang === 'en' ? 'Track all international and domestic exports' : 'Pantau semua ekspor internasional dan penjualan domestik' }}
                    </p>
                </div>
                <Link
                    href="/company/exports/create"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition-all duration-200 hover:shadow-lg hover:shadow-primary/25 hover:-translate-y-0.5 active:scale-[0.97]"
                    style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    {{ lang === 'en' ? 'New Export' : 'Buat Ekspor' }}
                </Link>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div v-for="(item, i) in [
                    { label: lang==='en'?'Total Exports':'Total Ekspor',  value: stats?.total,         color: 'text-foreground' },
                    { label: lang==='en'?'International':'Internasional', value: stats?.international,  color: 'text-primary' },
                    { label: lang==='en'?'Domestic':'Domestik',           value: stats?.domestic,       color: 'text-cyan-600 dark:text-cyan-400' },
                    { label: lang==='en'?'Total Weight':'Total Tonase',   value: null,                  color: 'text-amber-600 dark:text-amber-400', weight: stats?.total_weight },
                ]" :key="item.label"
                     class="rounded-2xl border border-border bg-card p-5 animate-fade-in"
                     :style="`animation-delay: ${i*60}ms`">
                    <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-2">{{ item.label }}</p>
                    <p class="text-3xl font-bold" :class="item.color">
                        <template v-if="item.weight !== undefined">
                            <WeightDisplay :kg="item.weight" size="lg" :color="null"/>
                        </template>
                        <template v-else>{{ item.value ?? 0 }}</template>
                    </p>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3 animate-fade-in delay-150">
                <select v-model="type" @change="applyFilters"
                        class="h-9 px-3 rounded-xl text-sm bg-card border border-border text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20 cursor-pointer">
                    <option value="">{{ lang === 'en' ? 'All Types' : 'Semua Tipe' }}</option>
                    <option value="international">🌍 {{ lang === 'en' ? 'International' : 'Internasional' }}</option>
                    <option value="domestic">🏭 {{ lang === 'en' ? 'Domestic' : 'Domestik' }}</option>
                </select>

                <select v-model="status" @change="applyFilters"
                        class="h-9 px-3 rounded-xl text-sm bg-card border border-border text-foreground focus:outline-none focus:ring-2 focus:ring-primary/20 cursor-pointer">
                    <option value="">{{ lang === 'en' ? 'All Status' : 'Semua Status' }}</option>
                    <option value="draft">Draft</option>
                    <option value="confirmed">{{ lang === 'en' ? 'Confirmed' : 'Dikonfirmasi' }}</option>
                    <option value="shipped">{{ lang === 'en' ? 'Shipped' : 'Dikirim' }}</option>
                    <option value="delivered">{{ lang === 'en' ? 'Delivered' : 'Diterima' }}</option>
                </select>
            </div>

            <!-- Table -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-200">

                <EmptyState v-if="!exports?.data?.length"
                    icon="🚢"
                    :title="lang === 'en' ? 'No exports yet' : 'Belum ada ekspor'"
                    :description="lang === 'en' ? 'Create your first export once batches are ready in warehouse.' : 'Buat ekspor pertama setelah batch tersedia di warehouse.'"
                    :action-label="lang === 'en' ? 'Create Export' : 'Buat Ekspor'"
                    action-href="/company/exports/create"
                />

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border bg-muted/30">
                                <th class="text-left px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">#</th>
                                <th class="text-left px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Tipe</th>
                                <th class="text-left px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Tujuan</th>
                                <th class="text-right px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Berat</th>
                                <th class="text-right px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Nilai</th>
                                <th class="text-center px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                <th class="text-left px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Tanggal</th>
                                <th class="px-4 py-3.5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="(exp, i) in exports.data" :key="exp.id"
                                class="group transition-colors duration-150 hover:bg-muted/20"
                                :style="`animation: fade-in 0.3s ease-out ${i*40}ms both;`">

                                <td class="px-5 py-4">
                                    <p class="font-bold text-xs text-foreground">#{{ String(exp.id).padStart(6, '0') }}</p>
                                    <p v-if="exp.invoice_number" class="text-xs text-muted-foreground font-mono">
                                        {{ exp.invoice_number }}
                                    </p>
                                </td>

                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-1.5">
                                        <span>{{ exp.export_type === 'international' ? '🌍' : '🏭' }}</span>
                                        <span class="text-xs font-medium text-foreground">
                                            {{ exp.export_type === 'international'
                                                ? (lang === 'en' ? 'International' : 'Internasional')
                                                : (lang === 'en' ? 'Domestic' : 'Domestik') }}
                                        </span>
                                    </div>
                                </td>

                                <td class="px-4 py-4">
                                    <p class="text-xs font-semibold text-foreground truncate max-w-[150px]">
                                        {{ exp.destination_company }}
                                    </p>
                                    <p v-if="exp.destination_country" class="text-xs text-muted-foreground">
                                        {{ exp.destination_country }}
                                    </p>
                                </td>

                                <td class="px-4 py-4 text-right">
                                    <WeightDisplay :kg="exp.total_weight_kg"/>
                                </td>

                                <td class="px-4 py-4 text-right">
                                    <span class="text-xs font-medium text-foreground">{{ formatValue(exp.value_usd) }}</span>
                                </td>

                                <td class="px-4 py-4 text-center">
                                    <StatusBadge :status="exp.status" type="export"/>
                                </td>

                                <td class="px-4 py-4">
                                    <p class="text-xs text-muted-foreground">{{ formatDate(exp.exported_at) }}</p>
                                    <p class="text-xs text-muted-foreground">{{ exp.created_by_user?.name ?? '—' }}</p>
                                </td>

                                <td class="px-4 py-4">
                                    <div class="flex items-center justify-end gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <Link :href="`/company/exports/${exp.id}`"
                                              class="px-2.5 py-1.5 rounded-lg text-xs font-semibold transition-all active:scale-95"
                                              style="background: hsl(var(--primary)/0.1); color: hsl(var(--primary));">
                                            Detail
                                            <svg class="w-3 h-3 inline ml-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                                            </svg>
                                        </Link>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="exports?.links?.length > 3" class="flex items-center justify-between px-5 py-4 border-t border-border">
                    <p class="text-sm text-muted-foreground">
                        Menampilkan <span class="font-semibold text-foreground">{{ exports.from }}–{{ exports.to }}</span>
                        dari <span class="font-semibold text-foreground">{{ exports.total }}</span>
                    </p>
                    <div class="flex items-center gap-1">
                        <template v-for="link in exports.links" :key="link.label">
                            <Link v-if="link.url" :href="link.url"
                                  class="w-8 h-8 rounded-lg text-xs font-semibold flex items-center justify-center transition-all"
                                  :class="link.active ? 'text-white' : 'text-muted-foreground hover:bg-muted'"
                                  :style="link.active ? 'background: hsl(var(--primary));' : ''"
                                  v-html="link.label" preserve-scroll/>
                            <span v-else class="w-8 h-8 text-xs flex items-center justify-center text-muted-foreground/40" v-html="link.label"/>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>