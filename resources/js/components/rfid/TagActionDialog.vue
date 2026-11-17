<script setup>
/**
 * TagActionDialog — dialog aksi "Kelola Tag": Lepas tag (alasan baku + catatan) atau Reset uji (pratinjau dampak + catatan).
 *
 * Dialog tidak memutuskan apa pun: izin, pagar keamanan, dan audit ditegakkan server. Tugasnya menjelaskan dampak sebelum
 * tombol ditekan, menampilkan galat server dengan bahasa aktif, lalu memberi tahu induknya (`done`) agar data disegarkan.
 */
import { computed, ref, watch } from 'vue'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import AppIcon from '@/components/app/AppIcon.vue'
import ResultBanner from '@/components/app/ResultBanner.vue'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import { rfidApi, useApiText } from '@/composables/rfid/useRfidApi.js'
import { useI18n } from '@/composables/core/useI18n.js'
import { formatUidShort } from '@/lib/format'

const props = defineProps({
    open:   { type: Boolean, default: false },
    mode:   { type: String, default: 'release' },   // release | reset
    tag:    { type: Object, default: null },        // data hasil rfidApi.tagLookup
    stages: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:open', 'done'])

const { t, loc } = useI18n()
const apiText = useApiText()

const REASONS = ['replaced', 'lost', 'damaged', 'other']

const reason = ref('replaced')
const note = ref('')
const loading = ref(false)
const error = ref('')

// Setiap dialog dibuka dari keadaan bersih (alasan/catatan/galat sisa dari tindakan sebelumnya tidak ikut terbawa).
watch(() => props.open, o => { if (o) { reason.value = 'replaced'; note.value = ''; error.value = ''; loading.value = false } })

const isReset = computed(() => props.mode === 'reset')
const uidShort = computed(() => formatUidShort(props.tag?.uid))
const batchCode = computed(() => props.tag?.batch?.batch_code ?? '')
const plan = computed(() => props.tag?.reset ?? null)
const rewind = computed(() => plan.value?.rewind ?? null)
const rewindStage = computed(() => {
    const key = rewind.value?.process_stage
    const s = props.stages.find(x => x.key === key)
    return s ? loc(s) : (key ?? '')
})

async function submit() {
    if (!props.tag || loading.value) return
    loading.value = true; error.value = ''
    const res = isReset.value
        ? await rfidApi.resetTag({ uid: props.tag.uid, note: note.value || null })
        : await rfidApi.releaseTagByUid({ uid: props.tag.uid, reason: reason.value, note: note.value || null })
    loading.value = false
    if (!res.ok) { error.value = apiText(res); return }
    emit('done', { mode: props.mode, uid: props.tag.uid, data: res.data })
    emit('update:open', false)
}
</script>

<template>
    <Dialog :open="open" @update:open="v => { if (!loading) emit('update:open', v) }">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle class="text-lg">
                    {{ isReset ? t('rfid.tagmgr.reset_title', { uid: uidShort }) : t('rfid.tagmgr.release_title', { batch: batchCode }) }}
                </DialogTitle>
                <DialogDescription class="text-sm">
                    {{ isReset ? t('rfid.tagmgr.reset_desc', { batch: batchCode }) : t('rfid.tagmgr.release_desc', { uid: uidShort }) }}
                </DialogDescription>
            </DialogHeader>

            <!-- Reset uji: tunjukkan dampaknya dulu (berapa catatan disembunyikan, apakah posisi batch dikembalikan) -->
            <ul v-if="isReset && plan" class="space-y-2 rounded-lg border border-border bg-muted/40 p-3 text-sm">
                <li class="flex items-start gap-2">
                    <AppIcon name="eye-off" :size="18" class="mt-0.5 text-muted-foreground" />
                    <span>{{ t('rfid.tagmgr.reset_plan_hide', { n: plan.records }) }}</span>
                </li>
                <li v-if="rewind" class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <AppIcon name="undo-2" :size="18" class="text-muted-foreground" />
                    <span>{{ t('rfid.tagmgr.reset_plan_rewind', { stage: rewindStage }) }}</span>
                    <StatusBadge :status="rewind.status" size="xs" />
                </li>
                <li v-else class="flex items-start gap-2">
                    <AppIcon name="circle-check" :size="18" class="mt-0.5 text-muted-foreground" />
                    <span>{{ t('rfid.tagmgr.reset_plan_stay') }}</span>
                </li>
            </ul>

            <!-- Lepas tag: alasan baku (tercatat di riwayat tag) -->
            <fieldset v-if="!isReset" class="space-y-2">
                <legend class="mb-1 text-sm font-medium">{{ t('rfid.tag.reason') }}</legend>
                <label v-for="r in REASONS" :key="r" class="flex min-h-[44px] cursor-pointer items-center gap-3 rounded-lg border border-border px-3 hover:bg-accent">
                    <input v-model="reason" type="radio" :value="r" class="h-5 w-5 border-input text-primary" />{{ t(`rfid.tag.reason_${r}`) }}
                </label>
            </fieldset>

            <div class="space-y-1.5">
                <label for="tag-action-note" class="block text-sm font-medium">{{ isReset ? t('rfid.tagmgr.reset_note') : t('rfid.tag.note') }}</label>
                <input id="tag-action-note" v-model="note" maxlength="255" autocomplete="off" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3" />
            </div>

            <ResultBanner v-if="error" type="error" :title="error" />

            <DialogFooter class="gap-2 sm:gap-2">
                <button type="button" :disabled="loading" class="min-h-[44px] rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent disabled:opacity-60" @click="emit('update:open', false)">
                    {{ t('app.cancel') }}
                </button>
                <button type="button" :disabled="loading"
                        class="min-h-[44px] rounded-lg px-4 text-sm font-semibold hover:opacity-90 disabled:opacity-60"
                        :class="isReset ? 'bg-primary text-primary-foreground' : 'bg-danger text-background'"
                        @click="submit">
                    {{ loading ? t('app.processing') : (isReset ? t('rfid.tagmgr.reset_confirm') : t('rfid.tag.confirm')) }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
