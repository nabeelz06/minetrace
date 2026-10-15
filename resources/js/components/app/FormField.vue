<script setup>
/**
 * FormField — label + petunjuk + pesan galat (terhubung via aria-describedby).
 * Slot default menerima { id, describedby, invalid } untuk dipasang ke kontrol.
 */
import { computed, useId } from 'vue'

const props = defineProps({
    label:    { type: String, required: true },
    hint:     { type: String, default: null },
    error:    { type: String, default: null },
    required: { type: Boolean, default: false },
})

const uid = useId()
const id = computed(() => `f-${uid}`)
const describedby = computed(() => [props.hint ? `${id.value}-hint` : null, props.error ? `${id.value}-err` : null].filter(Boolean).join(' ') || undefined)
</script>

<template>
    <div class="space-y-1.5">
        <label :for="id" class="block text-sm font-medium text-foreground">
            {{ label }}<span v-if="required" class="text-danger" aria-hidden="true"> *</span>
        </label>
        <slot :id="id" :describedby="describedby" :invalid="!!error" />
        <p v-if="hint" :id="`${id}-hint`" class="text-sm text-muted-foreground">{{ hint }}</p>
        <p v-if="error" :id="`${id}-err`" class="text-sm font-medium text-danger" role="alert">{{ error }}</p>
    </div>
</template>
