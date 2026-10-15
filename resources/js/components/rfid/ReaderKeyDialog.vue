<script setup>
/** ReaderKeyDialog — tampilkan kunci perangkat SEKALI (setelah dibuat/dirotasi) beserta cara memakainya. */
import { ref } from 'vue'
import { useClipboard } from '@vueuse/core'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import AppIcon from '@/components/app/AppIcon.vue'
import { useI18n } from '@/composables/core/useI18n.js'

defineProps({
    open:   { type: Boolean, default: false },
    reader: { type: Object, default: null },
    keyValue: { type: String, default: '' },
})
const emit = defineEmits(['update:open'])

const { t } = useI18n()
const { copy, copied } = useClipboard({ copiedDuring: 2500 })
const origin = window.location.origin
const endpoints = [
    ['POST', '/api/rfid/register'], ['POST', '/api/rfid/login'], ['POST', '/api/rfid/logout'],
    ['POST', '/api/rfid/heartbeat'], ['POST', '/api/v1/hardware/rfid-scan'],
]
</script>

<template>
    <Dialog :open="open" @update:open="v => emit('update:open', v)">
        <DialogContent class="max-w-lg" @interact-outside.prevent>
            <DialogHeader>
                <DialogTitle class="text-lg">{{ t('rfid.dev.key_title') }}</DialogTitle>
                <DialogDescription class="text-sm">{{ t('rfid.dev.key_desc') }}</DialogDescription>
            </DialogHeader>

            <div v-if="reader" class="text-sm text-muted-foreground">{{ reader.name }} · {{ reader.code }}</div>

            <div class="flex items-center gap-2">
                <code class="min-w-0 flex-1 select-all break-all rounded-lg border border-border bg-muted px-3 py-3 text-sm font-semibold" data-testid="device-key">{{ keyValue }}</code>
                <button type="button" class="inline-flex min-h-[48px] items-center gap-2 rounded-lg border border-input px-3 text-sm font-semibold hover:bg-accent" @click="copy(keyValue)">
                    <AppIcon :name="copied ? 'check' : 'copy'" :size="16" />{{ copied ? t('rfid.dev.copied') : t('rfid.dev.copy') }}
                </button>
            </div>

            <div class="space-y-1.5">
                <p class="text-sm font-medium">{{ t('rfid.dev.endpoints') }}</p>
                <ul class="space-y-1 rounded-lg bg-muted p-3 font-mono text-xs">
                    <li v-for="[m, p] in endpoints" :key="p"><span class="font-bold">{{ m }}</span> {{ origin }}{{ p }}</li>
                    <li class="pt-1 text-muted-foreground">Header: X-Device-Key · X-Event-Id (opsional)</li>
                </ul>
            </div>

            <DialogFooter>
                <button type="button" class="min-h-[48px] rounded-lg bg-primary px-5 text-sm font-semibold text-primary-foreground hover:opacity-90" @click="emit('update:open', false)">{{ t('rfid.dev.done') }}</button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
