<script setup>
/**
 * BatchTagCard — status tag RFID pada detail batch + aksi:
 *   - belum bertag  → "Daftarkan Tag" (langsung ke wizard dengan batch terisi: 1 klik lalu tempelkan tag)
 *   - sudah bertag  → UID + "Lepas / ganti tag" (alasan wajib; tercatat di riwayat tag, tidak menghapus riwayat scan)
 */
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import AppIcon from '@/components/app/AppIcon.vue'
import ResultBanner from '@/components/app/ResultBanner.vue'
import { rfidApi, useApiText } from '@/composables/rfid/useRfidApi.js'
import { http } from '@/lib/http'
import { pushToast } from '@/composables/core/useToast.js'
import { useI18n } from '@/composables/core/useI18n.js'
import { formatDateTime, groupUid } from '@/lib/format'

const props = defineProps({
    batch:      { type: Object, required: true },     // { id, batch_code, rfid_tag }
    canOperate: { type: Boolean, default: false },
})

const { t, lang } = useI18n()
const apiText = useApiText()

const open = ref(false)
const reason = ref('replaced')
const note = ref('')
const loading = ref(false)
const error = ref('')
const history = ref(null)

async function release() {
    loading.value = true; error.value = ''
    const res = await rfidApi.releaseTag(props.batch.id, { reason: reason.value, note: note.value || null })
    loading.value = false
    if (!res.ok) { error.value = apiText(res); return }
    open.value = false
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
                    <p v-if="batch.rfid_tag" class="text-sm"><span class="text-muted-foreground">{{ t('rfid.tag.attached') }}:</span> <span class="font-mono font-semibold">{{ groupUid(batch.rfid_tag) }}</span></p>
                    <p v-else class="text-sm text-muted-foreground">{{ t('rfid.tag.none') }}</p>
                </div>
            </div>

            <div v-if="canOperate" class="flex flex-wrap gap-2">
                <Link v-if="!batch.rfid_tag" :href="`/company/hardware/rfid?register_batch=${batch.id}`"
                      class="inline-flex min-h-[44px] items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90">
                    <AppIcon name="nfc" :size="18" />{{ t('rfid.tag.register') }}
                </Link>
                <button v-else type="button" class="min-h-[44px] rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent" @click="open = true">
                    {{ t('rfid.tag.release') }}
                </button>
            </div>
            <p v-else class="text-sm text-muted-foreground">{{ t('rfid.tag.readonly') }}</p>
        </div>

        <details class="mt-3 text-sm" @toggle="loadHistory">
            <summary class="min-h-[44px] cursor-pointer select-none py-2 font-medium text-muted-foreground hover:text-foreground">{{ t('rfid.tag.history') }}</summary>
            <ul v-if="history?.length" class="space-y-1.5 pb-2">
                <li v-for="h in history" :key="h.id" class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                    <span class="font-semibold" :class="h.event === 'released' ? 'text-warning' : 'text-success'">{{ t(`rfid.tag.ev_${h.event}`) }}</span>
                    <span class="font-mono text-xs">{{ groupUid(h.uid) }}</span>
                    <span v-if="h.reason" class="text-muted-foreground">· {{ t(`rfid.tag.reason_${h.reason}`) }}</span>
                    <span class="text-muted-foreground">· {{ formatDateTime(h.at, lang) }}<template v-if="h.by"> · {{ h.by }}</template></span>
                </li>
            </ul>
            <p v-else-if="history" class="pb-2 text-muted-foreground">{{ t('rfid.tag.history_empty') }}</p>
        </details>

        <Dialog v-model:open="open">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle class="text-lg">{{ t('rfid.tag.release_title') }}</DialogTitle>
                    <DialogDescription class="text-sm">{{ t('rfid.tag.release_desc') }}</DialogDescription>
                </DialogHeader>
                <fieldset class="space-y-2">
                    <legend class="mb-1 text-sm font-medium">{{ t('rfid.tag.reason') }}</legend>
                    <label v-for="r in ['replaced', 'lost', 'damaged', 'other']" :key="r" class="flex min-h-[44px] cursor-pointer items-center gap-3 rounded-lg border border-border px-3 hover:bg-accent">
                        <input v-model="reason" type="radio" :value="r" class="h-5 w-5 border-input text-primary" />{{ t(`rfid.tag.reason_${r}`) }}
                    </label>
                </fieldset>
                <div class="space-y-1.5">
                    <label for="tag-note" class="block text-sm font-medium">{{ t('rfid.tag.note') }}</label>
                    <input id="tag-note" v-model="note" maxlength="255" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3" />
                </div>
                <ResultBanner v-if="error" type="error" :title="error" />
                <DialogFooter class="gap-2 sm:gap-2">
                    <button type="button" class="min-h-[44px] rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent" @click="open = false">{{ t('app.cancel') }}</button>
                    <button type="button" :disabled="loading" class="min-h-[44px] rounded-lg bg-danger px-4 text-sm font-semibold text-background hover:opacity-90 disabled:opacity-60" @click="release">
                        {{ loading ? t('app.processing') : t('rfid.tag.confirm') }}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
