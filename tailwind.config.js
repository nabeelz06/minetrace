/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
        './resources/js/**/*.js',
        './resources/js/**/*.ts',
    ],

    theme: {
        extend: {
            /* ─── Typography ──────────────────────────────────────────── */
            fontFamily: {
                sans:    ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
                heading: ['Inter', 'system-ui', 'sans-serif'],
                mono:    ['ui-monospace', 'SFMono-Regular', 'Menlo', 'Consolas', 'Liberation Mono', 'monospace'],
            },

            /* ─── Colors — mapped ke CSS variables ────────────────────── */
            colors: {
                background: 'hsl(var(--background) / <alpha-value>)',
                foreground: 'hsl(var(--foreground) / <alpha-value>)',

                primary: {
                    DEFAULT: 'hsl(var(--primary) / <alpha-value>)',
                    foreground: 'hsl(var(--primary-foreground) / <alpha-value>)',
                },
                secondary: {
                    DEFAULT: 'hsl(var(--secondary) / <alpha-value>)',
                    foreground: 'hsl(var(--secondary-foreground) / <alpha-value>)',
                },
                muted: {
                    DEFAULT: 'hsl(var(--muted) / <alpha-value>)',
                    foreground: 'hsl(var(--muted-foreground) / <alpha-value>)',
                },
                accent: {
                    DEFAULT: 'hsl(var(--accent) / <alpha-value>)',
                    foreground: 'hsl(var(--accent-foreground) / <alpha-value>)',
                },
                card: {
                    DEFAULT: 'hsl(var(--card) / <alpha-value>)',
                    foreground: 'hsl(var(--card-foreground) / <alpha-value>)',
                },
                popover: {
                    DEFAULT: 'hsl(var(--popover) / <alpha-value>)',
                    foreground: 'hsl(var(--popover-foreground) / <alpha-value>)',
                },
                border:  'hsl(var(--border) / <alpha-value>)',
                input:   'hsl(var(--input) / <alpha-value>)',
                ring:    'hsl(var(--ring) / <alpha-value>)',

                // Status semantik — teks/ikon (DEFAULT) + latar lembut (soft)
                success: { DEFAULT: 'hsl(var(--success) / <alpha-value>)', soft: 'hsl(var(--success-soft) / <alpha-value>)' },
                warning: { DEFAULT: 'hsl(var(--warning) / <alpha-value>)', soft: 'hsl(var(--warning-soft) / <alpha-value>)' },
                danger:  { DEFAULT: 'hsl(var(--danger) / <alpha-value>)',  soft: 'hsl(var(--danger-soft) / <alpha-value>)' },
                info:    { DEFAULT: 'hsl(var(--info) / <alpha-value>)',    soft: 'hsl(var(--info-soft) / <alpha-value>)' },
                neutral: { DEFAULT: 'hsl(var(--neutral) / <alpha-value>)',  soft: 'hsl(var(--neutral-soft) / <alpha-value>)' },

                // Sidebar
                sidebar: {
                    DEFAULT:     'hsl(var(--sidebar-background) / <alpha-value>)',
                    foreground:  'hsl(var(--sidebar-foreground) / <alpha-value>)',
                    border:      'hsl(var(--sidebar-border) / <alpha-value>)',
                    accent:      'hsl(var(--sidebar-accent) / <alpha-value>)',
                    primary:     'hsl(var(--sidebar-primary) / <alpha-value>)',
                },
            },

            /* ─── Border Radius ───────────────────────────────────────── */
            borderRadius: {
                sm:   'var(--radius-sm)',
                DEFAULT: 'var(--radius)',
                lg:   'var(--radius-lg)',
                xl:   '0.75rem',
                '2xl':'var(--radius-2xl)',
                '3xl':'1rem',
            },

            /* ─── Box Shadow ──────────────────────────────────────────── */
            boxShadow: {
                sm:  'var(--shadow-sm)',
                DEFAULT: 'var(--shadow)',
                md:  'var(--shadow-md)',
                lg:  'var(--shadow-lg)',
                xl:  'var(--shadow-xl)',
            },

            /* ─── Font Size ───────────────────────────────────────────── */
            fontSize: {
                // Tak ada teks fungsional < 12px. '2xs' dipertahankan sebagai alias 12px (kompatibilitas).
                '2xs': ['0.75rem',   { lineHeight: '1.125rem' }],
                xs:    ['0.75rem',   { lineHeight: '1.125rem' }],
                sm:    ['0.875rem',  { lineHeight: '1.375rem' }],
                base:  ['0.9375rem', { lineHeight: '1.5rem' }],
                lg:    ['1.0625rem', { lineHeight: '1.625rem' }],
                xl:    ['1.1875rem', { lineHeight: '1.75rem' }],
                '2xl': ['1.375rem',  { lineHeight: '1.875rem' }],
                '3xl': ['1.625rem',  { lineHeight: '2.125rem' }],
                '4xl': ['2rem',      { lineHeight: '2.5rem' }],
            },

            /* ─── Animations ──────────────────────────────────────────── */
            animation: {
                'fade-in':     'fade-in 0.2s ease-out both',
                'fade-in-up':  'fade-in-up 0.24s ease-out both',
                'scale-in':    'scale-in 0.18s ease-out both',
                'slide-right': 'slide-in-right 0.2s ease-out both',
                'slide-left':  'slide-in-left 0.2s ease-out both',
            },
        },
    },

    plugins: [
        require('@tailwindcss/forms'),
    ],
}