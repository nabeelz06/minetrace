<script setup>
/**
 * DataTable — tabel responsif: tabel di ≥ md, KARTU di ponsel (tanpa scroll horizontal).
 * Menangani state loading (skeleton), error (+coba lagi), dan kosong (EmptyState) secara seragam.
 * Paginasi/sort/filter dilakukan SERVER-SIDE oleh pemanggil; komponen ini hanya menampilkan.
 *
 * columns: [{ key, label, align?: 'left'|'right'|'center', primary?: boolean (judul kartu), hideOnMobile?: boolean }]
 * Slot: `cell-{key}` ({ row, value }), `actions` ({ row }), `empty`.
 */
import { computed } from 'vue'
import EmptyState from '@/components/shared/EmptyState.vue'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    columns:     { type: Array, required: true },
    rows:        { type: Array, default: () => [] },
    rowKey:      { type: String, default: 'id' },
    loading:     { type: Boolean, default: false },
    error:       { type: String, default: null },
    caption:     { type: String, default: null },
    emptyTitle:  { type: String, default: null },
    emptyText:   { type: String, default: null },
    emptyIcon:   { type: String, default: 'package' },
    rowClass:    { type: Function, default: null },
})
const emit = defineEmits(['retry', 'row-click'])

const { t } = useI18n()
const primary = computed(() => props.columns.find(c => c.primary) ?? props.columns[0])
const secondary = computed(() => props.columns.filter(c => c !== primary.value && !c.hideOnMobile))
const align = c => (c.align === 'right' ? 'text-right' : c.align === 'center' ? 'text-center' : 'text-left')
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-border bg-card">
        <!-- Error -->
        <div v-if="error" class="p-6 text-center" role="alert">
            <p class="font-semibold text-danger">{{ error }}</p>
            <button type="button" class="mt-3 min-h-[44px] rounded-lg border border-input px-4 text-sm font-semibold hover:bg-accent" @click="emit('retry')">
                {{ t('app.retry') }}
            </button>
        </div>

        <!-- Loading -->
        <div v-else-if="loading && !rows.length" class="space-y-2 p-4" aria-busy="true" :aria-label="t('app.loading')">
            <div v-for="i in 5" :key="i" class="h-10 animate-pulse rounded-md bg-muted" />
        </div>

        <!-- Kosong -->
        <slot v-else-if="!rows.length" name="empty">
            <EmptyState :icon="emptyIcon" :title="emptyTitle || t('app.no_data')" :description="emptyText" />
        </slot>

        <template v-else>
            <!-- Desktop -->
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full text-sm">
                    <caption v-if="caption" class="sr-only">{{ caption }}</caption>
                    <thead class="bg-muted/60 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th v-for="c in columns" :key="c.key" scope="col" class="px-4 py-3" :class="align(c)">{{ c.label }}</th>
                            <th v-if="$slots.actions" scope="col" class="px-4 py-3"><span class="sr-only">{{ t('app.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in rows" :key="row[rowKey]" class="hover:bg-accent/40" :class="rowClass?.(row)" @click="emit('row-click', row)">
                            <td v-for="c in columns" :key="c.key" class="px-4 py-3 align-middle" :class="align(c)">
                                <slot :name="`cell-${c.key}`" :row="row" :value="row[c.key]">{{ row[c.key] ?? '—' }}</slot>
                            </td>
                            <td v-if="$slots.actions" class="px-4 py-3 text-right"><slot name="actions" :row="row" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Ponsel: kartu -->
            <ul class="divide-y divide-border md:hidden">
                <li v-for="row in rows" :key="row[rowKey]" class="space-y-2 p-4" :class="rowClass?.(row)" @click="emit('row-click', row)">
                    <div class="font-semibold text-foreground">
                        <slot :name="`cell-${primary.key}`" :row="row" :value="row[primary.key]">{{ row[primary.key] ?? '—' }}</slot>
                    </div>
                    <dl class="grid grid-cols-2 gap-x-3 gap-y-1 text-sm">
                        <template v-for="c in secondary" :key="c.key">
                            <dt class="text-muted-foreground">{{ c.label }}</dt>
                            <dd class="text-right"><slot :name="`cell-${c.key}`" :row="row" :value="row[c.key]">{{ row[c.key] ?? '—' }}</slot></dd>
                        </template>
                    </dl>
                    <div v-if="$slots.actions" class="flex justify-end pt-1"><slot name="actions" :row="row" /></div>
                </li>
            </ul>
        </template>
    </div>
</template>
