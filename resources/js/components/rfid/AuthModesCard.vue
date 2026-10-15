<script setup>
/**
 * AuthModesCard — mode otorisasi per tahap, diterjemahkan ke kalimat DAMPAK (bukan istilah teknis).
 * Hanya administrator yang dapat mengubah; mode otomatis meminta konfirmasi (memindahkan batch sungguhan).
 */
import { ref } from 'vue'
import AppIcon from '@/components/app/AppIcon.vue'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import { rfidApi, useApiText } from '@/composables/rfid/useRfidApi.js'
import { pushToast } from '@/composables/core/useToast.js'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    modes:     { type: Object, required: true },
    stages:    { type: Array, required: true },
    canManage: { type: Boolean, default: false },
})
const emit = defineEmits(['changed'])

const { t, loc } = useI18n()
const apiText = useApiText()
const saving = ref('')
const pending = ref(null)          // { stage, mode } menunggu konfirmasi
const confirmOpen = ref(false)

const MODES = ['audit_only', 'scan_to_receive', 'scan_to_dispatch']

function choose(stage, mode) {
    if (!props.canManage || props.modes[stage]?.mode === mode) return
    if (mode !== 'audit_only') { pending.value = { stage, mode }; confirmOpen.value = true; return }
    save(stage, mode)
}

async function save(stage, mode) {
    saving.value = stage
    const res = await rfidApi.setAuthMode({ stage, mode })
    saving.value = ''
    if (res.ok) { pushToast(t('rfid.modes.saved'), 'success'); emit('changed', res.raw?.data ?? res.data) }
    else pushToast(apiText(res), 'error')
}

function confirmAuto() {
    confirmOpen.value = false
    if (pending.value) save(pending.value.stage, pending.value.mode)
}
</script>

<template>
    <div class="space-y-4 rounded-xl border border-border bg-card p-4 sm:p-5">
        <div>
            <h3 class="text-lg font-semibold">{{ t('rfid.modes.title') }}</h3>
            <p class="text-sm text-muted-foreground">{{ t('rfid.modes.intro') }}</p>
            <p v-if="!canManage" class="mt-2 text-sm font-medium text-warning">{{ t('rfid.modes.admin_only') }}</p>
        </div>

        <div class="divide-y divide-border">
            <fieldset v-for="s in stages" :key="s.key" class="space-y-2 py-3 first:pt-0 last:pb-0" :disabled="!canManage || saving === s.key">
                <legend class="mb-1 flex items-center gap-2 text-base font-semibold"><AppIcon :name="s.icon" :size="18" class="text-muted-foreground" />{{ loc(s) }}</legend>
                <div class="grid gap-2 lg:grid-cols-3">
                    <label v-for="m in MODES" :key="m"
                           class="flex min-h-[64px] cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm has-[:disabled]:cursor-not-allowed"
                           :class="modes[s.key]?.mode === m ? 'border-primary bg-primary/5' : 'border-border hover:bg-accent'">
                        <input type="radio" :name="`mode-${s.key}`" :value="m" :checked="modes[s.key]?.mode === m" class="mt-1 h-5 w-5 flex-shrink-0 border-input text-primary" @change="choose(s.key, m)" />
                        <span>
                            <span class="block font-semibold">{{ t(`rfid.modes.${m}`) }}</span>
                            <span class="block text-muted-foreground">{{ t(`rfid.modes.desc_${m}`) }}</span>
                            <span v-if="m !== 'audit_only'" class="mt-1 flex items-center gap-1 font-medium text-warning"><AppIcon name="triangle-alert" :size="14" />{{ t('rfid.modes.warn_auto') }}</span>
                        </span>
                    </label>
                </div>
            </fieldset>
        </div>

        <ConfirmDialog v-model:open="confirmOpen" :title="t('rfid.modes.confirm_title')" :description="t('rfid.modes.confirm_desc')"
                       :confirm-label="t('rfid.modes.confirm_yes')" tone="danger" @confirm="confirmAuto" />
    </div>
</template>
