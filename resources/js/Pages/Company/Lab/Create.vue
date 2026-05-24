<script setup>
import { ref, computed, watch } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    batch:     Object,
    locations: Array,
    methods:   Array,
})

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

// ─── Hitung total REO realtime ────────────────────────────────────────────
const totalReo = computed(() => {
    const sum = [form.la_pct, form.ce_pct, form.pr_pct, form.nd_pct, form.y_pct]
        .reduce((acc, v) => acc + (parseFloat(v) || 0), 0)
    return Math.round(sum * 1000) / 1000
})

// ─── Hitung berat per elemen ──────────────────────────────────────────────
const batchWeight = computed(() => props.batch?.current_weight ?? 0)

function calcKg(pct) {
    const v = parseFloat(pct)
    if (!v || !batchWeight.value) return null
    return Math.round(batchWeight.value * v / 100 * 1000) / 1000
}

// ─── Parameter config ──────────────────────────────────────────────────────
const parameters = computed(() => [
    { key: 'la_pct', label: 'Lantanum (La)', symbol: 'La', color: 'bg-violet-500', required: true },
    { key: 'ce_pct', label: 'Serium (Ce)',   symbol: 'Ce', color: 'bg-blue-500',   required: true },
    { key: 'pr_pct', label: 'Praseodimium (Pr)', symbol: 'Pr', color: 'bg-cyan-500', required: true },
    { key: 'nd_pct', label: 'Neodimium (Nd)', symbol: 'Nd', color: 'bg-emerald-500', required: true },
    { key: 'y_pct',  label: 'Itrium (Y)',     symbol: 'Y',  color: 'bg-amber-500', required: true },
])

function submit() {
    form.post(`/company/batches/${props.batch.id}/lab`, {
        onSuccess: () => form.reset(),
    })
}

function formatWeight(kg) {
    if (!kg) return '—'
    return `${parseFloat(kg).toLocaleString('id-ID', { maximumFractionDigits: 1 })} kg`
}
</script>

<template>
    <AppLayout>
        <Head :title="`Lab Analysis — ${batch.batch_code}`" />
        <template #header>Lab Analisis</template>

        <div class="max-w-3xl mx-auto space-y-6">

            <!-- Breadcrumb -->
            <div class="flex items-center gap-2 text-sm text-muted-foreground animate-fade-in">
                <Link href="/company/batches" class="hover:text-foreground transition-colors">Batch</Link>
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                <Link :href="`/company/batches/${batch.id}`" class="hover:text-foreground transition-colors font-mono text-xs">{{ batch.batch_code }}</Link>
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                <span class="text-foreground font-medium">Input Analisis Lab</span>
            </div>

            <!-- Batch Info Card -->
            <div class="rounded-2xl border border-border bg-card p-5 animate-scale-in">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl flex items-center justify-center text-2xl flex-shrink-0"
                         style="background: hsl(var(--primary)/0.1);">
                        🔬
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2 class="font-mono font-bold text-lg text-foreground">{{ batch.batch_code }}</h2>
                            <span v-if="batch.product_code?.is_hazardous"
                                  class="text-xs px-2 py-0.5 rounded-lg font-semibold badge-radioactive">
                                ☢ Radioaktif
                            </span>
                        </div>
                        <p class="text-sm text-muted-foreground">
                            {{ batch.product_code?.code }} — {{ batch.product_code?.name }}
                        </p>
                    </div>
                    <div class="text-right flex-shrink-0">
                        <p class="text-xs text-muted-foreground">Berat Batch</p>
                        <p class="text-xl font-bold text-foreground">{{ formatWeight(batch.current_weight) }}</p>
                    </div>
                </div>
            </div>

            <!-- Form -->
            <form @submit.prevent="submit" class="space-y-5 animate-fade-in delay-100">

                <!-- Lokasi & Metode -->
                <div class="rounded-2xl border border-border bg-card p-6 space-y-5">
                    <h3 class="text-sm font-semibold text-foreground border-b border-border pb-3">
                        Informasi Lab
                    </h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">Lokasi Lab</label>
                            <select
                                v-model="form.location_id"
                                class="w-full h-11 px-3 rounded-xl text-sm border bg-background text-foreground outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all cursor-pointer"
                                :class="form.errors.location_id ? 'border-red-500' : 'border-input'"
                            >
                                <option value="">Pilih lokasi...</option>
                                <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                                    [{{ loc.code }}] {{ loc.full_name || loc.name }}
                                </option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">Metode Analisis</label>
                            <select
                                v-model="form.lab_method"
                                class="w-full h-11 px-3 rounded-xl text-sm border border-input bg-background text-foreground outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all cursor-pointer"
                            >
                                <option v-for="m in methods" :key="m" :value="m">{{ m }}</option>
                            </select>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">Peralatan</label>
                            <input
                                v-model="form.equipment_used"
                                type="text"
                                placeholder="Contoh: XRF Analyzer X-500"
                                class="w-full h-11 px-3 rounded-xl text-sm border border-input bg-background text-foreground outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">Berat Sampel (gram)</label>
                            <input
                                v-model="form.sample_weight_g"
                                type="number"
                                step="0.001"
                                placeholder="Contoh: 50.0"
                                class="w-full h-11 px-3 rounded-xl text-sm border border-input bg-background text-foreground outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                            />
                        </div>
                    </div>
                </div>

                <!-- Parameter LTJ -->
                <div class="rounded-2xl border border-border bg-card p-6 space-y-5">
                    <div class="flex items-center justify-between border-b border-border pb-3">
                        <h3 class="text-sm font-semibold text-foreground">Parameter LTJ (5 Unsur Aktif)</h3>
                        <!-- Live Total REO -->
                        <div class="flex items-center gap-2 px-3 py-1.5 rounded-xl border"
                             :style="totalReo > 0
                                ? 'background: hsl(var(--primary)/0.08); border-color: hsl(var(--primary)/0.3);'
                                : 'background: hsl(var(--muted)); border-color: hsl(var(--border));'">
                            <span class="text-xs text-muted-foreground font-medium">Total REO:</span>
                            <span class="text-sm font-bold" :style="totalReo > 0 ? 'color: hsl(var(--primary));' : 'color: hsl(var(--muted-foreground));'">
                                {{ totalReo }}%
                            </span>
                        </div>
                    </div>

                    <!-- Parameter Input Grid -->
                    <div class="space-y-3">
                        <div
                            v-for="param in parameters"
                            :key="param.key"
                            class="flex items-center gap-4 p-3.5 rounded-xl border border-border hover:border-primary/20 transition-all duration-150 group"
                            :class="form[param.key] ? 'bg-primary/3' : 'bg-muted/20'"
                        >
                            <!-- Symbol badge -->
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 text-white text-sm font-bold shadow-sm"
                                 :class="param.color">
                                {{ param.symbol }}
                            </div>

                            <!-- Label -->
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-foreground">{{ param.label }}</p>
                                <p class="text-xs text-muted-foreground">
                                    ≈ {{ calcKg(form[param.key]) ? calcKg(form[param.key]) + ' kg' : '—' }}
                                </p>
                            </div>

                            <!-- Input -->
                            <div class="relative w-36 flex-shrink-0">
                                <input
                                    v-model="form[param.key]"
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    max="100"
                                    :placeholder="param.required ? 'Wajib...' : 'Opsional'"
                                    class="w-full h-10 pl-3 pr-10 rounded-lg text-sm border bg-background text-foreground outline-none transition-all text-right"
                                    :class="[
                                        form.errors[param.key] ? 'border-red-400 focus:ring-2 focus:ring-red-400/20' : 'border-input focus:ring-2 focus:ring-primary/20 focus:border-primary',
                                        form[param.key] ? 'font-semibold' : ''
                                    ]"
                                />
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-muted-foreground">%</span>
                            </div>
                        </div>
                    </div>

                    <!-- Progress bar visual REO -->
                    <div v-if="totalReo > 0" class="pt-2 space-y-1.5">
                        <div class="flex items-center justify-between text-xs text-muted-foreground">
                            <span>Komposisi REO</span>
                            <span>{{ totalReo }}%</span>
                        </div>
                        <div class="h-2 rounded-full bg-muted overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all duration-500"
                                :style="`width: ${Math.min(totalReo, 100)}%; background: linear-gradient(to right, hsl(var(--primary)), hsl(207 40% 60%));`"
                            />
                        </div>
                        <!-- Breakdown bar -->
                        <div class="flex h-1.5 rounded-full overflow-hidden gap-0.5">
                            <div v-if="form.la_pct" class="h-full bg-violet-500 rounded transition-all duration-300"
                                 :style="`width: ${(parseFloat(form.la_pct)||0)/totalReo*100}%`" :title="`La: ${form.la_pct}%`"/>
                            <div v-if="form.ce_pct" class="h-full bg-blue-500 rounded transition-all duration-300"
                                 :style="`width: ${(parseFloat(form.ce_pct)||0)/totalReo*100}%`" :title="`Ce: ${form.ce_pct}%`"/>
                            <div v-if="form.pr_pct" class="h-full bg-cyan-500 rounded transition-all duration-300"
                                 :style="`width: ${(parseFloat(form.pr_pct)||0)/totalReo*100}%`" :title="`Pr: ${form.pr_pct}%`"/>
                            <div v-if="form.nd_pct" class="h-full bg-emerald-500 rounded transition-all duration-300"
                                 :style="`width: ${(parseFloat(form.nd_pct)||0)/totalReo*100}%`" :title="`Nd: ${form.nd_pct}%`"/>
                            <div v-if="form.y_pct"  class="h-full bg-amber-500 rounded transition-all duration-300"
                                 :style="`width: ${(parseFloat(form.y_pct)||0)/totalReo*100}%`" :title="`Y: ${form.y_pct}%`"/>
                        </div>
                        <div class="flex items-center gap-3 flex-wrap">
                            <div v-for="param in parameters" :key="param.key" class="flex items-center gap-1 text-xs text-muted-foreground">
                                <div class="w-2 h-2 rounded-full" :class="param.color"/>
                                {{ param.symbol }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Parameter Radioaktif (Opsional) -->
                <div v-if="batch.product_code?.is_hazardous"
                     class="rounded-2xl border p-5 space-y-4 animate-fade-in"
                     style="border-color: rgba(168,85,247,0.3); background: rgba(168,85,247,0.04);">
                    <h3 class="text-sm font-semibold flex items-center gap-2" style="color: #7c3aed;">
                        <span>☢</span> Parameter Radioaktif (Opsional)
                    </h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="block text-xs font-semibold" style="color: #7c3aed;">ThO₂ (%)</label>
                            <div class="relative">
                                <input v-model="form.tho2_pct" type="number" step="0.001" min="0" max="100" placeholder="Thorium Oxide"
                                       class="w-full h-10 pl-3 pr-10 rounded-xl text-sm border bg-background text-foreground outline-none focus:ring-2 transition-all"
                                       style="border-color: rgba(168,85,247,0.3); focus-ring-color: rgba(168,85,247,0.2);"/>
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-purple-400">%</span>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-xs font-semibold" style="color: #7c3aed;">U₃O₈ (%)</label>
                            <div class="relative">
                                <input v-model="form.u3o8_pct" type="number" step="0.001" min="0" max="100" placeholder="Uranium Oxide"
                                       class="w-full h-10 pl-3 pr-10 rounded-xl text-sm border bg-background text-foreground outline-none focus:ring-2 transition-all"
                                       style="border-color: rgba(168,85,247,0.3);"/>
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-purple-400">%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Catatan -->
                <div class="rounded-2xl border border-border bg-card p-5 space-y-3">
                    <h3 class="text-sm font-semibold text-foreground">Catatan Tambahan</h3>
                    <textarea
                        v-model="form.notes"
                        rows="3"
                        placeholder="Kondisi sampel, anomali, atau catatan khusus lainnya..."
                        class="w-full px-4 py-3 rounded-xl text-sm border border-input bg-background text-foreground placeholder:text-muted-foreground outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all resize-none"
                    />
                </div>

                <!-- Action Buttons -->
                <div class="flex items-center gap-3">
                    <Link
                        :href="`/company/batches/${batch.id}`"
                        class="flex-1 h-11 rounded-xl text-sm font-semibold text-muted-foreground border border-border flex items-center justify-center gap-2 hover:bg-muted hover:text-foreground transition-all duration-200 active:scale-[0.98]"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
                        </svg>
                        Kembali
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="flex-[2] h-11 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed hover:shadow-lg hover:shadow-primary/25 hover:-translate-y-0.5 active:scale-[0.97]"
                        style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));"
                    >
                        <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        {{ form.processing ? 'Menyimpan...' : 'Simpan Analisis Lab' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>