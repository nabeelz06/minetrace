<script setup>
/**
 * RegisterTagWizard — Daftarkan Tag dalam 3 langkah (Pilih batch → Tempelkan tag → Hasil).
 *
 * Dari halaman detail batch (initialBatchId) langkah 1 dilewati: 1 klik "Mulai" lalu tempelkan tag.
 * Dua cara membaca tag: reader terdaftar (sesi menunggu, hitung mundur) atau input manual/USB
 * (keyboard-wedge: kolom otomatis fokus, UID + Enter). Semua teks lewat kamus i18n (rfid.reg.*).
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useLocalStorage, useNow } from '@vueuse/core'
import { Link } from '@inertiajs/vue3'
import AppIcon from '@/components/app/AppIcon.vue'
import BigActionButton from '@/components/app/BigActionButton.vue'
import ResultBanner from '@/components/app/ResultBanner.vue'
import BatchPicker from '@/components/rfid/BatchPicker.vue'
import { rfidApi, useApiText } from '@/composables/rfid/useRfidApi.js'
import { usePolling } from '@/composables/core/usePolling.js'
import { useI18n } from '@/composables/core/useI18n.js'
import { groupUid } from '@/lib/format'

const props = defineProps({
    readers:         { type: Array, default: () => [] },
    canOperate:      { type: Boolean, default: false },
    initialBatchId:  { type: Number, default: null },
    initialUid:      { type: String, default: '' },
    field:           { type: Boolean, default: false },
})
const emit = defineEmits(['registered'])

const { t } = useI18n()
const apiText = useApiText()

const step = ref(1)
const batch = ref(null)
const activeReaders = computed(() => props.readers.filter(r => r.is_active))
const method = ref(activeReaders.value.length ? 'reader' : 'manual')
const rememberedReader = useLocalStorage('minetrace-rfid-reg-reader', '')
const readerId = ref('')
const session = ref(null)
const manualUid = ref(props.initialUid ?? '')
const submitting = ref(false)
const inlineError = ref('')
const result = ref(null)          // { type: 'success'|'error'|'expired', title, desc, uid? }
const resultRef = ref(null)
const manualRef = ref(null)

const now = useNow({ interval: 1000 })
const ttl = computed(() => session.value?.ttl_seconds ?? 90)
const remaining = computed(() => session.value ? Math.max(0, Math.ceil((new Date(session.value.expires_at) - now.value) / 1000)) : 0)
const percent = computed(() => Math.round((remaining.value / ttl.value) * 100))
const readerName = computed(() => activeReaders.value.find(r => String(r.id) === String(readerId.value))?.name)

watch(activeReaders, list => {
    // reader yang diingat harus masih aktif; jika tidak, kosongkan (= reader mana pun)
    readerId.value = list.some(r => String(r.id) === String(rememberedReader.value)) ? String(rememberedReader.value) : ''
}, { immediate: true })

// ─── Polling status sesi (ramah visibilitas tab) ──────────────────────────────
const poll = usePolling(async () => {
    if (!batch.value || !session.value) return true
    const res = await rfidApi.registrationStatus(batch.value.id)
    if (!res.ok) return false
    if (res.data.state === 'completed') finish({ type: 'success', uid: res.data.uid })
    else if (res.data.state === 'expired') finish({ type: 'expired' })
    return true
}, { interval: 1500, immediate: false })

function finish(r) {
    poll.stop()
    session.value = null
    result.value = r.type === 'success'
        ? { type: 'success', uid: r.uid, title: t('rfid.reg.success'), desc: t('rfid.reg.success_desc', { uid: groupUid(r.uid), batch: batch.value?.batch_code }) }
        : r.type === 'expired'
            ? { type: 'warning', title: t('rfid.reg.expired'), desc: t('rfid.reg.expired_desc') }
            : { type: 'error', title: t('rfid.reg.failed'), desc: r.desc }
    step.value = 3
    if (r.type === 'success') emit('registered', batch.value)
    nextTick(() => resultRef.value?.focus())
}

// ─── Aksi ─────────────────────────────────────────────────────────────────────
async function startReader() {
    if (!batch.value || submitting.value) return
    submitting.value = true
    rememberedReader.value = readerId.value
    const res = await rfidApi.startRegistration({ batch_id: batch.value.id, reader_id: readerId.value ? Number(readerId.value) : null })
    submitting.value = false
    if (!res.ok) return finish({ type: 'error', desc: apiText(res) })
    session.value = res.data
    poll.start()
}

async function submitManual() {
    const uid = manualUid.value.trim()
    if (uid.length < 4 || submitting.value || !batch.value) return
    submitting.value = true
    inlineError.value = ''
    const res = await rfidApi.startRegistration({ batch_id: batch.value.id, uid })
    submitting.value = false
    if (res.ok) return finish({ type: 'success', uid: res.data.uid })
    inlineError.value = apiText(res)
    manualRef.value?.select()
}

async function cancelSession() {
    poll.stop()
    if (session.value) await rfidApi.cancelRegistration(session.value.session_id)
    session.value = null
    step.value = 2
}

function reset() {
    batch.value = null; session.value = null; result.value = null; manualUid.value = ''; inlineError.value = ''
    step.value = 1
}

function chooseBatch() {
    if (batch.value) step.value = 2
}

watch([step, method], async () => {
    await nextTick()
    if (step.value === 2 && method.value === 'manual') manualRef.value?.focus()
})

onMounted(async () => {
    if (props.initialUid) method.value = 'manual'
    if (props.initialBatchId) {
        const res = await rfidApi.searchBatches({ id: props.initialBatchId, tag: 'all', limit: 1 })
        const b = res.ok ? res.data[0] : null
        if (b?.rfid_tag) finish({ type: 'error', desc: t('rfid.err.batch_already_tagged') })
        else if (b) { batch.value = b; step.value = 2 }
    }
})

onBeforeUnmount(() => { if (session.value) rfidApi.cancelRegistration(session.value.session_id) })

const steps = [1, 2, 3]
</script>

<template>
    <section :aria-label="t('rfid.view.register')" class="space-y-5">
        <!-- Indikator langkah -->
        <ol class="flex items-center gap-2 text-sm" :aria-label="t('rfid.reg.step_of', { n: step })">
            <li v-for="n in steps" :key="n" class="flex flex-1 items-center gap-2" :aria-current="step === n ? 'step' : undefined">
                <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-sm font-bold"
                      :class="step > n ? 'bg-success-soft text-success' : step === n ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'">
                    <AppIcon v-if="step > n" name="check" :size="16" /><template v-else>{{ n }}</template>
                </span>
                <span class="truncate font-medium" :class="step === n ? 'text-foreground' : 'text-muted-foreground'">{{ t(`rfid.reg.step${n}`) }}</span>
                <span v-if="n < 3" class="h-px flex-1 bg-border" aria-hidden="true" />
            </li>
        </ol>

        <!-- Langkah 1 -->
        <div v-if="step === 1" class="space-y-4">
            <BatchPicker v-model="batch" tag="untagged" />
            <div class="sticky bottom-0 -mx-4 border-t border-border bg-background/95 p-4 sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0">
                <button type="button" :disabled="!batch || !canOperate"
                        class="inline-flex min-h-[52px] w-full items-center justify-center gap-2 rounded-xl bg-primary px-6 text-base font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-50 sm:w-auto"
                        @click="chooseBatch">
                    {{ t('app.next') }}<AppIcon name="chevron-right" :size="18" />
                </button>
                <p v-if="!canOperate" class="mt-2 text-sm text-warning">{{ t('rfid.readonly_note') }}</p>
            </div>
        </div>

        <!-- Langkah 2 -->
        <div v-else-if="step === 2" class="space-y-5">
            <div class="flex items-center gap-3 rounded-xl border border-border bg-card p-3">
                <AppIcon name="boxes" :size="22" class="text-muted-foreground" />
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium text-muted-foreground">{{ t('rfid.reg.chosen') }}</p>
                    <p class="truncate font-mono text-sm font-semibold">{{ batch?.batch_code }}</p>
                </div>
                <button v-if="!session" type="button" class="min-h-[44px] rounded-lg px-3 text-sm font-medium text-primary hover:bg-accent" @click="step = 1">
                    {{ t('rfid.reg.change') }}
                </button>
            </div>

            <!-- Menunggu tag -->
            <div v-if="session" class="space-y-4 rounded-xl border border-primary/40 bg-card p-6 text-center" aria-live="polite">
                <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-info-soft text-info animate-pulse"><AppIcon name="nfc" :size="44" /></span>
                <p class="text-2xl font-bold text-foreground">{{ readerName ? t('rfid.reg.tap_on', { reader: readerName }) : t('rfid.reg.tap_any') }}</p>
                <p class="text-lg font-semibold tabular-nums text-muted-foreground">{{ t('rfid.reg.countdown', { sec: remaining }) }}</p>
                <div class="mx-auto h-2 max-w-sm overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="remaining" aria-valuemin="0" :aria-valuemax="ttl">
                    <div class="h-full bg-primary" :style="{ width: percent + '%' }" />
                </div>
                <button type="button" class="min-h-[48px] rounded-xl border border-input px-5 text-base font-semibold hover:bg-accent" @click="cancelSession">
                    {{ t('rfid.reg.cancel') }}
                </button>
            </div>

            <template v-else>
                <fieldset class="space-y-2">
                    <legend class="mb-2 text-sm font-medium text-muted-foreground">{{ t('rfid.reg.method') }}</legend>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <BigActionButton icon="radio-tower" :title="t('rfid.reg.method_reader')" :description="t('rfid.reg.method_reader_desc')"
                                         :selected="method === 'reader'" :disabled="!activeReaders.length && false" @click="method = 'reader'" />
                        <BigActionButton icon="keyboard" :title="t('rfid.reg.method_manual')" :description="t('rfid.reg.method_manual_desc')"
                                         :selected="method === 'manual'" @click="method = 'manual'" />
                    </div>
                </fieldset>

                <div v-if="method === 'reader'" class="space-y-4">
                    <div class="space-y-1.5">
                        <label for="reg-reader" class="block text-sm font-medium">{{ t('rfid.reg.reader') }}</label>
                        <select id="reg-reader" v-model="readerId" class="min-h-[48px] w-full rounded-lg border border-input bg-background px-3 text-base">
                            <option value="">{{ t('rfid.reg.reader_any') }}</option>
                            <option v-for="r in activeReaders" :key="r.id" :value="String(r.id)">{{ r.name }} ({{ r.code }})</option>
                        </select>
                    </div>
                    <button type="button" :disabled="submitting || !canOperate"
                            class="inline-flex min-h-[56px] w-full items-center justify-center gap-2 rounded-xl bg-primary px-6 text-lg font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60 sm:w-auto"
                            @click="startReader">
                        <AppIcon v-if="submitting" name="loader" :size="20" spin /><AppIcon v-else name="nfc" :size="22" />
                        {{ t('rfid.reg.start') }}
                    </button>
                </div>

                <form v-else class="space-y-3" @submit.prevent="submitManual">
                    <div class="space-y-1.5">
                        <label for="reg-uid" class="block text-sm font-medium">{{ t('rfid.reg.manual_label') }}</label>
                        <input id="reg-uid" ref="manualRef" v-model="manualUid" autocomplete="off" autocapitalize="characters" spellcheck="false"
                               aria-describedby="reg-uid-hint" :aria-invalid="!!inlineError || undefined"
                               class="min-h-[56px] w-full rounded-lg border bg-background px-4 font-mono text-xl uppercase tracking-wider"
                               :class="inlineError ? 'border-danger' : 'border-input'" />
                        <p id="reg-uid-hint" class="text-sm text-muted-foreground">{{ t('rfid.reg.manual_hint') }}</p>
                    </div>
                    <ResultBanner v-if="inlineError" type="error" :title="t('rfid.reg.failed')" :description="inlineError" />
                    <button type="submit" :disabled="submitting || manualUid.trim().length < 4 || !canOperate"
                            class="inline-flex min-h-[56px] w-full items-center justify-center gap-2 rounded-xl bg-primary px-6 text-lg font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60 sm:w-auto">
                        <AppIcon v-if="submitting" name="loader" :size="20" spin /><AppIcon v-else name="tag" :size="22" />
                        {{ t('rfid.reg.save_tag') }}
                    </button>
                </form>
            </template>
        </div>

        <!-- Langkah 3 -->
        <div v-else class="space-y-4">
            <div ref="resultRef" tabindex="-1" class="focus:outline-none">
                <ResultBanner :type="result.type" :title="result.title" :description="result.desc" size="xl" />
            </div>
            <div class="flex flex-wrap gap-3">
                <button type="button" class="inline-flex min-h-[52px] items-center gap-2 rounded-xl bg-primary px-6 text-base font-semibold text-primary-foreground hover:opacity-90" @click="result.type === 'success' ? reset() : (step = 2)">
                    <AppIcon :name="result.type === 'success' ? 'plus' : 'refresh-cw'" :size="18" />
                    {{ result.type === 'success' ? t('rfid.reg.next_bag') : t('rfid.reg.try_again') }}
                </button>
                <Link v-if="batch" :href="`/company/batches/${batch.id}`" class="inline-flex min-h-[52px] items-center gap-2 rounded-xl border border-input px-6 text-base font-semibold hover:bg-accent">
                    <AppIcon name="external-link" :size="18" />{{ t('rfid.reg.view_batch') }}
                </Link>
            </div>
        </div>
    </section>
</template>
