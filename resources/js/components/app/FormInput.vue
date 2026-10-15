<script setup>
/**
 * FormInput — input teks konsisten: tinggi ≥ 44px, batas kontrol ≥ 3:1, galat dengan ikon + teks (via FormField).
 * Slot `trailing` untuk tombol (mis. tampilkan password). Mendukung v-model.
 */
import AppIcon from '@/components/app/AppIcon.vue'

defineProps({
    modelValue:  { type: [String, Number], default: '' },
    id:          { type: String, default: undefined },
    type:        { type: String, default: 'text' },
    icon:        { type: String, default: null },
    invalid:     { type: Boolean, default: false },
    describedby: { type: String, default: undefined },
})
defineEmits(['update:modelValue'])
</script>

<template>
    <div class="relative">
        <AppIcon v-if="icon" :name="icon" :size="18" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
        <input :id="id" :type="type" :value="modelValue" :aria-invalid="invalid || undefined" :aria-describedby="describedby"
               v-bind="$attrs"
               class="min-h-[44px] w-full rounded-lg border bg-background text-base text-foreground placeholder:text-muted-foreground focus-visible:outline-2"
               :class="[icon ? 'pl-10' : 'pl-3', $slots.trailing ? 'pr-12' : 'pr-3', invalid ? 'border-danger' : 'border-input']"
               @input="$emit('update:modelValue', $event.target.value)" />
        <div v-if="$slots.trailing" class="absolute right-1 top-1/2 -translate-y-1/2"><slot name="trailing" /></div>
    </div>
</template>

<script>
export default { inheritAttrs: false }
</script>
