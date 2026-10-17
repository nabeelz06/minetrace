<script setup>
/**
 * ReaderFormDialog — tambah/ubah reader terdaftar (administrator). Kunci TIDAK dikelola di sini.
 * Reader gate Chainway UR4 (TCP) butuh alamat IP/port + pemetaan antena → arah (dibaca daemon rfid:uhf-gate).
 */
import { computed, ref, watch } from 'vue'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import FormField from '@/components/app/FormField.vue'
import FormInput from '@/components/app/FormInput.vue'
import ResultBanner from '@/components/app/ResultBanner.vue'
import { rfidApi, useApiText } from '@/composables/rfid/useRfidApi.js'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    open:   { type: Boolean, default: false },
    reader: { type: Object, default: null },    // null = tambah
    stages: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:open', 'saved'])

const { t, loc } = useI18n()
const apiText = useApiText()
const TYPES = ['uhf_chainway_ur4', 'uhf_desktop_r2000', 'esp32_rc522', 'uhf_epc_gen2', 'keyboard_wedge', 'other']
const ANTENNAS = [1, 2, 3, 4]
const blankConn = () => ({ host: '', port: 8888, min_rssi: '', presence_seconds: '', antennas: { 1: '', 2: '', 3: '', 4: '' } })
const blank = () => ({ code: '', name: '', stage: '', direction: 'in', device_type: 'uhf_chainway_ur4', conn: blankConn() })
const form = ref(blank())
const isUr4 = computed(() => form.value.device_type === 'uhf_chainway_ur4')

function toConn(c) {
    const base = blankConn()
    if (!c) return base
    return { ...base, ...c, min_rssi: c.min_rssi ?? '', presence_seconds: c.presence_seconds ?? '', antennas: { ...base.antennas, ...(c.antennas ?? {}) } }
}

/** connection untuk API: hanya reader UR4; field kosong dibuang. */
function connectionBody() {
    if (!isUr4.value) return null
    const c = form.value.conn
    const antennas = Object.fromEntries(Object.entries(c.antennas).filter(([, d]) => d))
    return {
        host: String(c.host).trim(),
        port: Number(c.port) || 8888,
        ...(c.min_rssi !== '' && c.min_rssi !== null ? { min_rssi: Number(c.min_rssi) } : {}),
        ...(c.presence_seconds !== '' && c.presence_seconds !== null ? { presence_seconds: Number(c.presence_seconds) } : {}),
        ...(Object.keys(antennas).length ? { antennas } : {}),
    }
}
const errors = ref({})
const message = ref('')
const loading = ref(false)

watch(() => props.open, o => {
    if (!o) return
    errors.value = {}; message.value = ''
    form.value = props.reader
        ? { code: props.reader.code, name: props.reader.name, stage: props.reader.stage ?? '', direction: props.reader.direction, device_type: props.reader.device_type, conn: toConn(props.reader.connection) }
        : blank()
})

async function submit() {
    loading.value = true; errors.value = {}; message.value = ''
    const { conn, ...rest } = form.value
    const body = { ...rest, stage: rest.stage || null, connection: connectionBody() }
    const res = props.reader
        ? await rfidApi.updateReader(props.reader.id, { name: body.name, stage: body.stage, direction: body.direction, device_type: body.device_type, connection: body.connection })
        : await rfidApi.createReader(body)
    loading.value = false
    if (res.ok) { emit('saved', res.data); emit('update:open', false) }
    else if (res.status === 422 && res.errors) errors.value = Object.fromEntries(Object.entries(res.errors).map(([k, v]) => [k, v[0]]))
    else message.value = apiText(res)
}
</script>

<template>
    <Dialog :open="open" @update:open="v => emit('update:open', v)">
        <DialogContent class="max-w-lg">
            <DialogHeader>
                <DialogTitle class="text-lg">{{ reader ? t('rfid.dev.edit') : t('rfid.dev.add') }}</DialogTitle>
                <DialogDescription class="sr-only">{{ t('rfid.dev.title') }}</DialogDescription>
            </DialogHeader>

            <form class="space-y-4" novalidate @submit.prevent="submit">
                <FormField :label="t('rfid.dev.code')" :hint="t('rfid.dev.code_hint')" :error="errors.code" required v-slot="{ id, describedby, invalid }">
                    <FormInput :id="id" v-model="form.code" :disabled="!!reader" class="uppercase" :invalid="invalid" :describedby="describedby" autocomplete="off" />
                </FormField>
                <FormField :label="t('rfid.dev.name')" :error="errors.name" required v-slot="{ id, describedby, invalid }">
                    <FormInput :id="id" v-model="form.name" :invalid="invalid" :describedby="describedby" autocomplete="off" />
                </FormField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField :label="t('rfid.dev.stage')" :error="errors.stage" v-slot="{ id }">
                        <select :id="id" v-model="form.stage" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3">
                            <option value="">{{ t('rfid.dev.stage_none') }}</option>
                            <option v-for="s in stages" :key="s.key" :value="s.key">{{ loc(s) }}</option>
                        </select>
                    </FormField>
                    <FormField :label="t('rfid.dev.direction')" :error="errors.direction" v-slot="{ id }">
                        <select :id="id" v-model="form.direction" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3">
                            <option value="in">{{ t('rfid.dev.dir_in') }}</option>
                            <option value="out">{{ t('rfid.dev.dir_out') }}</option>
                            <option value="any">{{ t('rfid.dev.dir_any') }}</option>
                        </select>
                    </FormField>
                </div>
                <FormField :label="t('rfid.dev.type')" :error="errors.device_type" v-slot="{ id }">
                    <select :id="id" v-model="form.device_type" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3">
                        <option v-for="k in TYPES" :key="k" :value="k">{{ t(`rfid.dev.type_${k}`) }}</option>
                    </select>
                </FormField>
                <p v-if="form.device_type === 'uhf_desktop_r2000'" class="text-sm text-muted-foreground">{{ t('rfid.dev.desktop_hint') }}</p>

                <!-- Koneksi reader gate UR4 -->
                <fieldset v-if="isUr4" class="space-y-4 rounded-xl border border-border p-4">
                    <legend class="px-1 text-sm font-semibold">{{ t('rfid.dev.conn_title') }}</legend>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <FormField class="sm:col-span-2" :label="t('rfid.dev.conn_host')" :hint="t('rfid.dev.conn_host_hint')" :error="errors['connection.host']" required v-slot="{ id, describedby, invalid }">
                            <FormInput :id="id" v-model="form.conn.host" :invalid="invalid" :describedby="describedby" autocomplete="off" placeholder="192.168.99.202" />
                        </FormField>
                        <FormField :label="t('rfid.dev.conn_port')" :error="errors['connection.port']" v-slot="{ id, describedby, invalid }">
                            <FormInput :id="id" v-model="form.conn.port" type="number" :invalid="invalid" :describedby="describedby" />
                        </FormField>
                    </div>
                    <div class="space-y-2">
                        <p class="text-sm font-medium">{{ t('rfid.dev.conn_antennas') }}</p>
                        <p class="text-xs text-muted-foreground">{{ t('rfid.dev.conn_antennas_hint') }}</p>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <label v-for="a in ANTENNAS" :key="a" class="space-y-1 text-sm">
                                <span class="block text-muted-foreground">{{ t('rfid.logs.antenna', { n: a }) }}</span>
                                <select v-model="form.conn.antennas[a]" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-2">
                                    <option value="">{{ t('rfid.dev.ant_follow') }}</option>
                                    <option value="in">{{ t('rfid.hist.dir_in') }}</option>
                                    <option value="out">{{ t('rfid.hist.dir_out') }}</option>
                                </select>
                            </label>
                        </div>
                        <p v-if="errors['connection.antennas']" class="text-sm text-danger">{{ errors['connection.antennas'] }}</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <FormField :label="t('rfid.dev.conn_min_rssi')" :hint="t('rfid.dev.conn_min_rssi_hint')" :error="errors['connection.min_rssi']" v-slot="{ id, describedby, invalid }">
                            <FormInput :id="id" v-model="form.conn.min_rssi" type="number" :invalid="invalid" :describedby="describedby" placeholder="-70" />
                        </FormField>
                        <FormField :label="t('rfid.dev.conn_presence')" :hint="t('rfid.dev.conn_presence_hint')" :error="errors['connection.presence_seconds']" v-slot="{ id, describedby, invalid }">
                            <FormInput :id="id" v-model="form.conn.presence_seconds" type="number" :invalid="invalid" :describedby="describedby" placeholder="10" />
                        </FormField>
                    </div>
                </fieldset>
                <ResultBanner v-if="message" type="error" :title="message" />

                <DialogFooter class="gap-2 sm:gap-2">
                    <button type="button" class="min-h-[44px] rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent" @click="emit('update:open', false)">{{ t('app.cancel') }}</button>
                    <button type="submit" :disabled="loading || !form.name || (!reader && !form.code) || (isUr4 && !String(form.conn.host).trim())" class="min-h-[44px] rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60">
                        {{ loading ? t('app.saving') : (reader ? t('rfid.dev.save') : t('rfid.dev.create')) }}
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
