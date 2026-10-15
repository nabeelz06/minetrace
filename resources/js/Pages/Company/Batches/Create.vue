<script setup>
/**
 * Create.vue — Form pembuatan batch baru MIT (Mineral Ikutan Timah).
 *
 * FITUR:
 * ✅ Pembuatan batch tetap di stage lab_kimia/receiving (TIDAK auto-forward)
 * ✅ Barcode/QR scanner via kamera (BarcodeDetector API + jsQR fallback)
 * ✅ Scan dari gambar (upload file)
 * ✅ Bidlingual (ID/EN)
 * ✅ Hasil scan mengisi field RFID/Scan Reference
 *
 * ITEM 3 — EXPLICIT SEND/RECEIVE:
 * Batch yang baru dibuat TETAP di stage 'lab_kimia' dengan status 'active'.
 * Operator Wet Process harus secara EKSPLISIT menekan tombol "Terima →"
 * di halaman Stage (Process/Stage.vue → Panel Incoming) untuk memindahkannya.
 * Tidak ada auto-forward sama sekali dari halaman ini.
 */
import { ref, computed, onUnmounted, inject } from 'vue'
import { Head, Link, useForm }                from '@inertiajs/vue3'
import AppLayout                              from '@/Layouts/AppLayout.vue'
import { useI18n }                            from '@/composables/core/useI18n.js'

const { lang } = useI18n()
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
    rfid_tag:        '', // scan reference / barcode / RFID
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

// ═══════════════════════════════════════════════════════════════════════
// BARCODE / QR SCANNER — Item 4
// ═══════════════════════════════════════════════════════════════════════
const scannerOpen    = ref(false)
const scanMode       = ref('camera')   // 'camera' | 'image'
const cameraActive   = ref(false)
const scanning       = ref(false)
const scanResult     = ref('')
const scanError      = ref('')
const scanSuccess    = ref(false)

const videoRef       = ref(null)
const canvasRef      = ref(null)
const fileInputRef   = ref(null)

let mediaStream      = null
let scanIntervalId   = null
let jsQRLoaded       = false

// ─── Load jsQR dari CDN (fallback QR decoder) ─────────────────────────
async function loadJsQR() {
    if (window.jsQR) return window.jsQR
    if (jsQRLoaded) return window.jsQR
    return new Promise((resolve, reject) => {
        const script = document.createElement('script')
        script.src   = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js'
        script.async = true
        script.onload = () => {
            jsQRLoaded = true
            resolve(window.jsQR)
        }
        script.onerror = () => reject(new Error('Gagal memuat jsQR'))
        document.head.appendChild(script)
    })
}

// ─── Deteksi barcode dari canvas (BarcodeDetector atau jsQR) ──────────
async function detectFromCanvas(canvas) {
    const ctx = canvas.getContext('2d', { willReadFrequently: true })

    // 1. Coba BarcodeDetector (native browser API — Chrome 83+, Edge 83+)
    if ('BarcodeDetector' in window) {
        try {
            const formats = ['qr_code', 'code_128', 'code_39', 'ean_13', 'ean_8', 'data_matrix', 'pdf417']
            const detector = new window.BarcodeDetector({ formats })
            const barcodes  = await detector.detect(canvas)
            if (barcodes.length > 0) {
                return barcodes[0].rawValue
            }
        } catch { /* fallthrough */ }
    }

    // 2. Fallback: jsQR (hanya QR code)
    try {
        const jsQR = await loadJsQR()
        if (jsQR) {
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height)
            const code = jsQR(imageData.data, imageData.width, imageData.height, {
                inversionAttempts: 'dontInvert',
            })
            if (code) return code.data
        }
    } catch { /* fallthrough */ }

    return null
}

// ─── Mulai kamera ─────────────────────────────────────────────────────
async function startCamera() {
    scanError.value   = ''
    scanSuccess.value = false
    scanning.value    = true

    try {
        mediaStream = await navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: 'environment',
                width:  { ideal: 1280 },
                height: { ideal: 720 },
            },
        })

        if (!videoRef.value) { scanning.value = false; return }
        videoRef.value.srcObject = mediaStream
        await videoRef.value.play()
        cameraActive.value = true
        scanning.value     = false

        // Scan setiap 400ms
        scanIntervalId = setInterval(async () => {
            if (!videoRef.value || !canvasRef.value || !cameraActive.value) return
            if (videoRef.value.readyState < 2) return

            const canvas = canvasRef.value
            canvas.width  = videoRef.value.videoWidth
            canvas.height = videoRef.value.videoHeight

            const ctx = canvas.getContext('2d', { willReadFrequently: true })
            ctx.drawImage(videoRef.value, 0, 0)

            const result = await detectFromCanvas(canvas)
            if (result) {
                handleScanResult(result)
            }
        }, 400)

    } catch (err) {
        scanning.value = false
        if (err.name === 'NotAllowedError') {
            scanError.value = lang.value === 'en'
                ? 'Camera access denied. Please allow camera permission in your browser settings.'
                : 'Izin kamera ditolak. Aktifkan izin kamera di pengaturan browser Anda.'
        } else if (err.name === 'NotFoundError') {
            scanError.value = lang.value === 'en'
                ? 'No camera found on this device.'
                : 'Kamera tidak ditemukan pada perangkat ini.'
        } else {
            scanError.value = lang.value === 'en'
                ? `Camera error: ${err.message}`
                : `Error kamera: ${err.message}`
        }
    }
}

// ─── Stop kamera ──────────────────────────────────────────────────────
function stopCamera() {
    if (mediaStream) {
        mediaStream.getTracks().forEach(t => t.stop())
        mediaStream = null
    }
    if (scanIntervalId) {
        clearInterval(scanIntervalId)
        scanIntervalId = null
    }
    cameraActive.value = false
    scanning.value     = false
}

// ─── Scan dari gambar ─────────────────────────────────────────────────
async function scanFromImage(event) {
    const file = event.target.files?.[0]
    if (!file) return

    scanError.value   = ''
    scanSuccess.value = false
    scanning.value    = true

    try {
        const img = await createImageBitmap(file)
        const canvas  = canvasRef.value ?? document.createElement('canvas')
        canvas.width  = img.width
        canvas.height = img.height
        const ctx = canvas.getContext('2d', { willReadFrequently: true })
        ctx.drawImage(img, 0, 0)
        img.close()

        const result = await detectFromCanvas(canvas)
        scanning.value = false

        if (result) {
            handleScanResult(result)
        } else {
            scanError.value = lang.value === 'en'
                ? 'No barcode/QR code detected in this image. Try a clearer photo with good lighting.'
                : 'Barcode/QR code tidak terdeteksi. Coba foto yang lebih jelas dengan pencahayaan yang baik.'
        }
    } catch (err) {
        scanning.value  = false
        scanError.value = lang.value === 'en'
            ? `Failed to process image: ${err.message}`
            : `Gagal memproses gambar: ${err.message}`
    }

    // Reset file input agar bisa scan gambar yang sama lagi
    event.target.value = ''
}

// ─── Handle hasil scan ────────────────────────────────────────────────
function handleScanResult(value) {
    stopCamera()
    scanResult.value   = value
    form.rfid_tag      = value
    scanSuccess.value  = true
    scanError.value    = ''

    // Vibrate on mobile (feedback)
    if (navigator.vibrate) navigator.vibrate([100, 50, 100])

    toast?.(
        'success',
        (lang.value === 'en' ? 'Barcode detected: ' : 'Barcode terdeteksi: ') + value,
        4000
    )
}

// ─── Clear scan result ────────────────────────────────────────────────
function clearScan() {
    scanResult.value   = ''
    form.rfid_tag      = ''
    scanSuccess.value  = false
    scanError.value    = ''
    stopCamera()
}

// ─── Toggle scanner panel ─────────────────────────────────────────────
function toggleScanner() {
    if (scannerOpen.value) {
        stopCamera()
        scannerOpen.value = false
    } else {
        scannerOpen.value = true
        scanError.value   = ''
    }
}

function switchMode(mode) {
    stopCamera()
    scanMode.value = mode
    scanError.value = ''
}

// ─── Cleanup on unmount ───────────────────────────────────────────────
onUnmounted(() => { stopCamera() })

// ─── Browser support check ────────────────────────────────────────────
const hasCamera = computed(() => !!(
    navigator.mediaDevices?.getUserMedia &&
    (window.isSecureContext || location.hostname === 'localhost')
))

const hasBarcodeDetector = computed(() => 'BarcodeDetector' in window)
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
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300">
                        1. Lab Kimia (Buat)
                    </span>
                    <svg class="w-3 h-3 text-muted-foreground flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                    </svg>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300">
                        2. Receiving (Terima eksplisit)
                    </span>
                    <svg class="w-3 h-3 text-muted-foreground flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                    </svg>
                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-cyan-100 dark:bg-cyan-900/30 text-cyan-700 dark:text-cyan-300">
                        3. Wet Process (Kirim eksplisit)
                    </span>
                    <span class="text-[10px] text-muted-foreground">...</span>
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
                    <p class="text-[10px] text-muted-foreground uppercase tracking-wider">
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
                        <span class="text-red-500 ml-0.5">*</span>
                    </label>
                    <select
                        v-model="form.product_code_id"
                        class="w-full h-11 px-4 rounded-xl text-sm border bg-background text-foreground outline-none cursor-pointer"
                        :class="form.errors.product_code_id
                            ? 'border-red-400 focus:ring-2 focus:ring-red-400/20'
                            : 'border-input focus:border-primary focus:ring-2 focus:ring-primary/20'"
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
                             :class="selectedProduct.is_hazardous
                                 ? 'border-red-200 dark:border-red-800/40 bg-red-50 dark:bg-red-950/10'
                                 : 'border-border bg-muted/40'">
                            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 text-xl"
                                 :class="selectedProduct.is_hazardous ? 'bg-red-100 dark:bg-red-900/30' : 'bg-primary/10'">
                                {{ selectedProduct.is_hazardous ? '☢' : '⛏' }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-foreground">
                                    [{{ selectedProduct.code }}] {{ selectedProduct.name }}
                                </p>
                                <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                    <span v-if="selectedProduct.is_hazardous"
                                          class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300">
                                        ☢ {{ lang === 'en' ? 'Radioactive — Handle with care' : 'Radioaktif — Tangani dengan hati-hati' }}
                                    </span>
                                    <span v-if="selectedProduct.is_export_blocked"
                                          class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">
                                        🔒 {{ lang === 'en' ? 'Export blocked' : 'Diblokir ekspor' }}
                                    </span>
                                    <span class="text-[10px] text-muted-foreground capitalize">
                                        {{ selectedProduct.category?.replace(/_/g,' ') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </Transition>

                    <p v-if="form.errors.product_code_id" class="text-xs text-red-500 flex items-center gap-1">
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
                        <span class="text-red-500 ml-0.5">*</span>
                    </label>
                    <select
                        v-model="form.location_id"
                        class="w-full h-11 px-4 rounded-xl text-sm border bg-background text-foreground outline-none cursor-pointer"
                        :class="form.errors.location_id
                            ? 'border-red-400 focus:ring-2 focus:ring-red-400/20'
                            : 'border-input focus:border-primary focus:ring-2 focus:ring-primary/20'"
                    >
                        <option value="" disabled>
                            {{ lang === 'en' ? 'Select location...' : 'Pilih lokasi...' }}
                        </option>
                        <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                            [{{ loc.code }}] {{ loc.full_name ?? loc.name }}
                        </option>
                    </select>
                    <p v-if="form.errors.location_id" class="text-xs text-red-500 flex items-center gap-1">
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
                        <span class="text-red-500 ml-0.5">*</span>
                    </label>
                    <div class="relative">
                        <input
                            v-model="form.initial_weight"
                            type="number"
                            step="0.001"
                            min="0.001"
                            :placeholder="lang === 'en' ? 'e.g. 1000.500' : 'Contoh: 1000.500'"
                            class="w-full h-11 pl-4 pr-24 rounded-xl text-sm border bg-background text-foreground font-mono outline-none"
                            :class="form.errors.initial_weight
                                ? 'border-red-400 focus:ring-2 focus:ring-red-400/20'
                                : 'border-input focus:border-primary focus:ring-2 focus:ring-primary/20'"
                        />
                        <div class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center gap-2">
                            <span v-if="weightDisplay" class="text-xs font-semibold text-primary tabular-nums">
                                {{ weightDisplay }}
                            </span>
                            <span class="text-xs font-bold px-2 py-0.5 rounded-lg bg-muted text-muted-foreground">kg</span>
                        </div>
                    </div>
                    <p v-if="form.errors.initial_weight" class="text-xs text-red-500 flex items-center gap-1">
                        <svg class="w-3 h-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                        </svg>
                        {{ form.errors.initial_weight }}
                    </p>
                </div>

                <!-- ═══════════════════════════════════════════════════
                     BARCODE / QR SCANNER — Item 4
                     Klik tombol untuk expand scanner panel
                ═══════════════════════════════════════════════════ -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <label class="text-sm font-semibold text-foreground">
                            {{ lang === 'en' ? 'Barcode / RFID Scan' : 'Scan Barcode / RFID' }}
                            <span class="text-muted-foreground font-normal ml-1">({{ lang === 'en' ? 'optional' : 'opsional' }})</span>
                        </label>
                        <div class="flex items-center gap-1.5">
                            <span v-if="hasBarcodeDetector"
                                  class="text-[10px] font-bold px-2 py-0.5 rounded-full"
                                  style="background:rgba(16,185,129,0.12);color:#059669">
                                ✓ {{ lang === 'en' ? 'Native scan ready' : 'Scan native siap' }}
                            </span>
                        </div>
                    </div>

                    <!-- Scan result display (jika sudah ada hasil scan) -->
                    <div v-if="scanResult"
                         class="flex items-center gap-3 p-3 rounded-xl border border-emerald-300 dark:border-emerald-700"
                         style="background:rgba(16,185,129,0.08)">
                        <div class="w-8 h-8 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center text-base flex-shrink-0">
                            ✅
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-[10px] font-semibold text-emerald-700 dark:text-emerald-400 uppercase tracking-wider">
                                {{ lang === 'en' ? 'Scan Result' : 'Hasil Scan' }}
                            </p>
                            <p class="text-sm font-mono font-bold text-foreground truncate">{{ scanResult }}</p>
                        </div>
                        <button type="button" @click="clearScan"
                                class="flex-shrink-0 w-7 h-7 rounded-lg flex items-center justify-center text-muted-foreground hover:text-foreground hover:bg-muted">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Manual input field -->
                    <div class="relative">
                        <input
                            v-model="form.rfid_tag"
                            type="text"
                            :placeholder="lang === 'en' ? 'Scan or type barcode/RFID manually...' : 'Scan atau ketik barcode/RFID secara manual...'"
                            class="w-full h-11 pl-4 pr-28 rounded-xl text-sm border border-input bg-background text-foreground font-mono outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                            :class="scanSuccess && !scanResult ? '' : ''"
                        />
                        <!-- Scan button -->
                        <button
                            type="button"
                            @click="toggleScanner"
                            class="absolute right-2 top-1/2 -translate-y-1/2 flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold transition-all active:scale-[0.97]"
                            :class="scannerOpen
                                ? 'bg-primary text-white'
                                : 'bg-primary/10 text-primary hover:bg-primary/20'"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h.75v.75h-.75v-.75zM6.75 16.5h.75v.75h-.75v-.75zM16.5 6.75h.75v.75h-.75v-.75zM13.5 13.5h.75v.75h-.75v-.75zM13.5 19.5h.75v.75h-.75v-.75zM19.5 13.5h.75v.75h-.75v-.75zM19.5 19.5h.75v.75h-.75v-.75zM16.5 16.5h.75v.75h-.75v-.75z"/>
                            </svg>
                            {{ lang === 'en' ? 'Scan' : 'Scan' }}
                        </button>
                    </div>

                    <!-- Scanner Panel (collapsible) -->
                    <Transition
                        enter-active-class="transition-all duration-250 ease-out overflow-hidden"
                        enter-from-class="opacity-0 max-h-0"
                        enter-to-class="opacity-100 max-h-[600px]"
                        leave-active-class="transition-all duration-200 ease-in overflow-hidden"
                        leave-from-class="opacity-100 max-h-[600px]"
                        leave-to-class="opacity-0 max-h-0"
                    >
                        <div v-if="scannerOpen"
                             class="rounded-2xl border border-border bg-muted/30 overflow-hidden">

                            <!-- Scanner header + mode tabs -->
                            <div class="flex items-center justify-between px-4 py-3 border-b border-border bg-card">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm">📷</span>
                                    <p class="text-sm font-bold text-foreground">
                                        {{ lang === 'en' ? 'Barcode / QR Scanner' : 'Scanner Barcode / QR' }}
                                    </p>
                                </div>
                                <!-- Mode tabs -->
                                <div class="flex gap-1 p-0.5 rounded-lg border border-border bg-background">
                                    <button
                                        type="button"
                                        @click="switchMode('camera')"
                                        class="px-3 py-1 rounded-md text-xs font-semibold"
                                        :class="scanMode === 'camera'
                                            ? 'bg-primary text-white shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground'">
                                        📷 {{ lang === 'en' ? 'Camera' : 'Kamera' }}
                                    </button>
                                    <button
                                        type="button"
                                        @click="switchMode('image')"
                                        class="px-3 py-1 rounded-md text-xs font-semibold"
                                        :class="scanMode === 'image'
                                            ? 'bg-primary text-white shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground'">
                                        🖼 {{ lang === 'en' ? 'Image' : 'Gambar' }}
                                    </button>
                                </div>
                            </div>

                            <div class="p-4">

                                <!-- ERROR MESSAGE -->
                                <div v-if="scanError"
                                     class="flex items-start gap-2 mb-3 p-3 rounded-xl border border-red-200 dark:border-red-800/40 bg-red-50 dark:bg-red-950/10">
                                    <span class="text-sm flex-shrink-0">⚠️</span>
                                    <p class="text-xs text-red-700 dark:text-red-300">{{ scanError }}</p>
                                </div>

                                <!-- SUCCESS MESSAGE -->
                                <div v-if="scanSuccess && scanResult"
                                     class="flex items-start gap-2 mb-3 p-3 rounded-xl border border-emerald-200 dark:border-emerald-800/40 bg-emerald-50 dark:bg-emerald-950/10">
                                    <span class="text-sm flex-shrink-0">✅</span>
                                    <div>
                                        <p class="text-xs font-bold text-emerald-700 dark:text-emerald-300">
                                            {{ lang === 'en' ? 'Barcode detected!' : 'Barcode terdeteksi!' }}
                                        </p>
                                        <p class="text-xs font-mono text-foreground mt-0.5">{{ scanResult }}</p>
                                    </div>
                                </div>

                                <!-- ─── MODE: CAMERA ──────────────────── -->
                                <div v-if="scanMode === 'camera'">
                                    <div v-if="!hasCamera"
                                         class="py-6 text-center">
                                        <p class="text-sm text-muted-foreground">
                                            {{ lang === 'en'
                                                ? 'Camera not available. This page requires HTTPS or localhost.'
                                                : 'Kamera tidak tersedia. Halaman ini memerlukan HTTPS atau localhost.' }}
                                        </p>
                                        <p class="text-xs text-muted-foreground mt-1">
                                            {{ lang === 'en' ? 'Use image upload instead.' : 'Gunakan mode upload gambar.' }}
                                        </p>
                                    </div>

                                    <div v-else class="space-y-3">
                                        <!-- Video preview -->
                                        <div class="relative rounded-xl overflow-hidden bg-black"
                                             :style="cameraActive ? 'aspect-ratio: 4/3;' : ''">

                                            <video v-show="cameraActive"
                                                   ref="videoRef"
                                                   autoplay playsinline muted
                                                   class="w-full h-full object-cover"
                                                   style="transform: scaleX(-1)"/>

                                            <!-- Placeholder saat kamera belum aktif -->
                                            <div v-if="!cameraActive"
                                                 class="flex flex-col items-center justify-center h-36 rounded-xl border-2 border-dashed border-border bg-muted/30">
                                                <span class="text-3xl mb-2">📷</span>
                                                <p class="text-sm text-muted-foreground">
                                                    {{ lang === 'en' ? 'Camera preview will appear here' : 'Preview kamera akan muncul di sini' }}
                                                </p>
                                            </div>

                                            <!-- Scan overlay (saat aktif) -->
                                            <div v-if="cameraActive"
                                                 class="absolute inset-0 flex items-center justify-center pointer-events-none">
                                                <!-- Crosshair target -->
                                                <div class="w-48 h-48 relative">
                                                    <!-- Corner brackets -->
                                                    <div class="absolute top-0 left-0 w-8 h-8 border-t-2 border-l-2 border-primary rounded-tl-sm"/>
                                                    <div class="absolute top-0 right-0 w-8 h-8 border-t-2 border-r-2 border-primary rounded-tr-sm"/>
                                                    <div class="absolute bottom-0 left-0 w-8 h-8 border-b-2 border-l-2 border-primary rounded-bl-sm"/>
                                                    <div class="absolute bottom-0 right-0 w-8 h-8 border-b-2 border-r-2 border-primary rounded-br-sm"/>
                                                    <!-- Scanning line animation -->
                                                    <div class="absolute top-0 left-0 right-0 h-0.5 bg-primary/70 scan-line"/>
                                                </div>
                                            </div>

                                            <!-- Status badge saat aktif -->
                                            <div v-if="cameraActive"
                                                 class="absolute top-2 right-2 flex items-center gap-1.5 px-2 py-1 rounded-lg bg-black/60 backdrop-blur-sm">
                                                <div class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse"/>
                                                <span class="text-[10px] font-bold text-white uppercase">
                                                    {{ lang === 'en' ? 'Scanning...' : 'Scanning...' }}
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Camera controls -->
                                        <div class="flex gap-2">
                                            <button v-if="!cameraActive" type="button"
                                                    @click="startCamera"
                                                    :disabled="scanning"
                                                    class="flex-1 h-10 rounded-xl text-sm font-bold text-white flex items-center justify-center gap-2 disabled:opacity-60"
                                                    style="background:linear-gradient(135deg,hsl(var(--primary)),hsl(207 30% 28%))">
                                                <svg v-if="scanning" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                                </svg>
                                                <span class="text-base" v-else>📷</span>
                                                {{ scanning
                                                    ? (lang === 'en' ? 'Starting camera...' : 'Memulai kamera...')
                                                    : (lang === 'en' ? 'Start Camera Scan' : 'Mulai Scan Kamera') }}
                                            </button>

                                            <button v-if="cameraActive" type="button"
                                                    @click="stopCamera"
                                                    class="flex-1 h-10 rounded-xl text-sm font-bold border border-border text-muted-foreground hover:bg-muted hover:text-foreground flex items-center justify-center gap-2">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5.25 7.5A2.25 2.25 0 017.5 5.25h9a2.25 2.25 0 012.25 2.25v9a2.25 2.25 0 01-2.25 2.25h-9a2.25 2.25 0 01-2.25-2.25v-9z"/>
                                                </svg>
                                                {{ lang === 'en' ? 'Stop Camera' : 'Stop Kamera' }}
                                            </button>
                                        </div>

                                        <!-- Tech info -->
                                        <p class="text-[10px] text-muted-foreground text-center">
                                            {{ hasBarcodeDetector
                                                ? (lang === 'en' ? '✓ Native BarcodeDetector API active — supports QR, Code128, EAN, DataMatrix' : '✓ BarcodeDetector API aktif — mendukung QR, Code128, EAN, DataMatrix')
                                                : (lang === 'en' ? 'jsQR fallback mode — QR codes only' : 'Mode jsQR fallback — hanya QR code') }}
                                        </p>
                                    </div>
                                </div>

                                <!-- ─── MODE: IMAGE UPLOAD ────────────── -->
                                <div v-if="scanMode === 'image'" class="space-y-3">
                                    <p class="text-xs text-muted-foreground">
                                        {{ lang === 'en'
                                            ? 'Upload a clear photo of the barcode/QR code label for automatic detection.'
                                            : 'Upload foto label barcode/QR code yang jelas untuk deteksi otomatis.' }}
                                    </p>

                                    <!-- Upload area -->
                                    <label
                                        class="flex flex-col items-center justify-center h-36 border-2 border-dashed rounded-xl cursor-pointer transition-colors hover:border-primary"
                                        :class="scanning ? 'opacity-60 cursor-not-allowed' : 'border-border hover:bg-primary/4'">
                                        <input
                                            ref="fileInputRef"
                                            type="file"
                                            accept="image/*"
                                            class="hidden"
                                            :disabled="scanning"
                                            @change="scanFromImage"
                                        />

                                        <div v-if="scanning" class="flex flex-col items-center gap-2">
                                            <svg class="w-8 h-8 text-primary animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                            </svg>
                                            <p class="text-sm font-semibold text-primary">
                                                {{ lang === 'en' ? 'Analyzing image...' : 'Menganalisis gambar...' }}
                                            </p>
                                        </div>
                                        <div v-else class="flex flex-col items-center gap-2">
                                            <div class="w-12 h-12 rounded-xl bg-primary/10 flex items-center justify-center">
                                                <svg class="w-6 h-6 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                                                </svg>
                                            </div>
                                            <div class="text-center">
                                                <p class="text-sm font-semibold text-foreground">
                                                    {{ lang === 'en' ? 'Click to upload image' : 'Klik untuk upload gambar' }}
                                                </p>
                                                <p class="text-xs text-muted-foreground mt-0.5">
                                                    {{ lang === 'en' ? 'JPG, PNG, WebP • Max 10 MB' : 'JPG, PNG, WebP • Maks 10 MB' }}
                                                </p>
                                            </div>
                                        </div>
                                    </label>

                                    <!-- Hint -->
                                    <div class="flex items-start gap-2 p-2.5 rounded-xl border border-border bg-muted/30">
                                        <span class="text-sm flex-shrink-0">💡</span>
                                        <p class="text-[10px] text-muted-foreground leading-relaxed">
                                            {{ lang === 'en'
                                                ? 'Best results: good lighting, flat surface, barcode fills at least 30% of the image. Avoid glare and blurry photos.'
                                                : 'Tips terbaik: pencahayaan baik, permukaan datar, barcode mengisi minimal 30% gambar. Hindari pantulan cahaya dan foto buram.' }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Canvas tersembunyi untuk image processing -->
                                <canvas ref="canvasRef" class="hidden" width="640" height="480"/>
                            </div>

                            <!-- Scanner footer — label status ready -->
                            <div class="px-4 py-2.5 border-t border-border bg-muted/20 flex items-center justify-between">
                                <p class="text-[10px] text-muted-foreground">
                                    {{ lang === 'en'
                                        ? '🔮 Demo mode — future integration: RFID Scanner via API'
                                        : '🔮 Mode demo — integrasi mendatang: Scanner RFID via API' }}
                                </p>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full"
                                      style="background:rgba(62,92,116,0.12);color:hsl(var(--primary))">
                                    MINETRACE RFID-Ready
                                </span>
                            </div>
                        </div>
                    </Transition>
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
                        class="w-full px-4 py-3 rounded-xl text-sm border border-input bg-background text-foreground resize-none outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
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
                            <p class="text-[10px] text-muted-foreground uppercase tracking-wider">
                                {{ lang === 'en' ? 'Batch Code Format' : 'Format Kode Batch' }}
                            </p>
                            <p class="text-xs font-mono font-bold text-foreground mt-1">
                                B-YYYYMMDD-XXX
                            </p>
                            <p class="text-[10px] text-muted-foreground mt-0.5">
                                {{ lang === 'en' ? 'Unique per day, sequential' : 'Unik per hari, sekuensial' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-[10px] text-muted-foreground uppercase tracking-wider">
                                {{ lang === 'en' ? 'Initial Stage & Status' : 'Stage & Status Awal' }}
                            </p>
                            <div class="flex items-center gap-1.5 mt-1">
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300">
                                    🧪 Lab Kimia
                                </span>
                                <span class="text-[10px] font-bold px-2 py-0.5 rounded-md bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300">
                                    ● {{ lang === 'en' ? 'Active' : 'Aktif' }}
                                </span>
                            </div>
                            <p class="text-[10px] text-muted-foreground mt-1">
                                {{ lang === 'en' ? 'Stays here until explicitly received' : 'Tetap di sini hingga diterima eksplisit' }}
                            </p>
                        </div>
                    </div>
                    <div v-if="form.rfid_tag" class="pt-2 border-t border-border">
                        <p class="text-[10px] text-muted-foreground uppercase tracking-wider">
                            {{ lang === 'en' ? 'Scan Reference (RFID/Barcode)' : 'Referensi Scan (RFID/Barcode)' }}
                        </p>
                        <p class="text-xs font-mono font-bold text-primary mt-1">{{ form.rfid_tag }}</p>
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
                        :class="!form.processing && form.product_code_id && form.location_id && form.initial_weight
                            ? 'hover:shadow-lg hover:shadow-primary/25 hover:-translate-y-0.5 active:scale-[0.98]'
                            : ''"
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