<script setup>
/**
 * BatchTagCard — status tag RFID pada detail batch + aksi:
 *   - belum bertag  → "Daftarkan Tag" (langsung ke wizard dengan batch terisi: 1 klik lalu tempelkan tag)
 *   - sudah bertag  → UID + "Lepas / ganti tag" (alasan wajib; tercatat di riwayat tag, tidak menghapus riwayat scan)
 *                     + "Kelola tag" (Kelola Tag dengan UID terisi: reset uji tanpa mengganti tag)
 * Dialog lepas-tag dipakai bersama dengan halaman Kelola Tag (TagActionDialog) agar perilakunya tidak bisa menyimpang.
 */
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AppIcon from '@/components/app/AppIcon.vue'
import TagActionDialog from '@/components/rfid/TagActionDialog.vue'
import { http } from '@/lib/http'
import { pushToast } from '@/composables/core/useToast.js'
import { useI18n } from '@/composables/core/useI18n.js'
import { formatDateTime, formatUidGrouped, formatUidShort, groupUid } from '@/lib/format'

const props = defineProps({
    batch:      { type: Object, required: true },     // { id, batch_code, rfid_tag }
    canOperate: { type: Boolean, default: false },
})

const { t, lang } = useI18n()

const open = ref(false)
const history = ref(null)
// Bentuk minimal yang dibutuhkan dialog (uid + kode batch); dampak & izin tetap ditegakkan server.
const dialogTag = computed(() => ({ uid: props.batch.rfid_tag, batch: { batch_code: props.batch.batch_code } }))

function onReleased() {
    pushToast(t('rfid.tag.released_toast'), 'success')
    history.value = null
    router.reload({ only: ['batch'] })
}

async function loadHistory(e) {
    if (!e.target.open || history.value) return
    const res = await http.get(`/api/v1/company/rfid/batches/${props.batch.id}/tag-history`)
    history.value = res.ok ? res.data : []
}
</script>

<template>
    <section class="rounded-xl border border-border bg-card p-4 sm:p-5" :aria-label="t('rfid.tag.title')">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg" :class="batch.rfid_tag ? 'bg-success-soft text-success' : 'bg-muted text-muted-foreground'">
                    <AppIcon name="tag" :size="22" />
                </span>
                <div>
                    <h2 class="text-base font-semibold">{{ t('rfid.tag.title') }}</h2>
                    <p v-if="batch.rfid_tag" class="text-sm">
                        <span class="text-muted-foreground">{{ t('rfid.tag.attached') }}:</span>
                        <span class="hidden font-mono font-semibold sm:inline" :title="batch.rfid_tag">{{ formatUidGrouped(batch.rfid_tag) }}</span>
                        <span class="font-mono font-semibold sm:hidden" :title="batch.rfid_tag">{{ formatUidShort(batch.rfid_tag) }}</span>
                    </p>
                    <p v-else class="text-sm text-muted-foreground">{{ t('rfid.tag.none') }}</p>
                </div>
            </div>

            <div v-if="canOperate" class="flex flex-wrap gap-2">
                <Link v-if="!batch.rfid_tag" :href="`/company/hardware/rfid?register_batch=${batch.id}`"
                      class="inline-flex min-h-[44px] items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90">
                    <AppIcon name="nfc" :size="18" />{{ t('rfid.tag.register') }}
                </Link>
                <template v-else>
                    <Link :href="`/company/hardware/rfid?view=tags&uid=${batch.rfid_tag}`" class="inline-flex min-h-[44px] items-center gap-2 rounded-lg px-3 text-sm font-medium text-primary hover:bg-accent">
                        <AppIcon name="rotate-ccw" :size="16" />{{ t('rfid.scan.manage_tag') }}
                    </Link>
                    <button type="button" class="min-h-[44px] rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent" @click="open = true">
                        {{ t('rfid.tag.release') }}
                    </button>
                </template>
            </div>
            <p v-else class="text-sm text-muted-foreground">{{ t('rfid.tag.readonly') }}</p>
        </div>

        <details class="mt-3 text-sm" @toggle="loadHistory">
            <summary class="min-h-[44px] cursor-pointer select-none py-2 font-medium text-muted-foreground hover:text-foreground">{{ t('rfid.tag.history') }}</summary>
            <ul v-if="history?.length" class="space-y-1.5 pb-2">
                <li v-for="h in history" :key="h.id" class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                    <span class="font-semibold" :class="h.event === 'released' ? 'text-warning' : h.event === 'reset' ? 'text-info' : 'text-success'">{{ t(`rfid.tag.ev_${h.event}`) }}</span>
                    <span class="font-mono text-xs">{{ groupUid(h.uid) }}</span>
                    <span v-if="h.reason" class="text-muted-foreground">· {{ t(`rfid.tag.reason_${h.reason}`) }}</span>
                    <span class="text-muted-foreground">· {{ formatDateTime(h.at, lang) }}<template v-if="h.by"> · {{ h.by }}</template></span>
                </li>
            </ul>
            <p v-else-if="history" class="pb-2 text-muted-foreground">{{ t('rfid.tag.history_empty') }}</p>
        </details>

        <TagActionDialog v-model:open="open" mode="release" :tag="dialogTag" @done="onReleased" />
    </section>
</template>
