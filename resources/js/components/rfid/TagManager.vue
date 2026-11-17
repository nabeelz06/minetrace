<script setup>
/**
 * TagManager — "Kelola Tag": cek status sebuah tag, lepas dari batch, atau reset uji agar tag yang SAMA bisa dites ulang.
 *
 * Alur operator (tidak perlu tahu batch pemilik tag):
 *   1. Tempelkan tag pada reader → tag muncul di "Tag terbaru dibaca" (diperbarui otomatis), atau ketik/pindai UID.
 *   2. Pilih tag → lihat status, batch pemilik, posisi batch, dan jumlah scan sejak didaftarkan.
 *   3. Pilih tindakan: Reset uji (tag tetap terpasang, scan disembunyikan) atau Lepas tag (bebas untuk batch lain).
 * Server menegakkan izin, pagar keamanan, dan audit (tidak ada riwayat yang dihapus); UI hanya menjelaskan dampaknya.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useNow } from '@vueuse/core'
import AppIcon from '@/components/app/AppIcon.vue'
import ResultBanner from '@/components/app/ResultBanner.vue'
import EmptyState from '@/components/shared/EmptyState.vue'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import TagActionDialog from '@/components/rfid/TagActionDialog.vue'
import { rfidApi, useApiText } from '@/composables/rfid/useRfidApi.js'
import { usePolling } from '@/composables/core/usePolling.js'
import { useI18n } from '@/composables/core/useI18n.js'
import { formatDateTime, formatRelative, formatUidGrouped, formatUidShort } from '@/lib/format'

const props = defineProps({
    stages:       { type: Array, default: () => [] },
    canOperate:   { type: Boolean, default: false },   // rfid.operate → boleh melepas tag
    canManage:    { type: Boolean, default: false },   // rfid.manage  → boleh reset uji
    resetEnabled: { type: Boolean, default: true },    // saklar sistem (RFID_TEST_RESET_ENABLED)
    initialUid:   { type: String, default: '' },
    field:        { type: Boolean, default: false },
})
const emit = defineEmits(['register-uid'])

const { t, lang, loc } = useI18n()
const apiText = useApiText()

// Waktu relatif ("5 detik lalu") ikut diperbarui tanpa menunggu data baru.
const now = useNow({ interval: 5000 })
const rel = iso => { void now.value; return formatRelative(iso, lang.value) }

// Tampilan status tag: ikon + teks + warna (warna tidak berdiri sendiri).
const STATE = {
    free:     { tone: 'bg-success-soft text-success', icon: 'circle-check' },
    bound:    { tone: 'bg-info-soft text-info',       icon: 'tag' },
    archived: { tone: 'bg-warning-soft text-warning', icon: 'triangle-alert' },
    foreign:  { tone: 'bg-neutral-soft text-neutral', icon: 'ban' },
}
const stateOf = s => STATE[s] ?? STATE.free

function stageName(key) {
    const s = props.stages.find(x => x.key === key)
    return s ? loc(s) : String(key ?? '—').replace(/_/g, ' ')
}

// ─── Tag terbaru dibaca (polling sadar visibilitas tab) ───────────────────────
const recent = ref(null)         // null = belum dimuat
const recentFailed = ref(false)

async function loadRecent() {
    const res = await rfidApi.tagsRecent(12)
    recentFailed.value = !res.ok
    if (res.ok) recent.value = res.data
    return res.ok
}
const poll = usePolling(loadRecent, { interval: 4000, immediate: true })

// ─── Cek tag ──────────────────────────────────────────────────────────────────
const uidInput = ref(props.initialUid)
const looked = ref(null)         // data hasil lookup
const looking = ref(false)
const lookupError = ref('')
const notice = ref(null)         // { type, text } umpan balik setelah tindakan
const resultEl = ref(null)
let seq = 0                      // abaikan respons usang bila operator memilih tag lain dengan cepat

const cleanUid = raw => String(raw ?? '').replace(/[\s:-]/g, '').toUpperCase()

async function check(raw = uidInput.value, { scroll = true } = {}) {
    const uid = cleanUid(raw)
    lookupError.value = ''
    if (uid.length < 4) { lookupError.value = t('rfid.tagmgr.uid_short'); return }
    uidInput.value = uid

    const mine = ++seq
    looking.value = true
    const res = await rfidApi.tagLookup(uid)
    if (mine !== seq) return
    looking.value = false

    if (!res.ok) { looked.value = null; lookupError.value = apiText(res); return }
    looked.value = res.data
    if (scroll) {
        await nextTick()
        const calm = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
        resultEl.value?.scrollIntoView?.({ behavior: calm ? 'auto' : 'smooth', block: 'nearest' })
    }
}

function pick(uid) { notice.value = null; check(uid) }

function clear() { seq++; looked.value = null; looking.value = false; lookupError.value = ''; notice.value = null; uidInput.value = '' }

// ─── Tindakan ─────────────────────────────────────────────────────────────────
const dialogOpen = ref(false)
const dialogMode = ref('release')
function openAction(mode) { dialogMode.value = mode; dialogOpen.value = true }

async function onDone({ mode, uid, data }) {
    notice.value = {
        type: 'success',
        text: mode === 'reset'
            ? t(data.rewound ? 'rfid.tagmgr.reset_done_rewind' : 'rfid.tagmgr.reset_done', { n: data.voided, uid: formatUidShort(uid) })
            : t('rfid.tagmgr.released_done', { uid: formatUidShort(uid), batch: data.batch_code }),
    }
    await Promise.all([check(uid, { scroll: false }), loadRecent()])
}

const state = computed(() => looked.value?.state ?? null)
const batch = computed(() => looked.value?.batch ?? null)
const plan = computed(() => looked.value?.reset ?? null)
const canRelease = computed(() => props.canOperate && ['bound', 'archived'].includes(state.value))

// Alasan reset uji tidak tersedia (null = boleh). Selalu diberi penjelasan agar tombol mati tidak membingungkan.
const resetBlocked = computed(() => {
    if (state.value !== 'bound') return null
    if (!props.canManage) return t('rfid.tagmgr.reset_admin_only')
    if (!props.resetEnabled || !plan.value?.enabled) return t('rfid.tagmgr.reset_disabled_note')
    if (plan.value.blocked) return t(`rfid.err.${plan.value.blocked}`)
    if (!plan.value.records) return t('rfid.tagmgr.reset_nothing')
    return null
})

onMounted(() => {
    poll.start()
    if (props.initialUid) check(props.initialUid, { scroll: false })
})
</script>

<template>
    <section class="space-y-5" :aria-label="t('rfid.tagmgr.title')">
        <div>
            <h2 class="text-xl font-semibold">{{ t('rfid.tagmgr.title') }}</h2>
            <p class="mt-1 max-w-3xl text-sm text-muted-foreground">{{ t('rfid.tagmgr.intro') }}</p>
        </div>

        <!-- grid-cols-1 = minmax(0,1fr): kolom boleh menyempit sehingga UID/kode batch panjang terpotong rapi, bukan melebarkan halaman -->
        <div class="grid grid-cols-1 gap-5" :class="field ? '' : 'lg:grid-cols-2'">
            <!-- Cek tag + hasil (di atas pada layar kecil agar hasil langsung terlihat setelah memilih tag) -->
            <div class="space-y-4">
                <form class="space-y-2 rounded-xl border border-border bg-card p-4 sm:p-5" @submit.prevent="check()">
                    <label for="tagmgr-uid" class="block text-sm font-semibold">{{ t('rfid.tagmgr.lookup_label') }}</label>
                    <div class="flex gap-2">
                        <input id="tagmgr-uid" v-model="uidInput" type="text" inputmode="text" autocomplete="off" autocapitalize="characters" spellcheck="false"
                               :placeholder="t('rfid.tagmgr.uid_placeholder')" :aria-invalid="lookupError ? 'true' : undefined"
                               class="min-h-[48px] min-w-0 flex-1 rounded-lg border border-input bg-background px-3 font-mono text-base" />
                        <button type="submit" :disabled="looking" class="inline-flex min-h-[48px] items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60">
                            <AppIcon :name="looking ? 'loader' : 'scan-search'" :size="18" :spin="looking" />{{ looking ? t('rfid.tagmgr.checking') : t('rfid.tagmgr.check') }}
                        </button>
                    </div>
                    <p class="text-sm text-muted-foreground">{{ t('rfid.tagmgr.lookup_hint') }}</p>
                    <p v-if="lookupError" role="alert" class="text-sm font-medium text-danger">{{ lookupError }}</p>
                </form>

                <ResultBanner v-if="notice" :type="notice.type" :title="notice.text" />

                <div ref="resultEl" aria-live="polite">
                    <article v-if="looked" class="space-y-4 rounded-xl border border-border bg-card p-4 sm:p-5">
                        <header class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="break-all font-mono text-base font-semibold" :title="looked.uid">{{ formatUidGrouped(looked.uid) }}</p>
                                <p v-if="state === 'free'" class="mt-1 text-sm text-muted-foreground">{{ t('rfid.tagmgr.free_desc') }}</p>
                                <p v-else-if="state === 'foreign'" class="mt-1 text-sm text-muted-foreground">{{ t('rfid.tagmgr.foreign_desc') }}</p>
                                <p v-else-if="state === 'archived'" class="mt-1 text-sm text-warning">{{ t('rfid.tagmgr.archived_desc') }}</p>
                            </div>
                            <span class="inline-flex flex-shrink-0 items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold" :class="stateOf(state).tone">
                                <AppIcon :name="stateOf(state).icon" :size="14" />{{ t(`rfid.tagmgr.state_${state}`) }}
                            </span>
                        </header>

                        <!-- Rincian batch pemilik tag -->
                        <dl v-if="batch" class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
                            <dt class="text-muted-foreground">{{ t('rfid.tagmgr.batch') }}</dt>
                            <dd class="text-right">
                                <Link v-if="!batch.archived" :href="`/company/batches/${batch.id}`" class="inline-flex items-center gap-1 font-mono font-semibold text-primary underline-offset-2 hover:underline">
                                    {{ batch.batch_code }}<AppIcon name="external-link" :size="14" :label="t('rfid.tagmgr.open_batch')" />
                                </Link>
                                <span v-else class="font-mono font-semibold">{{ batch.batch_code }}</span>
                            </dd>
                            <dt class="text-muted-foreground">{{ t('rfid.tagmgr.product') }}</dt>
                            <dd class="text-right">{{ [batch.product_code, batch.product_name].filter(Boolean).join(' · ') || '—' }}</dd>
                            <dt class="text-muted-foreground">{{ t('rfid.tagmgr.position') }}</dt>
                            <dd class="flex flex-wrap items-center justify-end gap-2">
                                <span>{{ stageName(batch.stage) }}</span><StatusBadge :status="batch.status" size="xs" />
                            </dd>
                            <dt class="text-muted-foreground">{{ t('rfid.tagmgr.scans') }}</dt>
                            <dd class="text-right tabular-nums">{{ looked.scans }}</dd>
                            <template v-if="looked.last_scan_at">
                                <dt class="text-muted-foreground">{{ t('rfid.tagmgr.last_scan') }}</dt>
                                <dd class="text-right">{{ rel(looked.last_scan_at) }}</dd>
                            </template>
                        </dl>

                        <!-- Tindakan: selalu terlihat; bila tidak tersedia, alasannya dijelaskan -->
                        <div v-if="state === 'free' && canOperate" class="border-t border-border pt-4">
                            <button type="button" class="inline-flex min-h-[48px] items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90" @click="emit('register-uid', looked.uid)">
                                <AppIcon name="tag" :size="18" />{{ t('rfid.tagmgr.register_this') }}
                            </button>
                        </div>

                        <div v-if="batch" class="space-y-3 border-t border-border pt-4">
                            <h3 class="text-sm font-semibold">{{ t('rfid.tagmgr.actions') }}</h3>

                            <div v-if="state === 'bound'" class="space-y-1.5">
                                <button type="button" :disabled="!!resetBlocked" class="inline-flex min-h-[48px] w-full items-center justify-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto" @click="openAction('reset')">
                                    <AppIcon name="rotate-ccw" :size="18" />{{ t('rfid.tagmgr.reset') }}
                                </button>
                                <p class="text-sm" :class="resetBlocked ? 'font-medium text-warning' : 'text-muted-foreground'">{{ resetBlocked || t('rfid.tagmgr.reset_hint') }}</p>
                            </div>

                            <div v-if="canRelease" class="space-y-1.5">
                                <button type="button" class="inline-flex min-h-[48px] w-full items-center justify-center gap-2 rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent sm:w-auto" @click="openAction('release')">
                                    <AppIcon name="unlink" :size="18" />{{ t('rfid.tagmgr.release') }}
                                </button>
                                <p class="text-sm text-muted-foreground">{{ t('rfid.tagmgr.release_hint') }}</p>
                            </div>
                        </div>

                        <!-- Riwayat singkat tag ini (lintas batch) -->
                        <div class="border-t border-border pt-4">
                            <h3 class="mb-2 text-sm font-semibold">{{ t('rfid.tagmgr.history') }}</h3>
                            <ul v-if="looked.history?.length" class="space-y-1.5 text-sm">
                                <li v-for="h in looked.history" :key="h.id" class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                    <span class="font-semibold" :class="h.event === 'released' ? 'text-warning' : 'text-success'">{{ t(`rfid.tagmgr.ev_${h.event}`) }}</span>
                                    <span v-if="h.batch_code" class="text-muted-foreground">{{ t('rfid.tagmgr.in_batch', { batch: h.batch_code }) }}</span>
                                    <span v-if="h.reason" class="text-muted-foreground">· {{ t(`rfid.tag.reason_${h.reason}`) }}</span>
                                    <span class="text-muted-foreground">· {{ formatDateTime(h.at, lang) }}<template v-if="h.by"> · {{ h.by }}</template></span>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-muted-foreground">{{ t('rfid.tagmgr.history_empty') }}</p>
                        </div>

                        <div class="flex justify-end">
                            <button type="button" class="min-h-[44px] rounded-lg px-3 text-sm font-medium text-muted-foreground hover:bg-accent hover:text-foreground" @click="clear">{{ t('rfid.tagmgr.clear') }}</button>
                        </div>
                    </article>
                </div>
            </div>

            <!-- Tag terbaru dibaca: tempelkan tag pada reader, lalu pilih barisnya -->
            <div class="space-y-3 lg:order-first">
                <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                    <h3 class="text-base font-semibold">{{ t('rfid.tagmgr.recent_title') }}</h3>
                    <span class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"><AppIcon name="refresh-cw" :size="12" />{{ t('rfid.tagmgr.recent_live') }}</span>
                </div>

                <p v-if="recentFailed" role="alert" class="text-sm font-medium text-warning">{{ t('rfid.tagmgr.recent_error') }}</p>

                <ul v-if="recent?.length" class="space-y-2">
                    <li v-for="row in recent" :key="row.uid">
                        <button type="button" class="flex min-h-[64px] w-full items-center gap-3 rounded-xl border bg-card p-3 text-left hover:bg-accent"
                                :class="looked?.uid === row.uid ? 'border-primary ring-2 ring-primary/30' : 'border-border'"
                                :aria-pressed="looked?.uid === row.uid" @click="pick(row.uid)">
                            <span class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg" :class="stateOf(row.state).tone"><AppIcon :name="stateOf(row.state).icon" :size="20" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-mono text-sm font-semibold" :title="row.uid">
                                    <span class="hidden sm:inline">{{ formatUidGrouped(row.uid) }}</span><span class="sm:hidden">{{ formatUidShort(row.uid) }}</span>
                                </span>
                                <span class="block truncate text-sm text-muted-foreground">
                                    <template v-if="row.batch">{{ row.batch.batch_code }} · {{ stageName(row.batch.stage) }}</template>
                                    <template v-else>{{ t(`rfid.tagmgr.state_${row.state}`) }}</template>
                                </span>
                            </span>
                            <span class="flex-shrink-0 text-right text-xs text-muted-foreground">
                                <span class="block tabular-nums">{{ t('rfid.tagmgr.reads', { n: row.reads }) }}</span>
                                <span class="block">{{ rel(row.last_read_at) }}</span>
                            </span>
                        </button>
                    </li>
                </ul>

                <EmptyState v-else-if="recent" icon="nfc" :title="t('rfid.tagmgr.recent_empty')" size="sm" />
                <div v-else class="space-y-2" aria-hidden="true"><div v-for="i in 3" :key="i" class="h-[64px] animate-pulse rounded-xl bg-muted" /></div>
            </div>
        </div>

        <TagActionDialog v-model:open="dialogOpen" :mode="dialogMode" :tag="looked" :stages="stages" @done="onDone" />
    </section>
</template>
