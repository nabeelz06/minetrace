<script setup>
/**
 * AppLayout — kerangka halaman pasca-login.
 *
 * Desktop: sidebar netral (dapat diciutkan) + header. Ponsel: sidebar menjadi drawer (Sheet, fokus-trap).
 * Aksesibilitas: skip-link, landmark (nav/header/main), aria-current, tombol ikon berlabel, target sentuh ≥ 44px.
 * Kontrak slot untuk halaman: #header (judul di bar atas) dan slot default (konten).
 * Kontrak provide/inject (halaman lama): confirm({title,message,confirmLabel,type}) → Promise<boolean>;
 *   toast(type, message, duration); can.
 */
import { computed, provide, ref, watch, onMounted } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import { useLocalStorage } from '@vueuse/core'
import { useI18n } from '@/composables/core/useI18n.js'
import { useTheme } from '@/composables/core/useTheme.js'
import { pushToast } from '@/composables/core/useToast.js'
import { buildNavigation } from '@/lib/navigation'
import AppIcon from '@/components/app/AppIcon.vue'
import ToastHost from '@/components/app/ToastHost.vue'
import ConfirmDialog from '@/components/app/ConfirmDialog.vue'
import SidebarNav from '@/Layouts/partials/SidebarNav.vue'
import { Sheet, SheetContent, SheetTitle, SheetDescription } from '@/components/ui/sheet'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'

const { t, lang, toggleLang, initFromPage } = useI18n()
const theme = useTheme()
onMounted(() => initFromPage())

// ─── Props halaman ────────────────────────────────────────────────────────────
const page    = usePage()
const user    = computed(() => page.props.auth?.user)
const company = computed(() => page.props.auth?.company)
const can     = computed(() => page.props.can ?? {})
const flash   = computed(() => page.props.flash ?? {})
const role    = computed(() => user.value?.role ?? '')

const navItems  = computed(() => buildNavigation(role.value, page.props.stages ?? []))
const roleLabel = computed(() => role.value ? t(`roles.${role.value}`, {}, role.value) : '')
const initials  = computed(() => (user.value?.name ?? 'U').split(' ').slice(0, 2).map(n => n[0]).join('').toUpperCase())

// ─── State UI ─────────────────────────────────────────────────────────────────
const collapsed  = useLocalStorage('minetrace-sidebar-collapsed', false)
const mobileOpen = ref(false)
router.on('navigate', () => { mobileOpen.value = false })   // drawer menutup setelah pindah halaman

// ─── Tema & bahasa ────────────────────────────────────────────────────────────
const themeIcon  = computed(() => ({ system: 'monitor', light: 'sun', dark: 'moon' }[theme.mode.value]))
const themeLabel = computed(() => t(`app.theme_${theme.mode.value}`))

// ─── Flash (pesan server) → toast ─────────────────────────────────────────────
watch(flash, f => {
    if (f?.success) pushToast(f.success, 'success')
    if (f?.error)   pushToast(f.error, 'error')
    if (f?.warning) pushToast(f.warning, 'warning')
    if (f?.info)    pushToast(f.info, 'info')
}, { immediate: true, deep: true })

// ─── Dialog konfirmasi (Promise) — kontrak lama dipertahankan ─────────────────
const confirmState = ref({ open: false, title: '', message: '', confirmLabel: '', cancelLabel: null, type: 'default', resolve: null })

function openConfirm({ title, message = '', confirmLabel, cancelLabel = null, type = 'default' }) {
    return new Promise(resolve => {
        confirmState.value = { open: true, title, message, confirmLabel: confirmLabel ?? t('app.confirm'), cancelLabel, type, resolve }
    })
}
function settle(value) {
    confirmState.value.resolve?.(value)
    confirmState.value = { ...confirmState.value, open: false, resolve: null }
}

provide('confirm', openConfirm)
provide('toast', (type, message, duration) => pushToast(message, type, { duration }))
provide('can', can)

async function logout() {
    const ok = await openConfirm({
        title: t('nav.sign_out'), message: t('app.sign_out_confirm'), confirmLabel: t('nav.sign_out'),
    })
    if (ok) router.post('/logout')
}
</script>

<template>
    <div class="flex h-dvh overflow-hidden bg-background">
        <a href="#main" class="skip-link">{{ t('app.skip_to_content') }}</a>

        <!-- Sidebar desktop -->
        <aside class="hidden flex-shrink-0 border-r border-sidebar-border bg-sidebar lg:block"
               :class="collapsed ? 'w-16' : 'w-60'" :aria-label="t('nav.main')">
            <SidebarNav :items="navItems" :path="page.url" :collapsed="collapsed" :company="company" :role-label="roleLabel" />
        </aside>

        <!-- Drawer ponsel -->
        <Sheet v-model:open="mobileOpen">
            <SheetContent side="left" class="w-72 max-w-[85vw] border-sidebar-border bg-sidebar p-0">
                <SheetTitle class="sr-only">{{ t('nav.main') }}</SheetTitle>
                <SheetDescription class="sr-only">{{ t('nav.main') }}</SheetDescription>
                <SidebarNav :items="navItems" :path="page.url" :company="company" :role-label="roleLabel" @navigate="mobileOpen = false" />
            </SheetContent>
        </Sheet>

        <div class="flex min-w-0 flex-1 flex-col">
            <header class="flex h-14 flex-shrink-0 items-center gap-2 border-b border-border bg-card px-3 sm:px-4">
                <button type="button" class="touch-target -ml-1 inline-flex items-center justify-center rounded-lg text-foreground hover:bg-accent lg:hidden"
                        :aria-label="t('nav.open_menu')" @click="mobileOpen = true">
                    <AppIcon name="menu" :size="22" />
                </button>
                <button type="button" class="touch-target hidden items-center justify-center rounded-lg text-muted-foreground hover:bg-accent hover:text-foreground lg:inline-flex"
                        :aria-label="collapsed ? t('nav.expand') : t('nav.collapse')" :aria-pressed="collapsed" @click="collapsed = !collapsed">
                    <AppIcon :name="collapsed ? 'panel-left-open' : 'panel-left-close'" :size="20" />
                </button>

                <div class="min-w-0 flex-1 truncate text-base font-semibold text-foreground">
                    <slot name="header" />
                </div>

                <Link v-if="can.create_batch" href="/company/batches/create"
                      class="hidden min-h-[44px] items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground hover:opacity-90 sm:inline-flex">
                    <AppIcon name="plus" :size="18" />{{ t('nav.new_batch') }}
                </Link>

                <button type="button" class="touch-target inline-flex items-center justify-center rounded-lg text-foreground hover:bg-accent"
                        :aria-label="`${t('app.theme')}: ${themeLabel}`" :title="`${t('app.theme')}: ${themeLabel}`" @click="theme.cycle()">
                    <AppIcon :name="themeIcon" :size="20" />
                </button>
                <button type="button" class="touch-target inline-flex items-center justify-center gap-1.5 rounded-lg px-2 text-sm font-semibold text-foreground hover:bg-accent"
                        :aria-label="t('app.language_switch')" :title="t('app.language_switch')" @click="toggleLang()">
                    <AppIcon name="languages" :size="18" /><span class="uppercase">{{ lang }}</span>
                </button>

                <DropdownMenu v-if="user">
                    <DropdownMenuTrigger as-child>
                        <button type="button" class="touch-target inline-flex items-center gap-2 rounded-lg px-1.5 hover:bg-accent" :aria-label="user.name">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">{{ initials }}</span>
                            <AppIcon name="chevron-down" :size="16" class="hidden text-muted-foreground sm:block" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-60">
                        <DropdownMenuLabel>
                            <span class="block truncate text-sm font-semibold">{{ user.name }}</span>
                            <span class="block truncate text-xs font-normal text-muted-foreground">{{ user.email }}</span>
                            <span class="mt-0.5 block truncate text-xs font-normal text-muted-foreground">{{ roleLabel }}</span>
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem class="min-h-[44px] cursor-pointer gap-2" @select="router.visit('/account/password')">
                            <AppIcon name="key-round" :size="18" />{{ t('account.menu') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem class="min-h-[44px] cursor-pointer gap-2" @select="logout">
                            <AppIcon name="log-out" :size="18" />{{ t('nav.sign_out') }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </header>

            <div v-if="can.read_only" class="flex items-center gap-2 border-b border-warning/30 bg-warning-soft px-4 py-2 text-sm font-medium text-warning" role="note">
                <AppIcon name="eye" :size="16" />{{ t('nav.read_only_banner') }}
            </div>

            <main id="main" tabindex="-1" class="flex-1 overflow-y-auto focus:outline-none">
                <div class="mx-auto w-full max-w-[1600px] p-4 sm:p-5 lg:p-6">
                    <slot />
                </div>
            </main>
        </div>

        <ToastHost />
        <ConfirmDialog v-model:open="confirmState.open" :title="confirmState.title" :description="confirmState.message"
                       :confirm-label="confirmState.confirmLabel" :cancel-label="confirmState.cancelLabel"
                       :tone="confirmState.type === 'danger' ? 'danger' : 'default'"
                       @confirm="settle(true)" @cancel="settle(false)" />
    </div>
</template>
