<script setup>
/**
 * StatusBadge — badge status konsisten (ikon + teks + warna; tidak mengandalkan warna saja).
 * Warna berasal dari token status semantik (success/warning/danger/info/neutral).
 */
import { computed } from 'vue'
import AppIcon from '@/components/app/AppIcon.vue'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    status: { type: [String, Boolean], required: true },
    type:   { type: String, default: 'batch' },   // 'batch' | 'export' | 'lab' | 'user'
    size:   { type: String, default: 'sm' },      // 'xs' | 'sm' | 'md'
    pulse:  { type: Boolean, default: false },    // dipertahankan (tak dipakai; tanpa animasi berkedip)
})

const { lang } = useI18n()

// tone: success | warning | danger | info | neutral
const CONFIG = {
    batch: {
        active:       { tone: 'success', icon: 'circle-check', id: 'Aktif',          en: 'Active' },
        in_transit:   { tone: 'warning', icon: 'truck',        id: 'Dalam pengiriman', en: 'In transit' },
        in_process:   { tone: 'info',    icon: 'activity',     id: 'Diproses',       en: 'Processing' },
        in_warehouse: { tone: 'neutral', icon: 'warehouse',    id: 'Di gudang',      en: 'In warehouse' },
        completed:    { tone: 'neutral', icon: 'check-check',  id: 'Selesai',        en: 'Completed' },
        split:        { tone: 'info',    icon: 'layers',       id: 'Dipecah',        en: 'Split' },
        transformed:  { tone: 'info',    icon: 'layers',       id: 'Ditransformasi', en: 'Transformed' },
        exported:     { tone: 'success', icon: 'ship',         id: 'Diekspor',       en: 'Exported' },
        terminated:   { tone: 'danger',  icon: 'ban',          id: 'Dihentikan',     en: 'Terminated' },
        draft:        { tone: 'neutral', icon: 'pencil',       id: 'Draf',           en: 'Draft' },
    },
    export: {
        draft:     { tone: 'neutral', icon: 'pencil',      id: 'Draf',         en: 'Draft' },
        confirmed: { tone: 'success', icon: 'circle-check', id: 'Dikonfirmasi', en: 'Confirmed' },
        shipped:   { tone: 'info',    icon: 'ship',        id: 'Dikirim',      en: 'Shipped' },
        delivered: { tone: 'success', icon: 'badge-check', id: 'Diterima',     en: 'Delivered' },
    },
    lab: {
        true:  { tone: 'success', icon: 'badge-check', id: 'Tervalidasi', en: 'Validated' },
        false: { tone: 'warning', icon: 'hourglass',   id: 'Menunggu',    en: 'Pending' },
    },
    user: {
        true:  { tone: 'success', icon: 'circle-check', id: 'Aktif',    en: 'Active' },
        false: { tone: 'neutral', icon: 'circle-x',     id: 'Nonaktif', en: 'Inactive' },
    },
}

const TONE = {
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger:  'bg-danger-soft text-danger',
    info:    'bg-info-soft text-info',
    neutral: 'bg-neutral-soft text-neutral',
}
const SIZE = { xs: 'text-xs px-1.5 py-0.5 gap-1', sm: 'text-xs px-2 py-1 gap-1.5', md: 'text-sm px-2.5 py-1 gap-1.5' }

const cfg = computed(() => {
    const group = CONFIG[props.type] ?? CONFIG.batch
    const key = String(props.status)
    return group[key] ?? { tone: 'neutral', icon: 'circle-dashed', id: key, en: key }
})
</script>

<template>
    <span class="inline-flex items-center rounded-md font-semibold whitespace-nowrap" :class="[TONE[cfg.tone], SIZE[size]]">
        <AppIcon :name="cfg.icon" :size="size === 'md' ? 16 : 14" />
        {{ lang === 'en' ? cfg.en : cfg.id }}
    </span>
</template>
