<script setup>
/**
 * PartnerInfoCard — data mitra PT Timah pada detail batch: nama mitra, no. partai/kode mitra, lokasi asal kiriman,
 * dan penanda tangan (validator). Semua opsional; dapat dilengkapi/dikoreksi kapan saja (tercatat di audit log).
 * Tanda tangan asli tetap di dokumen fisik — sistem hanya menyimpan nama penanda tangan sebagai rujukan.
 */
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import AppIcon from '@/components/app/AppIcon.vue'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    batch:           { type: Object, required: true },   // { id, lot_number, partner_name, origin_location, partner_signatory }
    canEdit:         { type: Boolean, default: false },
    originLocations: { type: Array, default: () => [] },
})

const { t } = useI18n()

const rows = computed(() => [
    { key: 'partner_name',      label: t('batch.partner.name'),      value: props.batch.partner_name },
    { key: 'lot_number',        label: t('batch.partner.lot'),       value: props.batch.lot_number, mono: true },
    { key: 'origin_location',   label: t('batch.partner.origin'),    value: props.batch.origin_location },
    { key: 'partner_signatory', label: t('batch.partner.signatory'), value: props.batch.partner_signatory },
])

const open = ref(false)
const busy = ref(false)
const errors = ref({})
const form = ref({ partner_name: '', lot_number: '', origin_location: '', partner_signatory: '' })

watch(open, o => {
    if (!o) return
    form.value = {
        partner_name: props.batch.partner_name ?? '', lot_number: props.batch.lot_number ?? '',
        origin_location: props.batch.origin_location ?? '', partner_signatory: props.batch.partner_signatory ?? '',
    }
    errors.value = {}
})

function save() {
    busy.value = true
    router.patch(`/company/batches/${props.batch.id}/partner`, { ...form.value }, {
        preserveScroll: true,
        onError: e => { errors.value = e },
        onSuccess: () => { open.value = false },
        onFinish: () => { busy.value = false },
    })
}
</script>

<template>
    <div class="rounded-2xl border border-border bg-card p-5 space-y-4">
        <div class="flex items-center justify-between gap-3">
            <h3 class="flex items-center gap-2 text-sm font-semibold text-foreground">
                <AppIcon name="building-2" :size="16" class="text-muted-foreground" />{{ t('batch.partner.title') }}
            </h3>
            <button v-if="canEdit" type="button"
                    class="inline-flex min-h-[36px] items-center gap-1.5 rounded-lg border border-input px-3 text-xs font-semibold hover:bg-accent"
                    @click="open = true">
                <AppIcon name="pencil" :size="14" />{{ t('batch.partner.edit') }}
            </button>
        </div>

        <div class="space-y-1">
            <div v-for="r in rows" :key="r.key" class="flex items-start justify-between gap-4 border-b border-border/50 py-2 last:border-0">
                <span class="flex-shrink-0 text-xs text-muted-foreground">{{ r.label }}</span>
                <span class="text-right text-xs font-semibold" :class="[r.mono ? 'font-mono' : '', r.value ? 'text-foreground' : 'text-muted-foreground font-normal']">
                    {{ r.value || t('batch.partner.empty') }}
                </span>
            </div>
        </div>

        <Dialog :open="open" @update:open="v => { if (!busy) open = v }">
            <DialogContent class="max-w-md">
                <DialogHeader>
                    <DialogTitle class="text-lg">{{ t('batch.partner.edit_title') }}</DialogTitle>
                    <DialogDescription class="text-sm">{{ t('batch.partner.subtitle') }}</DialogDescription>
                </DialogHeader>

                <form class="space-y-3" @submit.prevent="save">
                    <div class="space-y-1">
                        <label for="pi-name" class="block text-sm font-medium">{{ t('batch.partner.name') }}</label>
                        <input id="pi-name" v-model="form.partner_name" maxlength="120" autocomplete="off" :placeholder="t('batch.partner.name_ph')"
                               class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3 text-sm" />
                        <p v-if="errors.partner_name" class="text-xs text-danger">{{ errors.partner_name }}</p>
                    </div>
                    <div class="space-y-1">
                        <label for="pi-lot" class="block text-sm font-medium">{{ t('batch.partner.lot') }}</label>
                        <input id="pi-lot" v-model="form.lot_number" maxlength="80" autocomplete="off" :placeholder="t('batch.partner.lot_ph')"
                               class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3 font-mono text-sm" />
                        <p v-if="errors.lot_number" class="text-xs text-danger">{{ errors.lot_number }}</p>
                    </div>
                    <div class="space-y-1">
                        <label for="pi-origin" class="block text-sm font-medium">{{ t('batch.partner.origin') }}</label>
                        <input id="pi-origin" v-model="form.origin_location" maxlength="120" autocomplete="off" list="pi-origin-list" :placeholder="t('batch.partner.origin_ph')"
                               class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3 text-sm" />
                        <datalist id="pi-origin-list"><option v-for="o in originLocations" :key="o" :value="o" /></datalist>
                        <p v-if="errors.origin_location" class="text-xs text-danger">{{ errors.origin_location }}</p>
                    </div>
                    <div class="space-y-1">
                        <label for="pi-sign" class="block text-sm font-medium">{{ t('batch.partner.signatory') }}</label>
                        <input id="pi-sign" v-model="form.partner_signatory" maxlength="120" autocomplete="off" :placeholder="t('batch.partner.signatory_ph')"
                               class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3 text-sm" />
                        <p v-if="errors.partner_signatory" class="text-xs text-danger">{{ errors.partner_signatory }}</p>
                    </div>

                    <DialogFooter class="gap-2 sm:gap-2">
                        <button type="button" :disabled="busy" class="min-h-[44px] rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent disabled:opacity-60" @click="open = false">{{ t('app.cancel') }}</button>
                        <button type="submit" :disabled="busy" class="min-h-[44px] rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60">
                            {{ busy ? t('app.processing') : t('batch.partner.save') }}
                        </button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
