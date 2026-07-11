<script setup>
/**
 * SnapshotHistory.vue — Riwayat Capture CCTV, manajemen lintas-batch global.
 *
 * Merender setiap capture lewat SnapshotGalleryCard.vue — komponen yang
 * SAMA PERSIS dipakai di Batches/Show.vue tab "Galeri Foto" — agar auditor
 * melihat presentasi identik baik masuk dari batch tertentu maupun
 * menjelajah di sini secara global (lihat Section 4.6 changelog).
 */
import { ref, computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import SnapshotGalleryCard from '@/components/shared/SnapshotGalleryCard.vue'
import { useI18n } from '@/composables/core/useI18n.js'

const { lang } = useI18n()

const props = defineProps({
    snapshots:  Object,
    filters:    Object,
    can_manage: Boolean,
    can_delete: Boolean,
})

const t = computed(() => ({
    title:      lang.value === 'en' ? 'CCTV Capture History'      : 'Riwayat Capture CCTV',
    subtitle:   lang.value === 'en'
        ? 'Cross-batch CCTV capture archive — filter, verify, or delete with a mandatory reason.'
        : 'Arsip capture CCTV lintas-batch — filter, verifikasi, atau hapus dengan alasan wajib.',
    trash:      lang.value === 'en' ? 'Deleted Archive' : 'Arsip Terhapus',
    batchCode:  lang.value === 'en' ? 'Batch Code'      : 'Kode Batch',
    stage:      lang.value === 'en' ? 'Stage'           : 'Tahap',
    allStages:  lang.value === 'en' ? 'All Stages'      : 'Semua Tahap',
    mode:       lang.value === 'en' ? 'Mode'            : 'Mode',
    allModes:   lang.value === 'en' ? 'All Modes'       : 'Semua Mode',
    status:     lang.value === 'en' ? 'Status'          : 'Status',
    allStatus:  lang.value === 'en' ? 'All Status'      : 'Semua Status',
    verified:   lang.value === 'en' ? 'Verification'    : 'Verifikasi',
    allVerified:lang.value === 'en' ? 'All'             : 'Semua',
    isVerified: lang.value === 'en' ? 'Verified'        : 'Terverifikasi',
    notVerified:lang.value === 'en' ? 'Not verified'    : 'Belum Diverifikasi',
    dateFrom:   lang.value === 'en' ? 'From'            : 'Dari Tanggal',
    dateTo:     lang.value === 'en' ? 'To'              : 'Sampai Tanggal',
    noResults:  lang.value === 'en' ? 'No captures match these filters.' : 'Tidak ada capture yang cocok dengan filter ini.',
    showing:    lang.value === 'en' ? 'Showing' : 'Menampilkan',
    of:         lang.value === 'en' ? 'of'      : 'dari',
    items:      lang.value === 'en' ? 'captures' : 'capture',
}))

const filters = ref({
    batch_code:      props.filters?.batch_code ?? '',
    stage:           props.filters?.stage ?? '',
    capture_mode:    props.filters?.capture_mode ?? '',
    capture_status:  props.filters?.capture_status ?? '',
    verified:        props.filters?.verified ?? '',
    date_from:       props.filters?.date_from ?? '',
    date_to:         props.filters?.date_to ?? '',
})

let searchTimeout = null
function applyFilters() {
    router.get('/company/cctv/snapshots', Object.fromEntries(
        Object.entries(filters.value).filter(([, v]) => v !== '' && v !== null)
    ), { preserveState: true, replace: true })
}
function onSearchInput() {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(applyFilters, 400)
}

const photoModal = ref(null)
function openPhoto(url) { photoModal.value = url }
function closePhoto()   { photoModal.value = null }

function handleVerify({ id, is_verified, review_notes }) {
    router.patch(`/company/cctv/snapshots/${id}/verify`, { is_verified, review_notes }, { preserveScroll: true, preserveState: true })
}
function handleDelete({ id, reason }) {
    router.delete(`/company/cctv/snapshots/${id}`, { data: { reason }, preserveScroll: true })
}
</script>

<template>
    <AppLayout>
        <Head :title="t.title" />
        <template #header>{{ t.title }}</template>

        <div class="space-y-6">
            <div class="flex items-start justify-between gap-4 animate-fade-in">
                <div>
                    <h1 class="text-2xl font-bold text-foreground tracking-tight">{{ t.title }}</h1>
                    <p class="text-sm text-muted-foreground mt-0.5">{{ t.subtitle }}</p>
                </div>
                <Link v-if="can_delete" href="/company/cctv/snapshots/trash"
                      class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold border border-border text-foreground hover:bg-muted transition-colors flex-shrink-0">
                    🗑️ {{ t.trash }}
                </Link>
            </div>

            <!-- ─── Filters ─────────────────────────────────────────────── -->
            <div class="rounded-2xl border border-border bg-card p-4 grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
                <input v-model="filters.batch_code" @input="onSearchInput" type="text" :placeholder="t.batchCode"
                       class="col-span-2 md:col-span-2 rounded-lg border border-border bg-background px-3 py-2 text-sm" />

                <select v-model="filters.stage" @change="applyFilters" class="rounded-lg border border-border bg-background px-3 py-2 text-sm">
                    <option value="">{{ t.allStages }}</option>
                    <option value="lab_kimia">Lab Kimia</option>
                    <option value="receiving">Receiving</option>
                    <option value="wet_process">Wet Process</option>
                    <option value="dry_process">Dry Process</option>
                    <option value="project_plant">Project Plant</option>
                    <option value="warehouse">Warehouse</option>
                </select>

                <select v-model="filters.capture_mode" @change="applyFilters" class="rounded-lg border border-border bg-background px-3 py-2 text-sm">
                    <option value="">{{ t.allModes }}</option>
                    <option value="real">REAL</option>
                    <option value="simulation">SIMULASI</option>
                </select>

                <select v-model="filters.capture_status" @change="applyFilters" class="rounded-lg border border-border bg-background px-3 py-2 text-sm">
                    <option value="">{{ t.allStatus }}</option>
                    <option value="success">Success</option>
                    <option value="failed">Failed</option>
                </select>

                <select v-model="filters.verified" @change="applyFilters" class="rounded-lg border border-border bg-background px-3 py-2 text-sm">
                    <option value="">{{ t.allVerified }}</option>
                    <option value="1">{{ t.isVerified }}</option>
                    <option value="0">{{ t.notVerified }}</option>
                </select>

                <input v-model="filters.date_from" @change="applyFilters" type="date" :placeholder="t.dateFrom"
                       class="rounded-lg border border-border bg-background px-3 py-2 text-sm" />
            </div>

            <!-- ─── Grid ────────────────────────────────────────────────── -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden">
                <div v-if="!snapshots?.data?.length" class="py-16 text-center">
                    <p class="text-sm text-muted-foreground">{{ t.noResults }}</p>
                </div>

                <div v-else class="p-5 grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                    <SnapshotGalleryCard
                        v-for="snapshot in snapshots.data"
                        :key="snapshot.id"
                        :snapshot="snapshot"
                        show-batch-code
                        :can-verify="can_manage"
                        :can-delete="can_delete"
                        @open="openPhoto"
                        @verify="handleVerify"
                        @delete="handleDelete"
                    />
                </div>

                <!-- Pagination -->
                <div v-if="snapshots?.links?.length > 3" class="flex items-center justify-between px-5 py-4 border-t border-border">
                    <p class="text-sm text-muted-foreground">
                        {{ t.showing }}
                        <span class="font-semibold text-foreground">{{ snapshots.from }}–{{ snapshots.to }}</span>
                        {{ t.of }}
                        <span class="font-semibold text-foreground">{{ snapshots.total }}</span>
                        {{ t.items }}
                    </p>
                    <div class="flex items-center gap-1">
                        <template v-for="link in snapshots.links" :key="link.label">
                            <Link v-if="link.url" :href="link.url" preserve-scroll
                                  class="w-8 h-8 rounded-lg text-xs font-semibold flex items-center justify-center transition-all duration-150"
                                  :class="link.active ? 'text-white shadow-sm' : 'text-muted-foreground hover:bg-muted hover:text-foreground'"
                                  :style="link.active ? 'background: hsl(var(--primary));' : ''"
                                  v-html="link.label" />
                            <span v-else class="w-8 h-8 rounded-lg text-xs flex items-center justify-center text-muted-foreground/40" v-html="link.label" />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Photo lightbox -->
        <Teleport to="body">
            <div v-if="photoModal" class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center p-4" @click="closePhoto">
                <img :src="photoModal" class="max-w-full max-h-full rounded-lg" />
            </div>
        </Teleport>
    </AppLayout>
</template>
