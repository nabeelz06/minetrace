<script setup>
/**
 * WeightDisplay — tampilan berat material yang konsisten.
 * Auto-convert: kg → ton → kTon berdasarkan magnitude.
 */
const props = defineProps({
    kg:        { type: Number, default: null },
    precision: { type: Number, default: 2 },
    showUnit:  { type: Boolean, default: true },
    size:      { type: String, default: 'sm' }, // 'xs' | 'sm' | 'md' | 'lg'
    color:     { type: String, default: null },
})

function format(kg) {
    if (kg === null || kg === undefined) return '—'
    const n = parseFloat(kg)
    if (isNaN(n)) return '—'

    if (n >= 1_000_000) {
        const val = (n / 1_000_000).toFixed(props.precision)
        return props.showUnit ? `${val} kT` : val
    }
    if (n >= 1_000) {
        const val = (n / 1_000).toFixed(props.precision)
        return props.showUnit ? `${val} ton` : val
    }
    const val = n.toLocaleString('id-ID', { maximumFractionDigits: props.precision })
    return props.showUnit ? `${val} kg` : val
}

const sizeClass = {
    xs: 'text-xs',
    sm: 'text-xs',
    md: 'text-sm',
    lg: 'text-base',
}
</script>

<template>
    <span
        class="tabular-nums font-semibold"
        :class="[sizeClass[size]]"
        :style="color ? `color: ${color}` : ''"
    >
        {{ format(kg) }}
    </span>
</template>