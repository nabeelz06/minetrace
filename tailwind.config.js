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
                sans:    ['Inter', 'Plus Jakarta Sans', 'system-ui', 'sans-serif'],
                heading: ['Plus Jakarta Sans', 'Inter', 'system-ui', 'sans-serif'],
                mono:    ['JetBrains Mono', 'Fira Code', 'ui-monospace', 'monospace'],
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

                // Sidebar
                sidebar: {
                    DEFAULT:     'hsl(var(--sidebar-background))',
                    foreground:  'hsl(var(--sidebar-foreground))',
                    border:      'hsl(var(--sidebar-border))',
                    accent:      'hsl(var(--sidebar-accent))',
                },
            },

            /* ─── Border Radius ───────────────────────────────────────── */
            borderRadius: {
                sm:   'var(--radius-sm)',
                DEFAULT: 'var(--radius)',
                lg:   'var(--radius-lg)',
                xl:   '1rem',
                '2xl':'var(--radius-2xl)',
                '3xl':'1.5rem',
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
                '2xs': ['0.625rem',  { lineHeight: '1rem' }],
                xs:    ['0.75rem',   { lineHeight: '1.125rem' }],
                sm:    ['0.8125rem', { lineHeight: '1.25rem' }],
                base:  ['0.875rem',  { lineHeight: '1.5rem' }],
                lg:    ['1rem',      { lineHeight: '1.625rem' }],
                xl:    ['1.125rem',  { lineHeight: '1.75rem' }],
                '2xl': ['1.25rem',   { lineHeight: '1.875rem' }],
                '3xl': ['1.5rem',    { lineHeight: '2rem' }],
            },

            /* ─── Animations ──────────────────────────────────────────── */
            animation: {
                'fade-in':     'fade-in 0.28s ease-out both',
                'fade-in-up':  'fade-in-up 0.32s ease-out both',
                'scale-in':    'scale-in 0.22s cubic-bezier(0.175, 0.885, 0.32, 1.275) both',
                'slide-right': 'slide-in-right 0.28s ease-out both',
                'slide-left':  'slide-in-left 0.28s ease-out both',
            },
        },
    },

    plugins: [
        require('@tailwindcss/forms'),
    ],
}