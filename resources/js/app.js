import './bootstrap'
import '../css/app.css'

import { createApp, h }     from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { createPinia }      from 'pinia'
import { ZiggyVue }         from '../../vendor/tightenco/ziggy'

const appName = import.meta.env.VITE_APP_NAME || 'MINETRA'

// ─── WSL2 Fix: Parse data-page manual ────────────────────────────────────
const appEl       = document.getElementById('app')
const initialPage = appEl?.dataset?.page ? JSON.parse(appEl.dataset.page) : null

if (!initialPage) {
    console.error('❌ data-page tidak ditemukan di #app element')
}

// ─── Apply dark mode sebelum render ──────────────────────────────────────
const savedTheme = localStorage.getItem('minetra-theme')
if (savedTheme === 'dark' ||
    (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
    document.documentElement.classList.add('dark')
}

createInertiaApp({
    title: (title) => title ? `${title} — ${appName}` : appName,

    // WSL2 Fix: inject page data manual
    page: initialPage,

    // WSL2 Fix: eager loading + manual resolution
    resolve: (name) => {
        const pages     = import.meta.glob('./Pages/**/*.vue', { eager: true })
        const component = pages[`./Pages/${name}.vue`]

        if (!component) {
            console.error(`❌ Komponen tidak ditemukan: ./Pages/${name}.vue`)
            console.log('Available pages:', Object.keys(pages))
        } else {
            console.log(`✅ Resolved: ${name}`)
        }

        return component
    },

    setup({ el, App, props, plugin }) {
        const pinia = createPinia()

        // ─── Sync language dari server (user preference) ──────────────
        try {
            const serverLang = props?.initialPage?.props?.auth?.user?.language
            if (serverLang && ['id', 'en'].includes(serverLang)) {
                localStorage.setItem('minetra-lang', serverLang)
            } else if (!localStorage.getItem('minetra-lang')) {
                localStorage.setItem('minetra-lang', 'id')
            }
        } catch (e) {
            // Silent fail - language akan pakai localStorage
        }

        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(pinia)
            .use(ZiggyVue)

        app.mount(el)
        return app
    },

    progress: {
        color: '#3E5C74',
        showSpinner: false,
    },
})