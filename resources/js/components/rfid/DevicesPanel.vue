<script setup>
/**
 * DevicesPanel — Perangkat & Pengaturan Lanjutan: reader terdaftar, mode otorisasi, ESP32 lama.
 * Administrator mengelola; peran lain hanya melihat (tanpa kunci).
 */
import { computed, ref, watch } from 'vue'
import { useNow } from '@vueuse/core'
import AppIcon from '@/components/app/AppIcon.vue'
import EmptyState from '@/components/shared/EmptyState.vue'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import ReaderFormDialog from '@/components/rfid/ReaderFormDialog.vue'
import ReaderKeyDialog from '@/components/rfid/ReaderKeyDialog.vue'
import AuthModesCard from '@/components/rfid/AuthModesCard.vue'
import LegacyEsp32Card from '@/components/rfid/LegacyEsp32Card.vue'
import { rfidApi, useApiText } from '@/composables/rfid/useRfidApi.js'
import { pushToast } from '@/composables/core/useToast.js'
import { useI18n } from '@/composables/core/useI18n.js'
import { formatRelative } from '@/lib/format'

const props = defineProps({
    readers:   { type: Array, default: () => [] },
    stages:    { type: Array, default: () => [] },
    modes:     { type: Object, required: true },
    canManage: { type: Boolean, default: false },
    esp32Ip:   { type: String, default: '' },
})
const emit = defineEmits(['readers-changed', 'modes-changed'])

const { t, lang, loc } = useI18n()
const apiText = useApiText()
useNow({ interval: 30000 })   // segarkan "terakhir terlihat"

const formOpen = ref(false)
const editing = ref(null)
const keyOpen = ref(false)
const keyReader = ref(null)
const keyValue = ref('')
watch(keyOpen, open => { if (!open) { keyValue.value = ''; keyReader.value = null } })   // kunci tidak tersisa di memori UI
const rotateTarget = ref(null)
const rotateOpen = ref(false)

const STATE = {
    online:   { tone: 'bg-success-soft text-success', icon: 'wifi' },
    offline:  { tone: 'bg-danger-soft text-danger',   icon: 'wifi-off' },
    never:    { tone: 'bg-warning-soft text-warning', icon: 'circle-dashed' },
    disabled: { tone: 'bg-neutral-soft text-neutral', icon: 'ban' },
}
const stageName = key => { const s = props.stages.find(x => x.key === key); return s ? loc(s) : t('rfid.dev.stage_none') }

function openAdd() { editing.value = null; formOpen.value = true }
function openEdit(r) { editing.value = r; formOpen.value = true }

function onSaved(data) {
    emit('readers-changed')
    if (data?.device_key) { keyReader.value = data.reader; keyValue.value = data.device_key; keyOpen.value = true }
    else pushToast(t('rfid.dev.saved'), 'success')
}

async function toggle(r) {
    const res = await rfidApi.updateReader(r.id, { is_active: !r.is_active })
    if (res.ok) emit('readers-changed'); else pushToast(apiText(res), 'error')
}

function askRotate(r) { rotateTarget.value = r; rotateOpen.value = true }
async function rotate() {
    rotateOpen.value = false
    const res = await rfidApi.rotateKey(rotateTarget.value.id)
    if (res.ok) { keyReader.value = res.data.reader; keyValue.value = res.data.device_key; keyOpen.value = true; emit('readers-changed') }
    else pushToast(apiText(res), 'error')
}
</script>

<template>
    <div class="space-y-6">
        <section class="space-y-3" :aria-label="t('rfid.dev.title')">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-lg font-semibold">{{ t('rfid.dev.title') }}</h3>
                <button v-if="canManage" type="button" class="inline-flex min-h-[44px] items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90" @click="openAdd">
                    <AppIcon name="plus" :size="18" />{{ t('rfid.dev.add') }}
                </button>
            </div>
            <p v-if="!canManage" class="text-sm text-muted-foreground">{{ t('rfid.dev.manage_only') }}</p>

            <EmptyState v-if="!readers.length" icon="radio-tower" :title="t('rfid.dev.empty')" :description="t('rfid.dev.empty_hint')" size="sm" />

            <ul v-else class="grid gap-3 lg:grid-cols-2">
                <li v-for="r in readers" :key="r.id" class="space-y-3 rounded-xl border border-border bg-card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-base font-semibold">{{ r.name }}</p>
                            <p class="font-mono text-xs text-muted-foreground">{{ r.code }} · {{ t(`rfid.dev.type_${r.device_type}`) }}</p>
                        </div>
                        <span class="inline-flex flex-shrink-0 items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold" :class="STATE[r.state].tone">
                            <AppIcon :name="STATE[r.state].icon" :size="14" />{{ t(`rfid.dev.state_${r.state}`) }}
                        </span>
                    </div>

                    <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                        <dt class="text-muted-foreground">{{ t('rfid.dev.stage') }}</dt><dd class="text-right">{{ stageName(r.stage) }}</dd>
                        <dt class="text-muted-foreground">{{ t('rfid.dev.direction') }}</dt><dd class="text-right">{{ t(`rfid.dev.dir_${r.direction}`) }}</dd>
                        <template v-if="r.connection?.host">
                            <dt class="text-muted-foreground">{{ t('rfid.dev.conn_host') }}</dt><dd class="text-right font-mono text-xs">{{ r.connection.host }}:{{ r.connection.port ?? 8888 }}</dd>
                        </template>
                        <template v-if="r.last_status?.reads_total != null">
                            <dt class="text-muted-foreground">{{ t('rfid.dev.reads') }}</dt><dd class="text-right tabular-nums">{{ r.last_status.events_total }} / {{ r.last_status.reads_total }}</dd>
                        </template>
                        <dt class="text-muted-foreground">{{ t('rfid.dev.key', { prefix: r.key_prefix }) }}</dt>
                        <dd class="text-right text-muted-foreground">{{ r.firmware_version ? t('rfid.dev.firmware', { v: r.firmware_version }) : '' }}</dd>
                    </dl>

                    <p v-if="r.last_seen_at" class="text-sm text-muted-foreground">{{ t('rfid.dev.last_seen', { time: formatRelative(r.last_seen_at, lang) }) }}</p>
                    <p v-if="r.state !== 'online'" class="text-sm font-medium" :class="r.state === 'offline' ? 'text-danger' : 'text-warning'">{{ t(`rfid.dev.hint_${r.state}`) }}</p>

                    <div v-if="canManage" class="flex flex-wrap gap-2 border-t border-border pt-3">
                        <button type="button" class="min-h-[44px] rounded-lg border border-input px-3 text-sm font-medium hover:bg-accent" @click="openEdit(r)">{{ t('rfid.dev.edit') }}</button>
                        <button type="button" class="inline-flex min-h-[44px] items-center gap-1.5 rounded-lg border border-input px-3 text-sm font-medium hover:bg-accent" @click="askRotate(r)"><AppIcon name="key-round" :size="16" />{{ t('rfid.dev.rotate') }}</button>
                        <button type="button" class="min-h-[44px] rounded-lg border border-input px-3 text-sm font-medium hover:bg-accent" @click="toggle(r)">{{ r.is_active ? t('rfid.dev.deactivate') : t('rfid.dev.activate') }}</button>
                    </div>
                </li>
            </ul>
        </section>

        <AuthModesCard :modes="modes" :stages="stages" :can-manage="canManage" @changed="m => emit('modes-changed', m)" />
        <LegacyEsp32Card :initial-ip="esp32Ip" :stages="stages" :can-manage="canManage" />

        <ReaderFormDialog v-model:open="formOpen" :reader="editing" :stages="stages" @saved="onSaved" />
        <ReaderKeyDialog v-model:open="keyOpen" :reader="keyReader" :key-value="keyValue" />
        <ConfirmDialog v-model:open="rotateOpen" :title="t('rfid.dev.rotate_title')" :description="t('rfid.dev.rotate_desc')" :confirm-label="t('rfid.dev.rotate')" tone="danger" @confirm="rotate" />
    </div>
</template>
