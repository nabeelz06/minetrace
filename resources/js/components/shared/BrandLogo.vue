<script setup>
/**
 * BrandLogo — logo MINETRACE (SVG inline, mengikuti tema lewat token --primary).
 * Dipakai AppLayout, AuthLayout. Tanda: heksagon (kristal mineral) dengan titik pusat (titik jejak).
 */
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { BRAND } from '@/lib/brand'

const props = defineProps({
    size:    { type: Number,  default: 32 },
    // Tampilkan teks nama di samping tanda
    showName: { type: Boolean, default: false },
    // Tampilkan tagline (hanya bila showName)
    tagline:  { type: String,  default: '' },
})

const page = usePage()
const name = computed(() => page.props?.app?.name || BRAND.name)
</script>

<template>
    <span class="inline-flex items-center gap-2.5 min-w-0">
        <svg :width="size" :height="size" viewBox="0 0 32 32" role="img" :aria-label="name" class="flex-shrink-0">
            <rect width="32" height="32" rx="7" fill="hsl(var(--primary))" />
            <polygon points="16,6 24.7,11 24.7,21 16,26 7.3,21 7.3,11"
                     fill="none" stroke="hsl(var(--primary-foreground))" stroke-width="2" stroke-linejoin="round" />
            <circle cx="16" cy="16" r="3" fill="hsl(var(--primary-foreground))" />
        </svg>
        <span v-if="showName" class="min-w-0 leading-tight">
            <span class="block truncate font-heading text-sm font-bold tracking-wide">{{ name }}</span>
            <span v-if="tagline" class="block truncate text-xs text-muted-foreground">{{ tagline }}</span>
        </span>
    </span>
</template>
