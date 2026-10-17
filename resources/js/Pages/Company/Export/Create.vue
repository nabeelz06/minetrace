<script setup>
import { ref, computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import axios from 'axios'

const props = defineProps({
    available_batches: Array,
})

// ─── Form state ────────────────────────────────────────────────────────────
const form = useForm({
    batch_ids:             [],
    export_type:           'international',
    destination_country:   '',
    destination_company:   '',
    destination_address:   '',
    manifest_number:       '',
    invoice_number:        '',
    container_number:      '',
    vessel_name:           '',
    port_of_loading:       '',
    port_of_discharge:     '',
    value_usd:             '',
    notes:                 '',
    warnings_acknowledged: [],
})

// ─── Validation state ──────────────────────────────────────────────────────
const validationResult  = ref(null)
const isValidating      = ref(false)
const showWarningDialog = ref(false)

// ─── Selected batches info ─────────────────────────────────────────────────
const selectedBatches = computed(() =>
    props.available_batches?.filter(b => form.batch_ids.includes(b.id)) ?? []
)

const totalWeight = computed(() =>
    selectedBatches.value.reduce((sum, b) => sum + (b.weight_kg ?? 0), 0)
)

function toggleBatch(batchId) {
    const idx = form.batch_ids.indexOf(batchId)
    if (idx === -1) {
        form.batch_ids.push(batchId)
    } else {
        form.batch_ids.splice(idx, 1)
    }
    validationResult.value = null // Reset validation saat pilihan berubah
}

function formatWeight(kg) {
    if (!kg) return '—'
    if (kg >= 1000) return `${(kg/1000).toFixed(3)} MT`
    return `${parseFloat(kg).toLocaleString('id-ID', { maximumFractionDigits: 1 })} kg`
}

// ─── Validasi batch sebelum submit ────────────────────────────────────────
async function validateAndSubmit() {
    if (!form.batch_ids.length) return

    isValidating.value = true
    try {
        const response = await axios.post('/company/exports/validate-batches', {
            batch_ids: form.batch_ids,
        })
        validationResult.value = response.data

        if (!response.data.can_proceed) {
            // Hard block — tidak bisa lanjut
            return
        }

        if (response.data.warnings?.length > 0) {
            // Ada warning — tampilkan dialog konfirmasi
            showWarningDialog.value = true
            return
        }

        // Tidak ada block/warning — langsung submit
        submitExport()

    } catch (error) {
        console.error('Validation error:', error)
    } finally {
        isValidating.value = false
    }
}

function acknowledgeWarnings() {
    form.warnings_acknowledged = validationResult.value.warnings.map(w => w.type)
    showWarningDialog.value = false
    submitExport()
}

function submitExport() {
    form.post('/company/exports', {
        onError: () => { validationResult.value = null },
    })
}
</script>

<template>
    <AppLayout>
        <Head title="Buat Ekspor Baru" />
        <template #header>Buat Ekspor Baru</template>

        <div class="max-w-4xl mx-auto space-y-6">

            <!-- Breadcrumb -->
            <div class="flex items-center gap-2 text-sm text-muted-foreground animate-fade-in">
                <Link href="/company/exports" class="hover:text-foreground transition-colors">Ekspor</Link>
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                <span class="text-foreground font-medium">Buat Ekspor Baru</span>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Left: Batch Selection -->
                <div class="lg:col-span-2 space-y-5">

                    <!-- Tipe Ekspor -->
                    <div class="rounded-2xl border border-border bg-card p-5 animate-scale-in">
                        <h3 class="text-sm font-semibold text-foreground mb-4">Tipe Ekspor</h3>
                        <div class="grid grid-cols-2 gap-3">
                            <button
                                type="button"
                                @click="form.export_type = 'international'"
                                :class="form.export_type === 'international' ? 'border-primary bg-primary/8 text-foreground' : 'border-border text-muted-foreground hover:border-primary/30'"
                                class="flex flex-col items-center gap-2 p-4 rounded-xl border-2 transition-all duration-200 active:scale-[0.98]"
                            >
                                <span class="text-2xl"></span>
                                <span class="text-sm font-semibold">Ekspor Internasional</span>
                                <span class="text-xs text-center text-muted-foreground">Pengiriman ke luar negeri</span>
                                <div v-if="form.export_type === 'international'"
                                     class="w-2 h-2 rounded-full bg-primary"/>
                            </button>
                            <button
                                type="button"
                                @click="form.export_type = 'domestic'"
                                :class="form.export_type === 'domestic' ? 'border-primary bg-primary/8 text-foreground' : 'border-border text-muted-foreground hover:border-primary/30'"
                                class="flex flex-col items-center gap-2 p-4 rounded-xl border-2 transition-all duration-200 active:scale-[0.98]"
                            >
                                <span class="text-2xl"></span>
                                <span class="text-sm font-semibold">Penjualan Domestik</span>
                                <span class="text-xs text-center text-muted-foreground">Penjualan dalam negeri</span>
                                <div v-if="form.export_type === 'domestic'"
                                     class="w-2 h-2 rounded-full bg-primary"/>
                            </button>
                        </div>
                    </div>

                    <!-- Pilih Batch -->
                    <div class="rounded-2xl border border-border bg-card p-5 animate-fade-in delay-75">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-sm font-semibold text-foreground">Pilih Batch untuk Diekspor</h3>
                            <span class="text-xs px-2 py-1 rounded-lg bg-muted text-muted-foreground font-medium">
                                {{ form.batch_ids.length }} dipilih
                            </span>
                        </div>

                        <div v-if="!available_batches?.length" class="py-8 text-center text-muted-foreground text-sm">
                            <div class="text-3xl mb-2"></div>
                            <p class="font-medium">Tidak ada batch tersedia untuk ekspor</p>
                            <p class="text-xs mt-1">Batch harus berstatus "Di Gudang" dan produknya tidak diblokir ekspor.</p>
                        </div>

                        <div v-else class="space-y-2 max-h-64 overflow-y-auto pr-1">
                            <label
                                v-for="batch in available_batches"
                                :key="batch.id"
                                :class="form.batch_ids.includes(batch.id) ? 'border-primary bg-primary/5 shadow-sm' : 'border-border hover:border-primary/30'"
                                class="flex items-center gap-3 p-3 rounded-xl border-2 transition-all duration-150 cursor-pointer group"
                            >
                                <div class="w-4 h-4 rounded-md border-2 flex items-center justify-center flex-shrink-0 transition-all duration-150"
                                     :class="form.batch_ids.includes(batch.id) ? 'border-primary bg-primary' : 'border-input'"
                                     @click="toggleBatch(batch.id)">
                                    <svg v-if="form.batch_ids.includes(batch.id)" class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </div>
                                <div class="flex-1 min-w-0" @click="toggleBatch(batch.id)">
                                    <p class="font-mono font-bold text-xs text-foreground">{{ batch.batch_code }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ batch.product?.code }} — {{ formatWeight(batch.weight_kg) }}
                                    </p>
                                </div>
                                <span class="text-xs font-semibold text-right text-foreground flex-shrink-0">
                                    {{ formatWeight(batch.weight_kg) }}
                                </span>
                            </label>
                        </div>
                    </div>

                    <!-- Detail Tujuan -->
                    <div class="rounded-2xl border border-border bg-card p-5 space-y-4 animate-fade-in delay-150">
                        <h3 class="text-sm font-semibold text-foreground border-b border-border pb-3">Informasi Tujuan</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2" :class="form.export_type === 'international' ? 'col-span-1' : 'col-span-2'">
                                <label class="block text-sm font-semibold text-foreground">
                                    Perusahaan Tujuan <span class="text-danger">*</span>
                                </label>
                                <input
                                    v-model="form.destination_company"
                                    type="text"
                                    placeholder="Nama perusahaan penerima"
                                    class="w-full h-11 px-3 rounded-xl text-sm border border-input bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                                    :class="form.errors.destination_company ? 'border-danger/30' : ''"
                                />
                                <p v-if="form.errors.destination_company" class="text-xs text-danger">{{ form.errors.destination_company }}</p>
                            </div>

                            <div v-if="form.export_type === 'international'" class="space-y-2">
                                <label class="block text-sm font-semibold text-foreground">
                                    Negara Tujuan <span class="text-danger">*</span>
                                </label>
                                <input
                                    v-model="form.destination_country"
                                    type="text"
                                    placeholder="Contoh: Japan, China, USA"
                                    class="w-full h-11 px-3 rounded-xl text-sm border border-input bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                                    :class="form.errors.destination_country ? 'border-danger/30' : ''"
                                />
                            </div>

                            <div class="col-span-2 space-y-2">
                                <label class="block text-sm font-semibold text-foreground">Alamat Tujuan</label>
                                <input
                                    v-model="form.destination_address"
                                    type="text"
                                    placeholder="Alamat lengkap perusahaan tujuan"
                                    class="w-full h-11 px-3 rounded-xl text-sm border border-input bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Dokumen & Pengiriman -->
                    <div class="rounded-2xl border border-border bg-card p-5 space-y-4 animate-fade-in delay-200">
                        <h3 class="text-sm font-semibold text-foreground border-b border-border pb-3">Nomor Dokumen & Pengiriman</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-2">
                                <label class="block text-sm font-semibold text-foreground">No. Invoice</label>
                                <input v-model="form.invoice_number" type="text" placeholder="INV-2026-001"
                                       class="w-full h-11 px-3 rounded-xl text-sm border border-input bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                            </div>
                            <div class="space-y-2">
                                <label class="block text-sm font-semibold text-foreground">No. Manifest</label>
                                <input v-model="form.manifest_number" type="text" placeholder="MNF-2026-001"
                                       class="w-full h-11 px-3 rounded-xl text-sm border border-input bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                            </div>
                            <div v-if="form.export_type === 'international'" class="space-y-2">
                                <label class="block text-sm font-semibold text-foreground">No. Container</label>
                                <input v-model="form.container_number" type="text" placeholder="TCKU1234567"
                                       class="w-full h-11 px-3 rounded-xl text-sm border border-input bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                            </div>
                            <div v-if="form.export_type === 'international'" class="space-y-2">
                                <label class="block text-sm font-semibold text-foreground">Nama Kapal</label>
                                <input v-model="form.vessel_name" type="text" placeholder="MV Ocean Star"
                                       class="w-full h-11 px-3 rounded-xl text-sm border border-input bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                            </div>
                            <div v-if="form.export_type === 'international'" class="space-y-2">
                                <label class="block text-sm font-semibold text-foreground">Pelabuhan Muat</label>
                                <input v-model="form.port_of_loading" type="text" placeholder="Tanjung Ular, Bangka"
                                       class="w-full h-11 px-3 rounded-xl text-sm border border-input bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                            </div>
                            <div v-if="form.export_type === 'international'" class="space-y-2">
                                <label class="block text-sm font-semibold text-foreground">Pelabuhan Tujuan</label>
                                <input v-model="form.port_of_discharge" type="text" placeholder="Port of Osaka, Japan"
                                       class="w-full h-11 px-3 rounded-xl text-sm border border-input bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                            </div>
                            <div class="space-y-2">
                                <label class="block text-sm font-semibold text-foreground">Nilai (USD)</label>
                                <div class="relative">
                                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-muted-foreground">$</span>
                                    <input v-model="form.value_usd" type="number" step="0.01" min="0" placeholder="0.00"
                                           class="w-full h-11 pl-7 pr-3 rounded-xl text-sm border border-input bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">Catatan</label>
                            <textarea v-model="form.notes" rows="3" placeholder="Catatan tambahan tentang pengiriman ini..."
                                      class="w-full px-3 py-3 rounded-xl text-sm border border-input bg-background text-foreground placeholder:text-muted-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all resize-none"/>
                        </div>
                    </div>
                </div>

                <!-- Right: Summary + Submit -->
                <div class="space-y-4">

                    <!-- Summary Card -->
                    <div class="rounded-2xl border border-border bg-card p-5 space-y-4 sticky top-5 animate-scale-in delay-100">
                        <h3 class="text-sm font-semibold text-foreground">Ringkasan Ekspor</h3>

                        <div class="space-y-3">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-muted-foreground">Batch Dipilih</span>
                                <span class="font-bold text-foreground">{{ form.batch_ids.length }}</span>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-muted-foreground">Total Berat</span>
                                <span class="font-bold text-foreground">{{ formatWeight(totalWeight) }}</span>
                            </div>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-muted-foreground">Tipe</span>
                                <span class="font-bold text-foreground capitalize">
                                    {{ form.export_type === 'international' ? 'Int\'l' : 'Domestik' }}
                                </span>
                            </div>
                            <div v-if="form.destination_company" class="flex items-start justify-between text-sm gap-2">
                                <span class="text-muted-foreground flex-shrink-0">Tujuan</span>
                                <span class="font-bold text-foreground text-right">{{ form.destination_company }}</span>
                            </div>
                        </div>

                        <!-- Weight bar -->
                        <div v-if="totalWeight > 0" class="space-y-1">
                            <div class="h-2 rounded-full bg-muted overflow-hidden">
                                <div class="h-full rounded-full animate-pulse"
                                     style="width: 100%; background: linear-gradient(to right, hsl(var(--primary)), hsl(207 40% 60%));"/>
                            </div>
                            <p class="text-xs text-center text-muted-foreground">{{ formatWeight(totalWeight) }} siap diekspor</p>
                        </div>

                        <!-- Validation Result -->
                        <div v-if="validationResult">
                            <!-- Hard blocks -->
                            <div v-if="validationResult.hard_blocks?.length"
                                 class="rounded-xl p-3 space-y-2"
                                 style="background: hsl(var(--danger-soft)); border: 1px solid hsl(var(--danger-soft));">
                                <p class="text-xs font-bold text-danger flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                                    </svg>
                                    Ekspor Diblokir
                                </p>
                                <p v-for="block in validationResult.hard_blocks" :key="block.batch_code"
                                   class="text-xs text-danger">
                                    {{ block.reason }}
                                </p>
                            </div>

                            <!-- Warnings -->
                            <div v-else-if="validationResult.warnings?.length"
                                 class="rounded-xl p-3 space-y-2"
                                 style="background: hsl(var(--warning-soft)); border: 1px solid hsl(var(--warning-soft));">
                                <p class="text-xs font-bold text-warning flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                                    </svg>
                                    Ada Peringatan
                                </p>
                                <p v-for="w in validationResult.warnings" :key="w.type"
                                   class="text-xs text-warning">
                                    {{ w.message }}
                                </p>
                            </div>

                            <!-- OK -->
                            <div v-else
                                 class="rounded-xl p-3"
                                 style="background: hsl(var(--success-soft)); border: 1px solid hsl(var(--success-soft));">
                                <p class="text-xs font-bold text-success flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Semua batch valid untuk ekspor
                                </p>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <button
                            type="button"
                            @click="validateAndSubmit"
                            :disabled="!form.batch_ids.length || !form.destination_company || form.processing || isValidating"
                            class="w-full h-11 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition-all duration-200 disabled:opacity-40 disabled:cursor-not-allowed hover:shadow-lg hover:shadow-primary/25 hover:-translate-y-0.5 active:scale-[0.97]"
                            style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));"
                        >
                            <svg v-if="form.processing || isValidating" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                            </svg>
                            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/>
                            </svg>
                            {{ form.processing ? 'Memproses...' : isValidating ? 'Memvalidasi...' : 'Konfirmasi Ekspor' }}
                        </button>

                        <Link
                            href="/company/exports"
                            class="w-full h-10 rounded-xl text-sm font-semibold text-muted-foreground border border-border flex items-center justify-center gap-2 hover:bg-muted hover:text-foreground transition-all duration-200 active:scale-[0.97]"
                        >
                            Batal
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── Warning Dialog ─────────────────────────────────────────── -->
        <Transition name="modal">
            <div v-if="showWarningDialog"
                 class="fixed inset-0 z-50 flex items-center justify-center p-4"
                 style="background: hsl(var(--foreground)/0.5); backdrop-filter: blur(4px);">
                <div class="w-full max-w-md rounded-2xl border border-border bg-card p-6 shadow-2xl space-y-5 animate-scale-in">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
                             style="background: hsl(var(--warning-soft));">
                            <svg class="w-5 h-5 text-warning" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-foreground">Konfirmasi Peringatan</h3>
                            <p class="text-xs text-muted-foreground">Ditemukan peringatan pada batch yang dipilih</p>
                        </div>
                    </div>

                    <div class="space-y-2 rounded-xl p-3"
                         style="background: hsl(var(--warning-soft)); border: 1px solid hsl(var(--warning-soft));">
                        <p v-for="w in validationResult?.warnings" :key="w.type"
                           class="text-xs text-warning flex items-start gap-2">
                            <span class="flex-shrink-0 mt-0.5"></span>
                            {{ w.message }}
                        </p>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Apakah Anda tetap ingin melanjutkan ekspor meskipun ada peringatan di atas? Tindakan ini akan dicatat dalam sistem.
                    </p>

                    <div class="flex gap-3">
                        <button
                            @click="showWarningDialog = false"
                            class="flex-1 h-10 rounded-xl text-sm font-semibold text-muted-foreground border border-border hover:bg-muted transition-all duration-150 active:scale-[0.98]"
                        >
                            Batal
                        </button>
                        <button
                            @click="acknowledgeWarnings"
                            class="flex-1 h-10 rounded-xl text-sm font-semibold text-white transition-all duration-150 hover:shadow-md active:scale-[0.97]"
                            style="background: hsl(var(--warning));"
                        >
                            Ya, Lanjutkan Ekspor
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </AppLayout>
</template>

<style scoped>
.modal-enter-active { transition: all 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.1); }
.modal-leave-active { transition: all 0.15s ease-in; }
.modal-enter-from   { opacity: 0; }
.modal-leave-to     { opacity: 0; }
</style>