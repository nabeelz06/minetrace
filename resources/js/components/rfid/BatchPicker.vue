<script setup>
/**
 * BatchPicker — cari & pilih batch (pencarian di server, dibatasi 20 hasil; aman untuk ribuan batch).
 * Baris besar (target sentuh ≥ 56px). v-model = objek batch terpilih.
 */
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { useDebounceFn } from '@vueuse/core'
import AppIcon from '@/components/app/AppIcon.vue'
import StatusBadge from '@/components/shared/StatusBadge.vue'
import EmptyState from '@/components/shared/EmptyState.vue'
import { rfidApi } from '@/composables/rfid/useRfidApi.js'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    modelValue: { type: Object, default: null },
    tag:        { type: String, default: 'untagged' },   // untagged | tagged | all
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const q = ref('')
const items = ref([])
const loading = ref(false)
const failed = ref(false)
let controller = null

async function load() {
    controller?.abort()
    controller = new AbortController()
    loading.value = true
    failed.value = false
    try {
        const res = await rfidApi.searchBatches({ q: q.value, tag: props.tag, limit: 20 }, { signal: controller.signal })
        if (res.ok) items.value = res.data
        else failed.value = true
    } catch (e) {
        if (e.name !== 'AbortError') failed.value = true
    } finally {
        loading.value = false
    }
}

const debounced = useDebounceFn(load, 250)
watch(q, debounced)
onMounted(load)
onBeforeUnmount(() => controller?.abort())
</script>

<template>
    <div class="space-y-3">
        <div class="relative">
            <AppIcon name="search" :size="18" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" />
            <input v-model="q" type="search" :aria-label="t('rfid.reg.search_label')" :placeholder="t('rfid.reg.search')" autocomplete="off"
                   class="min-h-[48px] w-full rounded-lg border border-input bg-background pl-10 pr-3 text-base" />
        </div>

        <p class="text-sm font-medium text-muted-foreground" aria-live="polite">{{ t('rfid.reg.list_title') }}</p>

        <div v-if="failed" role="alert" class="rounded-lg border border-danger/30 bg-danger-soft p-3 text-sm text-danger">
            {{ t('rfid.err.network_error') }}
            <button type="button" class="ml-2 underline" @click="load">{{ t('app.retry') }}</button>
        </div>

        <ul v-else-if="items.length" class="space-y-2" :aria-busy="loading">
            <li v-for="b in items" :key="b.id">
                <button type="button" class="flex min-h-[60px] w-full items-center gap-3 rounded-xl border bg-card p-3 text-left hover:bg-accent"
                        :class="modelValue?.id === b.id ? 'border-primary ring-2 ring-primary/30' : 'border-border'"
                        :aria-pressed="modelValue?.id === b.id" @click="emit('update:modelValue', b)">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-mono text-sm font-semibold text-foreground">{{ b.batch_code }}</span>
                        <span class="block text-sm text-muted-foreground">{{ b.product_code }} · {{ b.stage }}</span>
                    </span>
                    <StatusBadge :status="b.status" size="xs" />
                    <AppIcon v-if="modelValue?.id === b.id" name="circle-check" :size="22" class="text-primary" />
                </button>
            </li>
        </ul>

        <EmptyState v-else-if="!loading" icon="tag" :title="t('rfid.reg.none')" size="sm" />
        <div v-else class="space-y-2" aria-hidden="true"><div v-for="i in 3" :key="i" class="h-[60px] animate-pulse rounded-xl bg-muted" /></div>
    </div>
</template>
