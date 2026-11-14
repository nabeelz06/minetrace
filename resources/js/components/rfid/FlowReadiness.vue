<script setup>
/**
 * FlowReadiness — "Kesiapan alur": per tahap, apakah scan dari reader benar-benar TERBACA dan MEMINDAHKAN batch.
 *
 * Membuat dua penyebab paling umum "tag terbaca tetapi batch tidak berpindah" kasatmata, tanpa membuka log server:
 *  1. Mode otorisasi tahap itu masih "Catat saja" (atau tidak mencakup arah itu) → scan hanya dicatat.
 *  2. Tidak ada reader yang membaca satu arah (mis. semua reader "Scan Masuk saja" → Scan Keluar tak pernah terbaca).
 *
 * Arah yang bisa dihasilkan sebuah reader dihitung SERVER (`directions`: arah tetap + pemetaan antena), UI hanya
 * menyusunnya — aturan tidak bercabang antara server dan layar. Scan manual dari halaman Scan Masuk/Keluar tidak
 * bergantung pada reader sehingga tidak dinilai di sini.
 */
import { computed } from 'vue'
import AppIcon from '@/components/app/AppIcon.vue'
import { activeReadersAt, laneStatus } from '@/lib/rfidFlow.js'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    readers:   { type: Array, default: () => [] },
    stages:    { type: Array, default: () => [] },
    modes:     { type: Object, default: () => ({}) },
    canManage: { type: Boolean, default: false },
})
const emit = defineEmits(['fix-modes', 'edit-reader'])

const { t, loc } = useI18n()

const rows = computed(() => props.stages.map(stage => {
    const mode = props.modes?.[stage.key]?.mode ?? 'audit_only'
    const here = activeReadersAt(props.readers, stage.key)
    return { stage, mode, here, lanes: ['in', 'out'].map(dir => laneStatus(here, mode, dir)) }
}))

const covered = computed(() => rows.value.filter(r => r.here.length))
const uncovered = computed(() => rows.value.filter(r => !r.here.length))
const allLanes = computed(() => covered.value.flatMap(r => r.lanes))
const issues = computed(() => allLanes.value.filter(l => l.status === 'audit' || l.status === 'offline').length)
const missing = computed(() => allLanes.value.filter(l => l.status === 'none').length)

const TONE = {
    ready:   { box: 'border-success/30 bg-success-soft', text: 'text-success', icon: 'circle-check' },
    audit:   { box: 'border-warning/40 bg-warning-soft', text: 'text-warning', icon: 'triangle-alert' },
    offline: { box: 'border-danger/30 bg-danger-soft',   text: 'text-danger',  icon: 'wifi-off' },
    none:    { box: 'border-border bg-muted/40',         text: 'text-muted-foreground', icon: 'circle-dashed' },
}

function headline(l) {
    if (l.status === 'ready' || l.status === 'none') return t(`rfid.ready.${l.status}_${l.direction}`)
    return t(`rfid.ready.${l.status}`)
}
// "KODE (arah yang dikonfigurasi)" — operator melihat reader mana yang dimaksud dan mengapa arah tertentu tidak terbaca.
const describeReaders = list => list.map(r => `${r.code} (${t(`rfid.dev.dir_${r.direction}`)})`).join(', ')
</script>

<template>
    <section id="rfid-flow-readiness" class="scroll-mt-20 space-y-4 rounded-xl border border-border bg-card p-4 sm:p-5" :aria-label="t('rfid.ready.title')">
        <div>
            <h3 class="text-lg font-semibold">{{ t('rfid.ready.title') }}</h3>
            <p class="text-sm text-muted-foreground">{{ t('rfid.ready.intro') }}</p>
        </div>

        <p v-if="!covered.length" class="flex items-start gap-2 rounded-lg border border-dashed border-border p-3 text-sm text-muted-foreground">
            <AppIcon name="radio-tower" :size="18" class="mt-0.5 flex-shrink-0" />{{ t('rfid.ready.none_active') }}
        </p>

        <template v-else>
            <!-- Ringkasan: yang perlu tindakan lebih dulu, lalu arah yang memang belum punya reader -->
            <div class="space-y-1" role="status">
                <p v-if="issues" class="flex items-start gap-2 text-sm font-semibold text-warning">
                    <AppIcon name="triangle-alert" :size="18" class="mt-0.5 flex-shrink-0" />{{ t('rfid.ready.summary_issues', { n: issues }) }}
                </p>
                <p v-else class="flex items-start gap-2 text-sm font-semibold text-success">
                    <AppIcon name="circle-check" :size="18" class="mt-0.5 flex-shrink-0" />{{ t('rfid.ready.summary_ok') }}
                </p>
                <p v-if="missing" class="flex items-start gap-2 text-sm text-muted-foreground">
                    <AppIcon name="info" :size="18" class="mt-0.5 flex-shrink-0" />{{ t('rfid.ready.summary_none', { n: missing }) }}
                </p>
            </div>

            <ul class="grid gap-3 lg:grid-cols-2">
                <li v-for="row in covered" :key="row.stage.key" class="space-y-3 rounded-xl border border-border p-3 sm:p-4" :data-stage="row.stage.key">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="flex items-center gap-2 text-base font-semibold"><AppIcon :name="row.stage.icon" :size="18" class="text-muted-foreground" />{{ loc(row.stage) }}</p>
                        <span class="rounded-md bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">{{ t('rfid.ready.mode') }}: {{ t(`rfid.modes.${row.mode}`) }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground">{{ t('rfid.ready.readers') }}: <span class="font-mono">{{ describeReaders(row.here) }}</span></p>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <div v-for="l in row.lanes" :key="l.direction" class="flex flex-col gap-1.5 rounded-lg border p-3 text-sm" :class="TONE[l.status].box" :data-lane="l.direction" :data-status="l.status">
                            <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide" :class="TONE[l.status].text">
                                <AppIcon :name="l.direction === 'in' ? 'arrow-down-to-line' : 'arrow-up-from-line'" :size="14" />{{ t(`rfid.ready.lane_${l.direction}`) }}
                            </p>
                            <p class="flex items-start gap-1.5 font-semibold" :class="TONE[l.status].text">
                                <AppIcon :name="TONE[l.status].icon" :size="16" class="mt-0.5 flex-shrink-0" />{{ headline(l) }}
                            </p>
                            <p v-if="l.readers.length" class="font-mono text-xs text-muted-foreground">{{ describeReaders(l.readers) }}</p>

                            <template v-if="l.status === 'audit'">
                                <p class="text-foreground">{{ t(row.mode === 'audit_only' ? 'rfid.ready.audit_why_audit_only' : 'rfid.ready.audit_why_other') }}</p>
                                <button v-if="canManage" type="button" class="mt-1 inline-flex min-h-[44px] items-center gap-2 self-start rounded-lg bg-card px-3 font-semibold text-foreground hover:bg-accent" @click="emit('fix-modes')">
                                    <AppIcon name="sliders-horizontal" :size="16" />{{ t('rfid.ready.audit_fix') }}
                                </button>
                                <p v-else class="text-foreground">{{ t('rfid.ready.audit_fix_hint') }}</p>
                            </template>
                            <p v-else-if="l.status === 'offline'" class="text-foreground">{{ t('rfid.ready.offline_hint') }}</p>
                            <template v-else-if="l.status === 'none'">
                                <p class="text-foreground">{{ t(`rfid.ready.hint_none_${l.direction}`) }}</p>
                                <button v-if="canManage" type="button" class="mt-1 inline-flex min-h-[44px] items-center gap-2 self-start rounded-lg bg-card px-3 font-semibold text-foreground hover:bg-accent" @click="emit('edit-reader', row.here[0])">
                                    <AppIcon name="pencil" :size="16" />{{ t('rfid.ready.edit_reader') }}
                                </button>
                            </template>
                        </div>
                    </div>
                </li>
            </ul>
        </template>

        <p v-if="covered.length && uncovered.length" class="text-sm text-muted-foreground">
            {{ t('rfid.ready.no_readers_stages', { names: uncovered.map(r => loc(r.stage)).join(', ') }) }}
        </p>
    </section>
</template>
