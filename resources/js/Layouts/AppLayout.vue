<script setup>
/**
 * AppLayout — Root layout MINETRA pasca-login.
 *
 * ✅ Navigation labels: langsung di array (bukan via t() lookup)
 * ✅ Dark mode: CSS transition 200ms, no JS animations
 * ✅ Role-based navigation (11 roles, semua real routes)
 * ✅ Toast + Confirmation dialog system
 * ✅ Sidebar collapse + mobile overlay
 * ✅ i18n: lang ref reaktif, semua UI text via t() KECUALI nav labels
 * ✅ Emoji icon support untuk top-level nav items
 * ✅ Hardware RFID menu tersedia untuk operator roles
 */
import { ref, computed, watch, provide, onMounted } from 'vue'
import { Link, usePage, router }                    from '@inertiajs/vue3'
import { useI18n }                                  from '@/composables/core/useI18n.js'
import NavIcon                                      from '@/components/shared/NavIcon.vue'

const { t, lang, toggleLang, initFromPage } = useI18n()

onMounted(() => { initFromPage() })

// ─── Page props ───────────────────────────────────────────────────────────────
const page    = usePage()
const auth    = computed(() => page.props.auth)
const user    = computed(() => auth.value?.user)
const company = computed(() => auth.value?.company)
const can     = computed(() => page.props.can ?? {})
const flash   = computed(() => page.props.flash ?? {})

// ─── Role helpers ─────────────────────────────────────────────────────────────
const role           = computed(() => user.value?.role ?? '')
const isSuperAdmin   = computed(() => role.value === 'super_admin')
const isBIM          = computed(() => ['bim_admin','bim_viewer','government_auditor'].includes(role.value))
const isCompanyAdmin = computed(() => role.value === 'company_admin')
const isLabStation   = computed(() => role.value === 'operator_lab_station')
const isLabLtj       = computed(() => role.value === 'operator_lab_ltj')
const isWet          = computed(() => role.value === 'operator_wet')
const isDry          = computed(() => role.value === 'operator_dry')
const isWarehouse    = computed(() => role.value === 'operator_warehouse')
const isAuditor      = computed(() => role.value === 'company_auditor')

// ─── UI State ─────────────────────────────────────────────────────────────────
const sidebarOpen  = ref(true)
const mobileOpen   = ref(false)
const expandedMenu = ref(null)
const userDropOpen = ref(false)

// ─── Dark Mode ────────────────────────────────────────────────────────────────
const isDark = ref(false)

onMounted(() => {
    const serverPref = user.value?.dark_mode
    const localPref  = localStorage.getItem('minetra-theme')
    const sysDark    = window.matchMedia?.('(prefers-color-scheme: dark)').matches

    const next = (serverPref !== undefined && serverPref !== null)
        ? Boolean(serverPref)
        : localPref !== null ? localPref === 'dark' : sysDark

    isDark.value = next
    document.documentElement.classList.toggle('dark', next)
})

function toggleDark() {
    const next = !isDark.value
    isDark.value = next
    document.documentElement.classList.toggle('dark', next)
    localStorage.setItem('minetra-theme', next ? 'dark' : 'light')
    router.patch('/user/dark-mode', { dark_mode: next }, {
        preserveState: true, preserveScroll: true, replace: true,
    })
}

// ─── Navigation ───────────────────────────────────────────────────────────────
const processStages = computed(() => [
    { key: 'lab_kimia',     href: '/company/process/lab_kimia',     icon: '🧪', id: 'Lab Kimia',      en: 'Chemical Lab'   },
    { key: 'receiving',     href: '/company/process/receiving',     icon: '📥', id: 'Receiving Area', en: 'Receiving Area' },
    { key: 'wet_process',   href: '/company/process/wet_process',   icon: '💧', id: 'Wet Process',    en: 'Wet Process'    },
    { key: 'dry_process',   href: '/company/process/dry_process',   icon: '🔥', id: 'Dry Process',    en: 'Dry Process'    },
    { key: 'project_plant', href: '/company/process/project_plant', icon: '⚗️', id: 'Project Plant',  en: 'Project Plant'  },
    { key: 'warehouse',     href: '/company/process/warehouse',     icon: '🏭', id: 'LTJ Warehouse',  en: 'LTJ Warehouse'  },
])

const wetStages       = computed(() => processStages.value.filter(s => ['receiving','wet_process'].includes(s.key)))
const dryStages       = computed(() => processStages.value.filter(s => s.key === 'dry_process'))
const ltjStages       = computed(() => processStages.value.filter(s => s.key === 'project_plant'))
const warehouseStages = computed(() => processStages.value.filter(s => s.key === 'warehouse'))

// ─── Nav item RFID Hardware (reusable) ────────────────────────────────────────
// Diakses oleh semua operator company — bukan BIM/SuperAdmin
const navHardwareRfid = computed(() => ({
    key:  'hardware',
    href: '/company/hardware/rfid',
    icon: '📡',
    id:   'Hardware RFID',
    en:   'Hardware RFID',
}))

const navigation = computed(() => {
    // ── SUPER ADMIN ─────────────────────────────────────────────────
    if (isSuperAdmin.value) return [
        { key: 'dashboard', href: '/super-admin/dashboard', icon: 'grid',     id: 'Dashboard',         en: 'Dashboard' },
        { key: 'users',     href: '/super-admin/users',     icon: 'users',    id: 'Kelola User',        en: 'Manage Users' },
        { key: 'companies', href: '/super-admin/companies', icon: 'building', id: 'Kelola Perusahaan',  en: 'Companies' },
    ]

    // ── BIM ─────────────────────────────────────────────────────────
    if (isBIM.value) return [
        { key: 'bim_dashboard', href: '/bim/dashboard', icon: 'grid', id: 'Dashboard Monitoring', en: 'Monitoring Dashboard' },
    ]

    // ── COMPANY ADMIN ────────────────────────────────────────────────
    // Akses penuh: semua menu termasuk RFID Hardware
    if (isCompanyAdmin.value) return [
        { key: 'dashboard',  href: '/company/dashboard', icon: 'grid',     id: 'Dashboard',        en: 'Dashboard' },
        { key: 'batches',    href: '/company/batches',   icon: 'cube',     id: 'Manajemen Batch',  en: 'Batch Management' },
        { key: 'process',    href: '/company/process',   icon: 'workflow', id: 'Proses Produksi',  en: 'Production Process',
          children: processStages.value },
        { key: 'lab',        href: '/company/lab',       icon: 'flask',    id: 'Lab Analisis LTJ', en: 'LTJ Lab Analysis' },
        { key: 'exports',    href: '/company/exports',   icon: 'ship',     id: 'Ekspor',           en: 'Export' },
        { key: 'reports',    href: '/company/reports',   icon: 'document', id: 'Laporan',          en: 'Reports' },
        navHardwareRfid.value, // 📡 Hardware RFID
        { key: 'settings',   href: '/company/settings',  icon: 'settings', id: 'Pengaturan',       en: 'Settings' },
    ]

    // ── OPERATOR LAB STATION ─────────────────────────────────────────
    // Register tag RFID ke karung mineral di Lab Kimia
    if (isLabStation.value) return [
        { key: 'dashboard', href: '/company/dashboard', icon: 'grid',     id: 'Dashboard',        en: 'Dashboard' },
        { key: 'batches',   href: '/company/batches',   icon: 'cube',     id: 'Manajemen Batch',  en: 'Batch Management' },
        { key: 'process',   href: '/company/process',   icon: 'workflow', id: 'Proses Produksi',  en: 'Production Process',
          children: processStages.value.filter(s => s.key === 'lab_kimia') },
        navHardwareRfid.value, // 📡 Hardware RFID — untuk register tag ke karung
    ]

    // ── OPERATOR WET ─────────────────────────────────────────────────
    // Receiving + Wet Process, bisa register tag & scan karung masuk
    if (isWet.value) return [
        { key: 'dashboard', href: '/company/dashboard', icon: 'grid',     id: 'Dashboard',        en: 'Dashboard' },
        { key: 'batches',   href: '/company/batches',   icon: 'cube',     id: 'Daftar Batch',     en: 'Batch List' },
        { key: 'process',   href: '/company/process',   icon: 'workflow', id: 'Proses Produksi',  en: 'Production Process',
          children: wetStages.value },
        navHardwareRfid.value, // 📡 Hardware RFID
    ]

    // ── OPERATOR DRY ─────────────────────────────────────────────────
    // Dry Process — scan karung masuk/keluar dry section
    if (isDry.value) return [
        { key: 'dashboard', href: '/company/dashboard', icon: 'grid',     id: 'Dashboard',        en: 'Dashboard' },
        { key: 'batches',   href: '/company/batches',   icon: 'cube',     id: 'Daftar Batch',     en: 'Batch List' },
        { key: 'process',   href: '/company/process',   icon: 'workflow', id: 'Proses Produksi',  en: 'Production Process',
          children: dryStages.value },
        navHardwareRfid.value, // 📡 Hardware RFID
    ]

    // ── OPERATOR LAB LTJ ─────────────────────────────────────────────
    if (isLabLtj.value) return [
        { key: 'dashboard', href: '/company/dashboard', icon: 'grid',  id: 'Dashboard',        en: 'Dashboard' },
        { key: 'batches',   href: '/company/batches',   icon: 'cube',  id: 'Daftar Batch',     en: 'Batch List' },
        { key: 'lab',       href: '/company/lab',       icon: 'flask', id: 'Lab Analisis LTJ', en: 'LTJ Lab Analysis' },
        { key: 'process',   href: '/company/process',   icon: 'workflow', id: 'Proses Produksi', en: 'Production Process',
          children: ltjStages.value },
        navHardwareRfid.value, // 📡 Hardware RFID
    ]

    // ── OPERATOR WAREHOUSE ───────────────────────────────────────────
    // Gudang — scan karung masuk/keluar warehouse (LOGIN/LOGOUT mode)
    if (isWarehouse.value) return [
        { key: 'dashboard', href: '/company/dashboard', icon: 'grid',     id: 'Dashboard',       en: 'Dashboard' },
        { key: 'batches',   href: '/company/batches',   icon: 'cube',     id: 'Daftar Batch',    en: 'Batch List' },
        { key: 'process',   href: '/company/process',   icon: 'workflow', id: 'Proses Produksi', en: 'Production Process',
          children: warehouseStages.value },
        { key: 'exports',   href: '/company/exports',   icon: 'ship',     id: 'Ekspor',          en: 'Export' },
        navHardwareRfid.value, // 📡 Hardware RFID — scan karung masuk/keluar gudang
    ]

    // ── COMPANY AUDITOR ──────────────────────────────────────────────
    // Read-only view — bisa lihat aktivitas RFID tapi tidak bisa kontrol
    if (isAuditor.value) return [
        { key: 'dashboard', href: '/company/dashboard', icon: 'grid',     id: 'Dashboard',        en: 'Dashboard' },
        { key: 'batches',   href: '/company/batches',   icon: 'cube',     id: 'Daftar Batch',     en: 'Batch List' },
        { key: 'process',   href: '/company/process',   icon: 'workflow', id: 'Proses Produksi',  en: 'Production Process',
          children: processStages.value },
        { key: 'lab',       href: '/company/lab',       icon: 'flask',    id: 'Lab Analisis',     en: 'Lab Analysis' },
        { key: 'exports',   href: '/company/exports',   icon: 'ship',     id: 'Ekspor',           en: 'Export' },
        { key: 'reports',   href: '/company/reports',   icon: 'document', id: 'Laporan',          en: 'Reports' },
        navHardwareRfid.value, // 📡 Hardware RFID — view-only log aktivitas
    ]

    return []
})

// ─── Helper: label ID/EN ──────────────────────────────────────────────────────
function label(item) {
    return lang.value === 'en' ? item.en : item.id
}

// ─── Helper: cek apakah icon adalah emoji atau nama NavIcon (string a-z) ──────
// Jika icon hanya mengandung huruf lowercase + tanda strip → NavIcon component
// Jika icon mengandung karakter lain (emoji, dll) → render sebagai teks langsung
function isEmojiIcon(icon) {
    return icon && !/^[a-z-]+$/.test(icon)
}

// ─── Active route ─────────────────────────────────────────────────────────────
function isActive(href) {
    if (!href) return false
    const path = window.location.pathname
    const base = href.split('?')[0]
    if (base === '/company/batches') return path === base
    return path === base || path.startsWith(base + '/')
}

function isActiveChild(href) {
    if (!href) return false
    return window.location.pathname === href.split('?')[0] ||
           window.location.pathname.startsWith(href.split('?')[0] + '/')
}

function toggleMenu(key) {
    expandedMenu.value = expandedMenu.value === key ? null : key
}

// ─── User initials ────────────────────────────────────────────────────────────
const userInitials = computed(() =>
    (user.value?.name ?? 'U').split(' ').slice(0, 2).map(n => n[0]).join('').toUpperCase()
)

// ─── Role display label ───────────────────────────────────────────────────────
const roleLabel = computed(() => {
    const map = {
        id: {
            super_admin:           'Super Admin',
            bim_admin:             'Admin BIM',
            bim_viewer:            'Viewer BIM',
            government_auditor:    'Auditor ESDM',
            company_admin:         'Admin',
            operator_lab_station:  'Operator Lab Kimia',
            operator_lab_ltj:      'Analis Lab LTJ',
            operator_wet:          'Operator Wet Process',
            operator_dry:          'Operator Dry Process',
            operator_warehouse:    'Operator Gudang',
            company_auditor:       'Auditor Internal',
        },
        en: {
            super_admin:           'Super Admin',
            bim_admin:             'BIM Admin',
            bim_viewer:            'BIM Viewer',
            government_auditor:    'Gov. Auditor',
            company_admin:         'Admin',
            operator_lab_station:  'Lab Station Op.',
            operator_lab_ltj:      'LTJ Lab Analyst',
            operator_wet:          'Wet Process Op.',
            operator_dry:          'Dry Process Op.',
            operator_warehouse:    'Warehouse Op.',
            company_auditor:       'Internal Auditor',
        },
    }
    return map[lang.value]?.[role.value] ?? role.value.replace(/_/g, ' ')
})

// ─── TOAST SYSTEM ─────────────────────────────────────────────────────────────
const toasts = ref([])
let toastId  = 0

function addToast(type, message, duration = 4500) {
    if (!message) return
    const id = ++toastId
    toasts.value.push({ id, type, message })
    setTimeout(() => { toasts.value = toasts.value.filter(t => t.id !== id) }, duration)
}

watch(flash, (f) => {
    if (f?.success) addToast('success', f.success)
    if (f?.error)   addToast('error',   f.error, 6500)
    if (f?.warning) addToast('warning', f.warning, 5500)
    if (f?.info)    addToast('info',    f.info)
}, { immediate: true, deep: true })

const toastTheme = {
    success: { icon: '✅', accent: '#10b981', border: 'border-emerald-400 dark:border-emerald-600' },
    error:   { icon: '❌', accent: '#ef4444', border: 'border-red-400 dark:border-red-600' },
    warning: { icon: '⚠️',  accent: '#f59e0b', border: 'border-amber-400 dark:border-amber-600' },
    info:    { icon: 'ℹ️',  accent: '#3b82f6', border: 'border-blue-400 dark:border-blue-600' },
}

// ─── CONFIRMATION DIALOG ──────────────────────────────────────────────────────
const confirmState = ref({
    show: false, title: '', message: '', detail: null,
    confirmLabel: '', cancelLabel: '', type: 'default', resolve: null,
})

function openConfirm({ title, message = '', confirmLabel, cancelLabel, type = 'default', detail = null }) {
    return new Promise((resolve) => {
        confirmState.value = {
            show: true, title, message, detail, type, resolve,
            confirmLabel: confirmLabel ?? (lang.value === 'en' ? 'Confirm' : 'Konfirmasi'),
            cancelLabel:  cancelLabel  ?? (lang.value === 'en' ? 'Cancel'  : 'Batal'),
        }
    })
}

function resolveConfirm(val) {
    confirmState.value.resolve?.(val)
    confirmState.value.show = false
}

provide('confirm', openConfirm)
provide('toast',   addToast)
provide('can',     can)

// ─── Logout ───────────────────────────────────────────────────────────────────
async function logout() {
    const ok = await openConfirm({
        title:        lang.value === 'en' ? 'Sign Out' : 'Keluar dari Sistem',
        message:      lang.value === 'en' ? 'Are you sure you want to sign out?' : 'Apakah Anda yakin ingin keluar?',
        confirmLabel: lang.value === 'en' ? 'Sign Out' : 'Ya, Keluar',
        cancelLabel:  lang.value === 'en' ? 'Cancel'   : 'Batal',
        type:         'default',
    })
    if (ok) { userDropOpen.value = false; router.post('/logout') }
}

function handleOutsideClick(e) {
    if (!e.target.closest('[data-user-dropdown]')) userDropOpen.value = false
}
</script>

<template>
<div class="flex h-screen overflow-hidden bg-background font-sans" @click="handleOutsideClick">

    <!-- ════════════════════════════════════════════════════
         TOASTS
    ════════════════════════════════════════════════════ -->
    <div class="fixed top-4 right-4 z-[100] pointer-events-none w-[340px]">
        <TransitionGroup tag="div" name="toast" class="flex flex-col gap-2.5">
            <div
                v-for="toast in toasts" :key="toast.id"
                class="flex items-start rounded-2xl shadow-xl border overflow-hidden pointer-events-auto relative"
                :class="toastTheme[toast.type]?.border"
                style="background: hsl(var(--card));"
            >
                <div class="absolute left-0 inset-y-0 w-[3px] rounded-l-2xl"
                     :style="`background: ${toastTheme[toast.type]?.accent}`"/>
                <div class="flex items-center gap-3 px-4 py-3.5 pl-5 w-full">
                    <span class="text-base flex-shrink-0 leading-none">{{ toastTheme[toast.type]?.icon }}</span>
                    <p class="flex-1 text-sm font-semibold text-foreground leading-snug">{{ toast.message }}</p>
                    <button @click.stop="toasts = toasts.filter(t => t.id !== toast.id)"
                            class="flex-shrink-0 text-muted-foreground hover:text-foreground ml-1">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        </TransitionGroup>
    </div>

    <!-- ════════════════════════════════════════════════════
         CONFIRMATION DIALOG
    ════════════════════════════════════════════════════ -->
    <Transition name="modal">
        <div v-if="confirmState.show"
             class="fixed inset-0 z-[90] flex items-end sm:items-center justify-center p-4 sm:p-6"
             style="background: rgba(0,0,0,0.4); backdrop-filter: blur(6px);">
            <div class="w-full max-w-md rounded-2xl border border-border bg-card shadow-xl p-6 space-y-5 dialog-in">
                <div class="flex items-start gap-4">
                    <div class="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0"
                         :class="confirmState.type === 'danger' ? 'bg-red-100 dark:bg-red-900/30' : 'bg-primary/10'">
                        <svg v-if="confirmState.type === 'danger'"
                             class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                        </svg>
                        <svg v-else class="w-5 h-5 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <h3 class="font-heading text-base font-bold text-foreground leading-snug">{{ confirmState.title }}</h3>
                        <p v-if="confirmState.message" class="text-sm text-muted-foreground mt-1.5 leading-relaxed">{{ confirmState.message }}</p>
                        <p v-if="confirmState.detail"  class="text-xs text-muted-foreground mt-1 italic">{{ confirmState.detail }}</p>
                    </div>
                </div>
                <div class="flex gap-3">
                    <button @click="resolveConfirm(false)"
                            class="flex-1 h-10 rounded-xl text-sm font-semibold border border-border text-muted-foreground hover:bg-muted hover:text-foreground active:scale-[0.97]">
                        {{ confirmState.cancelLabel }}
                    </button>
                    <button @click="resolveConfirm(true)"
                            class="flex-[1.5] h-10 rounded-xl text-sm font-semibold text-white hover:shadow-lg hover:-translate-y-0.5 active:scale-[0.97]"
                            :class="confirmState.type === 'danger' ? 'bg-red-600 hover:bg-red-700' : ''"
                            :style="confirmState.type !== 'danger' ? 'background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));' : ''">
                        {{ confirmState.confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </Transition>

    <!-- ════════════════════════════════════════════════════
         SIDEBAR
    ════════════════════════════════════════════════════ -->
    <aside
        :class="[
            'flex flex-col flex-shrink-0 z-30 border-r border-sidebar-border overflow-hidden',
            sidebarOpen ? 'w-[220px]' : 'w-[56px]',
            mobileOpen  ? 'fixed inset-y-0 left-0 !w-[220px]' : 'hidden lg:flex',
        ]"
        style="background: hsl(var(--sidebar-background)); transition: width 280ms cubic-bezier(0.4,0,0.2,1);"
    >
        <!-- Logo -->
        <div class="flex items-center h-14 px-3 border-b border-sidebar-border flex-shrink-0 gap-2.5">
            <div class="w-8 h-8 rounded-xl flex-shrink-0 flex items-center justify-center shadow-sm"
                 style="background: linear-gradient(135deg, #3E5C74, #5ea1c2);">
                <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125s-8.25 1.847-8.25 4.125m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 2.625c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125"/>
                </svg>
            </div>
            <Transition name="st">
                <div v-if="sidebarOpen" class="flex-1 min-w-0 overflow-hidden">
                    <p class="font-heading text-xs font-black tracking-[0.2em] leading-tight"
                       style="color: hsl(var(--sidebar-foreground));">MINETRA</p>
                    <p class="text-[9px] tracking-widest leading-tight"
                       style="color: hsl(var(--sidebar-foreground)/0.3);">MINERAL TRACEABILITY</p>
                </div>
            </Transition>
            <button @click="sidebarOpen = !sidebarOpen"
                    class="w-6 h-6 rounded-lg flex items-center justify-center opacity-30 hover:opacity-70 flex-shrink-0"
                    :class="sidebarOpen ? 'ml-auto' : ''"
                    :title="sidebarOpen ? (lang==='en'?'Collapse':'Ciutkan') : (lang==='en'?'Expand':'Perbesar')">
                <svg class="w-3.5 h-3.5"
                     :style="`transition: transform 280ms ease; transform: rotate(${sidebarOpen?0:180}deg); color: hsl(var(--sidebar-foreground))`"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.75 19.5l-7.5-7.5 7.5-7.5m-6 15L5.25 12l7.5-7.5"/>
                </svg>
            </button>
        </div>

        <!-- Company badge -->
        <Transition name="st">
            <div v-if="sidebarOpen && company && !isSuperAdmin && !isBIM"
                 class="mx-2.5 mt-2.5 px-3 py-2 rounded-xl border"
                 style="border-color: hsl(var(--sidebar-border)); background: hsl(var(--sidebar-accent)/0.6);">
                <p class="text-[9px] uppercase tracking-widest font-semibold mb-0.5"
                   style="color: hsl(var(--sidebar-foreground)/0.32);">
                    {{ lang === 'en' ? 'Company' : 'Perusahaan' }}
                </p>
                <p class="text-xs font-bold truncate" style="color: hsl(var(--sidebar-foreground));">{{ company.name }}</p>
                <div class="flex items-center gap-1.5 mt-1">
                    <div class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"/>
                    <span class="text-[9px]" style="color: hsl(var(--sidebar-foreground)/0.32);">Online</span>
                </div>
            </div>
        </Transition>

        <!-- Role chip -->
        <Transition name="st">
            <div v-if="sidebarOpen" class="px-2.5 mt-1.5">
                <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-[9px] font-bold uppercase tracking-wider"
                      :style="{
                          'super_admin':          'background:rgba(147,51,234,0.15);color:#c084fc',
                          'bim_admin':            'background:rgba(239,68,68,0.12);color:#f87171',
                          'bim_viewer':           'background:rgba(239,68,68,0.08);color:#fca5a5',
                          'government_auditor':   'background:rgba(249,115,22,0.12);color:#fb923c',
                          'company_admin':        'background:rgba(62,92,116,0.18);color:#7cb4d4',
                          'operator_lab_station': 'background:rgba(16,185,129,0.12);color:#34d399',
                          'operator_lab_ltj':     'background:rgba(124,58,237,0.12);color:#a78bfa',
                          'operator_wet':         'background:rgba(6,182,212,0.12);color:#22d3ee',
                          'operator_dry':         'background:rgba(245,158,11,0.12);color:#fbbf24',
                          'operator_warehouse':   'background:rgba(20,184,166,0.12);color:#2dd4bf',
                          'company_auditor':      'background:rgba(107,114,128,0.12);color:#9ca3af',
                      }[role.value] ?? 'background:rgba(107,114,128,0.12);color:#9ca3af'">
                    {{ roleLabel }}
                </span>
            </div>
        </Transition>

        <!-- Nav items -->
        <nav class="flex-1 overflow-y-auto py-2 px-2 space-y-0.5 mt-1"
             style="scrollbar-width:thin;scrollbar-color:hsl(var(--sidebar-border)) transparent;">
            <template v-for="item in navigation" :key="item.key">

                <!-- Accordion parent (dengan children) -->
                <div v-if="item.children">
                    <button
                        @click="toggleMenu(item.key)"
                        :title="!sidebarOpen ? label(item) : ''"
                        class="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-xl text-xs font-semibold"
                        :style="expandedMenu===item.key
                            ? 'background:hsl(var(--sidebar-accent));color:hsl(var(--sidebar-foreground))'
                            : 'color:hsl(var(--sidebar-foreground)/0.5)'"
                        @mouseenter="e=>{if(expandedMenu!==item.key){e.currentTarget.style.background='hsl(var(--sidebar-accent)/0.55)';e.currentTarget.style.color='hsl(var(--sidebar-foreground))'}}"
                        @mouseleave="e=>{if(expandedMenu!==item.key){e.currentTarget.style.background='';e.currentTarget.style.color='hsl(var(--sidebar-foreground)/0.5)'}}"
                    >
                        <!-- Icon: emoji atau NavIcon -->
                        <span v-if="isEmojiIcon(item.icon)"
                              class="flex-shrink-0 w-4 h-4 flex items-center justify-center text-sm leading-none">
                            {{ item.icon }}
                        </span>
                        <NavIcon v-else :icon="item.icon" :active="expandedMenu===item.key" class="flex-shrink-0 w-4 h-4"/>

                        <Transition name="st">
                            <span v-if="sidebarOpen" class="flex-1 text-left truncate">{{ label(item) }}</span>
                        </Transition>
                        <Transition name="st">
                            <svg v-if="sidebarOpen" class="w-3 h-3 flex-shrink-0 opacity-45"
                                 :style="`transition:transform 200ms ease;transform:rotate(${expandedMenu===item.key?90:0}deg)`"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
                            </svg>
                        </Transition>
                    </button>

                    <!-- Children -->
                    <Transition name="accord">
                        <div v-if="expandedMenu===item.key && sidebarOpen"
                             class="mt-0.5 ml-3.5 border-l border-sidebar-border pl-2 space-y-0.5">
                            <Link v-for="child in item.children" :key="child.key"
                                  :href="child.href"
                                  class="flex items-center gap-2 px-2.5 py-1.5 rounded-lg text-xs font-medium"
                                  :style="isActiveChild(child.href)
                                      ? 'background:hsl(var(--primary)/0.18);color:hsl(var(--primary))'
                                      : 'color:hsl(var(--sidebar-foreground)/0.45)'"
                                  @mouseenter="e=>{if(!isActiveChild(child.href)){e.currentTarget.style.background='hsl(var(--sidebar-accent)/0.5)';e.currentTarget.style.color='hsl(var(--sidebar-foreground))'}}"
                                  @mouseleave="e=>{if(!isActiveChild(child.href)){e.currentTarget.style.background='';e.currentTarget.style.color='hsl(var(--sidebar-foreground)/0.45)'}}"
                            >
                                <span class="text-sm w-4 text-center flex-shrink-0">{{ child.icon }}</span>
                                <span class="truncate">{{ label(child) }}</span>
                            </Link>
                        </div>
                    </Transition>
                </div>

                <!-- Normal nav item (tanpa children) -->
                <Link v-else
                      :href="item.href"
                      :title="!sidebarOpen ? label(item) : ''"
                      class="flex items-center gap-2.5 px-2.5 py-2 rounded-xl text-xs font-semibold relative"
                      :style="isActive(item.href)
                          ? 'background:hsl(var(--primary)/0.18);color:hsl(var(--primary))'
                          : 'color:hsl(var(--sidebar-foreground)/0.5)'"
                      @mouseenter="e=>{if(!isActive(item.href)){e.currentTarget.style.background='hsl(var(--sidebar-accent)/0.55)';e.currentTarget.style.color='hsl(var(--sidebar-foreground))'}}"
                      @mouseleave="e=>{if(!isActive(item.href)){e.currentTarget.style.background='';e.currentTarget.style.color='hsl(var(--sidebar-foreground)/0.5)'}}"
                >
                    <!-- ✅ Fix emoji icon support untuk top-level nav items -->
                    <!-- Jika icon adalah emoji → render sebagai teks -->
                    <!-- Jika icon adalah nama SVG (huruf kecil) → gunakan NavIcon -->
                    <span v-if="isEmojiIcon(item.icon)"
                          class="flex-shrink-0 w-4 h-4 flex items-center justify-center text-sm leading-none">
                        {{ item.icon }}
                    </span>
                    <NavIcon v-else :icon="item.icon" :active="isActive(item.href)" class="flex-shrink-0 w-4 h-4"/>

                    <Transition name="st">
                        <span v-if="sidebarOpen" class="flex-1 truncate">{{ label(item) }}</span>
                    </Transition>

                    <!-- Active indicator saat sidebar collapsed -->
                    <div v-if="!sidebarOpen && isActive(item.href)"
                         class="absolute right-1.5 top-1/2 -translate-y-1/2 w-1 h-5 rounded-full bg-primary"/>
                </Link>
            </template>
        </nav>

        <!-- Bottom actions -->
        <div class="flex-shrink-0 border-t border-sidebar-border p-2 space-y-0.5">

            <!-- Dark mode toggle -->
            <button
                @click="toggleDark"
                :title="isDark ? (lang==='en'?'Light Mode':'Mode Terang') : (lang==='en'?'Dark Mode':'Mode Gelap')"
                class="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-xl text-xs font-semibold"
                style="color:hsl(var(--sidebar-foreground)/0.5)"
                @mouseenter="e=>{e.currentTarget.style.background='hsl(var(--sidebar-accent)/0.55)';e.currentTarget.style.color='hsl(var(--sidebar-foreground))'}"
                @mouseleave="e=>{e.currentTarget.style.background='';e.currentTarget.style.color='hsl(var(--sidebar-foreground)/0.5)'}"
            >
                <div class="w-9 h-5 flex-shrink-0 rounded-full border-2 flex items-center px-0.5 overflow-hidden"
                     :style="isDark
                         ? 'border-color:hsl(var(--primary));background:hsl(var(--primary)/0.2)'
                         : 'border-color:hsl(var(--sidebar-border));background:hsl(var(--sidebar-border)/0.35)'">
                    <div class="w-3.5 h-3.5 rounded-full flex items-center justify-center text-[8px] shadow-sm"
                         :style="`
                             transform: translateX(${isDark?16:0}px);
                             background: ${isDark?'hsl(var(--primary))':'#f59e0b'};
                             transition: transform 250ms cubic-bezier(0.4,0,0.2,1);
                             color: white;
                         `">
                        {{ isDark ? '🌙' : '☀' }}
                    </div>
                </div>
                <Transition name="st">
                    <span v-if="sidebarOpen" class="truncate">
                        {{ isDark ? (lang==='en'?'Light Mode':'Mode Terang') : (lang==='en'?'Dark Mode':'Mode Gelap') }}
                    </span>
                </Transition>
            </button>

            <!-- Language toggle -->
            <button
                @click="toggleLang"
                :title="lang==='id' ? 'Switch to English' : 'Ganti ke Indonesia'"
                class="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-xl text-xs font-semibold"
                style="color:hsl(var(--sidebar-foreground)/0.5)"
                @mouseenter="e=>{e.currentTarget.style.background='hsl(var(--sidebar-accent)/0.55)';e.currentTarget.style.color='hsl(var(--sidebar-foreground))'}"
                @mouseleave="e=>{e.currentTarget.style.background='';e.currentTarget.style.color='hsl(var(--sidebar-foreground)/0.5)'}"
            >
                <span class="text-sm w-4 text-center flex-shrink-0">{{ lang==='id' ? '🇮🇩' : '🇬🇧' }}</span>
                <Transition name="st">
                    <span v-if="sidebarOpen" class="truncate">
                        {{ lang==='id' ? 'Bahasa Indonesia' : 'English' }}
                    </span>
                </Transition>
            </button>

            <!-- User card -->
            <div data-user-dropdown class="relative">
                <button
                    @click.stop="userDropOpen = !userDropOpen"
                    class="w-full flex items-center gap-2.5 px-2.5 py-2 rounded-xl"
                    :title="!sidebarOpen ? user?.name : ''"
                    @mouseenter="e=>{e.currentTarget.style.background='hsl(var(--sidebar-accent)/0.55)'}"
                    @mouseleave="e=>{if(!userDropOpen)e.currentTarget.style.background=''}"
                >
                    <div class="w-7 h-7 rounded-lg flex items-center justify-center text-white text-[10px] font-bold flex-shrink-0"
                         style="background:linear-gradient(135deg,#3E5C74,#5ea1c2)">
                        {{ userInitials }}
                    </div>
                    <Transition name="st">
                        <div v-if="sidebarOpen" class="flex-1 min-w-0 text-left overflow-hidden">
                            <p class="text-xs font-semibold truncate leading-tight" style="color:hsl(var(--sidebar-foreground))">{{ user?.name }}</p>
                            <p class="text-[9px] truncate leading-tight opacity-35" style="color:hsl(var(--sidebar-foreground))">{{ user?.email }}</p>
                        </div>
                    </Transition>
                    <Transition name="st">
                        <svg v-if="sidebarOpen" class="w-3 h-3 flex-shrink-0 opacity-25"
                             :style="`transition:transform 200ms ease;transform:rotate(${userDropOpen?180:0}deg);color:hsl(var(--sidebar-foreground))`"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                        </svg>
                    </Transition>
                </button>

                <Transition name="drop-up">
                    <div v-if="userDropOpen"
                         class="absolute bottom-full left-0 right-0 mb-1.5 mx-0.5 rounded-2xl border border-border shadow-xl overflow-hidden"
                         style="background:hsl(var(--popover));z-index:55">
                        <div class="p-3 border-b border-border">
                            <p class="text-xs font-bold text-foreground truncate">{{ user?.name }}</p>
                            <p class="text-xs text-muted-foreground truncate">{{ user?.email }}</p>
                            <p v-if="user?.department || user?.employee_id"
                               class="text-xs text-muted-foreground mt-0.5 truncate">
                                {{ [user?.department, user?.employee_id].filter(Boolean).join(' · ') }}
                            </p>
                        </div>
                        <div class="p-1.5">
                            <button
                                @click="userDropOpen=false; logout()"
                                class="w-full flex items-center gap-2 px-3 py-2 rounded-xl text-xs font-semibold text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20"
                            >
                                <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
                                </svg>
                                {{ lang==='en' ? 'Sign Out' : 'Keluar' }}
                            </button>
                        </div>
                    </div>
                </Transition>
            </div>
        </div>
    </aside>

    <!-- ════════════════════════════════════════════════════
         MAIN CONTENT
    ════════════════════════════════════════════════════ -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">

        <!-- Mobile header -->
        <div class="lg:hidden flex items-center h-14 px-4 border-b border-border bg-card flex-shrink-0 gap-3">
            <button @click="mobileOpen = !mobileOpen"
                    class="w-8 h-8 rounded-xl flex items-center justify-center border border-border text-muted-foreground hover:bg-muted">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                </svg>
            </button>
            <span class="font-heading text-sm font-black tracking-widest text-foreground">MINETRA</span>
        </div>

        <!-- Mobile overlay -->
        <Transition name="fade">
            <div v-if="mobileOpen" class="lg:hidden fixed inset-0 bg-black/50 z-20" @click="mobileOpen=false"/>
        </Transition>

        <!-- Desktop header -->
        <header class="hidden lg:flex items-center h-14 px-6 border-b border-border bg-card flex-shrink-0 gap-4">
            <div class="flex-1 min-w-0">
                <p class="font-heading text-sm font-semibold text-foreground truncate">
                    <slot name="header">Dashboard</slot>
                </p>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
                <Link v-if="can.create_batch"
                      href="/company/batches/create"
                      class="hidden xl:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-bold text-white hover:shadow-md hover:-translate-y-0.5 active:scale-[0.97]"
                      style="background:linear-gradient(135deg,hsl(var(--primary)),hsl(207 30% 28%))">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    {{ lang==='en' ? 'New Batch' : 'Batch Baru' }}
                </Link>
                <div class="hidden xl:flex items-center gap-2 px-3 py-1.5 rounded-xl border border-border bg-muted/50">
                    <div class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"/>
                    <span class="text-xs font-semibold text-muted-foreground">{{ roleLabel }}</span>
                </div>
            </div>
        </header>

        <!-- Read-only banner -->
        <div v-if="can.read_only"
             class="hidden lg:flex items-center gap-2 px-5 py-2 border-b border-amber-200 dark:border-amber-900/40 flex-shrink-0"
             style="background:rgba(245,158,11,0.06)">
            <svg class="w-3.5 h-3.5 text-amber-600 dark:text-amber-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            <span class="text-xs font-semibold text-amber-700 dark:text-amber-400">
                {{ lang==='en'
                    ? '👁 Read-only access — You can view all data but cannot make changes.'
                    : '👁 Akses baca saja — Anda dapat melihat semua data namun tidak dapat melakukan perubahan.' }}
            </span>
        </div>

        <!-- Page content -->
        <main class="flex-1 overflow-y-auto">
            <div class="p-5 lg:p-6">
                <slot/>
            </div>
        </main>
    </div>
</div>
</template>

<style scoped>
.st-enter-active { transition: opacity 0.13s ease, transform 0.13s ease; }
.st-leave-active { transition: opacity 0.08s ease; }
.st-enter-from   { opacity: 0; transform: translateX(-4px); }
.st-leave-to     { opacity: 0; }

.accord-enter-active,
.accord-leave-active { transition: max-height 0.22s ease, opacity 0.18s ease; overflow: hidden; }
.accord-enter-from,
.accord-leave-to     { max-height: 0; opacity: 0; }
.accord-enter-to,
.accord-leave-from   { max-height: 500px; opacity: 1; }

.toast-enter-active { transition: all 0.3s cubic-bezier(0.175,0.885,0.32,1.275); }
.toast-leave-active { transition: all 0.18s ease; }
.toast-enter-from   { opacity: 0; transform: translateX(100%) scale(0.85); }
.toast-leave-to     { opacity: 0; transform: translateX(50%) scale(0.9); }
.toast-move         { transition: transform 0.2s ease; }

.modal-enter-active { transition: opacity 0.2s ease; }
.modal-leave-active { transition: opacity 0.15s ease; }
.modal-enter-from,
.modal-leave-to     { opacity: 0; }

@keyframes dialog-in {
    from { opacity: 0; transform: scale(0.94) translateY(6px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}
.dialog-in { animation: dialog-in 0.22s cubic-bezier(0.175,0.885,0.32,1.275); }

.drop-up-enter-active { transition: all 0.18s cubic-bezier(0.175,0.885,0.32,1.275); }
.drop-up-leave-active { transition: all 0.12s ease; }
.drop-up-enter-from   { opacity: 0; transform: translateY(8px) scale(0.96); }
.drop-up-leave-to     { opacity: 0; transform: translateY(4px); }

.fade-enter-active { transition: opacity 0.2s ease; }
.fade-leave-active { transition: opacity 0.15s ease; }
.fade-enter-from,
.fade-leave-to     { opacity: 0; }
</style>