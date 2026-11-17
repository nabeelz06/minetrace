<script setup>
/**
 * AuthModesCard — mode otorisasi per tahap, diterjemahkan ke kalimat DAMPAK (bukan istilah teknis).
 * Hanya administrator yang dapat mengubah; mode otomatis meminta konfirmasi (memindahkan batch sungguhan).
 *
 * Mode baku "Catat saja" TIDAK memindahkan batch — penyebab paling umum "tag terbaca tetapi status tidak berubah".
 * Karena itu tersedia mode Dua Arah (Masuk = terima, Keluar = kirim) dan preset "semua pos sekaligus" (satu transaksi
 * di server, tidak pernah menyisakan campuran mode di tengah jalan).
 */
import { computed, ref } from 'vue'
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
const pending = ref(null)          // { stages: [...], mode, preset } menunggu konfirmasi
const confirmOpen = ref(false)

const MODES = ['audit_only', 'scan_to_receive', 'scan_to_dispatch', 'scan_full']

// Tahap yang masih "Catat saja" → petunjuk bahwa batch tidak akan berpindah oleh scan di tahap itu.
const auditStages = computed(() => props.stages.filter(s => (props.modes[s.key]?.mode ?? 'audit_only') === 'audit_only'))

function choose(stage, mode) {
    if (!props.canManage || props.modes[stage]?.mode === mode) return
    if (mode !== 'audit_only') { pending.value = { stages: [stage], mode, preset: false }; confirmOpen.value = true; return }
    save([stage], mode)
}

// Preset semua pos: selalu lewat konfirmasi (mengubah perilaku seluruh lantai produksi sekaligus).
function choosePreset(mode) {
    if (!props.canManage) return
    pending.value = { stages: props.stages.map(s => s.key), mode, preset: true }
    confirmOpen.value = true
}

// Satu tahap memakai kontrak lama (`stage`); beberapa tahap dikirim sekaligus (`stages[]`) dan ditulis satu transaksi.
async function save(stages, mode) {
    saving.value = stages.length === 1 ? stages[0] : '*'
    const res = await rfidApi.setAuthMode(stages.length === 1 ? { stage: stages[0], mode } : { stages, mode })
    saving.value = ''
    if (res.ok) { pushToast(t('rfid.modes.saved'), 'success'); emit('changed', res.raw?.data ?? res.data) }
    else pushToast(apiText(res), 'error')
}

function confirmAuto() {
    confirmOpen.value = false
    if (pending.value) save(pending.value.stages, pending.value.mode)
}

const confirmTitle = computed(() => !pending.value?.preset ? t('rfid.modes.confirm_title') : t(pending.value.mode === 'audit_only' ? 'rfid.modes.preset_audit_title' : 'rfid.modes.preset_full_title'))
const confirmDesc = computed(() => !pending.value?.preset ? t('rfid.modes.confirm_desc') : t(pending.value.mode === 'audit_only' ? 'rfid.modes.preset_audit_desc' : 'rfid.modes.preset_full_desc'))
</script>

<template>
    <div id="rfid-auth-modes" class="scroll-mt-20 space-y-4 rounded-xl border border-border bg-card p-4 sm:p-5">
        <div>
            <h3 class="text-lg font-semibold">{{ t('rfid.modes.title') }}</h3>
            <p class="text-sm text-muted-foreground">{{ t('rfid.modes.intro') }}</p>
            <p v-if="!canManage" class="mt-2 text-sm font-medium text-warning">{{ t('rfid.modes.admin_only') }}</p>
            <p v-if="auditStages.length" class="mt-2 flex items-start gap-2 text-sm font-medium text-info"><AppIcon name="info" :size="16" class="mt-0.5" />{{ t('rfid.modes.audit_hint') }}</p>
        </div>

        <!-- Preset semua pos sekaligus -->
        <div v-if="canManage" class="space-y-2 rounded-lg border border-border bg-muted/40 p-3">
            <div>
                <p class="text-sm font-semibold">{{ t('rfid.modes.preset_title') }}</p>
                <p class="text-sm text-muted-foreground">{{ t('rfid.modes.preset_desc') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" :disabled="!!saving" class="inline-flex min-h-[44px] items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60" @click="choosePreset('scan_full')">
                    <AppIcon :name="saving === '*' ? 'loader' : 'zap'" :size="16" :spin="saving === '*'" />{{ t('rfid.modes.preset_full') }}
                </button>
                <button type="button" :disabled="!!saving" class="min-h-[44px] rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent disabled:opacity-60" @click="choosePreset('audit_only')">
                    {{ t('rfid.modes.preset_audit') }}
                </button>
            </div>
        </div>

        <div class="divide-y divide-border">
            <fieldset v-for="s in stages" :key="s.key" class="space-y-2 py-3 first:pt-0 last:pb-0" :disabled="!canManage || saving === s.key || saving === '*'">
                <legend class="mb-1 flex items-center gap-2 text-base font-semibold"><AppIcon :name="s.icon" :size="18" class="text-muted-foreground" />{{ loc(s) }}</legend>
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
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

        <ConfirmDialog v-model:open="confirmOpen" :title="confirmTitle" :description="confirmDesc"
                       :confirm-label="t('rfid.modes.confirm_yes')" :tone="pending?.mode === 'audit_only' ? 'default' : 'danger'" @confirm="confirmAuto" />
    </div>
</template>
