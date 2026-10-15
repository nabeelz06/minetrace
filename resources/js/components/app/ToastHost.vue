<script setup>
/** ToastHost — render notifikasi (aria-live). Dipasang sekali di AppLayout/PublicLayout. */
import AppIcon from '@/components/app/AppIcon.vue'
import { useToast } from '@/composables/core/useToast.js'

const { toasts, dismiss } = useToast()
const TONE = {
    success: { box: 'border-success/40', icon: 'circle-check', color: 'text-success' },
    error:   { box: 'border-danger/40',  icon: 'circle-x',     color: 'text-danger' },
    warning: { box: 'border-warning/40', icon: 'triangle-alert', color: 'text-warning' },
    info:    { box: 'border-info/40',    icon: 'info',         color: 'text-info' },
}
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 sm:items-end" aria-live="polite" aria-atomic="false">
        <div v-for="t in toasts" :key="t.id" :role="t.type === 'error' ? 'alert' : 'status'"
             class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border bg-card p-3 shadow-lg animate-fade-in"
             :class="(TONE[t.type] ?? TONE.info).box">
            <AppIcon :name="(TONE[t.type] ?? TONE.info).icon" :size="20" :class="(TONE[t.type] ?? TONE.info).color" class="mt-0.5" />
            <p class="min-w-0 flex-1 text-sm text-foreground">{{ t.message }}</p>
            <button type="button" class="touch-target -m-2 inline-flex items-center justify-center rounded text-muted-foreground hover:text-foreground"
                    aria-label="Tutup / Close" @click="dismiss(t.id)">
                <AppIcon name="x" :size="16" />
            </button>
        </div>
    </div>
</template>
