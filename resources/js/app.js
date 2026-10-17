import './bootstrap'
import '../css/app.css'

// Daftarkan aset statis (font) ke manifest Vite agar bisa di-preload lewat Vite::asset() di app.blade.php
import.meta.glob(['../fonts/*.woff2'])

import { createApp, h }     from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { createPinia }      from 'pinia'
import { BRAND, THEME_KEY, LANG_KEY, readPref, writePref } from './lib/brand'

const appName = import.meta.env.VITE_APP_NAME || BRAND.name

// ─── WSL2 Fix: Parse data-page manual ────────────────────────────────────
const appEl       = document.getElementById('app')
const initialPage = appEl?.dataset?.page ? JSON.parse(appEl.dataset.page) : null

if (!initialPage) {
    console.error('❌ data-page tidak ditemukan di #app element')
}

// Code-splitting per halaman (bundel awal kecil; hls.js hanya dimuat di halaman CCTV).
const pages = import.meta.glob('./Pages/**/*.vue')

// ─── Apply dark mode sebelum render ──────────────────────────────────────
const savedTheme = readPref(THEME_KEY)
if (savedTheme === 'dark' ||
    ((!savedTheme || savedTheme === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('dark')
}

createInertiaApp({
    title: (title) => title ? `${title} — ${appName}` : appName,

    // WSL2 Fix: inject page data manual
    page: initialPage,

    resolve: (name) => {
        const importPage = pages[`./Pages/${name}.vue`]
        if (!importPage) throw new Error(`Komponen halaman tidak ditemukan: ./Pages/${name}.vue`)
        return importPage()
    },

    setup({ el, App, props, plugin }) {
        const pinia = createPinia()

        // ─── Sync language dari server (user preference) ──────────────
        try {
            const serverLang = props?.initialPage?.props?.auth?.user?.language
            if (serverLang && ['id', 'en'].includes(serverLang)) {
                writePref(LANG_KEY, serverLang)
            } else if (!readPref(LANG_KEY)) {
                writePref(LANG_KEY, 'id')
            }
        } catch (e) {
            // Silent fail - language akan pakai localStorage
        }

        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)

        app.mount(el)
        return app
    },

    progress: {
        color: 'hsl(207 40% 31%)',
        showSpinner: false,
    },
})