<script setup>
/**
 * SnapshotTrash.vue — Arsip capture CCTV yang sudah di-soft-delete.
 * Hanya company_admin (digerbang di route + controller). Menggunakan
 * SnapshotGalleryCard.vue yang sama dengan SnapshotHistory.vue/Show.vue,
 * dengan tombol restore diaktifkan lewat prop `show-restore`.
 */
import { ref, computed } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import SnapshotGalleryCard from '@/components/shared/SnapshotGalleryCard.vue'
import { useI18n } from '@/composables/core/useI18n.js'

const { lang } = useI18n()

const props = defineProps({
    snapshots: Object,
    filters:   Object,
})

const t = computed(() => ({
    title:    lang.value === 'en' ? 'Deleted Capture Archive' : 'Arsip Capture Terhapus',
    subtitle: lang.value === 'en'
        ? 'Soft-deleted captures — recoverable at any time. Nothing here is ever permanently destroyed.'
        : 'Capture yang sudah dihapus (soft-delete) — dapat dipulihkan kapan saja. Tidak ada yang dihapus permanen.',
    back:      lang.value === 'en' ? 'Back to History' : 'Kembali ke Riwayat',
    noResults: lang.value === 'en' ? 'The archive is empty.' : 'Arsip kosong.',
    showing:   lang.value === 'en' ? 'Showing' : 'Menampilkan',
    of:        lang.value === 'en' ? 'of'      : 'dari',
    items:     lang.value === 'en' ? 'archived captures' : 'capture terarsip',
}))

function handleRestore({ id }) {
    router.patch(`/company/cctv/snapshots/${id}/restore`, {}, { preserveScroll: true })
}

const photoModal = ref(null)
function openPhoto(url) { photoModal.value = url }
function closePhoto()   { photoModal.value = null }
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
                <Link href="/company/cctv/snapshots"
                      class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold border border-border text-foreground hover:bg-muted transition-colors flex-shrink-0">
                    ← {{ t.back }}
                </Link>
            </div>

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
                        show-restore
                        @open="openPhoto"
                        @restore="handleRestore"
                    />
                </div>

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

        <Teleport to="body">
            <div v-if="photoModal" class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center p-4" @click="closePhoto">
                <img :src="photoModal" class="max-w-full max-h-full rounded-lg" />
            </div>
        </Teleport>
    </AppLayout>
</template>
