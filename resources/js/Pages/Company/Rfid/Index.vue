<script setup>
/**
 * RFID — satu pintu untuk tugas harian: Daftarkan Tag · Scan Masuk · Scan Keluar · Kelola Tag (+ Riwayat & Log).
 * Konsep teknis (reader, mode otorisasi, ESP32 lama) dipindah ke "Perangkat & Pengaturan".
 *
 * Satu komponen, dua presentasi: `field=true` (/company/hardware/rfid/field) = kontrol besar untuk lapangan.
 * Tidak ada sniffing User-Agent: pilihan presentasi eksplisit lewat tautan Mode Lapangan/Mode Penuh.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PageHeader from '@/components/app/PageHeader.vue'
import StatCard from '@/components/app/StatCard.vue'
import AppIcon from '@/components/app/AppIcon.vue'
import BigActionButton from '@/components/app/BigActionButton.vue'
import RegisterTagWizard from '@/components/rfid/RegisterTagWizard.vue'
import ScanStation from '@/components/rfid/ScanStation.vue'
import ScanHistory from '@/components/rfid/ScanHistory.vue'
import ReadLogList from '@/components/rfid/ReadLogList.vue'
import TagManager from '@/components/rfid/TagManager.vue'
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

const VIEWS = ['register', 'in', 'out', 'tags', 'history', 'logs', 'devices']
const view = ref(
    VIEWS.includes(props.initial.view) ? props.initial.view
        : props.initial.register_batch || props.initial.uid ? 'register'
        : props.field ? 'in' : 'register'
)
const wizardKey = ref(0)
const prefillUid = ref(props.initial.uid ?? '')
const prefillBatch = ref(props.initial.register_batch ?? null)
const manageUid = ref(props.initial.view === 'tags' ? (props.initial.uid ?? '') : '')   // UID yang dibawa ke Kelola Tag
const tagsKey = ref(0)

function go(v) {
    view.value = v
    // Layar sempit (ponsel/mode lapangan): kartu tugas menumpuk satu kolom, jadi isi tugas berada jauh di bawah lipatan.
    if (window.matchMedia?.('(max-width: 639px)').matches) {
        nextTick(() => {
            const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches
            document.getElementById('rfid-content')?.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' })
        })
    }
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

// Pintasan dari Scan Masuk/Keluar ke bagian tertentu di Perangkat & Pengaturan: kartu Mode otorisasi (peringatan
// "Catat saja") atau panel Kesiapan alur (tidak ada reader untuk arah ini).
async function openDevicesAt(anchorId) {
    go('devices')
    await nextTick()
    const calm = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    document.getElementById(anchorId)?.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' })
}
const openModes = () => openDevicesAt('rfid-auth-modes')
const openReadiness = () => openDevicesAt('rfid-flow-readiness')

// Buka Kelola Tag untuk satu UID (dari Scan Masuk/Keluar atau wizard registrasi)
function manageTag(uid) {
    manageUid.value = uid ?? ''
    tagsKey.value++
    go('tags')
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

// Sebelum ringkasan pertama tiba, jumlah reader online dihitung dari daftar reader yang sudah ada — bukan 0, yang akan
// berkedip merah "0/N" (seolah semua reader mati) pada setiap pemuatan halaman.
const stats = computed(() => overview.value ?? {
    scans_today: 0, scans_today_sim: 0,
    readers_total: readers.value.length,
    readers_online: readers.value.filter(r => r.state === 'online').length,
    batches_untagged: 0,
})
const can = computed(() => props.rfid.can)

const CARDS = [
    { key: 'register', icon: 'tag',                tone: 'primary' },
    { key: 'in',       icon: 'arrow-down-to-line', tone: 'info' },
    { key: 'out',      icon: 'arrow-up-from-line', tone: 'neutral' },
    { key: 'tags',     icon: 'unlink',             tone: 'success' },
    { key: 'history',  icon: 'history',            tone: 'neutral' },
    { key: 'logs',     icon: 'clipboard-list',     tone: 'neutral' },
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
            <nav class="mb-6 grid gap-3" :class="field ? 'grid-cols-1 sm:grid-cols-2' : 'sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6'" :aria-label="t('rfid.title')">
                <BigActionButton v-for="c in CARDS" :key="c.key" :icon="c.icon" :tone="c.tone"
                                 :title="t(`rfid.view.${c.key}`)" :description="t(`rfid.view.${c.key}_desc`)" :selected="view === c.key" @click="go(c.key)" />
            </nav>

            <div id="rfid-content" class="scroll-mt-3">
                <RegisterTagWizard v-if="view === 'register'" :key="wizardKey" :readers="readers" :can-operate="can.operate" :can-manage="can.manage"
                                   :initial-batch-id="prefillBatch" :initial-uid="prefillUid" :field="field" :uhf-baud="rfid.uhf_desktop_baud ?? 9600"
                                   @manage-uid="manageTag" />

                <ScanStation v-else-if="view === 'in' || view === 'out'" :key="view" :direction="view" :readers="readers" :stages="rfid.stages" :modes="modes"
                             :can-operate="can.operate" :can-manage="can.manage" :simulation-enabled="rfid.simulation_enabled" :field="field"
                             @register-uid="registerUid" @manage-uid="manageTag" @open-devices="openModes" @open-readiness="openReadiness" />

                <TagManager v-else-if="view === 'tags'" :key="tagsKey" :stages="rfid.stages" :can-operate="can.operate" :can-manage="can.manage"
                            :reset-enabled="rfid.test_reset_enabled ?? true" :initial-uid="manageUid" :field="field" @register-uid="registerUid" />

                <ScanHistory v-else-if="view === 'history'" :readers="readers" :stages="rfid.stages" :can-operate="can.operate" />

                <ReadLogList v-else-if="view === 'logs'" :readers="readers" :stages="rfid.stages" />

                <DevicesPanel v-else :readers="readers" :stages="rfid.stages" :modes="modes" :can-manage="can.manage" :esp32-ip="rfid.esp32_ip ?? ''"
                              @readers-changed="refresh" @modes-changed="m => modes = m" />
            </div>

            <div v-if="view !== 'devices'" class="mt-8 border-t border-border pt-4">
                <button type="button" class="inline-flex min-h-[44px] items-center gap-2 rounded-lg px-3 text-sm font-medium text-muted-foreground hover:bg-accent hover:text-foreground" @click="go('devices')">
                    <AppIcon name="sliders-horizontal" :size="18" />{{ t('rfid.view.devices') }}
                </button>
            </div>
        </div>
    </AppLayout>
</template>
