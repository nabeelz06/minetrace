<script setup>
/** StatCard — angka ringkas. Nada warna hanya untuk anomali (kondisi normal = netral). */
import AppIcon from '@/components/app/AppIcon.vue'

defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    hint:  { type: String, default: null },
    icon:  { type: String, default: null },
    tone:  { type: String, default: 'neutral' },   // neutral | success | warning | danger | info
})
const TONE = { neutral: 'text-foreground', success: 'text-success', warning: 'text-warning', danger: 'text-danger', info: 'text-info' }
</script>

<template>
    <div class="rounded-xl border border-border bg-card p-4">
        <div class="flex items-center justify-between gap-2">
            <p class="text-sm font-medium text-muted-foreground">{{ label }}</p>
            <AppIcon v-if="icon" :name="icon" :size="18" class="text-muted-foreground" />
        </div>
        <p class="mt-2 text-3xl font-bold tabular-nums" :class="TONE[tone]">{{ value }}</p>
        <p v-if="hint" class="mt-1 text-sm text-muted-foreground">{{ hint }}</p>
    </div>
</template>
