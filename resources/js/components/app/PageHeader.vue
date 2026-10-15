<script setup>
/** PageHeader — judul halaman (h1) + deskripsi + aksi. Satu aksi primer per layar (di slot `actions`). */
import AppIcon from '@/components/app/AppIcon.vue'

defineProps({
    title:       { type: String, required: true },
    description: { type: String, default: null },
    icon:        { type: String, default: null },
})
</script>

<template>
    <header class="flex flex-wrap items-start justify-between gap-3 mb-6">
        <div class="min-w-0">
            <h1 class="flex items-center gap-2 text-2xl font-bold tracking-tight text-foreground">
                <AppIcon v-if="icon" :name="icon" :size="24" class="text-muted-foreground" />
                <span class="truncate">{{ title }}</span>
                <slot name="badge" />
            </h1>
            <p v-if="description" class="mt-1 text-sm text-muted-foreground">{{ description }}</p>
            <slot name="meta" />
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
    </header>
</template>
