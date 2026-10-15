<script setup>
import AppIcon from '@/components/app/AppIcon.vue'
import { ref, computed } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

// ─── Props dari Controller ─────────────────────────────────────────────────
const props = defineProps({
    batches:      Object,
    stats:        Object,
    filters:      Object,
    productCodes: Array,
})

// ─── Filter state ──────────────────────────────────────────────────────────
const search     = ref(props.filters?.search ?? '')
const status     = ref(props.filters?.status ?? 'all')
const stage      = ref(props.filters?.stage ?? 'all')
const alertsOnly = ref(props.filters?.alerts_only ?? false)

// ─── Apply filters dengan debounce ────────────────────────────────────────
let searchTimeout = null
function applyFilters() {
    router.get('/company/batches', {
        search:      search.value || undefined,
        status:      status.value !== 'all' ? status.value : undefined,
        stage:       stage.value !== 'all' ? stage.value : undefined,
        alerts_only: alertsOnly.value || undefined,
    }, { preserveState: true, replace: true })
}

function onSearchInput() {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(applyFilters, 400)
}

// ─── Status badge config ───────────────────────────────────────────────────
function statusBadge(s) {
    const map = {
        active:      { class: 'badge-active',      label: 'Aktif' },
        in_transit:  { class: 'badge-transit',     label: 'Transit' },
        in_process:  { class: 'badge-process',     label: 'Diproses' },
        in_warehouse:{ class: 'badge-warehouse',   label: 'Di Gudang' },
        completed:   { class: 'badge-completed',   label: 'Selesai' },
        split:       { class: 'badge-split',       label: 'Di-split' },
        transformed: { class: 'badge-transformed', label: 'Ditransformasi' },
        exported:    { class: 'badge-exported',    label: 'Diekspor' },
        terminated:  { class: 'badge-terminated',  label: 'Terminal' },
        draft:       { class: 'badge-completed',   label: 'Draft' },
    }
    return map[s] ?? { class: 'badge-completed', label: s }
}

function stageBadge(s) {
    const map = {
        lab_kimia:     { icon: 'flask-conical', label: 'Lab Kimia', color: 'text-info ' },
        receiving:     { icon: 'package-open', label: 'Receiving', color: 'text-info ' },
        wet_process:   { icon: 'droplets', label: 'Wet Process', color: 'text-info ' },
        dry_process:   { icon: 'flame', label: 'Dry Process', color: 'text-warning ' },
        project_plant: { icon: 'microscope', label: 'Project Plant', color: 'text-success ' },
        warehouse:     { icon: 'warehouse', label: 'Warehouse', color: 'text-primary' },
        smelter:       { icon: 'zap', label: 'Smelter', color: 'text-danger ' },
        exported:      { icon: 'ship', label: 'Diekspor', color: 'text-success ' },
    }
    return map[s] ?? { icon: 'package', label: s, color: 'text-muted-foreground' }
}

// ─── Format weight untuk display ──────────────────────────────────────────
function formatWeight(kg) {
    if (kg === null || kg === undefined) return '—'
    if (kg >= 1000) return `${(kg / 1000).toFixed(2)} ton`
    return `${parseFloat(kg).toLocaleString('id-ID', { maximumFractionDigits: 1 })} kg`
}

// ─── Format date ──────────────────────────────────────────────────────────
function formatDate(dateStr) {
    if (!dateStr) return '—'
    const d = new Date(dateStr)
    return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
    <AppLayout>
        <Head title="Manajemen Batch" />
        <template #header>Manajemen Batch</template>

        <div class="space-y-6">

            <!-- ─── Page Header ─────────────────────────────────────────── -->
            <div class="flex items-start justify-between gap-4 animate-fade-in">
                <div>
                    <h1 class="text-2xl font-bold text-foreground tracking-tight">Manajemen Batch</h1>
                    <p class="text-sm text-muted-foreground mt-0.5">
                        Kelola dan pantau seluruh batch material LTJ dalam rantai proses
                    </p>
                </div>
                <Link
                    href="/company/batches/create"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition-all duration-200 hover:shadow-lg hover:shadow-primary/25 hover:-translate-y-0.5 active:scale-[0.97] flex-shrink-0"
                    style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Buat Batch Baru
                </Link>
            </div>

            <!-- ─── Stats Cards ─────────────────────────────────────────── -->
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">

                <!-- Total Batch -->
                <div class="rounded-2xl border border-border bg-card p-5 animate-fade-in delay-75 card-interactive">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Total Batch</span>
                        <div class="w-9 h-9 rounded-xl bg-primary/10 flex items-center justify-center">
                            <svg class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-foreground">{{ stats?.total ?? 0 }}</p>
                    <p class="text-xs text-muted-foreground mt-1">Semua batch terdaftar</p>
                </div>

                <!-- Aktif -->
                <div class="rounded-2xl border border-border bg-card p-5 animate-fade-in delay-100 card-interactive">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Aktif</span>
                        <div class="w-9 h-9 rounded-xl bg-success-soft flex items-center justify-center">
                            <div class="w-2.5 h-2.5 rounded-full bg-success animate-pulse"/>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-success">{{ stats?.active ?? 0 }}</p>
                    <p class="text-xs text-muted-foreground mt-1">Sedang berproses</p>
                </div>

                <!-- Di Gudang -->
                <div class="rounded-2xl border border-border bg-card p-5 animate-fade-in delay-150 card-interactive">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Di Gudang</span>
                        <div class="w-9 h-9 rounded-xl bg-info-soft flex items-center justify-center">
                            <svg class="w-5 h-5 text-info" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-info">{{ stats?.in_warehouse ?? 0 }}</p>
                    <p class="text-xs text-muted-foreground mt-1">Tersimpan di warehouse</p>
                </div>

                <!-- Alert -->
                <div class="rounded-2xl border bg-card p-5 animate-fade-in delay-200 card-interactive"
                     :class="(stats?.has_alert ?? 0) > 0 ? 'border-danger/30 bg-danger-soft ' : 'border-border'">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-semibold uppercase tracking-wider"
                              :class="(stats?.has_alert ?? 0) > 0 ? 'text-danger ' : 'text-muted-foreground'">
                            Alert
                        </span>
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center"
                             :class="(stats?.has_alert ?? 0) > 0 ? 'bg-danger-soft' : 'bg-muted'">
                            <svg class="w-5 h-5" :class="(stats?.has_alert ?? 0) > 0 ? 'text-danger animate-bounce-subtle' : 'text-muted-foreground'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold" :class="(stats?.has_alert ?? 0) > 0 ? 'text-danger ' : 'text-foreground'">
                        {{ stats?.has_alert ?? 0 }}
                    </p>
                    <p class="text-xs mt-1" :class="(stats?.has_alert ?? 0) > 0 ? 'text-danger' : 'text-muted-foreground'">
                        Mass balance discrepancy
                    </p>
                </div>

                <!-- Total Weight -->
                <div class="rounded-2xl border border-border bg-card p-5 animate-fade-in delay-300 card-interactive">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Total Tonase</span>
                        <div class="w-9 h-9 rounded-xl bg-warning-soft flex items-center justify-center">
                            <svg class="w-5 h-5 text-warning" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0012 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52l2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 01-2.031.352 5.988 5.988 0 01-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.971zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0l2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 01-2.031.352 5.989 5.989 0 01-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.971z" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-xl font-bold text-warning leading-tight">
                        {{ formatWeight(stats?.total_weight_kg ?? 0) }}
                    </p>
                    <p class="text-xs text-muted-foreground mt-1">Batch aktif & proses</p>
                </div>
            </div>

            <!-- ─── Filters ─────────────────────────────────────────────── -->
            <div class="flex flex-wrap items-center gap-3 animate-fade-in delay-300">

                <!-- Search -->
                <div class="relative flex-1 min-w-[200px] max-w-xs">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </div>
                    <input
                        v-model="search"
                        @input="onSearchInput"
                        type="text"
                        placeholder="Cari kode batch..."
                        class="w-full pl-9 pr-4 h-9 rounded-xl text-sm bg-card border border-border text-foreground placeholder:text-muted-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                    />
                </div>

                <!-- Status Filter -->
                <select
                    v-model="status"
                    @change="applyFilters"
                    class="h-9 px-3 rounded-xl text-sm bg-card border border-border text-foreground focus:ring-2 focus:ring-primary/20 cursor-pointer"
                >
                    <option value="all">Semua Status</option>
                    <option value="active">Aktif</option>
                    <option value="in_transit">Transit</option>
                    <option value="in_process">Diproses</option>
                    <option value="in_warehouse">Di Gudang</option>
                    <option value="completed">Selesai</option>
                    <option value="split">Di-split</option>
                    <option value="exported">Diekspor</option>
                </select>

                <!-- Stage Filter -->
                <select
                    v-model="stage"
                    @change="applyFilters"
                    class="h-9 px-3 rounded-xl text-sm bg-card border border-border text-foreground focus:ring-2 focus:ring-primary/20 cursor-pointer"
                >
                    <option value="all">Semua Stage</option>
                    <option value="lab_kimia">Lab Kimia</option>
                    <option value="receiving">Receiving</option>
                    <option value="wet_process">Wet Process</option>
                    <option value="dry_process">Dry Process</option>
                    <option value="project_plant">Project Plant</option>
                    <option value="warehouse">Warehouse</option>
                    <option value="smelter">Smelter</option>
                </select>

                <!-- Alert Filter Toggle -->
                <button
                    @click="alertsOnly = !alertsOnly; applyFilters()"
                    :class="alertsOnly ? 'bg-danger-soft border-danger/30 text-danger ' : 'bg-card border-border text-muted-foreground hover:border-danger/30 hover:text-danger'"
                    class="h-9 px-3 rounded-xl text-sm border flex items-center gap-1.5 transition-all duration-200"
                >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                    </svg>
                    Alert
                </button>
            </div>

            <!-- ─── Batch Table ──────────────────────────────────────────── -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-300">

                <!-- Empty State -->
                <div v-if="!batches?.data?.length" class="py-16 text-center space-y-4">
                    <div class="w-16 h-16 rounded-2xl bg-muted flex items-center justify-center mx-auto">
                        <svg class="w-8 h-8 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                        </svg>
                    </div>
                    <div>
                        <p class="font-semibold text-foreground">Belum ada batch</p>
                        <p class="text-sm text-muted-foreground mt-1">
                            {{ search ? `Tidak ada batch dengan kode "${search}"` : 'Buat batch pertama untuk memulai traceability.' }}
                        </p>
                    </div>
                    <Link
                        href="/company/batches/create"
                        class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-white mx-auto"
                        style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Buat Batch Pertama
                    </Link>
                </div>

                <!-- Table -->
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border bg-muted/30">
                                <th class="text-left px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Kode Batch</th>
                                <th class="text-left px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Produk</th>
                                <th class="text-left px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Stage</th>
                                <th class="text-left px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                <th class="text-right px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Berat</th>
                                <th class="text-left px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Lokasi</th>
                                <th class="text-left px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Dibuat</th>
                                <th class="px-4 py-3.5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="(batch, i) in batches.data"
                                :key="batch.id"
                                class="group transition-colors duration-150 hover:bg-muted/30"
                                :class="batch.has_discrepancy ? 'bg-danger-soft ' : ''"
                                :style="`animation: fade-in 0.3s ease-out ${i * 40}ms both;`"
                            >
                                <!-- Kode Batch -->
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-2">
                                        <!-- Alert indicator -->
                                        <div
                                            v-if="batch.has_discrepancy"
                                            class="w-2 h-2 rounded-full bg-danger animate-pulse flex-shrink-0"
                                            title="Mass balance discrepancy"
                                        />
                                        <div>
                                            <p class="font-mono font-semibold text-foreground text-xs tracking-wide">
                                                {{ batch.batch_code }}
                                            </p>
                                            <p v-if="batch.lot_number" class="text-xs text-muted-foreground mt-0.5 font-mono">
                                                {{ batch.lot_number }}
                                            </p>
                                        </div>
                                    </div>
                                </td>

                                <!-- Produk -->
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-2">
                                        <!-- Hazard indicator -->
                                        <div
                                            v-if="batch.product_code?.is_hazardous"
                                            class="w-5 h-5 rounded-md flex items-center justify-center flex-shrink-0"
                                            style="background: hsl(var(--info-soft));"
                                            title="Material radioaktif/berbahaya"
                                        >
                                            <span style="font-size:11px;"></span>
                                        </div>
                                        <div>
                                            <p class="font-semibold text-foreground">{{ batch.product_code?.code }}</p>
                                            <p class="text-xs text-muted-foreground truncate max-w-[120px]">
                                                {{ batch.product_code?.name }}
                                            </p>
                                        </div>
                                    </div>
                                    <!-- Export blocked -->
                                    <span
                                        v-if="batch.product_code?.is_export_blocked"
                                        class="inline-flex items-center gap-1 mt-1 text-xs font-semibold px-1.5 py-0.5 rounded badge-export-blocked"
                                    >
                                        <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                        </svg>
                                        Blokir Ekspor
                                    </span>
                                </td>

                                <!-- Stage -->
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-1.5">
                                        <AppIcon :name="stageBadge(batch.process_stage).icon || 'circle'" :size="15" />
                                        <span class="font-medium text-xs" :class="stageBadge(batch.process_stage).color">
                                            {{ stageBadge(batch.process_stage).label }}
                                        </span>
                                    </div>
                                </td>

                                <!-- Status -->
                                <td class="px-4 py-4">
                                    <span class="inline-flex items-center px-2 py-1 rounded-lg text-xs font-semibold" :class="statusBadge(batch.status).class">
                                        {{ statusBadge(batch.status).label }}
                                    </span>
                                </td>

                                <!-- Berat -->
                                <td class="px-4 py-4 text-right">
                                    <p class="font-semibold text-foreground tabular-nums">
                                        {{ formatWeight(batch.current_weight) }}
                                    </p>
                                    <p v-if="batch.mass_loss_pct !== null" class="text-xs mt-0.5"
                                       :class="batch.has_discrepancy ? 'text-danger font-semibold' : 'text-muted-foreground'">
                                        -{{ batch.mass_loss_pct }}%
                                    </p>
                                </td>

                                <!-- Lokasi -->
                                <td class="px-4 py-4">
                                    <div v-if="batch.current_location">
                                        <p class="font-medium text-foreground text-xs">{{ batch.current_location.code }}</p>
                                        <p class="text-xs text-muted-foreground">{{ batch.current_location.name }}</p>
                                    </div>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>

                                <!-- Dibuat -->
                                <td class="px-4 py-4">
                                    <p class="text-xs text-muted-foreground">{{ formatDate(batch.created_at) }}</p>
                                </td>

                                <!-- Action -->
                                <td class="px-4 py-4">
                                    <Link
                                        :href="`/company/batches/${batch.id}`"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all duration-200 opacity-0 group-hover:opacity-100 hover:shadow-sm"
                                        style="background: hsl(var(--primary)/0.12); color: hsl(var(--primary));"
                                    >
                                        Detail
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                        </svg>
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="batches?.links?.length > 3" class="flex items-center justify-between px-5 py-4 border-t border-border">
                    <p class="text-sm text-muted-foreground">
                        Menampilkan
                        <span class="font-semibold text-foreground">{{ batches.from }}–{{ batches.to }}</span>
                        dari
                        <span class="font-semibold text-foreground">{{ batches.total }}</span>
                        batch
                    </p>
                    <div class="flex items-center gap-1">
                        <template v-for="link in batches.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                class="w-8 h-8 rounded-lg text-xs font-semibold flex items-center justify-center transition-all duration-150"
                                :class="link.active ? 'text-white shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
                                :style="link.active ? 'background: hsl(var(--primary));' : ''"
                                v-html="link.label"
                                preserve-scroll
                            />
                            <span
                                v-else
                                class="w-8 h-8 rounded-lg text-xs flex items-center justify-center text-muted-foreground/40"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>