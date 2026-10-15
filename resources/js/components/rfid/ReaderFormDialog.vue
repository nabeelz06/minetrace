<script setup>
/** ReaderFormDialog — tambah/ubah reader terdaftar (administrator). Kunci TIDAK dikelola di sini. */
import { ref, watch } from 'vue'
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
const blank = () => ({ code: '', name: '', stage: '', direction: 'in', device_type: 'esp32_rc522' })
const form = ref(blank())
const errors = ref({})
const message = ref('')
const loading = ref(false)

watch(() => props.open, o => {
    if (!o) return
    errors.value = {}; message.value = ''
    form.value = props.reader
        ? { code: props.reader.code, name: props.reader.name, stage: props.reader.stage ?? '', direction: props.reader.direction, device_type: props.reader.device_type }
        : blank()
})

async function submit() {
    loading.value = true; errors.value = {}; message.value = ''
    const body = { ...form.value, stage: form.value.stage || null }
    const res = props.reader
        ? await rfidApi.updateReader(props.reader.id, { name: body.name, stage: body.stage, direction: body.direction, device_type: body.device_type })
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
                        <option v-for="k in ['esp32_rc522', 'uhf_epc_gen2', 'keyboard_wedge', 'other']" :key="k" :value="k">{{ t(`rfid.dev.type_${k}`) }}</option>
                    </select>
                </FormField>
                <ResultBanner v-if="message" type="error" :title="message" />

                <DialogFooter class="gap-2 sm:gap-2">
                    <button type="button" class="min-h-[44px] rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent" @click="emit('update:open', false)">{{ t('app.cancel') }}</button>
                    <button type="submit" :disabled="loading || !form.name || (!reader && !form.code)" class="min-h-[44px] rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60">
                        {{ loading ? t('app.saving') : (reader ? t('rfid.dev.save') : t('rfid.dev.create')) }}
                    </button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
