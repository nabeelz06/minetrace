<script setup>
/**
 * SnapshotGalleryCard.vue — kartu presentasi SATU capture CCTV.
 *
 * Dipakai IDENTIK di dua tempat:
 *   1. Batches/Show.vue — tab "Galeri Foto", di-scope ke satu batch
 *   2. Company/Cctv/SnapshotHistory.vue — manajemen lintas-batch global
 *
 * Markup, badge, dan layout metadata SENGAJA sama persis di kedua tempat
 * (lihat Section 4.6 changelog) — bedanya hanya prop `show-batch-code`
 * dan `can-verify`/`can-delete` yang mengaktifkan kontrol CRUD tambahan
 * saat dipakai dari halaman manajemen global.
 */
import { ref, computed } from 'vue'
import { useI18n } from '@/composables/core/useI18n.js'

const { lang } = useI18n()

const props = defineProps({
    snapshot:      { type: Object,  required: true },
    isLatest:      { type: Boolean, default: false },
    showBatchCode: { type: Boolean, default: false },
    canVerify:     { type: Boolean, default: false },
    canDelete:     { type: Boolean, default: false },
    showRestore:   { type: Boolean, default: false },
})

const emit = defineEmits(['open', 'verify', 'delete', 'restore'])

const t = computed(() => ({
    latest:         lang.value === 'en' ? '● LATEST'        : '● TERBARU',
    scanIn:         lang.value === 'en' ? 'SCAN IN'         : 'SCAN IN',
    scanOut:        lang.value === 'en' ? 'SCAN OUT'        : 'SCAN OUT',
    real:           lang.value === 'en' ? 'REAL'            : 'REAL',
    simulation:     lang.value === 'en' ? 'SIMULATION'      : 'SIMULASI',
    verified:       lang.value === 'en' ? 'Verified'        : 'Terverifikasi',
    unverified:     lang.value === 'en' ? 'Not verified'    : 'Belum Diverifikasi',
    captureFailed:  lang.value === 'en' ? 'Capture Failed'  : 'Capture Gagal',
    verify:         lang.value === 'en' ? 'Verify'          : 'Verifikasi',
    unverify:       lang.value === 'en' ? 'Unverify'        : 'Batal Verifikasi',
    delete:         lang.value === 'en' ? 'Delete'          : 'Hapus',
    restore:        lang.value === 'en' ? 'Restore'         : 'Pulihkan',
    reviewNotes:    lang.value === 'en' ? 'Review notes'    : 'Catatan Review',
    reviewPlaceholder: lang.value === 'en' ? 'Optional notes...' : 'Catatan opsional...',
    save:           lang.value === 'en' ? 'Save'            : 'Simpan',
    cancel:         lang.value === 'en' ? 'Cancel'          : 'Batal',
    deleteReasonTitle: lang.value === 'en' ? 'Delete with reason' : 'Hapus dengan Alasan',
    deleteReasonHelp:  lang.value === 'en'
        ? 'This is evidentiary data — deletion is soft (recoverable from trash) and a reason is mandatory for the audit log.'
        : 'Data ini bersifat evidentiary — penghapusan bersifat soft (bisa dipulihkan dari arsip) dan alasan wajib diisi untuk audit log.',
    deleteReasonPlaceholder: lang.value === 'en' ? 'Explain why this capture is being deleted...' : 'Jelaskan alasan penghapusan capture ini...',
    confirmDelete:  lang.value === 'en' ? 'Confirm Delete' : 'Konfirmasi Hapus',
    confirmRestore: lang.value === 'en' ? 'Restore this capture?' : 'Pulihkan capture ini?',
}))

function scanTypeLabel(type) {
    if (type === 'scan_out' || type === 'departure_snapshot') return t.value.scanOut
    return t.value.scanIn
}

const showDeleteModal = ref(false)
const deleteReason     = ref('')
const showNotesEditor  = ref(false)
const reviewNotesInput = ref(props.snapshot.review_notes || '')

function openDeleteModal() {
    deleteReason.value = ''
    showDeleteModal.value = true
}

function confirmDelete() {
    if (deleteReason.value.trim().length < 5) return
    emit('delete', { id: props.snapshot.id, reason: deleteReason.value.trim() })
    showDeleteModal.value = false
}

function toggleVerify() {
    emit('verify', {
        id: props.snapshot.id,
        is_verified: !props.snapshot.is_verified,
        review_notes: reviewNotesInput.value,
    })
}

function saveNotes() {
    emit('verify', {
        id: props.snapshot.id,
        is_verified: props.snapshot.is_verified,
        review_notes: reviewNotesInput.value,
    })
    showNotesEditor.value = false
}

function requestRestore() {
    if (confirm(t.value.confirmRestore)) {
        emit('restore', { id: props.snapshot.id })
    }
}
</script>

<template>
    <div
        class="group relative rounded-xl overflow-hidden border transition-all duration-150 bg-muted"
        :class="isLatest ? 'border-primary/50 ring-1 ring-primary/20' : 'border-border hover:border-primary/40'"
    >
        <button
            type="button"
            class="w-full aspect-video relative overflow-hidden block"
            :disabled="!snapshot.url"
            @click="snapshot.url && emit('open', snapshot.url)"
        >
            <img
                v-if="snapshot.url"
                :src="snapshot.url"
                :alt="`Foto ${snapshot.stage_label}`"
                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                loading="lazy"
            />
            <div v-else class="w-full h-full flex items-center justify-center bg-muted">
                <span class="text-xs text-muted-foreground">{{ t.captureFailed }}</span>
            </div>
            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/10 to-transparent" />

            <!-- Scan type + latest badge -->
            <div class="absolute top-1.5 left-1.5 flex items-center gap-1 flex-wrap">
                <span class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-black/60 text-white">
                    {{ scanTypeLabel(snapshot.scan_type) }}
                </span>
                <span class="text-[9px] font-bold px-1.5 py-0.5 rounded text-white"
                      :class="snapshot.capture_mode === 'real' ? 'bg-blue-600/80' : 'bg-slate-500/80'">
                    {{ snapshot.capture_mode === 'real' ? t.real : t.simulation }}
                </span>
                <span v-if="isLatest"
                      class="text-[9px] font-bold px-1.5 py-0.5 rounded text-white"
                      style="background: hsl(var(--primary));">
                    {{ t.latest }}
                </span>
            </div>

            <!-- Verified badge -->
            <div class="absolute top-1.5 right-1.5">
                <span class="text-[9px] font-bold px-1.5 py-0.5 rounded"
                      :class="snapshot.is_verified
                          ? 'bg-emerald-500/90 text-white'
                          : 'bg-black/50 text-gray-200'">
                    {{ snapshot.is_verified ? '✓ ' + t.verified : t.unverified }}
                </span>
            </div>

            <!-- Stage + time overlay — timestamp mentah dari backend, TIDAK
                 di-reparse via `new Date()` (ambigu dd/mm vs mm/dd). -->
            <div class="absolute bottom-0 left-0 right-0 p-2 space-y-0.5 text-left">
                <p v-if="showBatchCode" class="text-[10px] text-primary-foreground/90 font-bold truncate">
                    {{ snapshot.batch_code }} <span v-if="snapshot.product_code">({{ snapshot.product_code }})</span>
                </p>
                <p class="text-xs text-white font-semibold truncate">{{ snapshot.stage_label }}</p>
                <p class="text-[11px] text-white font-mono font-medium">{{ snapshot.captured_at || '—' }}</p>
                <p v-if="snapshot.captured_at_diff" class="text-[10px] text-gray-300">{{ snapshot.captured_at_diff }}</p>
            </div>
        </button>

        <!-- Metadata row -->
        <div class="px-2 py-1.5 space-y-0.5 text-left">
            <div class="flex items-center justify-between gap-1">
                <span class="text-[10px] text-muted-foreground truncate">{{ snapshot.camera_name ?? '—' }}</span>
                <span v-if="snapshot.capture_status === 'failed'"
                      class="text-[9px] font-bold px-1 py-0.5 rounded bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400 flex-shrink-0">
                    ✕ {{ snapshot.failure_reason ?? 'failed' }}
                </span>
            </div>
            <p v-if="snapshot.rfid_uid" class="text-[9px] font-mono text-muted-foreground/60 truncate">
                UID: {{ snapshot.rfid_uid }}
            </p>
            <p v-if="snapshot.review_notes" class="text-[9px] text-muted-foreground/80 italic truncate" :title="snapshot.review_notes">
                {{ snapshot.review_notes }}
            </p>
        </div>

        <!-- ─── Aksi CRUD (hanya tampil jika diaktifkan via prop) ──────────── -->
        <div v-if="canVerify || canDelete || showRestore" class="px-2 pb-2 flex items-center gap-1.5 flex-wrap">
            <button v-if="canVerify" type="button"
                    class="text-[10px] px-1.5 py-1 rounded font-medium transition-colors"
                    :class="snapshot.is_verified
                        ? 'bg-muted text-muted-foreground hover:bg-muted/70'
                        : 'bg-emerald-600 text-white hover:bg-emerald-700'"
                    @click="toggleVerify">
                {{ snapshot.is_verified ? t.unverify : t.verify }}
            </button>
            <button v-if="canVerify" type="button"
                    class="text-[10px] px-1.5 py-1 rounded font-medium bg-muted text-muted-foreground hover:bg-muted/70"
                    @click="showNotesEditor = !showNotesEditor">
                {{ t.reviewNotes }}
            </button>
            <button v-if="canDelete" type="button"
                    class="text-[10px] px-1.5 py-1 rounded font-medium bg-red-600 text-white hover:bg-red-700"
                    @click="openDeleteModal">
                {{ t.delete }}
            </button>
            <button v-if="showRestore" type="button"
                    class="text-[10px] px-1.5 py-1 rounded font-medium bg-primary text-primary-foreground hover:opacity-90"
                    @click="requestRestore">
                {{ t.restore }}
            </button>
        </div>

        <!-- Inline review notes editor -->
        <div v-if="showNotesEditor" class="px-2 pb-2 space-y-1">
            <textarea v-model="reviewNotesInput" rows="2" :placeholder="t.reviewPlaceholder"
                      class="w-full text-[11px] rounded-lg border border-border bg-background px-2 py-1 resize-none" />
            <div class="flex justify-end gap-1">
                <button type="button" class="text-[10px] px-2 py-1 rounded bg-muted" @click="showNotesEditor = false">{{ t.cancel }}</button>
                <button type="button" class="text-[10px] px-2 py-1 rounded bg-primary text-primary-foreground" @click="saveNotes">{{ t.save }}</button>
            </div>
        </div>

        <!-- Delete-with-reason modal -->
        <Teleport to="body">
            <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                 @click.self="showDeleteModal = false">
                <div class="bg-card rounded-2xl border border-border shadow-xl max-w-sm w-full p-5 space-y-3">
                    <h3 class="text-sm font-semibold text-foreground">{{ t.deleteReasonTitle }}</h3>
                    <p class="text-xs text-muted-foreground">{{ t.deleteReasonHelp }}</p>
                    <textarea v-model="deleteReason" rows="3" :placeholder="t.deleteReasonPlaceholder"
                              class="w-full text-xs rounded-lg border border-border bg-background px-3 py-2 resize-none" />
                    <div class="flex justify-end gap-2">
                        <button type="button" class="text-xs px-3 py-1.5 rounded-lg bg-muted" @click="showDeleteModal = false">
                            {{ t.cancel }}
                        </button>
                        <button type="button"
                                class="text-xs px-3 py-1.5 rounded-lg bg-red-600 text-white disabled:opacity-40"
                                :disabled="deleteReason.trim().length < 5"
                                @click="confirmDelete">
                            {{ t.confirmDelete }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
