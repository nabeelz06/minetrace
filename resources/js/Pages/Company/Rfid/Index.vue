<script setup>
/**
 * RFID — satu pintu untuk tiga tugas: Daftarkan Tag · Scan Masuk · Scan Keluar (+ Riwayat).
 * Konsep teknis (reader, mode otorisasi, ESP32 lama) dipindah ke "Perangkat & Pengaturan".
 *
 * Satu komponen, dua presentasi: `field=true` (/company/hardware/rfid/field) = kontrol besar untuk lapangan.
 * Tidak ada sniffing User-Agent: pilihan presentasi eksplisit lewat tautan Mode Lapangan/Mode Penuh.
 */
import { computed, onMounted, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/components/app/PageHeader.vue'
import StatCard from '@/components/app/StatCard.vue'
import AppIcon from '@/components/app/AppIcon.vue'
import BigActionButton from '@/components/app/BigActionButton.vue'
import RegisterTagWizard from '@/components/rfid/RegisterTagWizard.vue'
import ScanStation from '@/components/rfid/ScanStation.vue'
import ScanHistory from '@/components/rfid/ScanHistory.vue'
import DevicesPanel from '@/components/rfid/DevicesPanel.vue'
import { rfidApi } from '@/composables/rfid/useRfidApi.js'
import { usePolling } from '@/composables/core/usePolling.js'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    field:   { type: Boolean, default: false },
    initial: { type: Object, default: () => ({}) },
    rfid:    { type: Object, required: true },
})

const { t } = useI18n()

const VIEWS = ['register', 'in', 'out', 'history', 'devices']
const view = ref(
    VIEWS.includes(props.initial.view) ? props.initial.view
        : props.initial.register_batch || props.initial.uid ? 'register'
        : props.field ? 'in' : 'register'
)
const wizardKey = ref(0)
const prefillUid = ref(props.initial.uid ?? '')
const prefillBatch = ref(props.initial.register_batch ?? null)

function go(v) {
    view.value = v
    // Tautan dapat di-bookmark tanpa navigasi Inertia penuh
    const url = new URL(window.location.href)
    url.searchParams.set('view', v)
    url.searchParams.delete('register_batch'); url.searchParams.delete('uid')
    window.history.replaceState(window.history.state, '', url)
}

// Daftarkan tag yang tidak dikenal (dari Scan Masuk/Keluar)
function registerUid(uid) {
    prefillUid.value = uid ?? ''
    prefillBatch.value = null
    wizardKey.value++
    go('register')
}

// ─── Data hidup (reader & ringkasan) ──────────────────────────────────────────
const readers = ref(props.rfid.readers)
const modes = ref(props.rfid.authorization_modes)
const overview = ref(null)

async function refresh() {
    const [o, r] = await Promise.all([rfidApi.overview(), rfidApi.readers()])
    if (o.ok) overview.value = o.data
    if (r.ok) readers.value = r.data
    return o.ok && r.ok
}
const poll = usePolling(refresh, { interval: 15000, immediate: true })
onMounted(() => poll.start())

const stats = computed(() => overview.value ?? { scans_today: 0, scans_today_sim: 0, readers_total: readers.value.length, readers_online: 0, batches_untagged: 0 })
const can = computed(() => props.rfid.can)

const CARDS = [
    { key: 'register', icon: 'tag',                tone: 'primary' },
    { key: 'in',       icon: 'arrow-down-to-line', tone: 'info' },
    { key: 'out',      icon: 'arrow-up-from-line', tone: 'neutral' },
    { key: 'history',  icon: 'history',            tone: 'neutral' },
]
</script>

<template>
    <Head :title="`${t('rfid.title')}`" />

    <AppLayout>
        <template #header>{{ t('rfid.title') }}</template>

        <div :class="field ? 'mx-auto max-w-3xl' : ''">
            <PageHeader :title="t('rfid.title')" :description="t('rfid.subtitle')" icon="nfc">
                <template #actions>
                    <Link :href="field ? '/company/hardware/rfid' : '/company/hardware/rfid/field'"
                          class="inline-flex min-h-[44px] items-center gap-2 rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent">
                        <AppIcon :name="field ? 'monitor' : 'smartphone'" :size="18" />{{ field ? t('rfid.full_mode') : t('rfid.field_mode') }}
                    </Link>
                </template>
            </PageHeader>

            <!-- Ringkasan (tidak ditampilkan di mode lapangan agar layar fokus) -->
            <div v-if="!field" class="mb-5 grid gap-3 sm:grid-cols-3">
                <StatCard :label="t('rfid.stats.scans_today')" :value="stats.scans_today" icon="scan-line"
                          :hint="stats.scans_today_sim ? t('rfid.stats.of_which_sim', { n: stats.scans_today_sim }) : null" />
                <StatCard :label="t('rfid.stats.readers')" :value="`${stats.readers_online}/${stats.readers_total}`" icon="radio-tower"
                          :tone="stats.readers_total && !stats.readers_online ? 'danger' : 'neutral'" />
                <StatCard :label="t('rfid.stats.untagged')" :value="stats.batches_untagged" icon="tag" :tone="stats.batches_untagged ? 'warning' : 'neutral'" />
            </div>

            <!-- Empat tugas utama -->
            <nav class="mb-6 grid gap-3" :class="field ? 'grid-cols-1 sm:grid-cols-2' : 'sm:grid-cols-2 xl:grid-cols-4'" :aria-label="t('rfid.title')">
                <BigActionButton v-for="c in CARDS" :key="c.key" :icon="c.icon" :tone="c.tone"
                                 :title="t(`rfid.view.${c.key}`)" :description="t(`rfid.view.${c.key}_desc`)" :selected="view === c.key" @click="go(c.key)" />
            </nav>

            <RegisterTagWizard v-if="view === 'register'" :key="wizardKey" :readers="readers" :can-operate="can.operate"
                               :initial-batch-id="prefillBatch" :initial-uid="prefillUid" :field="field" />

            <ScanStation v-else-if="view === 'in' || view === 'out'" :key="view" :direction="view" :readers="readers" :stages="rfid.stages" :modes="modes"
                         :can-operate="can.operate" :simulation-enabled="rfid.simulation_enabled" :field="field" @register-uid="registerUid" />

            <ScanHistory v-else-if="view === 'history'" :readers="readers" :stages="rfid.stages" :can-operate="can.operate" />

            <DevicesPanel v-else :readers="readers" :stages="rfid.stages" :modes="modes" :can-manage="can.manage" :esp32-ip="rfid.esp32_ip ?? ''"
                          @readers-changed="refresh" @modes-changed="m => modes = m" />

            <div v-if="view !== 'devices'" class="mt-8 border-t border-border pt-4">
                <button type="button" class="inline-flex min-h-[44px] items-center gap-2 rounded-lg px-3 text-sm font-medium text-muted-foreground hover:bg-accent hover:text-foreground" @click="go('devices')">
                    <AppIcon name="sliders-horizontal" :size="18" />{{ t('rfid.view.devices') }}
                </button>
            </div>
        </div>
    </AppLayout>
</template>
