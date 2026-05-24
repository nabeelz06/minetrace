<script setup>
/**
 * RfidPanel.vue — Panel Manajemen Hardware RFID di MINETRA.
 *
 * FITUR:
 * ✅ Daftar semua user + status kartu RFID (ada/tidak, hadir/tidak)
 * ✅ Mulai sesi registrasi kartu baru ke user tertentu (POST /api/rfid/pending)
 * ✅ Hapus kartu dari user (DELETE /api/rfid/users/{id}/card)
 * ✅ Live countdown saat sesi registrasi aktif
 * ✅ Tabel operator hadir saat ini (via RFID login)
 * ✅ Link ke standalone Control Panel HTML untuk kontrol ESP32
 * ✅ Bilingual ID/EN
 * ✅ Akses link ke standalone HTML panel (buka tab baru)
 *
 * ARSITEKTUR:
 *   Halaman ini = manajemen kartu dari sisi MINETRA web
 *   Kontrol ESP32 (set mode, live preview LCD) = standalone HTML panel
 */
import { ref, computed, onUnmounted } from 'vue'
import { Head, Link, router }         from '@inertiajs/vue3'
import AppLayout                       from '@/Layouts/AppLayout.vue'
import EmptyState                      from '@/components/shared/EmptyState.vue'
import { useI18n }                     from '@/composables/core/useI18n.js'

const { lang } = useI18n()

const props = defineProps({
    rfid_users:        { type: Array,  default: () => [] },
    present_operators: { type: Array,  default: () => [] },
    pending:           { type: Object, default: null },
    stats:             Object,
    user_role:         String,
    current_time:      String,
})

// ─── Role capabilities ─────────────────────────────────────────────────────
const canManage = computed(() => ['company_admin', 'operator_lab_station'].includes(props.user_role))

// ─── Tab active ────────────────────────────────────────────────────────────
const activeTab = ref('cards')  // 'cards' | 'present'

// ─── Search ────────────────────────────────────────────────────────────────
const search = ref('')
const filteredUsers = computed(() => {
    if (!search.value) return props.rfid_users
    const q = search.value.toLowerCase()
    return props.rfid_users.filter(u =>
        u.name.toLowerCase().includes(q) ||
        u.email.toLowerCase().includes(q) ||
        (u.rfid_uid ?? '').toLowerCase().includes(q)
    )
})

// ─── Pending Registration Session ──────────────────────────────────────────
const selectedUserId   = ref('')
const isPending        = ref(!!props.pending)
const pendingUserName  = ref(props.pending?.user_name ?? '')
const countdown        = ref(props.pending ? 90 : 0)
const isStarting       = ref(false)
const isCancelling     = ref(false)
let countdownTimer     = null

if (props.pending) startCountdown()

function startCountdown() {
    if (countdownTimer) clearInterval(countdownTimer)
    countdown.value = 90
    countdownTimer  = setInterval(() => {
        countdown.value--
        if (countdown.value <= 0) {
            clearInterval(countdownTimer)
            isPending.value       = false
            pendingUserName.value = ''
            countdown.value       = 0
            // Refresh page data setelah expired
            router.reload({ only: ['pending', 'rfid_users', 'stats'] })
        }
    }, 1000)
}

// ─── Mulai registrasi kartu ────────────────────────────────────────────────
async function startRegistration() {
    if (!selectedUserId.value) {
        toast('Pilih user terlebih dahulu.', 'error')
        return
    }

    isStarting.value = true

    try {
        const response = await fetch('/api/rfid/pending', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':  document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Accept':        'application/json',
            },
            body: JSON.stringify({ user_id: parseInt(selectedUserId.value) }),
        })

        const data = await response.json()

        if (response.ok && data.status === 'success') {
            const user = props.rfid_users.find(u => u.id == selectedUserId.value)
            isPending.value       = true
            pendingUserName.value = user?.name ?? data.user_name ?? ''
            toast(data.message, 'success')
            startCountdown()
        } else {
            toast(data.message ?? 'Gagal memulai sesi.', 'error')
        }
    } catch (err) {
        toast('Tidak dapat terhubung ke server.', 'error')
    } finally {
        isStarting.value = false
    }
}

// ─── Batalkan sesi registrasi ──────────────────────────────────────────────
async function cancelRegistration() {
    isCancelling.value = true
    clearInterval(countdownTimer)

    try {
        await fetch('/api/rfid/pending', {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Accept':       'application/json',
            },
        })
        isPending.value       = false
        pendingUserName.value = ''
        countdown.value       = 0
        toast(lang.value === 'en' ? 'Registration session cancelled.' : 'Sesi registrasi dibatalkan.', 'info')
        router.reload({ only: ['pending', 'rfid_users', 'stats'] })
    } catch {
        toast('Gagal membatalkan sesi.', 'error')
    } finally {
        isCancelling.value = false
    }
}

// ─── Hapus kartu dari user ─────────────────────────────────────────────────
const removingUserId = ref(null)

async function removeCard(user) {
    if (!confirm(lang.value === 'en'
        ? `Remove RFID card from ${user.name}? This action cannot be undone.`
        : `Hapus kartu RFID dari ${user.name}? Tindakan ini tidak dapat dibatalkan.`)) return

    removingUserId.value = user.id

    try {
        const response = await fetch(`/api/rfid/users/${user.id}/card`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Accept':       'application/json',
            },
        })
        const data = await response.json()
        toast(data.message, data.status === 'success' ? 'success' : 'error')
        if (data.status === 'success') {
            router.reload({ only: ['rfid_users', 'stats', 'present_operators'] })
        }
    } catch {
        toast('Gagal menghapus kartu.', 'error')
    } finally {
        removingUserId.value = null
    }
}

// ─── Toast ─────────────────────────────────────────────────────────────────
const toastMsg  = ref('')
const toastType = ref('info')
const toastShow = ref(false)
let toastTimer  = null

function toast(msg, type = 'info') {
    toastMsg.value  = msg
    toastType.value = type
    toastShow.value = true
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => { toastShow.value = false }, 3500)
}

// ─── Cleanup ───────────────────────────────────────────────────────────────
onUnmounted(() => {
    clearInterval(countdownTimer)
    clearTimeout(toastTimer)
})

// ─── Helpers ───────────────────────────────────────────────────────────────
const countdownPct = computed(() => Math.round((countdown.value / 90) * 100))

function getRoleColor(role) {
    const m = {
        operator_wet:        'bg-cyan-100 dark:bg-cyan-900/30 text-cyan-700 dark:text-cyan-300',
        operator_dry:        'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300',
        operator_warehouse:  'bg-teal-100 dark:bg-teal-900/30 text-teal-700 dark:text-teal-300',
        operator_lab_station:'bg-violet-100 dark:bg-violet-900/30 text-violet-700 dark:text-violet-300',
        operator_lab_ltj:    'bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300',
        company_admin:       'bg-primary/10 text-primary',
        company_auditor:     'bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-300',
    }
    return m[role] ?? 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400'
}
</script>

<template>
<AppLayout>
    <Head :title="lang === 'en' ? 'RFID Hardware Panel' : 'Panel Hardware RFID'"/>
    <template #header>{{ lang === 'en' ? 'RFID Hardware Panel' : 'Panel Hardware RFID' }}</template>

    <!-- Toast -->
    <Transition name="toast">
        <div v-if="toastShow"
             class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 px-5 py-2.5 rounded-xl border text-sm font-semibold shadow-xl"
             :class="{
                 'bg-emerald-50 dark:bg-emerald-950/90 border-emerald-400 text-emerald-700 dark:text-emerald-300': toastType === 'success',
                 'bg-red-50 dark:bg-red-950/90 border-red-400 text-red-700 dark:text-red-300': toastType === 'error',
                 'bg-card border-border text-foreground': toastType === 'info',
             }">
            {{ toastMsg }}
        </div>
    </Transition>

    <div class="space-y-5">

        <!-- ══════════════════════════════════════════════════════════
             HEADER
        ══════════════════════════════════════════════════════════ -->
        <div class="flex items-start justify-between gap-4 animate-fade-in">
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-2xl">📡</span>
                    <h1 class="font-heading text-xl font-bold text-foreground">
                        {{ lang === 'en' ? 'RFID Hardware Integration' : 'Integrasi Hardware RFID' }}
                    </h1>
                </div>
                <p class="text-sm text-muted-foreground mt-0.5">
                    {{ lang === 'en'
                        ? 'Manage RFID cards for operators. Use the standalone control panel to communicate with ESP32.'
                        : 'Kelola kartu RFID operator. Gunakan panel kontrol standalone untuk komunikasi dengan ESP32.' }}
                </p>
            </div>
            <!-- Timestamp + link ke standalone panel -->
            <div class="flex-shrink-0 flex flex-col items-end gap-2">
                <div class="rounded-xl border border-border bg-card px-4 py-2 text-right">
                    <p class="text-[10px] text-muted-foreground uppercase tracking-wider">Waktu Sistem</p>
                    <p class="font-mono text-xs font-bold text-foreground">{{ current_time }}</p>
                </div>
                <a href="/rfid-panel.html" target="_blank"
                   class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-bold border border-primary/30 text-primary hover:bg-primary/5 active:scale-[0.97] transition-all">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                    </svg>
                    {{ lang === 'en' ? 'Open ESP32 Control Panel' : 'Buka Panel Kontrol ESP32' }}
                </a>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             KPI STATS
        ══════════════════════════════════════════════════════════ -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div v-for="(item, i) in [
                { label_id:'Total User',          label_en:'Total Users',        value: stats?.total_users,      icon:'👥', color:'text-foreground' },
                { label_id:'Kartu Terdaftar',     label_en:'Registered Cards',   value: stats?.registered_count, icon:'🪪', color:'text-primary' },
                { label_id:'Operator Hadir',      label_en:'Present Operators',  value: stats?.present_count,    icon:'✅', color:'text-emerald-600 dark:text-emerald-400' },
                { label_id:'Sesi Aktif',          label_en:'Active Session',     value: stats?.pending_count,    icon:'⏳', color:'text-amber-600 dark:text-amber-400' },
            ]" :key="item.label_id"
                 class="rounded-2xl border border-border bg-card p-4 animate-fade-in"
                 :style="`animation-delay: ${i*50}ms`">
                <div class="flex items-start justify-between mb-2">
                    <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider leading-tight">
                        {{ lang === 'en' ? item.label_en : item.label_id }}
                    </p>
                    <span class="text-xl">{{ item.icon }}</span>
                </div>
                <p class="font-heading text-3xl font-bold tabular-nums" :class="item.color">{{ item.value ?? 0 }}</p>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             PENDING REGISTRATION BANNER
             Muncul saat ada sesi registrasi kartu yang aktif
        ══════════════════════════════════════════════════════════ -->
        <Transition name="slide-down">
            <div v-if="isPending"
                 class="rounded-2xl border-2 border-amber-400 dark:border-amber-600 p-5 animate-fade-in"
                 style="background: rgba(245,158,11,0.06)">
                <div class="flex items-start gap-4">
                    <!-- Pulsing icon -->
                    <div class="w-12 h-12 rounded-xl bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center flex-shrink-0">
                        <span class="text-2xl animate-pulse">🪪</span>
                    </div>

                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold text-amber-700 dark:text-amber-400">
                            {{ lang === 'en' ? '⚡ Registration Session Active' : '⚡ Sesi Registrasi Aktif' }}
                        </p>
                        <p class="text-xs text-muted-foreground mt-1">
                            {{ lang === 'en'
                                ? `Waiting for card tap for: ${pendingUserName}`
                                : `Menunggu tempel kartu untuk: ${pendingUserName}` }}
                        </p>
                        <p class="text-xs text-muted-foreground mt-0.5">
                            {{ lang === 'en'
                                ? 'Switch the ESP32 to REGISTER mode, then tap the card now.'
                                : 'Ubah ESP32 ke mode REGISTER di Control Panel, kemudian tempelkan kartu sekarang.' }}
                        </p>

                        <!-- Countdown bar -->
                        <div class="mt-3 space-y-1">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] font-mono text-muted-foreground uppercase tracking-wider">
                                    {{ lang === 'en' ? 'Timeout' : 'Batas Waktu' }}
                                </span>
                                <span class="text-xs font-black font-mono"
                                      :class="countdown <= 15 ? 'text-red-500' : 'text-amber-600 dark:text-amber-400'">
                                    {{ countdown }}s
                                </span>
                            </div>
                            <div class="h-2 rounded-full bg-amber-100 dark:bg-amber-900/30 overflow-hidden">
                                <div class="h-full rounded-full transition-all duration-1000"
                                     :class="countdown <= 15 ? 'bg-red-500' : 'bg-amber-400'"
                                     :style="`width: ${countdownPct}%`"/>
                            </div>
                        </div>
                    </div>

                    <!-- Cancel button -->
                    <button @click="cancelRegistration"
                            :disabled="isCancelling"
                            class="flex-shrink-0 px-3 py-2 rounded-xl border border-amber-400 text-xs font-bold text-amber-700 dark:text-amber-400 hover:bg-amber-100 dark:hover:bg-amber-900/30 active:scale-[0.97] disabled:opacity-60">
                        {{ lang === 'en' ? 'Cancel' : 'Batal' }}
                    </button>
                </div>
            </div>
        </Transition>

        <!-- ══════════════════════════════════════════════════════════
             REGISTER NEW CARD PANEL — Hanya untuk company_admin
        ══════════════════════════════════════════════════════════ -->
        <div v-if="canManage && !isPending"
             class="rounded-2xl border border-border bg-card p-5 animate-fade-in delay-100">
            <h3 class="font-heading text-sm font-bold text-foreground mb-1">
                🪪 {{ lang === 'en' ? 'Register New RFID Card' : 'Daftarkan Kartu RFID Baru' }}
            </h3>
            <p class="text-xs text-muted-foreground mb-4">
                {{ lang === 'en'
                    ? 'Select a user, then tap a new RFID card on the ESP32 reader (REGISTER mode) within 90 seconds.'
                    : 'Pilih user, lalu tempelkan kartu RFID baru ke reader ESP32 (mode REGISTER) dalam 90 detik.' }}
            </p>

            <div class="flex items-center gap-3">
                <!-- Dropdown pilih user (yang belum punya kartu) -->
                <select v-model="selectedUserId"
                        class="flex-1 h-10 px-3 rounded-xl border border-border bg-background text-sm text-foreground outline-none focus:border-primary focus:ring-1 focus:ring-primary/20">
                    <option value="" disabled>
                        {{ lang === 'en' ? 'Select user without a card...' : 'Pilih user yang belum punya kartu...' }}
                    </option>
                    <option v-for="u in rfid_users.filter(u => !u.has_card)" :key="u.id" :value="u.id">
                        {{ u.name }} — {{ u.role }}
                    </option>
                </select>

                <button @click="startRegistration"
                        :disabled="!selectedUserId || isStarting"
                        class="flex items-center gap-2 px-4 h-10 rounded-xl text-sm font-bold text-white disabled:opacity-50 active:scale-[0.97]"
                        style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));">
                    <svg v-if="isStarting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <span class="text-base" v-else>🪪</span>
                    {{ isStarting
                        ? (lang === 'en' ? 'Starting...' : 'Memulai...')
                        : (lang === 'en' ? 'Start Registration' : 'Mulai Registrasi') }}
                </button>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             TABS: Kartu RFID / Operator Hadir
        ══════════════════════════════════════════════════════════ -->
        <div class="flex gap-1 p-1 rounded-2xl border border-border bg-muted/30 w-fit animate-fade-in delay-150">
            <button @click="activeTab = 'cards'"
                    class="px-4 py-2 rounded-xl text-xs font-semibold"
                    :style="activeTab === 'cards'
                        ? 'background: hsl(var(--card)); color: hsl(var(--foreground)); box-shadow: 0 1px 3px rgba(0,0,0,0.1);'
                        : 'color: hsl(var(--muted-foreground));'">
                🪪 {{ lang === 'en' ? 'RFID Cards' : 'Kartu RFID' }}
                <span class="ml-1 text-[9px] font-bold px-1.5 py-0.5 rounded-full text-white"
                      style="background: hsl(var(--primary))">{{ stats?.total_users ?? 0 }}</span>
            </button>
            <button @click="activeTab = 'present'"
                    class="px-4 py-2 rounded-xl text-xs font-semibold"
                    :style="activeTab === 'present'
                        ? 'background: hsl(var(--card)); color: hsl(var(--foreground)); box-shadow: 0 1px 3px rgba(0,0,0,0.1);'
                        : 'color: hsl(var(--muted-foreground));'">
                ✅ {{ lang === 'en' ? 'Present Now' : 'Hadir Sekarang' }}
                <span v-if="stats?.present_count > 0"
                      class="ml-1 text-[9px] font-bold px-1.5 py-0.5 rounded-full text-white bg-emerald-500">
                    {{ stats.present_count }}
                </span>
            </button>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             TAB 1: DAFTAR KARTU RFID
        ══════════════════════════════════════════════════════════ -->
        <div v-show="activeTab === 'cards'" class="space-y-4">
            <!-- Search -->
            <div class="relative max-w-xs">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-muted-foreground"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
                </svg>
                <input v-model="search" type="text"
                       :placeholder="lang === 'en' ? 'Search user or UID...' : 'Cari nama, email, atau UID...'"
                       class="w-full pl-9 pr-4 h-9 rounded-xl border border-border bg-card text-sm text-foreground outline-none focus:border-primary focus:ring-1 focus:ring-primary/20"/>
            </div>

            <!-- Table -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden">
                <EmptyState v-if="!filteredUsers.length"
                    icon="🪪"
                    :title="lang === 'en' ? 'No users found' : 'Tidak ada user ditemukan'"
                    :description="lang === 'en' ? 'Try a different search term.' : 'Coba kata kunci pencarian yang berbeda.'"
                    size="sm"
                />

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border bg-muted/30">
                                <th class="text-left px-5 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ lang === 'en' ? 'User' : 'User' }}
                                </th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ lang === 'en' ? 'Role' : 'Role' }}
                                </th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ lang === 'en' ? 'RFID UID' : 'UID Kartu' }}
                                </th>
                                <th class="text-center px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ lang === 'en' ? 'Status' : 'Status' }}
                                </th>
                                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ lang === 'en' ? 'Last Scan' : 'Scan Terakhir' }}
                                </th>
                                <th class="px-4 py-3 w-24"/>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="(user, i) in filteredUsers" :key="user.id"
                                class="group hover:bg-muted/20"
                                :style="`animation: fade-in 0.25s ease-out ${i*30}ms both`">
                                <!-- Name + Email -->
                                <td class="px-5 py-3.5">
                                    <p class="text-sm font-bold text-foreground">{{ user.name }}</p>
                                    <p class="text-[10px] text-muted-foreground">{{ user.email }}</p>
                                </td>

                                <!-- Role badge -->
                                <td class="px-4 py-3.5">
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded-md" :class="getRoleColor(user.role)">
                                        {{ user.role?.replace(/_/g, ' ') }}
                                    </span>
                                </td>

                                <!-- RFID UID -->
                                <td class="px-4 py-3.5">
                                    <span v-if="user.rfid_uid"
                                          class="font-mono text-xs font-bold text-amber-600 dark:text-amber-400">
                                        {{ user.rfid_uid }}
                                    </span>
                                    <span v-else class="text-xs text-muted-foreground italic">
                                        {{ lang === 'en' ? 'No card' : 'Belum punya kartu' }}
                                    </span>
                                </td>

                                <!-- Hadir/Tidak -->
                                <td class="px-4 py-3.5 text-center">
                                    <span v-if="!user.has_card"
                                          class="text-[10px] font-bold px-2 py-0.5 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-500">
                                        —
                                    </span>
                                    <span v-else-if="user.is_present"
                                          class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-lg bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300">
                                        <div class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"/>
                                        {{ lang === 'en' ? 'Present' : 'Hadir' }}
                                    </span>
                                    <span v-else
                                          class="text-[10px] font-bold px-2 py-0.5 rounded-lg bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400">
                                        {{ lang === 'en' ? 'Away' : 'Tidak Hadir' }}
                                    </span>
                                </td>

                                <!-- Last scan -->
                                <td class="px-4 py-3.5">
                                    <p v-if="user.last_scan_at" class="text-[11px] text-muted-foreground font-mono">
                                        {{ user.last_scan_diff }}
                                    </p>
                                    <p v-else class="text-[10px] text-muted-foreground">—</p>
                                </td>

                                <!-- Actions -->
                                <td class="px-4 py-3.5">
                                    <button v-if="user.has_card && canManage"
                                            @click="removeCard(user)"
                                            :disabled="removingUserId === user.id"
                                            class="opacity-0 group-hover:opacity-100 text-[10px] font-bold px-2.5 py-1.5 rounded-lg text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 hover:bg-red-100 dark:hover:bg-red-900/40 active:scale-[0.97] disabled:opacity-40 transition-all">
                                        {{ lang === 'en' ? 'Remove' : 'Hapus' }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             TAB 2: OPERATOR HADIR SAAT INI
        ══════════════════════════════════════════════════════════ -->
        <div v-show="activeTab === 'present'" class="space-y-4">
            <EmptyState v-if="!present_operators.length"
                icon="✅"
                :title="lang === 'en' ? 'No operators present' : 'Tidak ada operator hadir saat ini'"
                :description="lang === 'en'
                    ? 'Operators appear here after scanning their RFID card in LOGIN mode.'
                    : 'Operator akan muncul di sini setelah scan kartu RFID dalam mode LOGIN.'"
            />

            <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div v-for="(op, i) in present_operators" :key="op.id"
                     class="rounded-2xl border border-emerald-200 dark:border-emerald-800/40 p-4 animate-fade-in"
                     style="background: rgba(16,185,129,0.05)"
                     :style="`animation-delay: ${i*40}ms`">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center text-lg"
                             style="background: rgba(16,185,129,0.15)">👤</div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-foreground truncate">{{ op.name }}</p>
                            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded" :class="getRoleColor(op.role)">
                                {{ op.role?.replace(/_/g, ' ') }}
                            </span>
                        </div>
                        <div class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse flex-shrink-0"/>
                    </div>
                    <div class="mt-3 space-y-1">
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-muted-foreground">UID</span>
                            <span class="font-mono font-bold text-amber-600 dark:text-amber-400">{{ op.rfid_uid }}</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-muted-foreground">{{ lang === 'en' ? 'Last scan' : 'Scan terakhir' }}</span>
                            <span class="font-mono text-[11px] text-muted-foreground">{{ op.last_scan_wib }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════════════════
             INFO BOX — Arsitektur + Cara Kerja
        ══════════════════════════════════════════════════════════ -->
        <div class="rounded-2xl border border-border bg-card p-5 animate-fade-in delay-300">
            <h3 class="font-heading text-sm font-bold text-foreground mb-3">
                {{ lang === 'en' ? 'ℹ️ How It Works' : 'ℹ️ Cara Kerja Sistem RFID' }}
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div v-for="step in [
                    {
                        num: '1', icon: '🪪',
                        title_id: 'Daftarkan Kartu',   title_en: 'Register Card',
                        desc_id: 'Pilih user di atas → klik Mulai Registrasi → tempel kartu baru ke ESP32 (mode REGISTER) dalam 90 detik.',
                        desc_en: 'Select user above → click Start Registration → tap new card to ESP32 (REGISTER mode) within 90 seconds.',
                    },
                    {
                        num: '2', icon: '🔓',
                        title_id: 'Login Operator',    title_en: 'Operator Login',
                        desc_id: 'Operator tempel kartu ke ESP32 (mode LOGIN) → nama operator tampil di LCD → sistem catat kehadiran.',
                        desc_en: 'Operator taps card to ESP32 (LOGIN mode) → name shown on LCD → system records attendance.',
                    },
                    {
                        num: '3', icon: '🔒',
                        title_id: 'Logout Operator',   title_en: 'Operator Logout',
                        desc_id: 'Operator tempel kartu ke ESP32 (mode LOGOUT) → status berubah tidak hadir → waktu keluar tercatat.',
                        desc_en: 'Operator taps card to ESP32 (LOGOUT mode) → status changes to away → departure time recorded.',
                    },
                ]" :key="step.num"
                     class="flex items-start gap-3 p-3 rounded-xl border border-border bg-muted/20">
                    <div class="w-8 h-8 rounded-lg flex items-center justify-center text-sm flex-shrink-0"
                         style="background: hsl(var(--primary)/0.12)">
                        {{ step.icon }}
                    </div>
                    <div>
                        <p class="text-xs font-bold text-foreground">
                            {{ lang === 'en' ? step.title_en : step.title_id }}
                        </p>
                        <p class="text-[10px] text-muted-foreground mt-0.5 leading-relaxed">
                            {{ lang === 'en' ? step.desc_en : step.desc_id }}
                        </p>
                    </div>
                </div>
            </div>
            <!-- API endpoints reference -->
            <div class="mt-4 p-3 rounded-xl bg-muted/30 border border-border">
                <p class="text-[10px] font-semibold text-muted-foreground uppercase tracking-wider mb-2">
                    ESP32 API Endpoints (MINETRA Backend)
                </p>
                <div class="space-y-1 font-mono text-[11px]">
                    <div class="flex items-center gap-3">
                        <span class="text-emerald-600 dark:text-emerald-400 font-bold w-10">POST</span>
                        <span class="text-foreground">/api/rfid/register</span>
                        <span class="text-muted-foreground">→ Daftarkan kartu baru</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-emerald-600 dark:text-emerald-400 font-bold w-10">POST</span>
                        <span class="text-foreground">/api/rfid/login</span>
                        <span class="text-muted-foreground">→ Login operator via kartu</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-emerald-600 dark:text-emerald-400 font-bold w-10">POST</span>
                        <span class="text-foreground">/api/rfid/logout</span>
                        <span class="text-muted-foreground">→ Logout operator via kartu</span>
                    </div>
                </div>
                <p class="text-[10px] text-muted-foreground mt-2">
                    Request body: <span class="font-mono">{{ '{"uid":"A3B2C1D0","api_token":"..."}' }}</span>
                </p>
            </div>
        </div>

    </div>
</AppLayout>
</template>

<style scoped>
@keyframes fade-in { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
.animate-fade-in { animation: fade-in 0.28s ease-out both; }
.delay-100 { animation-delay: 100ms; }
.delay-150 { animation-delay: 150ms; }
.delay-300 { animation-delay: 300ms; }

.toast-enter-active { transition: all 0.2s ease; }
.toast-leave-active { transition: all 0.15s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateX(-50%) translateY(20px); }

.slide-down-enter-active { transition: all 0.25s ease-out; }
.slide-down-leave-active { transition: all 0.2s ease-in; }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-8px); }
</style>