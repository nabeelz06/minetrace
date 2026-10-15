<script setup>
/**
 * LegacyEsp32Card — dukungan ESP32 lama (tanpa kunci perangkat) yang sedang berjalan.
 * Memeriksa koneksi & mengatur mode layar langsung ke perangkat (HTTP di LAN) secara BEST-EFFORT;
 * mode scan tetap diatur di server (cache) sehingga pemindaian tidak bergantung pada browser.
 */
import { ref } from 'vue'
import AppIcon from '@/components/app/AppIcon.vue'
import { rfidApi, useApiText } from '@/composables/rfid/useRfidApi.js'
import { pushToast } from '@/composables/core/useToast.js'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    initialIp: { type: String, default: '' },
    stages:    { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
})

const { t, loc } = useI18n()
const apiText = useApiText()
const ip = ref(props.initialIp ?? '')
const state = ref('')            // '' | online | offline | blocked
const stage = ref(props.stages[0]?.key ?? '')
const mode = ref('scan_in')
const busy = ref(false)
const isHttps = window.location.protocol === 'https:'

const base = () => (ip.value.startsWith('http') ? ip.value : `http://${ip.value}`).replace(/\/$/, '')
// Firmware lama memakai nama mode login/logout
const FIRMWARE_MODE = { scan_in: 'login', scan_out: 'logout', idle: 'idle' }

async function saveIp() {
    busy.value = true
    const res = await rfidApi.saveDeviceIp(ip.value.trim())
    busy.value = false
    if (res.ok) pushToast(t('rfid.legacy.saved'), 'success'); else pushToast(apiText(res), 'error')
}

async function check() {
    if (!ip.value.trim()) return
    try {
        const r = await fetch(`${base()}/status`, { signal: AbortSignal.timeout(2500) })
        state.value = r.ok ? 'online' : 'offline'
    } catch (e) {
        state.value = isHttps && /Failed to fetch/i.test(e?.message ?? '') ? 'blocked' : 'offline'
    }
}

async function apply() {
    busy.value = true
    const res = await rfidApi.setLegacyMode({ mode, stage: mode === 'idle' ? null : stage.value })   // server dulu: sumber kebenaran
    if (!res.ok) { busy.value = false; return pushToast(apiText(res), 'error') }
    if (ip.value.trim()) {
        try {
            await fetch(`${base()}/set-mode`, {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mode: FIRMWARE_MODE[mode.value], stage: stage.value }), signal: AbortSignal.timeout(4000),
            })
        } catch { /* perangkat tak terjangkau: mode server tetap aktif */ }
    }
    busy.value = false
    pushToast(t('rfid.legacy.applied'), 'success')
}
</script>

<template>
    <div class="space-y-4 rounded-xl border border-border bg-card p-4 sm:p-5">
        <div>
            <h3 class="text-lg font-semibold">{{ t('rfid.legacy.title') }}</h3>
            <p class="text-sm text-muted-foreground">{{ t('rfid.legacy.desc') }}</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-[1fr_auto_auto]">
            <div class="space-y-1.5">
                <label for="legacy-ip" class="block text-sm font-medium">{{ t('rfid.legacy.ip') }}</label>
                <input id="legacy-ip" v-model="ip" :disabled="!canManage" placeholder="192.168.1.50" inputmode="url" autocomplete="off"
                       class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3 font-mono" />
            </div>
            <button type="button" :disabled="!canManage || busy || !ip.trim()" class="min-h-[44px] self-end rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent disabled:opacity-50" @click="saveIp">{{ t('rfid.legacy.save_ip') }}</button>
            <button type="button" :disabled="!ip.trim()" class="min-h-[44px] self-end rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent disabled:opacity-50" @click="check">{{ t('rfid.legacy.check') }}</button>
        </div>

        <p v-if="state" class="flex items-center gap-2 text-sm font-medium" :class="state === 'online' ? 'text-success' : 'text-warning'" role="status">
            <AppIcon :name="state === 'online' ? 'wifi' : 'wifi-off'" :size="16" />{{ t(`rfid.legacy.${state}`) }}
        </p>
        <p v-if="isHttps" class="text-sm text-muted-foreground">{{ t('rfid.legacy.https_note') }}</p>

        <div v-if="canManage" class="grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
            <div class="space-y-1.5">
                <label for="legacy-mode" class="block text-sm font-medium">{{ t('rfid.legacy.mode') }}</label>
                <select id="legacy-mode" v-model="mode" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3">
                    <option value="scan_in">{{ t('rfid.legacy.mode_scan_in') }}</option>
                    <option value="scan_out">{{ t('rfid.legacy.mode_scan_out') }}</option>
                    <option value="idle">{{ t('rfid.legacy.mode_idle') }}</option>
                </select>
            </div>
            <div class="space-y-1.5">
                <label for="legacy-stage" class="block text-sm font-medium">{{ t('rfid.scan.stage') }}</label>
                <select id="legacy-stage" v-model="stage" :disabled="mode === 'idle'" class="min-h-[44px] w-full rounded-lg border border-input bg-background px-3">
                    <option v-for="s in stages" :key="s.key" :value="s.key">{{ loc(s) }}</option>
                </select>
            </div>
            <button type="button" :disabled="busy" class="min-h-[44px] self-end rounded-lg bg-primary px-5 text-sm font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60" @click="apply">{{ t('rfid.legacy.apply') }}</button>
        </div>
    </div>
</template>
