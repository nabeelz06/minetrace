<script setup>
/**
 * ResultBanner — umpan balik hasil tindakan. Ikon + teks + warna (tidak hanya warna);
 * live region agar pembaca layar mengumumkan hasil. size="xl" untuk layar lapangan.
 */
import { computed } from 'vue'
import AppIcon from '@/components/app/AppIcon.vue'

const props = defineProps({
    type:        { type: String, default: 'info' },     // success | warning | error | info
    title:       { type: String, required: true },
    description: { type: String, default: null },
    size:        { type: String, default: 'md' },       // md | xl
})

const TONE = {
    success: { box: 'bg-success-soft border-success/30 text-success', icon: 'circle-check' },
    warning: { box: 'bg-warning-soft border-warning/30 text-warning', icon: 'triangle-alert' },
    error:   { box: 'bg-danger-soft border-danger/30 text-danger',    icon: 'circle-x' },
    info:    { box: 'bg-info-soft border-info/30 text-info',          icon: 'info' },
}
const tone = computed(() => TONE[props.type] ?? TONE.info)
const role = computed(() => (props.type === 'error' || props.type === 'warning' ? 'alert' : 'status'))
</script>

<template>
    <div :role="role" class="flex items-start gap-3 rounded-xl border" :class="[tone.box, size === 'xl' ? 'p-5' : 'p-3']">
        <AppIcon :name="tone.icon" :size="size === 'xl' ? 36 : 22" class="mt-0.5" />
        <div class="min-w-0">
            <p :class="size === 'xl' ? 'text-xl font-bold' : 'text-base font-semibold'">{{ title }}</p>
            <p v-if="description" :class="size === 'xl' ? 'text-base' : 'text-sm'" class="mt-0.5 opacity-90">{{ description }}</p>
            <slot />
        </div>
    </div>
</template>
