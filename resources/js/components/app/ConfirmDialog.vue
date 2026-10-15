<script setup>
/**
 * ConfirmDialog — konfirmasi aksi berisiko (fokus-trap, Esc menutup, label tombol berkata-kerja).
 * `requireText`: pengguna harus mengetik kata itu untuk aksi yang tidak dapat dibalik.
 */
import { computed, ref, watch } from 'vue'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    open:         { type: Boolean, default: false },
    title:        { type: String, required: true },
    description:  { type: String, default: null },
    confirmLabel: { type: String, required: true },
    cancelLabel:  { type: String, default: null },
    tone:         { type: String, default: 'default' },   // default | danger
    requireText:  { type: String, default: null },
    loading:      { type: Boolean, default: false },
})
const emit = defineEmits(['update:open', 'confirm', 'cancel'])

const { t } = useI18n()
const typed = ref('')
watch(() => props.open, o => { if (o) typed.value = '' })
const canConfirm = computed(() => !props.requireText || typed.value.trim().toUpperCase() === props.requireText.toUpperCase())

function cancel() { emit('update:open', false); emit('cancel') }
</script>

<template>
    <Dialog :open="open" @update:open="v => { emit('update:open', v); if (!v) emit('cancel') }">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle class="text-lg">{{ title }}</DialogTitle>
                <DialogDescription v-if="description" class="text-sm">{{ description }}</DialogDescription>
            </DialogHeader>
            <slot />
            <div v-if="requireText" class="space-y-1.5">
                <label class="block text-sm font-medium" for="confirm-text">
                    {{ t('app.type_to_confirm', { word: requireText }) }}
                </label>
                <input id="confirm-text" v-model="typed" autocomplete="off"
                       class="w-full min-h-[44px] rounded-lg border border-input bg-background px-3 text-base" />
            </div>
            <DialogFooter class="gap-2 sm:gap-2">
                <button type="button" class="min-h-[44px] rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent" @click="cancel">
                    {{ cancelLabel || t('app.cancel') }}
                </button>
                <button type="button" :disabled="!canConfirm || loading"
                        class="min-h-[44px] rounded-lg px-4 text-sm font-semibold disabled:opacity-50"
                        :class="tone === 'danger' ? 'bg-danger text-background hover:opacity-90' : 'bg-primary text-primary-foreground hover:opacity-90'"
                        @click="emit('confirm')">
                    {{ loading ? t('app.processing') : confirmLabel }}
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
