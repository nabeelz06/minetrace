<script setup>
/** VoidScanDialog — batalkan pemindaian dengan alasan (koreksi beralasan; riwayat tidak dihapus). */
import { ref, watch } from 'vue'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import ResultBanner from '@/components/app/ResultBanner.vue'
import { rfidApi, useApiText } from '@/composables/rfid/useRfidApi.js'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    open: { type: Boolean, default: false },
    scan: { type: Object, default: null },
})
const emit = defineEmits(['update:open', 'voided'])

const { t } = useI18n()
const apiText = useApiText()
const reason = ref('')
const loading = ref(false)
const error = ref('')

watch(() => props.open, o => { if (o) { reason.value = ''; error.value = '' } })

async function submit() {
    if (reason.value.trim().length < 3 || loading.value || !props.scan) return
    loading.value = true
    const res = await rfidApi.voidScan(props.scan.id, reason.value.trim())
    loading.value = false
    if (res.ok) {
        emit('voided', res.data)
        emit('update:open', false)
    } else {
        error.value = apiText(res)
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="v => emit('update:open', v)">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle class="text-lg">{{ t('rfid.scan.void_title') }}</DialogTitle>
                <DialogDescription class="text-sm">{{ t('rfid.scan.void_desc') }}</DialogDescription>
            </DialogHeader>

            <p v-if="scan" class="rounded-lg bg-muted px-3 py-2 font-mono text-sm">{{ scan.batch_code }} · {{ scan.uid }}</p>

            <div class="space-y-1.5">
                <label for="void-reason" class="block text-sm font-medium">{{ t('rfid.scan.void_reason') }}</label>
                <textarea id="void-reason" v-model="reason" rows="3" maxlength="255" aria-describedby="void-hint"
                          class="w-full rounded-lg border border-input bg-background px-3 py-2 text-base" />
                <p id="void-hint" class="text-sm text-muted-foreground">{{ t('rfid.scan.void_reason_hint') }}</p>
            </div>
            <p class="text-sm text-muted-foreground">{{ t('rfid.scan.void_transition_note') }}</p>
            <ResultBanner v-if="error" type="error" :title="error" />

            <DialogFooter class="gap-2 sm:gap-2">
                <button type="button" class="min-h-[44px] rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent" @click="emit('update:open', false)">
                    {{ t('app.cancel') }}
                </button>
                <button type="button" :disabled="reason.trim().length < 3 || loading"
                        class="min-h-[44px] rounded-lg bg-danger px-4 text-sm font-semibold text-background hover:opacity-90 disabled:opacity-50" @click="submit">
                    {{ loading ? t('app.processing') : t('rfid.scan.void_confirm') }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
