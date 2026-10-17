<script setup>
/**
 * UhfDesktopPanel — registrasi tag dengan reader UHF desktop R2000 (USB) di Receiving Area.
 * Browser membaca EPC lewat Web Serial (useUhfDesktopReader), lalu mengikatnya ke batch terpilih
 * (POST registration, source=uhf_desktop). Server mencatat log pembacaan + capture CCTV Receiving.
 */
import { computed, onMounted, ref } from 'vue'
import { useLocalStorage } from '@vueuse/core'
import AppIcon from '@/components/app/AppIcon.vue'
import ResultBanner from '@/components/app/ResultBanner.vue'
import { useUhfDesktopReader } from '@/composables/rfid/useUhfDesktopReader.js'
import { rfidApi, useApiText } from '@/composables/rfid/useRfidApi.js'
import { useI18n } from '@/composables/core/useI18n.js'
import { groupUid } from '@/lib/format'

const props = defineProps({
    batch:      { type: Object, required: true },
    readers:    { type: Array, default: () => [] },
    canOperate: { type: Boolean, default: false },
    baud:       { type: Number, default: 9600 },
})
const emit = defineEmits(['registered'])

const { t } = useI18n()
const apiText = useApiText()
const uhf = useUhfDesktopReader({ baud: props.baud })

const desktopReaders = computed(() => props.readers.filter(r => r.is_active && r.device_type === 'uhf_desktop_r2000'))
const rememberedReader = useLocalStorage('minetrace-rfid-uhf-desktop-reader', '')
const readerId = ref('')
const candidates = ref([])        // >1 tag terbaca: operator memilih
const inlineError = ref('')
const submitting = ref(false)

const busy = computed(() => submitting.value || uhf.state.value === 'reading' || uhf.state.value === 'connecting')
const connected = computed(() => ['connected', 'reading'].includes(uhf.state.value))

onMounted(async () => {
    readerId.value = desktopReaders.value.some(r => String(r.id) === String(rememberedReader.value)) ? String(rememberedReader.value) : (desktopReaders.value[0] ? String(desktopReaders.value[0].id) : '')
    await uhf.autoConnect()
})

async function read() {
    inlineError.value = ''
    candidates.value = []
    const tags = await uhf.readTags()
    if (uhf.error.value) { inlineError.value = t('rfid.uhf.read_error', { msg: uhf.error.value }); return }
    if (!tags.length) { inlineError.value = t('rfid.uhf.no_tag'); return }
    if (tags.length > 1) { candidates.value = tags; return }
    await submit(tags[0])
}

async function submit(tag) {
    if (submitting.value || !props.canOperate) return
    submitting.value = true
    inlineError.value = ''
    rememberedReader.value = readerId.value
    const res = await rfidApi.startRegistration({
        batch_id: props.batch.id,
        uid: tag.epc,
        source: 'uhf_desktop',
        reader_id: readerId.value ? Number(readerId.value) : null,
        rssi: tag.rssi ?? null,
        antenna: tag.antenna ?? null,
    })
    submitting.value = false
    candidates.value = []
    if (res.ok) emit('registered', res.data.uid)
    else inlineError.value = apiText(res)
}
</script>

<template>
    <div class="space-y-4">
        <ResultBanner v-if="!uhf.supported" type="warning" :title="t('rfid.uhf.unsupported')" :description="t('rfid.uhf.unsupported_desc')" />

        <template v-else>
            <!-- Status koneksi -->
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border bg-card p-4">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full"
                          :class="connected ? 'bg-success-soft text-success' : 'bg-muted text-muted-foreground'">
                        <AppIcon name="usb" :size="20" />
                    </span>
                    <div class="min-w-0">
                        <p class="font-semibold">{{ connected ? t('rfid.uhf.connected') : t('rfid.uhf.disconnected') }}</p>
                        <p class="truncate text-sm text-muted-foreground">
                            {{ connected ? (uhf.firmware.value || t('rfid.uhf.firmware_unknown')) : t('rfid.uhf.connect_hint') }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <template v-if="!connected">
                        <button type="button" :disabled="busy" class="inline-flex min-h-[44px] items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60" @click="uhf.connect()">
                            <AppIcon v-if="uhf.state.value === 'connecting'" name="loader" :size="16" spin /><AppIcon v-else name="usb" :size="16" />{{ t('rfid.uhf.connect') }}
                        </button>
                        <button type="button" :disabled="busy" class="min-h-[44px] rounded-lg px-3 text-sm font-medium text-primary hover:bg-accent" @click="uhf.connect({ anyPort: true })">
                            {{ t('rfid.uhf.other_port') }}
                        </button>
                    </template>
                    <button v-else type="button" :disabled="busy" class="min-h-[44px] rounded-lg border border-input px-3 text-sm font-medium hover:bg-accent" @click="uhf.disconnect()">
                        {{ t('rfid.uhf.disconnect') }}
                    </button>
                </div>
            </div>
            <p v-if="uhf.state.value === 'error' && uhf.error.value" class="text-sm text-danger">
                {{ uhf.error.value === 'unplugged' ? t('rfid.uhf.unplugged') : t('rfid.uhf.port_error', { msg: uhf.error.value }) }}
            </p>

            <div v-if="desktopReaders.length" class="space-y-1.5">
                <label for="uhf-reader" class="block text-sm font-medium">{{ t('rfid.uhf.reader') }}</label>
                <select id="uhf-reader" v-model="readerId" class="min-h-[48px] w-full rounded-lg border border-input bg-background px-3 text-base">
                    <option value="">{{ t('rfid.uhf.reader_none') }}</option>
                    <option v-for="r in desktopReaders" :key="r.id" :value="String(r.id)">{{ r.name }} ({{ r.code }})</option>
                </select>
            </div>

            <!-- Baca -->
            <div class="space-y-3 rounded-xl border border-primary/40 bg-card p-6 text-center" aria-live="polite">
                <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-info-soft text-info" :class="uhf.state.value === 'reading' ? 'animate-pulse' : ''">
                    <AppIcon name="antenna" :size="40" />
                </span>
                <p class="text-xl font-bold">{{ uhf.state.value === 'reading' ? t('rfid.uhf.reading') : t('rfid.uhf.place_tag') }}</p>
                <button type="button" :disabled="!connected || busy || !canOperate"
                        class="inline-flex min-h-[56px] w-full items-center justify-center gap-2 rounded-xl bg-primary px-6 text-lg font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60 sm:w-auto"
                        @click="read">
                    <AppIcon v-if="busy" name="loader" :size="20" spin /><AppIcon v-else name="scan-line" :size="22" />
                    {{ t('rfid.uhf.read_register') }}
                </button>
            </div>

            <!-- Lebih dari satu tag di medan antena -->
            <div v-if="candidates.length" class="space-y-2 rounded-xl border border-warning/50 bg-warning-soft/30 p-4">
                <p class="font-semibold text-warning">{{ t('rfid.uhf.multiple', { n: candidates.length }) }}</p>
                <ul class="space-y-2">
                    <li v-for="c in candidates" :key="c.epc" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border bg-card p-3">
                        <div class="min-w-0">
                            <p class="truncate font-mono text-sm font-semibold">{{ groupUid(c.epc) }}</p>
                            <p class="text-xs text-muted-foreground">{{ t('rfid.uhf.tag_stats', { count: c.count, rssi: c.rssi ?? '-' }) }}</p>
                        </div>
                        <button type="button" :disabled="submitting" class="min-h-[44px] rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60" @click="submit(c)">
                            {{ t('rfid.uhf.use_this') }}
                        </button>
                    </li>
                </ul>
            </div>

            <ResultBanner v-if="inlineError" type="error" :title="t('rfid.reg.failed')" :description="inlineError" />
        </template>
    </div>
</template>
