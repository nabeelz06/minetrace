import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import path from 'path'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],

    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },

    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,  // ← gagal jika port 5173 tidak tersedia
        hmr: {
            host: 'localhost',
            port: 5173,
        },
    },

    build: {
        rollupOptions: {
            output: {
                manualChunks: {
                    'vendor-vue':   ['vue', '@inertiajs/vue3', 'pinia'],
                    'vendor-utils': ['axios', 'clsx'],
                },
            },
        },
        chunkSizeWarningLimit: 1500,
    },
})