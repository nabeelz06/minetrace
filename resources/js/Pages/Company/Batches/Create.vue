<script setup>
/**
 * Create.vue — Form pembuatan batch baru MIT (Mineral Ikutan Timah).
 *
 * FITUR:
 * Pembuatan batch tetap di stage lab_kimia/receiving (TIDAK auto-forward)
 * Bilingual (ID/EN)
 * Tag RFID TIDAK didaftarkan di sini (satu jalur: halaman RFID / tombol "Daftarkan Tag" di detail batch)
 *
 * ITEM 3 — EXPLICIT SEND/RECEIVE:
 * Batch yang baru dibuat TETAP di stage 'lab_kimia' dengan status 'active'.
 * Operator Wet Process harus secara EKSPLISIT menekan tombol "Terima →"
 * di halaman Stage (Process/Stage.vue → Panel Incoming) untuk memindahkannya.
 * Tidak ada auto-forward sama sekali dari halaman ini.
 */
import { computed, inject } from 'vue'
import { Head, Link, useForm }                from '@inertiajs/vue3'
import AppLayout                              from '@/Layouts/AppLayout.vue'
import { useI18n }                            from '@/composables/core/useI18n.js'
import AppIcon                               from '@/components/app/AppIcon.vue'

const { lang, t } = useI18n()
const toast = inject('toast', null)

const props = defineProps({
    productCodes: Array,
    locations:    Array,
    user_name:    String,
    user_role:    String,
    timestamp_now:String,
})

// ═══════════════════════════════════════════════════════════════════════
// FORM STATE
// ═══════════════════════════════════════════════════════════════════════
const form = useForm({
    product_code_id: '',
    location_id:     '',
    initial_weight:  '',
    notes:           '',
})

const selectedProduct = computed(() =>
    props.productCodes?.find(p => p.id == form.product_code_id) ?? null
)

const weightDisplay = computed(() => {
    const w = parseFloat(form.initial_weight)
    if (!w || isNaN(w)) return null
    if (w >= 1_000_000) return `= ${(w / 1_000_000).toFixed(3)} kT`
    if (w >= 1_000)     return `= ${(w / 1_000).toFixed(3)} ton`
    return null
})

function submit() {
    form.post('/company/batches', {
        onSuccess: () => {
            form.reset()
        },
        onError: (errors) => {
            const msg = Object.values(errors)[0]
            toast?.('error', msg, 5000)
        },
    })
}

</script>

<template>
<AppLayout>
    <Head :title="lang === 'en' ? 'Create New Batch' : 'Buat Batch Baru'"/>
    <template #header>{{ lang === 'en' ? 'Create New Batch' : 'Buat Batch Baru' }}</template>

    <div class="max-w-2xl mx-auto space-y-6">

        <!-- ─── Breadcrumb ─────────────────────────────────────────── -->
        <div class="flex items-center gap-2 text-sm text-muted-foreground animate-fade-in">
            <Link href="/company/batches" class="hover:text-foreground transition-colors">
                {{ lang === 'en' ? 'Batch Management' : 'Manajemen Batch' }}
            </Link>
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
            </svg>
            <span class="text-foreground font-medium">
                {{ lang === 'en' ? 'Create New Batch' : 'Buat Batch Baru' }}
            </span>
        </div>

        <!-- ─── Info Banner ────────────────────────────────────────── -->
        <div class="rounded-2xl border p-4 flex items-start gap-3 animate-fade-in delay-75"
             style="border-color: hsl(var(--primary)/0.25); background: hsl(var(--primary)/0.05);">
            <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0 mt-0.5"
                 style="background: hsl(var(--primary)/0.15);">
                <svg class="w-4 h-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>
                </svg>
            </div>
            <div>
                <p class="text-sm font-semibold text-foreground">
                    {{ lang === 'en' ? 'Traceability Starting Point' : 'Titik Awal Traceability' }}
                </p>
                <p class="text-xs text-muted-foreground mt-0.5 leading-relaxed">
                    {{ lang === 'en'
                        ? 'Register a new Tin Mineral By-product (MIT) batch from the Chemical Lab. After creation, the batch stays at the Lab Kimia stage. The Wet Process operator must explicitly receive it via the Stage page.'
                        : 'Daftarkan batch Mineral Ikutan Timah (MIT) baru dari Stasiun Uji Lab Kimia. Setelah dibuat, batch tetap di stage Lab Kimia. Operator Wet Process harus secara eksplisit menerimanya melalui halaman Stage.' }}
                </p>
                <!-- Workflow hint -->
                <div class="flex items-center gap-1.5 mt-2 flex-wrap">
                    <span class="text-xs font-bold px-2 py-0.5 rounded-md bg-info-soft text-info">
                        1. Lab Kimia (Buat)
                    </span>
                    <svg class="w-3 h-3 text-muted-foreground flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                    </svg>
                    <span class="text-xs font-bold px-2 py-0.5 rounded-md bg-info-soft text-info">
                        2. Receiving (Terima eksplisit)
                    </span>
                    <svg class="w-3 h-3 text-muted-foreground flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                    </svg>
                    <span class="text-xs font-bold px-2 py-0.5 rounded-md bg-info-soft text-info">
                        3. Wet Process (Kirim eksplisit)
                    </span>
                    <span class="text-xs text-muted-foreground">...</span>
                </div>
            </div>
        </div>

        <!-- ─── Main Form Card ─────────────────────────────────────── -->
        <div class="rounded-2xl border border-border bg-card overflow-hidden animate-scale-in delay-100">
            <div class="px-6 py-5 border-b border-border flex items-center justify-between">
                <div>
                    <h2 class="font-heading text-base font-bold text-foreground">
                        {{ lang === 'en' ? 'Batch Information' : 'Informasi Batch' }}
                    </h2>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ lang === 'en'
                            ? 'Fill in all required information for the new MIT batch'
                            : 'Isi semua informasi yang diperlukan untuk batch MIT baru' }}
                    </p>
                </div>
                <!-- Current timestamp -->
                <div class="text-right hidden sm:block">
                    <p class="text-xs text-muted-foreground uppercase tracking-wider">
                        {{ lang === 'en' ? 'Entry Time' : 'Waktu Input' }}
                    </p>
                    <p class="font-mono text-xs font-bold text-foreground">{{ timestamp_now }}</p>
                </div>
            </div>

            <form @submit.prevent="submit" class="p-6 space-y-5">

                <!-- ─── Kode Produk ──────────────────────────────────── -->
                <div class="space-y-2">
                    <label class="block text-sm font-semibold text-foreground">
                        {{ lang === 'en' ? 'Product Code' : 'Kode Produk' }}
                        <span class="text-danger ml-0.5">*</span>
                    </label>
                    <select
                        v-model="form.product_code_id"
                        class="w-full h-11 px-4 rounded-xl text-sm border bg-background text-foreground cursor-pointer"
                        :class="form.errors.product_code_id ? 'border-danger/30 focus:ring-2 focus:ring-danger/30' : 'border-input focus:border-primary focus:ring-2 focus:ring-primary/20'"
                    >
                        <option value="" disabled>
                            {{ lang === 'en' ? 'Select product code...' : 'Pilih kode produk...' }}
                        </option>
                        <option v-for="p in productCodes" :key="p.id" :value="p.id">
                            [{{ p.code }}] {{ p.name }}
                        </option>
                    </select>

                    <!-- Product info card -->
                    <Transition
                        enter-active-class="transition-all duration-200 ease-out"
                        enter-from-class="opacity-0 translate-y-1"
                        enter-to-class="opacity-100 translate-y-0"
                        leave-active-class="transition-all duration-150 ease-in"
                        leave-from-class="opacity-100"
                        leave-to-class="opacity-0"
                    >
                        <div v-if="selectedProduct"
                             class="flex items-center gap-3 p-3 rounded-xl border"
                             :class="selectedProduct.is_hazardous ? 'border-danger/30 bg-danger-soft ' : 'border-border bg-muted/40'">
                            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 text-xl"
                                 :class="selectedProduct.is_hazardous ? 'bg-danger-soft ' : 'bg-primary/10'">
                                {{ selectedProduct.is_hazardous ? 'radiation' : 'pickaxe' }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-foreground">
                                    [{{ selectedProduct.code }}] {{ selectedProduct.name }}
                                </p>
                                <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                    <span v-if="selectedProduct.is_hazardous"
                                          class="text-xs font-bold px-1.5 py-0.5 rounded bg-danger-soft text-danger">
                                        {{ lang === 'en' ? 'Radioactive — Handle with care' : 'Radioaktif — Tangani dengan hati-hati' }}
                                    </span>
                                    <span v-if="selectedProduct.is_export_blocked"
                                          class="text-xs font-bold px-1.5 py-0.5 rounded bg-warning-soft text-warning">
                                        {{ lang === 'en' ? 'Export blocked' : 'Diblokir ekspor' }}
                                    </span>
                                    <span class="text-xs text-muted-foreground capitalize">
                                        {{ selectedProduct.category?.replace(/_/g,' ') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </Transition>

                    <p v-if="form.errors.product_code_id" class="text-xs text-danger flex items-center gap-1">
                        <svg class="w-3 h-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        {{ form.errors.product_code_id }}
                    </p>
                </div>

                <!-- ─── Lokasi ─────────────────────────────────────── -->
                <div class="space-y-2">
                    <label class="block text-sm font-semibold text-foreground">
                        {{ lang === 'en' ? 'Lab Location' : 'Lokasi Lab' }}
                        <span class="text-danger ml-0.5">*</span>
                    </label>
                    <select
                        v-model="form.location_id"
                        class="w-full h-11 px-4 rounded-xl text-sm border bg-background text-foreground cursor-pointer"
                        :class="form.errors.location_id ? 'border-danger/30 focus:ring-2 focus:ring-danger/30' : 'border-input focus:border-primary focus:ring-2 focus:ring-primary/20'"
                    >
                        <option value="" disabled>
                            {{ lang === 'en' ? 'Select location...' : 'Pilih lokasi...' }}
                        </option>
                        <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                            [{{ loc.code }}] {{ loc.full_name ?? loc.name }}
                        </option>
                    </select>
                    <p v-if="form.errors.location_id" class="text-xs text-danger flex items-center gap-1">
                        <svg class="w-3 h-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        {{ form.errors.location_id }}
                    </p>
                </div>

                <!-- ─── Berat Awal ─────────────────────────────────── -->
                <div class="space-y-2">
                    <label class="block text-sm font-semibold text-foreground">
                        {{ lang === 'en' ? 'Initial Weight (kg)' : 'Berat Awal (kg)' }}
                        <span class="text-danger ml-0.5">*</span>
                    </label>
                    <div class="relative">
                        <input
                            v-model="form.initial_weight"
                            type="number"
                            step="0.001"
                            min="0.001"
                            :placeholder="lang === 'en' ? 'e.g. 1000.500' : 'Contoh: 1000.500'"
                            class="w-full h-11 pl-4 pr-24 rounded-xl text-sm border bg-background text-foreground font-mono"
                            :class="form.errors.initial_weight ? 'border-danger/30 focus:ring-2 focus:ring-danger/30' : 'border-input focus:border-primary focus:ring-2 focus:ring-primary/20'"
                        />
                        <div class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center gap-2">
                            <span v-if="weightDisplay" class="text-xs font-semibold text-primary tabular-nums">
                                {{ weightDisplay }}
                            </span>
                            <span class="text-xs font-bold px-2 py-0.5 rounded-lg bg-muted text-muted-foreground">kg</span>
                        </div>
                    </div>
                    <p v-if="form.errors.initial_weight" class="text-xs text-danger flex items-center gap-1">
                        <svg class="w-3 h-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        {{ form.errors.initial_weight }}
                    </p>
                </div>

                <!-- Tag RFID: satu jalur registrasi (halaman RFID / tombol "Daftarkan Tag" di detail batch) -->
                <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/40 p-3 text-sm">
                    <AppIcon name="tag" :size="20" class="mt-0.5 flex-shrink-0 text-muted-foreground" />
                    <p class="text-muted-foreground">
                        <span class="font-semibold text-foreground">{{ t('batch.rfid_note_title') }}</span>
                        {{ t('batch.rfid_note') }}
                    </p>
                </div>

                <!-- ─── Catatan ────────────────────────────────────── -->
                <div class="space-y-2">
                    <label class="block text-sm font-semibold text-foreground">
                        {{ lang === 'en' ? 'Notes' : 'Catatan' }}
                        <span class="text-muted-foreground font-normal ml-1">({{ lang === 'en' ? 'optional' : 'opsional' }})</span>
                    </label>
                    <textarea
                        v-model="form.notes"
                        rows="3"
                        :placeholder="lang === 'en'
                            ? 'Additional notes: material condition, mine source, quality observations...'
                            : 'Catatan tambahan: kondisi material, sumber tambang, observasi kualitas...'"
                        class="w-full px-4 py-3 rounded-xl text-sm border border-input bg-background text-foreground resize-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                    />
                    <p class="text-xs text-muted-foreground text-right">{{ (form.notes || '').length }}/1000</p>
                </div>

                <!-- ─── Auto-generated Info ────────────────────────── -->
                <div class="rounded-xl border border-dashed border-border bg-muted/20 p-4 space-y-3">
                    <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                        {{ lang === 'en' ? 'Auto-generated by System' : 'Di-generate Otomatis oleh Sistem' }}
                    </p>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-xs text-muted-foreground uppercase tracking-wider">
                                {{ lang === 'en' ? 'Batch Code Format' : 'Format Kode Batch' }}
                            </p>
                            <p class="text-xs font-mono font-bold text-foreground mt-1">
                                B-YYYYMMDD-XXX
                            </p>
                            <p class="text-xs text-muted-foreground mt-0.5">
                                {{ lang === 'en' ? 'Unique per day, sequential' : 'Unik per hari, sekuensial' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground uppercase tracking-wider">
                                {{ lang === 'en' ? 'Initial Stage & Status' : 'Stage & Status Awal' }}
                            </p>
                            <div class="flex items-center gap-1.5 mt-1">
                                <span class="text-xs font-bold px-2 py-0.5 rounded-md bg-info-soft text-info">
                                    Lab Kimia
                                </span>
                                <span class="text-xs font-bold px-2 py-0.5 rounded-md bg-success-soft text-success">
                                    ● {{ lang === 'en' ? 'Active' : 'Aktif' }}
                                </span>
                            </div>
                            <p class="text-xs text-muted-foreground mt-1">
                                {{ lang === 'en' ? 'Stays here until explicitly received' : 'Tetap di sini hingga diterima eksplisit' }}
                            </p>
                        </div>
                    </div>

                </div>

                <!-- ─── Action Buttons ─────────────────────────────── -->
                <div class="flex items-center gap-3 pt-2 border-t border-border">
                    <Link href="/company/batches"
                          class="flex-1 h-11 rounded-xl text-sm font-semibold text-muted-foreground border border-border flex items-center justify-center gap-2 hover:bg-muted hover:text-foreground transition-all">
                        {{ lang === 'en' ? 'Cancel' : 'Batal' }}
                    </Link>
                    <button
                        type="submit"
                        :disabled="form.processing || !form.product_code_id || !form.location_id || !form.initial_weight"
                        class="flex-[2] h-11 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                        :class="!form.processing && form.product_code_id && form.location_id && form.initial_weight ? 'hover:shadow-lg hover:shadow-primary/25 hover:-translate-y-0.5 active:scale-[0.98]' : ''"
                        style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));"
                    >
                        <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                        </svg>
                        {{ form.processing
                            ? (lang === 'en' ? 'Creating Batch...' : 'Membuat Batch...')
                            : (lang === 'en' ? 'Create Batch (MIT)' : 'Buat Batch (MIT)') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</AppLayout>
</template>

<style scoped>
@keyframes scan-line-move {
    0%   { transform: translateY(0); opacity: 0.8; }
    50%  { opacity: 1; }
    100% { transform: translateY(190px); opacity: 0.8; }
}
.scan-line {
    animation: scan-line-move 2s ease-in-out infinite;
}

@keyframes fade-in { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
@keyframes scale-in { from { opacity: 0; transform: scale(0.97); } to { opacity: 1; transform: scale(1); } }
.animate-fade-in  { animation: fade-in  0.28s ease-out both; }
.animate-scale-in { animation: scale-in 0.22s cubic-bezier(0.175,0.885,0.32,1.275) both; }
.delay-75  { animation-delay: 75ms; }
.delay-100 { animation-delay: 100ms; }
</style>