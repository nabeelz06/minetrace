<script setup>
/**
 * StatusBadge — badge status yang konsisten dipakai di seluruh aplikasi.
 * Mendukung: batch status, export status, lab status, user status.
 */
const props = defineProps({
    status: { type: String, required: true },
    type:   { type: String, default: 'batch' }, // 'batch' | 'export' | 'lab' | 'user'
    size:   { type: String, default: 'sm' },     // 'xs' | 'sm' | 'md'
    pulse:  { type: Boolean, default: false },
})

const configs = {
    batch: {
        active:       { class: 'badge-active',      label: { id: 'Aktif',       en: 'Active' } },
        in_transit:   { class: 'badge-transit',     label: { id: 'Transit',     en: 'In Transit' } },
        in_process:   { class: 'badge-process',     label: { id: 'Diproses',    en: 'Processing' } },
        in_warehouse: { class: 'badge-warehouse',   label: { id: 'Di Gudang',   en: 'In Warehouse' } },
        completed:    { class: 'badge-completed',   label: { id: 'Selesai',     en: 'Completed' } },
        split:        { class: 'badge-split',       label: { id: 'Di-split',    en: 'Split' } },
        transformed:  { class: 'badge-transformed', label: { id: 'Ditransformasi', en: 'Transformed' } },
        exported:     { class: 'badge-exported',    label: { id: 'Diekspor',    en: 'Exported' } },
        terminated:   { class: 'badge-terminated',  label: { id: 'Terminal',    en: 'Terminal' } },
        draft:        { class: 'badge-completed',   label: { id: 'Draft',       en: 'Draft' } },
    },
    export: {
        draft:     { class: 'badge-completed', label: { id: 'Draft',          en: 'Draft' } },
        confirmed: { class: 'badge-active',    label: { id: 'Dikonfirmasi',   en: 'Confirmed' } },
        shipped:   { class: 'badge-process',   label: { id: 'Dikirim',        en: 'Shipped' } },
        delivered: { class: 'badge-exported',  label: { id: 'Diterima',       en: 'Delivered' } },
    },
    lab: {
        true:  { class: 'badge-active',    label: { id: 'Tervalidasi', en: 'Validated' } },
        false: { class: 'badge-transit',   label: { id: 'Pending',     en: 'Pending' } },
    },
    user: {
        true:  { class: 'badge-active',    label: { id: 'Aktif',     en: 'Active' } },
        false: { class: 'badge-completed', label: { id: 'Nonaktif',  en: 'Inactive' } },
    },
}

const sizeClass = {
    xs: 'text-[10px] px-1.5 py-0.5',
    sm: 'text-xs px-2 py-0.5',
    md: 'text-sm px-2.5 py-1',
}

function getConfig() {
    const typeConfig = configs[props.type] ?? configs.batch
    const key = String(props.status)
    return typeConfig[key] ?? { class: 'badge-completed', label: { id: key, en: key } }
}

const lang = localStorage.getItem('minetra-lang') || 'id'
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-lg font-semibold"
        :class="[getConfig().class, sizeClass[size]]"
    >
        <div
            v-if="pulse && status === 'active'"
            class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse flex-shrink-0"
        />
        {{ getConfig().label[lang] ?? getConfig().label.id }}
    </span>
</template>