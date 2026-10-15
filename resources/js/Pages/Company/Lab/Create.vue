<script setup>
/**
 * Lab/Create.vue — Input analisis unsur LTJ untuk batch MON di Project Plant.
 *
 * FEATURES:
 * - Two-column layout: form (kiri) | live preview panel (kanan, sticky)
 * - Real-time kalkulasi: total REO%, kg per elemen, quality badge
 * - localStorage autosave sebagai draft
 * - Language support (ID / EN)
 * - Visual breakdown bar chart (color-coded per elemen)
 * - Redirect ke /company/lab setelah simpan (untuk validasi langsung)
 */
import { ref, computed, watch, onMounted } from 'vue'
import { Head, Link, useForm }              from '@inertiajs/vue3'
import AppLayout                            from '@/Layouts/AppLayout.vue'
import { useI18n }                          from '@/composables/core/useI18n.js'

const { lang } = useI18n()

const props = defineProps({
    batch:             Object,
    existing_analyses: { type: Number, default: 0 },
    has_validated:     { type: Boolean, default: false },
    locations:         { type: Array, default: () => [] },
    methods:           { type: Array, default: () => ['XRF','ICP-MS','AAS','Gravimetri'] },
    current_time:      String,
})

// ─── Form ─────────────────────────────────────────────────────────────────────
const form = useForm({
    location_id:     '',
    la_pct:          '',
    ce_pct:          '',
    pr_pct:          '',
    nd_pct:          '',
    y_pct:           '',
    tho2_pct:        '',
    u3o8_pct:        '',
    lab_method:      'XRF',
    equipment_used:  '',
    sample_weight_g: '',
    notes:           '',
})

// ─── Draft autosave ────────────────────────────────────────────────────────────
const DRAFT_KEY  = computed(() => `lab_draft_${props.batch?.id}`)
const hasDraft   = ref(false)
const draftSaved = ref(false)

onMounted(() => {
    try {
        const raw = localStorage.getItem(DRAFT_KEY.value)
        if (raw) {
            const d = JSON.parse(raw)
            Object.entries(d).forEach(([k, v]) => { if (k in form) form[k] = v })
            hasDraft.value = true
        }
    } catch (e) { /* ignore */ }
})

// Simpan draft setiap ada perubahan
watch([
    () => form.la_pct, () => form.ce_pct, () => form.pr_pct,
    () => form.nd_pct, () => form.y_pct,  () => form.tho2_pct,
    () => form.u3o8_pct, () => form.lab_method, () => form.equipment_used,
    () => form.sample_weight_g, () => form.notes, () => form.location_id,
], () => {
    try {
        localStorage.setItem(DRAFT_KEY.value, JSON.stringify(form.data()))
        draftSaved.value = true
        setTimeout(() => { draftSaved.value = false }, 1500)
    } catch (e) {}
}, { debounce: 300 })

// ─── Konfigurasi 5 unsur LTJ ───────────────────────────────────────────────────
const ELEMENTS = [
    { key: 'la_pct', symbol: 'La', num: 57, name_id: 'Lantanum',     name_en: 'Lanthanum',    color: 'hsl(var(--info))', bg: 'hsl(var(--info-soft))' },
    { key: 'ce_pct', symbol: 'Ce', num: 58, name_id: 'Serium',       name_en: 'Cerium',        color: 'hsl(var(--info))', bg: 'hsl(var(--info-soft))'  },
    { key: 'pr_pct', symbol: 'Pr', num: 59, name_id: 'Praseodimium', name_en: 'Praseodymium',  color: 'hsl(var(--success))', bg: 'hsl(var(--success-soft))'  },
    { key: 'nd_pct', symbol: 'Nd', num: 60, name_id: 'Neodimium',    name_en: 'Neodymium',     color: 'hsl(var(--warning))', bg: 'hsl(var(--warning-soft))'  },
    { key: 'y_pct',  symbol: 'Y',  num: 39, name_id: 'Itrium',       name_en: 'Yttrium',       color: 'hsl(var(--danger))', bg: 'hsl(var(--danger-soft))'  },
]

// ─── Kalkulasi real-time ───────────────────────────────────────────────────────
const batchWeight = computed(() => parseFloat(props.batch?.current_weight) || 0)

const elementValues = computed(() =>
    ELEMENTS.map(el => {
        const pct = Math.max(0, parseFloat(form[el.key]) || 0)
        const kg  = batchWeight.value > 0
            ? Math.round(batchWeight.value * pct / 100 * 10000) / 10000 : 0
        return { ...el, pct, kg }
    })
)

const totalReo = computed(() =>
    Math.round(elementValues.value.reduce((s, e) => s + e.pct, 0) * 10000) / 10000
)

const totalLtjKg = computed(() =>
    Math.round(elementValues.value.reduce((s, e) => s + e.kg, 0) * 10000) / 10000
)

const maxPct = computed(() =>
    Math.max(...elementValues.value.map(e => e.pct), 0.001)
)

const reoQuality = computed(() => {
    const r = totalReo.value
    if (r >= 10) return { id: 'Sangat Baik', en: 'Excellent', color: 'hsl(var(--success))', bg: 'hsl(var(--success-soft))'  }
    if (r >= 5)  return { id: 'Baik',        en: 'Good',      color: 'hsl(var(--info))', bg: 'hsl(var(--info-soft))'  }
    if (r >= 2)  return { id: 'Cukup',       en: 'Moderate',  color: 'hsl(var(--warning))', bg: 'hsl(var(--warning-soft))'  }
    if (r > 0)   return { id: 'Rendah',      en: 'Low',       color: 'hsl(var(--danger))', bg: 'hsl(var(--danger-soft))'  }
    return null
})

const reoProgress = computed(() => Math.min((totalReo.value / 15) * 100, 100))

// ─── Submit ────────────────────────────────────────────────────────────────────
function submit() {
    form.post(`/company/batches/${props.batch.id}/lab`, {
        onSuccess: () => {
            try { localStorage.removeItem(DRAFT_KEY.value) } catch (e) {}
        },
    })
}

function clearDraft() {
    try { localStorage.removeItem(DRAFT_KEY.value) } catch (e) {}
    form.reset()
    hasDraft.value = false
}
</script>

<template>
<AppLayout>
    <Head :title="`${lang==='en'?'Lab Analysis':'Analisis Lab'} — ${batch.batch_code}`"/>
    <template #header>{{ lang==='en' ? 'LTJ Lab Analysis Input' : 'Input Analisis Lab LTJ' }}</template>

    <div class="max-w-[1400px] mx-auto space-y-4">

        <!-- Breadcrumb -->
        <nav class="flex items-center gap-1.5 text-xs text-muted-foreground animate-fade-in">
            <Link href="/company/process/project_plant" class="hover:text-foreground transition-colors">Project Plant</Link>
            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
            </svg>
            <Link :href="`/company/batches/${batch.id}`" class="hover:text-foreground font-mono">{{ batch.batch_code }}</Link>
            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
            </svg>
            <span class="text-foreground font-medium">{{ lang==='en' ? 'Lab Analysis' : 'Analisis Lab' }}</span>
        </nav>

        <!-- Draft restore banner -->
        <Transition name="slide-down">
            <div v-if="hasDraft"
                 class="flex items-center justify-between px-4 py-3 rounded-xl border border-warning/30 animate-fade-in"
                 style="background:hsl(var(--warning-soft))">
                <div class="flex items-center gap-2">
                    <span class="text-base"></span>
                    <p class="text-xs font-semibold text-warning">
                        {{ lang==='en'
                            ? 'Draft restored from your previous session.'
                            : 'Draft dipulihkan dari sesi sebelumnya.' }}
                    </p>
                </div>
                <button @click="clearDraft"
                        class="text-xs font-bold text-warning hover:text-warning underline px-2 py-1 rounded">
                    {{ lang==='en' ? 'Clear & Reset' : 'Hapus & Reset' }}
                </button>
            </div>
        </Transition>

        <!-- Validated warning -->
        <div v-if="has_validated"
             class="flex items-center gap-3 px-4 py-3 rounded-xl border border-danger/30 animate-fade-in"
             style="background:hsl(var(--danger-soft))">
            <span class="text-base flex-shrink-0"></span>
            <p class="text-xs font-semibold text-danger">
                {{ lang==='en'
                    ? 'This batch already has a validated analysis. A new submission will create an additional record.'
                    : 'Batch ini sudah memiliki analisis yang tervalidasi. Input baru akan membuat catatan tambahan.' }}
            </p>
        </div>

        <!-- ════════════════════════════════════════════════
             MAIN LAYOUT — 2 kolom
        ════════════════════════════════════════════════ -->
        <div class="grid grid-cols-1 xl:grid-cols-[1fr_360px] gap-5">

            <!-- ─────────────────────────────────────────
                 KIRI: Form Input
            ───────────────────────────────────────── -->
            <div class="space-y-4">

                <!-- Batch Info Header -->
                <div class="rounded-2xl border border-border bg-card p-5 animate-fade-in">
                    <div class="flex items-center gap-4">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center text-3xl flex-shrink-0"
                             style="background:hsl(var(--primary)/0.12)">
                                                    </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap mb-1">
                                <h2 class="font-mono font-bold text-xl text-foreground tracking-wider">{{ batch.batch_code }}</h2>
                                <span v-if="batch.product?.is_hazardous"
                                      class="text-xs px-2 py-0.5 rounded-lg font-bold"
                                      style="background:hsl(var(--info-soft));color:hsl(var(--info))">
                                    {{ lang==='en' ? 'Radioactive' : 'Radioaktif' }}
                                </span>
                                <span class="text-xs px-2 py-0.5 rounded-lg font-bold bg-success-soft text-success">
                                    Project Plant
                                </span>
                            </div>
                            <p class="text-sm text-muted-foreground">{{ batch.product?.code }} — {{ batch.product?.name }}</p>
                            <p v-if="batch.location" class="text-xs text-muted-foreground mt-0.5">{{ batch.location }}</p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-xs text-muted-foreground uppercase tracking-wider mb-0.5">
                                {{ lang==='en' ? 'Batch Weight' : 'Berat Batch' }}
                            </p>
                            <p class="text-2xl font-black text-foreground tabular-nums leading-none">
                                {{ parseFloat(batch.current_weight).toLocaleString('id-ID', {maximumFractionDigits: 1}) }}
                            </p>
                            <p class="text-sm font-semibold text-muted-foreground">kg</p>
                        </div>
                    </div>
                </div>

                <!-- Form -->
                <form @submit.prevent="submit" class="space-y-4">

                    <!-- ── Lab Info ─────────────────────────────── -->
                    <div class="rounded-2xl border border-border bg-card p-5 space-y-4 animate-fade-in delay-50">
                        <h3 class="text-xs font-bold text-muted-foreground uppercase tracking-wider flex items-center gap-2 pb-3 border-b border-border">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15.3M14.25 3.104c.251.023.501.05.75.082M19.8 15.3l-1.57.393A9.065 9.065 0 0112 15a9.065 9.065 0 00-6.23-.693L5 14.5m14.8.8l1.402 1.402c1.232 1.232.65 3.318-1.067 3.611A48.309 48.309 0 0112 21c-2.773 0-5.491-.235-8.135-.687-1.718-.293-2.3-2.379-1.067-3.61L5 14.5"/>
                            </svg>
                            {{ lang==='en' ? 'Lab Information' : 'Informasi Lab' }}
                        </h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <label class="block text-xs font-semibold text-foreground">
                                    {{ lang==='en' ? 'Lab Location' : 'Lokasi Lab' }}
                                </label>
                                <select v-model="form.location_id"
                                        class="w-full h-10 px-3 rounded-xl text-xs border border-border bg-background text-foreground focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all">
                                    <option value="">{{ lang==='en' ? 'Select location...' : 'Pilih lokasi...' }}</option>
                                    <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                                        [{{ loc.code }}] {{ loc.full_name || loc.name }}
                                    </option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <label class="block text-xs font-semibold text-foreground">
                                    {{ lang==='en' ? 'Analysis Method' : 'Metode Analisis' }}
                                </label>
                                <select v-model="form.lab_method"
                                        class="w-full h-10 px-3 rounded-xl text-xs border border-border bg-background text-foreground focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all">
                                    <option v-for="m in methods" :key="m" :value="m">{{ m }}</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <label class="block text-xs font-semibold text-foreground">
                                    {{ lang==='en' ? 'Equipment Used' : 'Peralatan Digunakan' }}
                                </label>
                                <input v-model="form.equipment_used" type="text"
                                       :placeholder="lang==='en' ? 'e.g. XRF Analyzer X-500' : 'Contoh: XRF Analyzer X-500'"
                                       class="w-full h-10 px-3 rounded-xl text-xs border border-border bg-background text-foreground focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all"/>
                            </div>
                            <div class="space-y-1.5">
                                <label class="block text-xs font-semibold text-foreground">
                                    {{ lang==='en' ? 'Sample Weight (gram)' : 'Berat Sampel (gram)' }}
                                </label>
                                <input v-model="form.sample_weight_g" type="number" step="0.001" min="0"
                                       placeholder="50.000"
                                       class="w-full h-10 px-3 rounded-xl text-xs border border-border bg-background text-foreground focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all font-mono"/>
                            </div>
                        </div>
                    </div>

                    <!-- ── Parameter LTJ (5 Unsur) ─────────────── -->
                    <div class="rounded-2xl border border-border bg-card p-5 space-y-4 animate-fade-in delay-75">
                        <div class="flex items-center justify-between pb-3 border-b border-border">
                            <h3 class="text-xs font-bold text-muted-foreground uppercase tracking-wider flex items-center gap-2">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5m.75-9l3-3 2.148 2.148A12.061 12.061 0 0116.5 7.605"/>
                                </svg>
                                {{ lang==='en' ? 'LTJ Parameters (5 Active Elements)' : 'Parameter LTJ (5 Unsur Aktif)' }}
                            </h3>
                            <!-- Live Total REO badge -->
                            <div v-if="totalReo > 0"
                                 class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold"
                                 :style="`background:${reoQuality?.bg};color:${reoQuality?.color}`">
                                <span>REO</span>
                                <span class="text-base font-black tabular-nums">{{ totalReo.toFixed(2) }}%</span>
                            </div>
                        </div>

                        <!-- Element input rows -->
                        <div class="space-y-2.5">
                            <div v-for="el in elementValues" :key="el.key"
                                 class="group/el rounded-xl p-3.5 transition-all duration-200 cursor-pointer"
                                 :style="el.pct > 0
                                     ? `background:${el.bg};border:1px solid ${el.color}30`
                                     : 'background:hsl(var(--muted)/0.3);border:1px solid hsl(var(--border))'">
                                <div class="grid grid-cols-[44px_1fr_140px] gap-3 items-center">

                                    <!-- Symbol badge -->
                                    <div class="w-11 h-11 rounded-xl flex flex-col items-center justify-center text-white flex-shrink-0 shadow-sm"
                                         :style="`background:linear-gradient(135deg,${el.color},${el.color}cc)`">
                                        <span class="text-xs font-black leading-none">{{ el.symbol }}</span>
                                        <span class="text-xs leading-none opacity-60 mt-0.5">{{ el.num }}</span>
                                    </div>

                                    <!-- Name + calculated kg -->
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-foreground leading-tight">
                                            {{ lang==='en' ? el.name_en : el.name_id }}
                                        </p>
                                        <p class="text-xs font-mono mt-0.5 transition-all duration-300"
                                           :style="el.pct > 0 ? `color:${el.color}` : 'color:hsl(var(--muted-foreground))'">
                                            {{ el.kg > 0 ? `≈ ${el.kg.toFixed(4)} kg dari ${batchWeight.toLocaleString('id-ID')} kg` : '— masukkan persentase' }}
                                        </p>
                                        <!-- Mini progress bar -->
                                        <div class="mt-1.5 h-1.5 rounded-full bg-muted/60 overflow-hidden">
                                            <div class="h-full rounded-full transition-all duration-500"
                                                 :style="`width:${maxPct > 0 ? (el.pct/maxPct)*100 : 0}%;background:${el.color}`"/>
                                        </div>
                                    </div>

                                    <!-- % Input -->
                                    <div class="relative">
                                        <input v-model="form[el.key]"
                                               type="number" step="0.001" min="0" max="100"
                                               :placeholder="lang==='en' ? 'Enter %...' : 'Masukkan %...'"
                                               class="w-full h-10 pl-4 pr-8 rounded-xl text-sm font-bold border bg-background text-foreground transition-all text-right tabular-nums"
                                               :class="form.errors[el.key] ? 'border-danger/30 focus:ring-2 focus:ring-danger/30' : 'border-border focus:ring-2 focus:ring-primary/25 focus:border-primary'"
                                               :style="el.pct > 0 ? `color:${el.color}` : ''"
                                        />
                                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-muted-foreground pointer-events-none">%</span>
                                    </div>
                                </div>

                                <!-- Error message -->
                                <p v-if="form.errors[el.key]"
                                   class="text-xs text-danger mt-1.5 ml-14">{{ form.errors[el.key] }}</p>
                            </div>
                        </div>

                        <!-- REO Progress bar -->
                        <div v-if="totalReo > 0" class="pt-1 space-y-1.5">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-muted-foreground font-medium">Total REO Progress</span>
                                <span class="font-bold tabular-nums" :style="`color:${reoQuality?.color}`">{{ totalReo.toFixed(3) }}% / ~15%</span>
                            </div>
                            <div class="h-2.5 rounded-full bg-muted/60 overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-700"
                                     :style="`width:${reoProgress}%;background:${reoQuality?.color}`"/>
                            </div>
                            <!-- Stacked breakdown bar -->
                            <div class="flex h-1.5 rounded-full overflow-hidden gap-0.5 mt-1">
                                <div v-for="el in elementValues.filter(e => e.pct > 0)" :key="el.key"
                                     class="h-full rounded transition-all duration-500"
                                     :style="`width:${totalReo > 0 ? (el.pct/totalReo)*100 : 0}%;background:${el.color}`"
                                     :title="`${el.symbol}: ${el.pct}%`"/>
                            </div>
                        </div>
                    </div>

                    <!-- ── Parameter Radioaktif ────────────────── -->
                    <div v-if="batch.product?.is_hazardous"
                         class="rounded-2xl border p-5 space-y-4 animate-fade-in delay-100"
                         style="border-color:hsl(var(--info)/0.3);background:hsl(var(--info-soft))">
                        <h3 class="text-xs font-bold uppercase tracking-wider flex items-center gap-2 pb-3 border-b"
                            style="color:hsl(var(--info));border-color:hsl(var(--info-soft))">
                            <span class="text-base"></span>
                            {{ lang==='en' ? 'Radioactive Parameters (optional)' : 'Parameter Radioaktif (opsional)' }}
                        </h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <label class="block text-xs font-semibold" style="color:hsl(var(--info))">
                                    ThO₂ — Thorium Oxide (%)
                                </label>
                                <div class="relative">
                                    <input v-model="form.tho2_pct" type="number" step="0.001" min="0" max="100"
                                           :placeholder="lang==='en' ? 'Enter ThO₂...' : 'Masukkan ThO₂...'"
                                           class="w-full h-10 pl-4 pr-8 rounded-xl text-xs border bg-background text-foreground focus:ring-2 transition-all font-mono"
                                           style="border-color:hsl(var(--info)/0.3)"/>
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold" style="color:hsl(var(--info))">%</span>
                                </div>
                            </div>
                            <div class="space-y-1.5">
                                <label class="block text-xs font-semibold" style="color:hsl(var(--info))">
                                    U₃O₈ — Uranium Oxide (%)
                                </label>
                                <div class="relative">
                                    <input v-model="form.u3o8_pct" type="number" step="0.001" min="0" max="100"
                                           :placeholder="lang==='en' ? 'Enter U₃O₈...' : 'Masukkan U₃O₈...'"
                                           class="w-full h-10 pl-4 pr-8 rounded-xl text-xs border bg-background text-foreground focus:ring-2 transition-all font-mono"
                                           style="border-color:hsl(var(--info)/0.3)"/>
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold" style="color:hsl(var(--info))">%</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Notes ──────────────────────────────── -->
                    <div class="rounded-2xl border border-border bg-card p-5 space-y-3 animate-fade-in delay-150">
                        <h3 class="text-xs font-bold text-muted-foreground uppercase tracking-wider">
                            {{ lang==='en' ? 'Additional Notes' : 'Catatan Tambahan' }}
                        </h3>
                        <textarea v-model="form.notes" rows="3"
                                  :placeholder="lang==='en'
                                      ? 'Sample conditions, anomalies, or special observations...'
                                      : 'Kondisi sampel, anomali, atau catatan pengamatan khusus...'"
                                  class="w-full px-4 py-3 rounded-xl text-xs border border-border bg-background text-foreground placeholder:text-muted-foreground focus:ring-2 focus:ring-primary/25 focus:border-primary transition-all resize-none leading-relaxed"/>
                    </div>

                    <!-- ── Action Buttons ──────────────────────── -->
                    <div class="flex items-center gap-3 animate-fade-in delay-200">
                        <Link href="/company/process/project_plant"
                              class="h-11 px-4 rounded-xl text-xs font-semibold text-muted-foreground border border-border flex items-center gap-2 hover:bg-muted hover:text-foreground transition-all active:scale-[0.98]">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
                            </svg>
                            {{ lang==='en' ? 'Back' : 'Kembali' }}
                        </Link>

                        <!-- Draft saved indicator -->
                        <Transition name="toast">
                            <span v-if="draftSaved"
                                  class="text-xs text-success font-semibold flex items-center gap-1">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75"/>
                                </svg>
                                {{ lang==='en' ? 'Draft saved' : 'Draft tersimpan' }}
                            </span>
                        </Transition>

                        <button type="submit" :disabled="form.processing"
                                class="flex-1 h-11 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2 transition-all disabled:opacity-50 active:scale-[0.97]"
                                style="background:linear-gradient(135deg,hsl(var(--primary)),hsl(207 30% 28%));box-shadow:0 4px 14px hsl(var(--primary)/0.3)">
                            <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            {{ form.processing
                                ? (lang==='en' ? 'Saving Analysis...' : 'Menyimpan Analisis...')
                                : (lang==='en' ? 'Save Lab Analysis' : 'Simpan Analisis Lab') }}
                        </button>
                    </div>
                </form>
            </div>

            <!-- ─────────────────────────────────────────
                 KANAN: Live Preview Panel (sticky)
            ───────────────────────────────────────── -->
            <div class="xl:block">
                <div class="sticky top-5 space-y-4">

                    <!-- ── REO Live Display ────────────────────── -->
                    <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-100">
                        <!-- Header -->
                        <div class="px-5 py-3.5 border-b border-border flex items-center justify-between"
                             :style="totalReo > 0 ? `background:${reoQuality?.bg}` : ''">
                            <div class="flex items-center gap-2">
                                <div class="w-1.5 h-1.5 rounded-full animate-pulse"
                                     :style="`background:${totalReo > 0 ? reoQuality?.color : 'hsl(var(--muted-foreground))'}`"/>
                                <span class="text-xs font-bold text-foreground">
                                    {{ lang==='en' ? 'Live Preview' : 'Pratinjau Live' }}
                                </span>
                            </div>
                            <div v-if="reoQuality"
                                 class="px-2.5 py-1 rounded-lg text-xs font-bold"
                                 :style="`background:${reoQuality.bg};color:${reoQuality.color};border:1px solid ${reoQuality.color}40`">
                                {{ lang==='en' ? reoQuality.en : reoQuality.id }}
                            </div>
                        </div>

                        <div class="p-5 space-y-4">
                            <!-- Total REO big number -->
                            <div class="rounded-xl p-5 text-center"
                                 :style="totalReo > 0
                                     ? `background:${reoQuality?.bg}`
                                     : 'background:hsl(var(--muted)/0.4)'">
                                <p class="text-xs font-semibold text-muted-foreground uppercase tracking-widest mb-2">
                                    Total REO
                                </p>
                                <div class="flex items-baseline justify-center gap-1">
                                    <p class="font-heading font-black tabular-nums leading-none"
                                       :class="totalReo > 0 ? 'text-6xl' : 'text-4xl'"
                                       :style="totalReo > 0 ? `color:${reoQuality?.color}` : 'color:hsl(var(--muted-foreground))'">
                                        {{ totalReo > 0 ? totalReo.toFixed(3) : '—' }}
                                    </p>
                                    <span v-if="totalReo > 0" class="text-xl font-bold text-muted-foreground">%</span>
                                </div>
                                <p v-if="totalReo > 0" class="text-xs text-muted-foreground mt-2 font-mono">
                                    ≈ {{ totalLtjKg.toFixed(4) }} kg LTJ
                                </p>
                            </div>

                            <!-- Batch stats -->
                            <div class="grid grid-cols-2 gap-2">
                                <div class="rounded-xl border border-border bg-muted/20 p-3 text-center">
                                    <p class="text-xs text-muted-foreground uppercase tracking-wider">
                                        {{ lang==='en' ? 'Batch Weight' : 'Berat Batch' }}
                                    </p>
                                    <p class="text-sm font-bold text-foreground tabular-nums">{{ batchWeight.toLocaleString('id-ID') }} kg</p>
                                </div>
                                <div class="rounded-xl border border-border bg-muted/20 p-3 text-center">
                                    <p class="text-xs text-muted-foreground uppercase tracking-wider">LTJ Total</p>
                                    <p class="text-sm font-bold tabular-nums"
                                       :style="totalLtjKg > 0 ? `color:${reoQuality?.color}` : 'color:hsl(var(--muted-foreground))'">
                                        {{ totalLtjKg > 0 ? totalLtjKg.toFixed(3) + ' kg' : '—' }}
                                    </p>
                                </div>
                            </div>

                            <!-- Element breakdown -->
                            <div class="space-y-2">
                                <p class="text-xs font-bold text-muted-foreground uppercase tracking-wider">
                                    {{ lang==='en' ? 'Element Breakdown' : 'Rincian Unsur' }}
                                </p>

                                <!-- Stacked bar -->
                                <div v-if="totalReo > 0" class="flex h-3 rounded-full overflow-hidden gap-0.5">
                                    <div v-for="el in elementValues.filter(e => e.pct > 0)" :key="el.key"
                                         class="h-full rounded transition-all duration-500 relative group/bar"
                                         :style="`width:${totalReo > 0 ? (el.pct/totalReo)*100 : 0}%;background:${el.color}`"
                                         :title="`${el.symbol}: ${el.pct}%`"/>
                                </div>
                                <div v-else class="h-3 rounded-full bg-muted/50"/>

                                <!-- Element rows -->
                                <div class="space-y-1.5 mt-2">
                                    <div v-for="el in elementValues" :key="el.key"
                                         class="flex items-center gap-2 px-2 py-1.5 rounded-lg transition-all"
                                         :style="el.pct > 0 ? `background:${el.bg}` : ''">
                                        <!-- Color dot + symbol -->
                                        <div class="w-6 h-6 rounded-md flex items-center justify-center text-xs font-black text-white flex-shrink-0"
                                             :style="`background:${el.pct > 0 ? el.color : 'hsl(var(--muted-foreground))'}`">
                                            {{ el.symbol }}
                                        </div>
                                        <!-- Name -->
                                        <span class="flex-1 text-xs font-semibold text-muted-foreground truncate">
                                            {{ lang==='en' ? el.name_en : el.name_id }}
                                        </span>
                                        <!-- Values -->
                                        <div class="text-right flex-shrink-0">
                                            <span class="text-xs font-bold tabular-nums"
                                                  :style="el.pct > 0 ? `color:${el.color}` : 'color:hsl(var(--muted-foreground))'">
                                                {{ el.pct > 0 ? el.pct.toFixed(3) + '%' : '—' }}
                                            </span>
                                            <p v-if="el.kg > 0" class="text-xs text-muted-foreground font-mono">
                                                {{ el.kg.toFixed(4) }} kg
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Empty state -->
                            <div v-if="totalReo === 0" class="py-4 text-center">
                                <div class="text-3xl mb-2 opacity-40"></div>
                                <p class="text-xs text-muted-foreground">
                                    {{ lang==='en'
                                        ? 'Enter element percentages to see live calculations'
                                        : 'Masukkan persentase unsur untuk melihat kalkulasi langsung' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- ── Quality Guide ───────────────────────── -->
                    <div class="rounded-2xl border border-border bg-card p-4 space-y-3 animate-fade-in delay-150">
                        <h3 class="text-xs font-bold text-muted-foreground uppercase tracking-wider">
                            {{ lang==='en' ? 'REO Quality Guide' : 'Panduan Kualitas REO' }}
                        </h3>
                        <div class="space-y-1.5">
                            <div v-for="q in [
                                { id:'≥10% — Sangat Baik', en:'≥10% — Excellent', color:'hsl(var(--success))', bg:'hsl(var(--success-soft))'  },
                                { id:'5–10% — Baik',       en:'5–10% — Good',     color:'hsl(var(--info))', bg:'hsl(var(--info-soft))'  },
                                { id:'2–5% — Cukup',       en:'2–5% — Moderate',  color:'hsl(var(--warning))', bg:'hsl(var(--warning-soft))'  },
                                { id:'<2% — Rendah',       en:'<2% — Low',        color:'hsl(var(--danger))', bg:'hsl(var(--danger-soft))'  },
                            ]" :key="q.id"
                                 class="flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-semibold"
                                 :style="`background:${q.bg};color:${q.color}`">
                                <div class="w-2 h-2 rounded-full flex-shrink-0" :style="`background:${q.color}`"/>
                                {{ lang==='en' ? q.en : q.id }}
                            </div>
                        </div>
                        <p class="text-xs text-muted-foreground leading-relaxed pt-1 border-t border-border">
                            {{ lang==='en'
                                ? 'La + Ce + Pr + Nd + Y = Total REO. Radioactive (ThO₂, U₃O₈) recorded separately.'
                                : 'La + Ce + Pr + Nd + Y = Total REO. Radioaktif (ThO₂, U₃O₈) dicatat terpisah.' }}
                        </p>
                    </div>

                    <!-- ── Existing analyses ──────────────────── -->
                    <div v-if="existing_analyses > 0"
                         class="rounded-xl border px-4 py-3 animate-fade-in"
                         style="border-color:hsl(var(--warning)/0.3);background:hsl(var(--warning-soft))">
                        <p class="text-xs font-semibold text-warning">
                            {{ lang==='en'
                                ? `${existing_analyses} previous record(s) for this batch.`
                                : `${existing_analyses} catatan analisis sebelumnya untuk batch ini.` }}
                        </p>
                        <Link href="/company/lab"
                              class="text-xs font-bold text-warning hover:underline mt-1 block">
                            {{ lang==='en' ? 'View all in Lab Index →' : 'Lihat semua di Lab Index →' }}
                        </Link>
                    </div>
                </div>
            </div>

        </div>
    </div>
</AppLayout>
</template>

<style scoped>
@keyframes fade-in { from{opacity:0;transform:translateY(5px)} to{opacity:1;transform:translateY(0)} }
.animate-fade-in { animation: fade-in 0.28s ease-out both; }
.delay-50  { animation-delay: 50ms; }
.delay-75  { animation-delay: 75ms; }
.delay-100 { animation-delay: 100ms; }
.delay-150 { animation-delay: 150ms; }
.delay-200 { animation-delay: 200ms; }

.slide-down-enter-active { transition: all 0.2s ease; }
.slide-down-leave-active { transition: all 0.15s ease; }
.slide-down-enter-from   { opacity:0; transform:translateY(-6px); }
.slide-down-leave-to     { opacity:0; transform:translateY(-4px); }

.toast-enter-active { transition: all 0.25s ease; }
.toast-leave-active { transition: all 0.15s ease; }
.toast-enter-from   { opacity:0; transform:translateX(-6px); }
.toast-leave-to     { opacity:0; }
</style>