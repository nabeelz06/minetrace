<script setup>
/**
 * BigActionButton — kartu aksi besar untuk tugas utama (target sentuh ≥ 44px).
 * Bila `disabled`, `reason` ditampilkan agar operator tahu KENAPA tidak bisa dipakai.
 */
import AppIcon from '@/components/app/AppIcon.vue'

defineProps({
    icon:        { type: String, required: true },
    title:       { type: String, required: true },
    description: { type: String, default: null },
    tone:        { type: String, default: 'neutral' },   // neutral | primary | success | info
    selected:    { type: Boolean, default: false },
    disabled:    { type: Boolean, default: false },
    reason:      { type: String, default: null },
})
defineEmits(['click'])

const TONE = {
    neutral: 'bg-card hover:bg-accent',
    primary: 'bg-card hover:bg-accent',
    success: 'bg-card hover:bg-accent',
    info:    'bg-card hover:bg-accent',
}
const ICON = {
    neutral: 'bg-muted text-foreground',
    primary: 'bg-primary text-primary-foreground',
    success: 'bg-success-soft text-success',
    info:    'bg-info-soft text-info',
}
</script>

<template>
    <button type="button" :disabled="disabled" :aria-pressed="selected"
            class="group flex w-full min-h-[88px] items-center gap-4 rounded-xl border p-4 text-left disabled:cursor-not-allowed disabled:opacity-60"
            :class="[TONE[tone], selected ? 'border-primary ring-2 ring-primary/30' : 'border-border']"
            @click="$emit('click')">
        <span class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-lg" :class="ICON[tone]">
            <AppIcon :name="icon" :size="26" />
        </span>
        <span class="min-w-0">
            <span class="block text-lg font-semibold text-foreground">{{ title }}</span>
            <span v-if="description" class="block text-sm text-muted-foreground">{{ description }}</span>
            <span v-if="disabled && reason" class="mt-1 block text-sm font-medium text-warning">{{ reason }}</span>
        </span>
    </button>
</template>
