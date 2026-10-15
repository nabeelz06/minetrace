<script setup>
/**
 * PublicLayout — kerangka halaman publik tanpa login (mis. verifikasi QR).
 * Ringan, mobile-first, dwibahasa, dan tidak memanggil endpoint yang butuh sesi.
 */
import { onMounted } from 'vue'
import BrandLogo from '@/components/shared/BrandLogo.vue'
import { useI18n } from '@/composables/core/useI18n.js'
import { BRAND } from '@/lib/brand'

const { lang, setLang, t } = useI18n()

onMounted(() => document.documentElement.setAttribute('lang', lang.value))
</script>

<template>
    <div class="min-h-screen bg-background text-foreground flex flex-col">
        <a href="#konten" class="sr-only focus:not-sr-only focus:absolute focus:left-3 focus:top-3 focus:z-50 focus:rounded-md focus:bg-card focus:px-3 focus:py-2">
            {{ t('app.skip_to_content') }}
        </a>

        <header class="border-b border-border bg-card">
            <div class="mx-auto flex h-14 w-full max-w-xl items-center justify-between gap-3 px-4">
                <BrandLogo :size="28" show-name />
                <div class="inline-flex rounded-md border border-border p-0.5" role="group" :aria-label="t('app.language')">
                    <button v-for="l in ['id', 'en']" :key="l" type="button"
                            class="min-h-[36px] min-w-[44px] rounded px-2.5 text-xs font-semibold uppercase"
                            :class="lang === l ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:text-foreground'"
                            :aria-pressed="lang === l"
                            @click="setLang(l)">
                        {{ l }}
                    </button>
                </div>
            </div>
        </header>

        <main id="konten" class="mx-auto w-full max-w-xl flex-1 px-4 py-6">
            <slot />
        </main>

        <footer class="border-t border-border py-4 text-center text-xs text-muted-foreground">
            {{ BRAND.name }} — {{ lang === 'en' ? BRAND.taglineEn : BRAND.taglineId }}
        </footer>
    </div>
</template>
