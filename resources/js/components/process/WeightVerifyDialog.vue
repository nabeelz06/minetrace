<script setup>
/**
 * WeightVerifyDialog — popup "timbang ulang": mencocokkan berat di sistem dengan berat timbangan nyata.
 *
 *  - mode "verify": POST /company/batches/{id}/verify-weight (Lab Kimia / Receiving, batch belum dihimpun).
 *  - mode "intake": POST /company/process/receiving/intake/{id} — berat + kadar Sn, lalu drum dituang ke Wet.
 *
 * Dibuat agar cepat di lapangan: kolom berat langsung terfokus dan terisi berat tercatat, selisih tampil langsung
 * saat mengetik, Enter menyimpan. Server tetap yang memutuskan (validasi, ambang alert, audit); dialog hanya
 * menampilkan hasilnya lewat flash/toast dan tetap terbuka bila server menolak.
 */
import { computed, nextTick, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import AppIcon from '@/components/app/AppIcon.vue'
import { useI18n } from '@/composables/core/useI18n.js'
import { diffTone, parseWeight, weightDiff } from '@/lib/weight.js'

const props = defineProps({
    open:  { type: Boolean, default: false },
    mode:  { type: String, default: 'verify' },     // verify | intake
    batch: { type: Object, default: null },         // { id, batch_code, current_weight, initial_weight, lot_number, partner_name, partner_signatory }
})
const emit = defineEmits(['update:open', 'done'])

const { t, lang } = useI18n()
const page = usePage()

const isIntake = computed(() => props.mode === 'intake')
const weightInput = ref('')
const sn = ref('')
const signatory = ref('')
const note = ref('')
const showMore = ref(false)
const busy = ref(false)
const errors = ref({})
const weightEl = ref(null)

const nf = computed(() => new Intl.NumberFormat(lang.value === 'en' ? 'en-US' : 'id-ID', { maximumFractionDigits: 3 }))
const fmt = n => nf.value.format(n)

// Setiap dialog dibuka dari keadaan bersih; berat awal = berat tercatat sehingga "sesuai" cukup satu Enter.
watch(() => props.open, async o => {
    if (!o) return
    weightInput.value = props.batch?.current_weight != null ? String(props.batch.current_weight) : ''
    sn.value = ''
    signatory.value = props.batch?.partner_signatory ?? ''
    note.value = ''
    showMore.value = false
    errors.value = {}
    busy.value = false
    await nextTick()
    weightEl.value?.focus?.()
    weightEl.value?.select?.()
})

const recorded = computed(() => Number(props.batch?.current_weight ?? 0))
const declared = computed(() => Number(props.batch?.initial_weight ?? 0))
const weight = computed(() => parseWeight(weightInput.value))
const weightValid = computed(() => weight.value !== null && weight.value > 0 && weight.value <= 999999.99)
const snValue = computed(() => (sn.value === '' ? null : parseWeight(sn.value)))
const snValid = computed(() => !isIntake.value || (snValue.value !== null && snValue.value >= 0 && snValue.value <= 100))

const delta = computed(() => (weightValid.value ? weightDiff(recorded.value, weight.value) : null))
const tone = computed(() => (delta.value ? diffTone(delta.value.diff, delta.value.pct) : null))
const unchanged = computed(() => tone.value === 'same')

const toneClass = computed(() => ({
    same:  'border-success/30 bg-success-soft text-success',
    small: 'border-info/30 bg-info-soft text-info',
    large: 'border-warning/40 bg-warning-soft text-warning',
}[tone.value] ?? 'border-border bg-muted/40 text-muted-foreground'))

const sign = n => (n > 0 ? '+' : n < 0 ? '−' : '')
const canSubmit = computed(() => !busy.value && weightValid.value && snValid.value && !!props.batch)

const submitLabel = computed(() => {
    if (busy.value) return t('app.processing')
    if (isIntake.value) return t('batch.verify.save_intake')
    return unchanged.value ? t('batch.verify.confirm_same') : t('batch.verify.save')
})

function submit() {
    if (!canSubmit.value) return
    busy.value = true
    errors.value = {}

    const payload = isIntake.value
        ? { weight: weight.value, sn_grade_pct: snValue.value }
        : { weight: weight.value }
    if (note.value.trim()) payload.note = note.value.trim()
    if (signatory.value.trim()) payload.partner_signatory = signatory.value.trim()

    const url = isIntake.value
        ? `/company/process/receiving/intake/${props.batch.id}`
        : `/company/batches/${props.batch.id}/verify-weight`

    router.post(url, payload, {
        preserveScroll: true,
        onError: e => { errors.value = e },
        onSuccess: () => {
            // Penolakan server (mis. status sudah berubah) datang sebagai flash error: biarkan terbuka agar terbaca.
            if (page.props.flash?.error) return
            emit('done', { weight: weight.value })
            emit('update:open', false)
        },
        onFinish: () => { busy.value = false },
    })
}
</script>

<template>
    <Dialog :open="open" @update:open="v => { if (!busy) emit('update:open', v) }">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2 text-lg">
                    <AppIcon name="scale" :size="20" class="text-primary" />
                    {{ isIntake ? t('batch.verify.title_intake') : t('batch.verify.title') }}
                </DialogTitle>
                <DialogDescription class="text-sm">
                    {{ isIntake ? t('batch.verify.desc_intake') : t('batch.verify.desc') }}
                </DialogDescription>
            </DialogHeader>

            <form v-if="batch" class="space-y-4" @submit.prevent="submit">
                <!-- Identitas batch + berat pembanding -->
                <div class="rounded-lg border border-border bg-muted/40 p-3 text-sm">
                    <p class="break-all font-mono text-sm font-bold">{{ batch.batch_code }}</p>
                    <p v-if="batch.partner_name || batch.lot_number" class="mt-0.5 text-xs text-muted-foreground">
                        <span v-if="batch.partner_name">{{ batch.partner_name }}</span>
                        <span v-if="batch.partner_name && batch.lot_number"> · </span>
                        <span v-if="batch.lot_number" class="font-mono">{{ batch.lot_number }}</span>
                    </p>
                    <dl class="mt-2 grid grid-cols-2 gap-2">
                        <div>
                            <dt class="text-xs text-muted-foreground">{{ t('batch.verify.recorded') }}</dt>
                            <dd class="font-semibold tabular-nums">{{ fmt(recorded) }} kg</dd>
                        </div>
                        <div v-if="declared && Math.abs(declared - recorded) > 0.0005">
                            <dt class="text-xs text-muted-foreground">{{ t('batch.verify.declared') }}</dt>
                            <dd class="font-semibold tabular-nums">{{ fmt(declared) }} kg</dd>
                        </div>
                    </dl>
                </div>

                <!-- Kolom berat nyata -->
                <div class="space-y-1.5">
                    <label for="wv-weight" class="block text-sm font-semibold">{{ t('batch.verify.actual') }}</label>
                    <div class="relative">
                        <input id="wv-weight" ref="weightEl" v-model="weightInput" type="text" inputmode="decimal" autocomplete="off"
                               class="h-14 w-full rounded-xl border bg-background pl-4 pr-14 font-mono text-2xl font-bold tabular-nums focus:ring-2 focus:ring-primary/20"
                               :class="weightInput !== '' && !weightValid ? 'border-danger/40' : 'border-input focus:border-primary'" />
                        <span class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-sm font-bold text-muted-foreground">kg</span>
                    </div>
                    <p v-if="weightInput !== '' && !weightValid" class="text-xs text-danger">{{ t('batch.verify.invalid') }}</p>
                    <p v-else class="text-xs text-muted-foreground">{{ t('batch.verify.actual_hint') }}</p>
                    <p v-if="errors.weight" class="text-xs text-danger">{{ errors.weight }}</p>
                </div>

                <!-- Selisih langsung -->
                <div v-if="delta" class="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm" :class="toneClass" aria-live="polite">
                    <AppIcon :name="tone === 'large' ? 'triangle-alert' : tone === 'same' ? 'circle-check' : 'arrow-left-right'" :size="18" class="mt-0.5 flex-shrink-0" />
                    <div>
                        <template v-if="tone === 'same'">{{ t('batch.verify.same') }}</template>
                        <template v-else>
                            <span class="font-semibold">{{ t('batch.verify.diff') }}: {{ sign(delta.diff) }}{{ fmt(Math.abs(delta.diff)) }} kg ({{ sign(delta.pct) }}{{ fmt(Math.abs(delta.pct)) }}%)</span>
                            <span v-if="tone === 'large'" class="block text-xs">{{ t('batch.verify.large') }}</span>
                        </template>
                    </div>
                </div>

                <!-- Kadar Sn (hanya penimbangan penerimaan) -->
                <div v-if="isIntake" class="space-y-1.5">
                    <label for="wv-sn" class="block text-sm font-semibold">{{ t('batch.verify.sn') }}</label>
                    <input id="wv-sn" v-model="sn" type="text" inputmode="decimal" autocomplete="off"
                           class="h-11 w-full rounded-lg border border-input bg-background px-3 font-mono tabular-nums focus:border-primary focus:ring-2 focus:ring-primary/20" />
                    <p v-if="sn !== '' && !snValid" class="text-xs text-danger">{{ t('batch.verify.sn_required') }}</p>
                    <p v-if="errors.sn_grade_pct" class="text-xs text-danger">{{ errors.sn_grade_pct }}</p>
                </div>

                <!-- Catatan & validator mitra: tersembunyi agar alur cepat tetap ringkas -->
                <details :open="showMore" class="rounded-lg border border-border" @toggle="showMore = $event.target.open">
                    <summary class="flex min-h-[44px] cursor-pointer items-center px-3 text-sm font-medium">{{ t('batch.verify.more') }}</summary>
                    <div class="space-y-3 border-t border-border p-3">
                        <div class="space-y-1">
                            <label for="wv-sign" class="block text-sm font-medium">{{ t('batch.verify.signatory') }}</label>
                            <input id="wv-sign" v-model="signatory" maxlength="120" autocomplete="off" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3 text-sm" />
                            <p class="text-xs text-muted-foreground">{{ t('batch.verify.signatory_hint') }}</p>
                        </div>
                        <div class="space-y-1">
                            <label for="wv-note" class="block text-sm font-medium">{{ t('batch.verify.note') }}</label>
                            <input id="wv-note" v-model="note" maxlength="500" autocomplete="off" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3 text-sm" />
                        </div>
                    </div>
                </details>

                <DialogFooter class="gap-2 sm:gap-2">
                    <button type="button" :disabled="busy" class="min-h-[44px] rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent disabled:opacity-60" @click="emit('update:open', false)">
                        {{ t('app.cancel') }}
                    </button>
                    <button type="submit" :disabled="!canSubmit"
                            class="min-h-[44px] rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50">
                        {{ submitLabel }}
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
