<script setup>
/**
 * CctvSettings.vue — Manajemen kamera CCTV (company_admin only).
 *
 * Menambah/mengedit/menghapus kamera SEPENUHNYA lewat UI ini — tidak perlu
 * akses server, tidak perlu edit mediamtx.yml, tidak perlu restart
 * container. Backend (CctvController + MediaMtxPathService) otomatis:
 *   - Provisioning path live-view mediamtx untuk kamera fisik baru
 *   - Mendeteksi jika RTSP URL yang sama sudah dipakai kamera lain (satu
 *     perangkat fisik boleh melayani banyak checkpoint/stage sekaligus)
 *     dan memakai ulang path yang sudah ada — tidak membuka koneksi
 *     duplikat ke kamera yang sama.
 */
import { ref, computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useI18n } from '@/composables/core/useI18n.js'

const { lang } = useI18n()

const props = defineProps({
    cameras:      { type: Array,  default: () => [] },
    valid_stages: { type: Array,  default: () => [] },
    hls_base_url: { type: String, default: '' },
})

const t = computed(() => ({
    title:        lang.value === 'en' ? 'CCTV Camera Settings'  : 'Pengaturan Kamera CCTV',
    subtitle:     lang.value === 'en'
        ? 'Add, edit, or remove cameras — no server access needed.'
        : 'Tambah, ubah, atau hapus kamera — tanpa perlu akses server.',
    backToMonitor: lang.value === 'en' ? 'Back to Monitor' : 'Kembali ke Monitor',
    addCamera:    lang.value === 'en' ? 'Add Camera'  : 'Tambah Kamera',
    editCamera:   lang.value === 'en' ? 'Edit Camera' : 'Ubah Kamera',
    noCameras:    lang.value === 'en' ? 'No cameras configured yet.' : 'Belum ada kamera yang dikonfigurasi.',
    name:         lang.value === 'en' ? 'Camera Name'  : 'Nama Kamera',
    stage:        lang.value === 'en' ? 'Checkpoint / Stage' : 'Checkpoint / Tahap',
    stageNone:    lang.value === 'en' ? '— Not stage-specific —' : '— Tidak spesifik ke stage —',
    cameraType:   lang.value === 'en' ? 'Camera Type' : 'Jenis Kamera',
    typeReal:     lang.value === 'en' ? 'Real (physical camera)' : 'Real (kamera fisik)',
    typeSim:      lang.value === 'en' ? 'Simulation (test pattern)' : 'Simulasi (test pattern)',
    rtspReal:     lang.value === 'en' ? 'RTSP URL (physical camera)' : 'RTSP URL (kamera fisik)',
    rtspRealHelp: lang.value === 'en'
        ? 'Format: rtsp://username:password@ip-address/stream-path'
        : 'Format: rtsp://username:password@ip-address/stream-path',
    rtspSim:      lang.value === 'en' ? 'RTSP URL (simulation, optional)' : 'RTSP URL (simulasi, opsional)',
    rtspSimHelp:  lang.value === 'en'
        ? 'Leave empty to use the default mediamtx simulation stream.'
        : 'Kosongkan untuk memakai simulation stream mediamtx bawaan.',
    active:       lang.value === 'en' ? 'Active' : 'Aktif',
    save:         lang.value === 'en' ? 'Save' : 'Simpan',
    cancel:       lang.value === 'en' ? 'Cancel' : 'Batal',
    saving:       lang.value === 'en' ? 'Saving...' : 'Menyimpan...',
    edit:         lang.value === 'en' ? 'Edit' : 'Ubah',
    delete:       lang.value === 'en' ? 'Delete' : 'Hapus',
    confirmDelete: lang.value === 'en'
        ? 'Delete this camera? This cannot be undone.'
        : 'Hapus kamera ini? Tindakan ini tidak bisa dibatalkan.',
    real:         lang.value === 'en' ? 'REAL' : 'REAL',
    simulation:   lang.value === 'en' ? 'SIMULATION' : 'SIMULASI',
    inactive:     lang.value === 'en' ? 'Inactive' : 'Nonaktif',
    liveUrl:      lang.value === 'en' ? 'Live HLS URL' : 'URL HLS Live',
    notProvisioned: lang.value === 'en'
        ? 'Not provisioned yet — save the camera to auto-generate.'
        : 'Belum di-provisioning — simpan kamera untuk generate otomatis.',
    sharedCameraHint: lang.value === 'en'
        ? 'Tip: if this is the same physical camera as another entry, use the identical RTSP URL — the system will automatically reuse its live-view feed instead of opening a duplicate connection.'
        : 'Tips: jika ini kamera fisik yang sama dengan entri lain, gunakan RTSP URL yang identik — sistem akan otomatis memakai ulang live-view yang sudah ada, tanpa membuka koneksi duplikat.',
}))

const stageLabel = (key) => props.valid_stages.find(s => s.value === key)?.label ?? key

// ─── Modal state ────────────────────────────────────────────────────────────
const showModal   = ref(false)
const editingId    = ref(null)

const form = useForm({
    name:                 '',
    location_key:         '',
    is_simulation:        false,
    rtsp_url_real:        '',
    rtsp_url_simulation:  '',
    is_active:            true,
})

function openAddModal() {
    editingId.value = null
    form.reset()
    form.clearErrors()
    form.is_active = true
    showModal.value = true
}

function openEditModal(cam) {
    editingId.value = cam.id
    form.clearErrors()
    form.name                = cam.name
    form.location_key        = cam.location_key ?? ''
    form.is_simulation       = cam.is_simulation
    form.rtsp_url_real       = cam.rtsp_url_real ?? ''
    form.rtsp_url_simulation = cam.rtsp_url_simulation ?? ''
    form.is_active           = cam.is_active
    showModal.value = true
}

function closeModal() {
    showModal.value = false
    form.reset()
    form.clearErrors()
}

function submit() {
    if (editingId.value) {
        form.put(`/company/hardware/cctv/${editingId.value}`, {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        })
    } else {
        form.post('/company/hardware/cctv', {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        })
    }
}

function removeCamera(cam) {
    if (!confirm(t.value.confirmDelete)) return
    form.delete(`/company/hardware/cctv/${cam.id}`, { preserveScroll: true })
}

// ─── Mask credentials for display in list (avoid casually exposing password) ──
function maskRtsp(url) {
    if (!url) return '—'
    return url.replace(/:\/\/([^:]+):([^@]+)@/, '://$1:••••••@')
}
</script>

<template>
<AppLayout>
    <Head :title="t.title"/>
    <template #header>{{ t.title }}</template>

    <div class="space-y-6">

        <!-- Header -->
        <div class="flex items-start justify-between gap-4 animate-fade-in">
            <div>
                <h1 class="text-2xl font-bold text-foreground tracking-tight">{{ t.title }}</h1>
                <p class="text-sm text-muted-foreground mt-0.5">{{ t.subtitle }}</p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <Link href="/company/hardware/cctv"
                      class="flex items-center gap-1.5 px-3 py-2 rounded-xl border border-border text-xs font-semibold text-muted-foreground hover:text-foreground hover:bg-muted transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                    </svg>
                    {{ t.backToMonitor }}
                </Link>
                <button @click="openAddModal"
                        class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-semibold text-white transition-colors"
                        style="background: hsl(var(--primary));">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    {{ t.addCamera }}
                </button>
            </div>
        </div>

        <!-- Camera list -->
        <div v-if="!cameras.length" class="rounded-2xl border border-border bg-card p-16 text-center animate-fade-in">
            <p class="text-sm text-muted-foreground">{{ t.noCameras }}</p>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4 animate-fade-in">
            <div v-for="cam in cameras" :key="cam.id"
                 class="rounded-2xl border border-border bg-card p-5 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-foreground truncate">{{ cam.name }}</p>
                        <p v-if="cam.location_key" class="text-xs text-muted-foreground mt-0.5">
                            {{ stageLabel(cam.location_key) }}
                        </p>
                    </div>
                    <div class="flex flex-col items-end gap-1 flex-shrink-0">
                        <span class="text-[9px] font-bold px-2 py-0.5 rounded-full"
                              :class="cam.is_simulation
                                  ? 'bg-violet-100 text-violet-700 dark:bg-violet-900/30 dark:text-violet-400'
                                  : 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'">
                            {{ cam.is_simulation ? t.simulation : t.real }}
                        </span>
                        <span v-if="!cam.is_active" class="text-[9px] font-bold px-2 py-0.5 rounded-full bg-muted text-muted-foreground">
                            {{ t.inactive }}
                        </span>
                    </div>
                </div>

                <div class="space-y-2 text-xs">
                    <div v-if="!cam.is_simulation">
                        <p class="text-muted-foreground">{{ t.rtspReal }}</p>
                        <p class="font-mono text-foreground truncate" :title="cam.rtsp_url_real">
                            {{ maskRtsp(cam.rtsp_url_real) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">{{ t.liveUrl }}</p>
                        <p v-if="cam.hls_url_effective" class="font-mono text-foreground truncate">
                            {{ cam.hls_url_effective }}
                        </p>
                        <p v-else class="text-amber-600 dark:text-amber-400 text-[11px]">
                            {{ t.notProvisioned }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2 pt-2 border-t border-border/60">
                    <button @click="openEditModal(cam)"
                            class="flex-1 py-1.5 rounded-lg text-xs font-semibold border border-border text-muted-foreground hover:bg-muted hover:text-foreground transition-colors">
                        {{ t.edit }}
                    </button>
                    <button @click="removeCamera(cam)"
                            class="flex-1 py-1.5 rounded-lg text-xs font-semibold border border-red-200 dark:border-red-900/40 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/20 transition-colors">
                        {{ t.delete }}
                    </button>
                </div>
            </div>
        </div>

    </div>

    <!-- ─── Add/Edit Modal ─────────────────────────────────────────────── -->
    <Teleport to="body">
        <Transition name="fade">
            <div v-if="showModal"
                 class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
                 @click.self="closeModal">
                <div class="w-full max-w-lg rounded-2xl border border-border bg-card p-6 space-y-5 max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-bold text-foreground">
                            {{ editingId ? t.editCamera : t.addCamera }}
                        </h2>
                        <button @click="closeModal" class="text-muted-foreground hover:text-foreground">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <form @submit.prevent="submit" class="space-y-4">
                        <!-- Name -->
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1.5">{{ t.name }}</label>
                            <input v-model="form.name" type="text" required maxlength="100"
                                   class="w-full h-10 px-3 rounded-lg border border-border bg-background text-sm text-foreground outline-none focus:border-primary"/>
                            <p v-if="form.errors.name" class="text-xs text-red-500 mt-1">{{ form.errors.name }}</p>
                        </div>

                        <!-- Stage -->
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1.5">{{ t.stage }}</label>
                            <select v-model="form.location_key"
                                    class="w-full h-10 px-3 rounded-lg border border-border bg-background text-sm text-foreground outline-none focus:border-primary">
                                <option value="">{{ t.stageNone }}</option>
                                <option v-for="s in valid_stages" :key="s.value" :value="s.value">{{ s.label }}</option>
                            </select>
                            <p v-if="form.errors.location_key" class="text-xs text-red-500 mt-1">{{ form.errors.location_key }}</p>
                        </div>

                        <!-- Camera type -->
                        <div>
                            <label class="block text-xs font-semibold text-foreground mb-1.5">{{ t.cameraType }}</label>
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="form.is_simulation = false"
                                        class="py-2 rounded-lg text-xs font-semibold border transition-all"
                                        :class="!form.is_simulation
                                            ? 'border-emerald-400 bg-emerald-50 dark:bg-emerald-950/20 text-emerald-700 dark:text-emerald-400'
                                            : 'border-border text-muted-foreground hover:bg-muted'">
                                    📷 {{ t.typeReal }}
                                </button>
                                <button type="button" @click="form.is_simulation = true"
                                        class="py-2 rounded-lg text-xs font-semibold border transition-all"
                                        :class="form.is_simulation
                                            ? 'border-violet-400 bg-violet-50 dark:bg-violet-950/20 text-violet-700 dark:text-violet-400'
                                            : 'border-border text-muted-foreground hover:bg-muted'">
                                    🎮 {{ t.typeSim }}
                                </button>
                            </div>
                        </div>

                        <!-- RTSP real -->
                        <div v-if="!form.is_simulation">
                            <label class="block text-xs font-semibold text-foreground mb-1.5">{{ t.rtspReal }}</label>
                            <input v-model="form.rtsp_url_real" type="text" placeholder="rtsp://user:password@192.168.1.10/stream2"
                                   class="w-full h-10 px-3 rounded-lg border border-border bg-background text-sm font-mono text-foreground outline-none focus:border-primary"/>
                            <p class="text-[11px] text-muted-foreground mt-1">{{ t.rtspRealHelp }}</p>
                            <p v-if="form.errors.rtsp_url_real" class="text-xs text-red-500 mt-1">{{ form.errors.rtsp_url_real }}</p>
                            <p class="text-[11px] text-sky-600 dark:text-sky-400 mt-2 leading-relaxed">
                                💡 {{ t.sharedCameraHint }}
                            </p>
                        </div>

                        <!-- RTSP simulation -->
                        <div v-else>
                            <label class="block text-xs font-semibold text-foreground mb-1.5">{{ t.rtspSim }}</label>
                            <input v-model="form.rtsp_url_simulation" type="text" placeholder="rtsp://127.0.0.1:8554/cam_gate1"
                                   class="w-full h-10 px-3 rounded-lg border border-border bg-background text-sm font-mono text-foreground outline-none focus:border-primary"/>
                            <p class="text-[11px] text-muted-foreground mt-1">{{ t.rtspSimHelp }}</p>
                            <p v-if="form.errors.rtsp_url_simulation" class="text-xs text-red-500 mt-1">{{ form.errors.rtsp_url_simulation }}</p>
                        </div>

                        <!-- Active -->
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="form.is_active" type="checkbox"
                                   class="w-4 h-4 rounded border-border text-primary focus:ring-primary"/>
                            <span class="text-xs font-semibold text-foreground">{{ t.active }}</span>
                        </label>

                        <!-- Actions -->
                        <div class="flex items-center gap-2 pt-2">
                            <button type="button" @click="closeModal"
                                    class="flex-1 py-2.5 rounded-xl text-sm font-semibold border border-border text-muted-foreground hover:bg-muted transition-colors">
                                {{ t.cancel }}
                            </button>
                            <button type="submit" :disabled="form.processing"
                                    class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-50 transition-colors"
                                    style="background: hsl(var(--primary));">
                                {{ form.processing ? t.saving : t.save }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Transition>
    </Teleport>
</AppLayout>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; }
</style>
