<script setup>
/**
 * AuthLayout — kerangka halaman masuk/lupa/reset password.
 *
 * Panel merek (layar lebar): pola kontur topografi statis (nuansa tambang, netral), kartu alur tahap proses
 * yang diambil dari ProcessFlow (props Inertia `stages`, satu sumber kebenaran), dan kartu fitur.
 * Seluruh warna memakai token tema (terang/gelap/sistem); dekorasi statis dan aria-hidden, tanpa gerak.
 */
import { computed, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'
import BrandLogo from '@/components/shared/BrandLogo.vue'
import AppIcon from '@/components/app/AppIcon.vue'
import ToastHost from '@/components/app/ToastHost.vue'
import { useI18n } from '@/composables/core/useI18n.js'
import { useTheme } from '@/composables/core/useTheme.js'
import { BRAND } from '@/lib/brand'

defineProps({
    heading:    { type: String, required: true },
    subheading: { type: String, required: true },
    status:     { type: String, default: null },
})

const { t, lang, toggleLang } = useI18n()
const theme = useTheme()
const page  = usePage()
onMounted(() => document.documentElement.setAttribute('lang', lang.value))

const features = [
    { key: 'f_trace',     icon: 'activity' },
    { key: 'f_lab',       icon: 'microscope' },
    { key: 'f_warehouse', icon: 'warehouse' },
    { key: 'f_audit',     icon: 'shield-check' },
]

const flow = computed(() =>
    (page.props.stages ?? []).map((s, i) => ({
        key: s.key,
        icon: s.icon,
        n: i + 1,
        label: lang.value === 'en' ? s.label_en : s.label,
    })),
)

const themeIcon = computed(() => ({ system: 'monitor', light: 'sun', dark: 'moon' }[theme.mode.value]))

// Cincin kontur: elips konsentris dengan rotasi sedikit bergeser agar terkesan organik.
const rings = Array.from({ length: 11 }, (_, i) => ({
    rx: 46 + i * 36,
    ry: 30 + i * 27,
    rot: -22 + i * 2.2,
}))
</script>

<template>
    <div class="grid min-h-dvh bg-background lg:grid-cols-[minmax(0,1.08fr)_minmax(0,1fr)]">
        <a href="#form" class="skip-link">{{ t('app.skip_to_content') }}</a>

        <!-- ═══ Panel merek (hanya layar lebar) ═══ -->
        <aside class="relative hidden overflow-hidden border-r border-border bg-secondary lg:flex" :aria-label="BRAND.name">

            <!-- Dekorasi statis -->
            <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                <div class="absolute inset-0"
                     style="background:
                        radial-gradient(60% 45% at 90% 8%, hsl(var(--primary) / 0.10), transparent 70%),
                        radial-gradient(55% 40% at 5% 100%, hsl(var(--primary) / 0.08), transparent 70%);"></div>
                <div class="absolute inset-0 opacity-60"
                     style="background-image:
                        linear-gradient(hsl(var(--border) / 0.55) 1px, transparent 1px),
                        linear-gradient(90deg, hsl(var(--border) / 0.55) 1px, transparent 1px);
                        background-size: 56px 56px;
                        -webkit-mask-image: radial-gradient(75% 65% at 30% 35%, #000 20%, transparent 78%);
                        mask-image: radial-gradient(75% 65% at 30% 35%, #000 20%, transparent 78%);"></div>

                <svg class="absolute -bottom-44 -right-44 w-[46rem] max-w-none" viewBox="0 0 800 800" fill="none">
                    <g stroke="hsl(var(--primary) / 0.16)" stroke-width="1.25">
                        <ellipse v-for="(r, i) in rings" :key="`a${i}`" cx="400" cy="400" :rx="r.rx" :ry="r.ry"
                                 :transform="`rotate(${r.rot} 400 400)`" />
                    </g>
                </svg>
                <svg class="absolute -left-40 -top-44 w-[30rem] max-w-none" viewBox="0 0 800 800" fill="none">
                    <g stroke="hsl(var(--primary) / 0.10)" stroke-width="1.25">
                        <ellipse v-for="(r, i) in rings.slice(0, 8)" :key="`b${i}`" cx="400" cy="400" :rx="r.rx" :ry="r.ry"
                                 :transform="`rotate(${28 - i * 2.4} 400 400)`" />
                    </g>
                </svg>
            </div>

            <div class="relative z-10 flex w-full flex-col justify-between gap-10 p-12 xl:p-14">
                <BrandLogo :size="40" show-name :tagline="t('brand.tagline')" />

                <div class="max-w-xl space-y-8">
                    <div class="space-y-4">
                        <span class="inline-flex items-center gap-2 rounded-full border border-border bg-card/70 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            <span class="h-1.5 w-1.5 rounded-full bg-primary"></span>{{ t('brand.tagline_short') }}
                        </span>
                        <h2 class="text-4xl font-bold leading-[1.15] tracking-tight text-foreground xl:text-[2.6rem]">{{ t('auth.hero_title') }}</h2>
                        <p class="max-w-md text-base leading-relaxed text-muted-foreground">{{ t('auth.hero_desc') }}</p>
                    </div>

                    <!-- Alur tahap proses -->
                    <section class="rounded-2xl border border-border bg-card/80 p-5 shadow-md backdrop-blur-sm" :aria-label="t('auth.flow_title')">
                        <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ t('auth.flow_title') }}</h3>
                        <ol class="relative grid grid-cols-3 gap-x-2 gap-y-5 xl:grid-cols-6">
                            <span class="absolute left-[8%] right-[8%] top-5 hidden h-px bg-border xl:block" aria-hidden="true"></span>
                            <li v-for="s in flow" :key="s.key" class="relative flex flex-col items-center gap-2 text-center">
                                <span class="relative flex h-10 w-10 items-center justify-center rounded-full border border-border bg-card text-primary shadow-sm">
                                    <AppIcon :name="s.icon" :size="18" />
                                    <span class="absolute -right-1.5 -top-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-primary text-[10px] font-bold leading-none text-primary-foreground">{{ s.n }}</span>
                                </span>
                                <span class="text-xs font-medium leading-tight text-foreground">{{ s.label }}</span>
                            </li>
                        </ol>
                    </section>

                    <!-- Fitur -->
                    <ul class="grid grid-cols-2 gap-3">
                        <li v-for="f in features" :key="f.key"
                            class="flex items-start gap-3 rounded-xl border border-border bg-card/70 p-3.5 text-sm font-medium leading-snug text-foreground">
                            <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><AppIcon :name="f.icon" :size="18" /></span>
                            <span class="pt-1">{{ t(`auth.${f.key}`) }}</span>
                        </li>
                    </ul>
                </div>

                <div class="flex items-center justify-between gap-4 border-t border-border pt-5 text-sm text-muted-foreground">
                    <span>BIM × PT Timah Tbk</span>
                    <span class="text-xs font-medium tracking-wide">RFID · CCTV · QR Code</span>
                </div>
            </div>
        </aside>

        <!-- ═══ Formulir ═══ -->
        <main id="form" tabindex="-1" class="flex flex-col focus:outline-none">
            <div class="flex items-center justify-between p-4 sm:p-6">
                <BrandLogo class="lg:invisible" :size="32" show-name />
                <div class="flex items-center gap-1">
                    <button type="button" class="touch-target inline-flex items-center justify-center rounded-lg hover:bg-accent"
                            :aria-label="`${t('app.theme')}: ${t(`app.theme_${theme.mode.value}`)}`" @click="theme.cycle()">
                        <AppIcon :name="themeIcon" :size="20" />
                    </button>
                    <button type="button" class="touch-target inline-flex items-center justify-center gap-1.5 rounded-lg px-2 text-sm font-semibold hover:bg-accent"
                            :aria-label="t('app.language_switch')" @click="toggleLang()">
                        <AppIcon name="languages" :size="18" /><span class="uppercase">{{ lang }}</span>
                    </button>
                </div>
            </div>

            <div class="flex flex-1 items-center justify-center px-4 pb-10 sm:px-6">
                <div class="w-full max-w-md animate-fade-in-up space-y-5">
                    <div class="space-y-6 rounded-2xl border border-border bg-card p-6 shadow-lg sm:p-8">
                        <div class="space-y-1.5">
                            <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ heading }}</h1>
                            <p class="text-sm text-muted-foreground">{{ subheading }}</p>
                        </div>

                        <div v-if="status" role="status" class="flex items-start gap-2 rounded-lg border border-success/30 bg-success-soft p-3 text-sm font-medium text-success">
                            <AppIcon name="circle-check" :size="18" class="mt-0.5" />{{ status }}
                        </div>

                        <slot />
                    </div>

                    <p class="text-center text-sm text-muted-foreground">
                        <AppIcon name="lock" :size="14" class="mr-1.5 inline -mt-0.5" />{{ t('auth.restricted') }}
                    </p>
                    <p class="text-center text-xs text-muted-foreground">{{ BRAND.name }} — {{ t('brand.tagline') }}</p>
                </div>
            </div>
        </main>

        <ToastHost />
    </div>
</template>
