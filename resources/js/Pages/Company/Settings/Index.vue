<script setup>
import { ref, reactive } from 'vue'
import { Head, router }  from '@inertiajs/vue3'
import AppLayout         from '@/Layouts/AppLayout.vue'
import { useI18n }      from '@/composables/core/useI18n.js'

const { lang } = useI18n()

const props = defineProps({
    settings:   Object,
    thresholds: Array,
    locations:  Array,
    company:    Object,
})

// ─── Active tab ────────────────────────────────────────────────────────────
const activeTab = ref('general')
const tabs = [
    { id: 'general',    label: { id: 'Umum',          en: 'General' },    icon: '⚙️' },
    { id: 'thresholds', label: { id: 'Mass Balance',  en: 'Mass Balance'},icon: '⚖️' },
    { id: 'warehouse',  label: { id: 'Warehouse',     en: 'Warehouse' },  icon: '🏭' },
    { id: 'reporting',  label: { id: 'Laporan',       en: 'Reports' },    icon: '📊' },
]

// ─── Local editable state untuk thresholds ────────────────────────────────
const editableThresholds = reactive(
    (props.thresholds ?? []).map(t => ({ ...t }))
)

const editableLocations = reactive(
    (props.locations ?? []).filter(l => l.location_type === 'warehouse').map(l => ({ ...l }))
)

// ─── Submit handlers ──────────────────────────────────────────────────────
const isSavingThresholds = ref(false)
const isSavingWarehouse  = ref(false)

function saveThresholds() {
    isSavingThresholds.value = true
    router.patch('/company/settings/thresholds', {
        thresholds: editableThresholds,
    }, {
        preserveScroll: true,
        onFinish: () => { isSavingThresholds.value = false },
    })
}

function saveWarehouse() {
    isSavingWarehouse.value = true
    router.patch('/company/settings/warehouse', {
        locations: editableLocations,
    }, {
        preserveScroll: true,
        onFinish: () => { isSavingWarehouse.value = false },
    })
}

// ─── Alert level color ─────────────────────────────────────────────────────
function alertColor(pct, warning, critical) {
    if (pct >= critical) return 'text-red-500'
    if (pct >= warning)  return 'text-amber-500'
    return 'text-emerald-500'
}
</script>

<template>
    <AppLayout>
        <Head :title="lang === 'en' ? 'Company Settings' : 'Pengaturan Perusahaan'" />
        <template #header>{{ lang === 'en' ? 'Settings' : 'Pengaturan' }}</template>

        <div class="max-w-3xl mx-auto space-y-6">

            <!-- Header -->
            <div class="animate-fade-in">
                <h1 class="text-2xl font-bold text-foreground tracking-tight">
                    {{ lang === 'en' ? 'Company Settings' : 'Pengaturan Perusahaan' }}
                </h1>
                <p class="text-sm text-muted-foreground mt-0.5">
                    {{ company?.name }} · {{ lang === 'en' ? 'Configure your company preferences' : 'Konfigurasi preferensi perusahaan' }}
                </p>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 p-1 rounded-xl bg-muted/50 border border-border w-fit animate-fade-in delay-75">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    :class="activeTab === tab.id ? 'text-white shadow-sm' : 'text-muted-foreground hover:text-foreground hover:bg-muted'"
                    class="flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-semibold transition-all duration-200"
                    :style="activeTab === tab.id ? 'background: hsl(var(--primary));' : ''"
                >
                    <span style="font-size:14px;">{{ tab.icon }}</span>
                    {{ tab.label[lang] }}
                </button>
            </div>

            <!-- Tab: Thresholds -->
            <div v-if="activeTab === 'thresholds'" class="space-y-4 animate-fade-in">
                <div class="rounded-2xl border border-border bg-card p-6 space-y-5">
                    <div class="flex items-center justify-between border-b border-border pb-4">
                        <div>
                            <h3 class="text-sm font-semibold text-foreground">
                                {{ lang === 'en' ? 'Mass Balance Thresholds' : 'Threshold Mass Balance' }}
                            </h3>
                            <p class="text-xs text-muted-foreground mt-0.5">
                                {{ lang === 'en'
                                    ? 'Set material loss tolerance per processing stage'
                                    : 'Atur toleransi susut material per tahap proses' }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3 text-xs text-muted-foreground">
                            <div class="flex items-center gap-1">
                                <div class="w-2.5 h-2.5 rounded-full bg-amber-500"/>
                                {{ lang === 'en' ? 'Warning' : 'Peringatan' }}
                            </div>
                            <div class="flex items-center gap-1">
                                <div class="w-2.5 h-2.5 rounded-full bg-red-500"/>
                                {{ lang === 'en' ? 'Critical' : 'Kritis' }}
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div
                            v-for="threshold in editableThresholds"
                            :key="threshold.id"
                            class="p-4 rounded-xl border border-border bg-muted/20 space-y-3 hover:border-primary/20 transition-colors"
                        >
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-semibold text-foreground">{{ threshold.stage_label }}</p>
                                <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <span class="font-medium" :class="alertColor(threshold.warning_threshold_pct, threshold.warning_threshold_pct - 1, threshold.critical_threshold_pct)">
                                        ⚠ {{ threshold.warning_threshold_pct }}%
                                    </span>
                                    <span>|</span>
                                    <span class="font-medium text-red-500">
                                        🔴 {{ threshold.critical_threshold_pct }}%
                                    </span>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div class="space-y-1.5">
                                    <label class="block text-xs font-semibold text-amber-600 dark:text-amber-400">
                                        {{ lang === 'en' ? 'Warning (%)' : 'Warning (%)' }}
                                    </label>
                                    <div class="relative">
                                        <input
                                            v-model.number="threshold.warning_threshold_pct"
                                            type="number" step="0.1" min="0" max="100"
                                            class="w-full h-9 pl-3 pr-8 rounded-lg text-sm border bg-background text-foreground outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition-all"
                                            :class="'border-amber-200 dark:border-amber-900/50'"
                                        />
                                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs font-semibold text-amber-500">%</span>
                                    </div>
                                </div>
                                <div class="space-y-1.5">
                                    <label class="block text-xs font-semibold text-red-600 dark:text-red-400">
                                        {{ lang === 'en' ? 'Critical (%)' : 'Kritis (%)' }}
                                    </label>
                                    <div class="relative">
                                        <input
                                            v-model.number="threshold.critical_threshold_pct"
                                            type="number" step="0.1" min="0" max="100"
                                            class="w-full h-9 pl-3 pr-8 rounded-lg text-sm border bg-background text-foreground outline-none focus:ring-2 focus:ring-red-500/20 focus:border-red-500 transition-all"
                                            :class="'border-red-200 dark:border-red-900/50'"
                                        />
                                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs font-semibold text-red-500">%</span>
                                    </div>
                                </div>
                            </div>

                            <p v-if="threshold.notes" class="text-xs text-muted-foreground italic border-t border-border pt-2">
                                {{ threshold.notes }}
                            </p>
                        </div>
                    </div>

                    <div class="flex justify-end pt-2 border-t border-border">
                        <button
                            @click="saveThresholds"
                            :disabled="isSavingThresholds"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white transition-all duration-200 disabled:opacity-50 hover:shadow-lg hover:shadow-primary/25 hover:-translate-y-0.5 active:scale-[0.97]"
                            style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));"
                        >
                            <svg v-if="isSavingThresholds" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                            </svg>
                            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            {{ isSavingThresholds ? (lang === 'en' ? 'Saving...' : 'Menyimpan...') : (lang === 'en' ? 'Save Thresholds' : 'Simpan Threshold') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tab: Warehouse -->
            <div v-if="activeTab === 'warehouse'" class="space-y-4 animate-fade-in">
                <div class="rounded-2xl border border-border bg-card p-6 space-y-5">
                    <div class="border-b border-border pb-4">
                        <h3 class="text-sm font-semibold text-foreground">
                            {{ lang === 'en' ? 'Warehouse Capacity' : 'Kapasitas Warehouse' }}
                        </h3>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ lang === 'en'
                                ? 'Configure maximum capacity and warning threshold for each warehouse location'
                                : 'Konfigurasi kapasitas maksimum dan threshold peringatan untuk setiap lokasi warehouse' }}
                        </p>
                    </div>

                    <div v-if="!editableLocations.length" class="py-8 text-center text-muted-foreground text-sm">
                        {{ lang === 'en' ? 'No warehouse locations configured.' : 'Belum ada lokasi warehouse dikonfigurasi.' }}
                    </div>

                    <div v-else class="space-y-4">
                        <div v-for="loc in editableLocations" :key="loc.id"
                             class="p-4 rounded-xl border border-border bg-muted/20 space-y-4 hover:border-primary/20 transition-colors">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-xl bg-primary/10 flex items-center justify-center text-lg flex-shrink-0">🏭</div>
                                <div>
                                    <p class="text-sm font-bold text-foreground">{{ loc.name }}</p>
                                    <p class="text-xs text-muted-foreground">{{ loc.code }} · {{ loc.city ?? '—' }}</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div class="space-y-1.5">
                                    <label class="block text-xs font-semibold text-foreground">
                                        {{ lang === 'en' ? 'Max Capacity (kg)' : 'Kapasitas Maks (kg)' }}
                                    </label>
                                    <input
                                        v-model.number="loc.max_capacity_kg"
                                        type="number" step="1000" min="0"
                                        :placeholder="lang === 'en' ? 'e.g. 500000' : 'mis. 500000'"
                                        class="w-full h-9 px-3 rounded-lg text-sm border border-input bg-background text-foreground outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <label class="block text-xs font-semibold text-amber-600 dark:text-amber-400">
                                        {{ lang === 'en' ? 'Warning at (%)' : 'Peringatan di (%)' }}
                                    </label>
                                    <div class="relative">
                                        <input
                                            v-model.number="loc.capacity_warning_pct"
                                            type="number" step="5" min="0" max="100"
                                            class="w-full h-9 pl-3 pr-8 rounded-lg text-sm border bg-background text-foreground outline-none focus:ring-2 focus:ring-amber-500/20 focus:border-amber-500 transition-all"
                                            :class="'border-amber-200 dark:border-amber-900/50'"
                                        />
                                        <span class="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs font-semibold text-amber-500">%</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="editableLocations.length" class="flex justify-end pt-2 border-t border-border">
                        <button
                            @click="saveWarehouse"
                            :disabled="isSavingWarehouse"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-white transition-all duration-200 disabled:opacity-50 hover:shadow-lg hover:shadow-primary/25 hover:-translate-y-0.5 active:scale-[0.97]"
                            style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));"
                        >
                            <svg v-if="isSavingWarehouse" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                            </svg>
                            {{ isSavingWarehouse ? (lang === 'en' ? 'Saving...' : 'Menyimpan...') : (lang === 'en' ? 'Save Warehouse Config' : 'Simpan Konfigurasi Warehouse') }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tab: General & Reporting placeholder -->
            <div v-if="activeTab === 'general' || activeTab === 'reporting'" class="animate-fade-in">
                <div class="rounded-2xl border border-border bg-card p-8 text-center space-y-3">
                    <div class="text-4xl">{{ activeTab === 'general' ? '⚙️' : '📊' }}</div>
                    <p class="text-sm font-semibold text-foreground">
                        {{ activeTab === 'general'
                            ? (lang === 'en' ? 'General Settings' : 'Pengaturan Umum')
                            : (lang === 'en' ? 'Report Settings' : 'Pengaturan Laporan') }}
                    </p>
                    <p class="text-xs text-muted-foreground max-w-xs mx-auto">
                        {{ lang === 'en'
                            ? 'This section will be available in the next update.'
                            : 'Bagian ini akan tersedia di update berikutnya.' }}
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>