<script setup>
/**
 * AuthLayout — kerangka halaman masuk/lupa/reset password.
 * Tenang & netral (token tema; terang/gelap/sistem), tanpa dekorasi bergerak. Panel kiri hanya di layar lebar.
 */
import { computed, onMounted } from 'vue'
import { Link } from '@inertiajs/vue3'
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
onMounted(() => document.documentElement.setAttribute('lang', lang.value))

const features = ['f_trace', 'f_lab', 'f_warehouse', 'f_audit']
const themeIcon = computed(() => ({ system: 'monitor', light: 'sun', dark: 'moon' }[theme.mode.value]))
</script>

<template>
    <div class="grid min-h-dvh bg-background lg:grid-cols-2">
        <a href="#form" class="skip-link">{{ t('app.skip_to_content') }}</a>

        <!-- Panel merek (hanya layar lebar) -->
        <aside class="hidden flex-col justify-between border-r border-border bg-secondary p-12 lg:flex" :aria-label="BRAND.name">
            <BrandLogo :size="40" show-name :tagline="t('brand.tagline')" />

            <div class="max-w-md space-y-6">
                <h2 class="text-3xl font-bold tracking-tight text-foreground">{{ t('auth.hero_title') }}</h2>
                <p class="text-base text-muted-foreground">{{ t('auth.hero_desc') }}</p>
                <ul class="space-y-3">
                    <li v-for="f in features" :key="f" class="flex items-center gap-3 text-sm text-foreground">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-card text-primary"><AppIcon name="circle-check" :size="18" /></span>
                        {{ t(`auth.${f}`) }}
                    </li>
                </ul>
            </div>

            <p class="text-sm text-muted-foreground">BIM × PT Timah Tbk</p>
        </aside>

        <!-- Form -->
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
                <div class="w-full max-w-sm space-y-6">
                    <div class="space-y-1.5">
                        <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ heading }}</h1>
                        <p class="text-sm text-muted-foreground">{{ subheading }}</p>
                    </div>

                    <div v-if="status" role="status" class="flex items-start gap-2 rounded-lg border border-success/30 bg-success-soft p-3 text-sm font-medium text-success">
                        <AppIcon name="circle-check" :size="18" class="mt-0.5" />{{ status }}
                    </div>

                    <slot />

                    <p class="text-center text-sm text-muted-foreground">{{ t('auth.restricted') }}</p>
                    <p class="text-center text-xs text-muted-foreground">{{ BRAND.name }} — {{ t('brand.tagline') }}</p>
                </div>
            </div>
        </main>

        <ToastHost />
    </div>
</template>
