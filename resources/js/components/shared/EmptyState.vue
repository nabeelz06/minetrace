<script setup>
/**
 * EmptyState — tampilan kosong yang konsisten di seluruh halaman.
 * Gunakan ketika tidak ada data untuk ditampilkan.
 */
defineProps({
    icon:        { type: String, default: '📦' },
    title:       { type: String, required: true },
    description: { type: String, default: null },
    actionLabel: { type: String, default: null },
    actionHref:  { type: String, default: null },
    size:        { type: String, default: 'md' }, // 'sm' | 'md' | 'lg'
})

const emit = defineEmits(['action'])

const paddingClass = { sm: 'py-8', md: 'py-14', lg: 'py-20' }
const iconClass    = { sm: 'text-3xl', md: 'text-5xl', lg: 'text-6xl' }
</script>

<template>
    <div class="text-center space-y-4" :class="paddingClass[size]">
        <div :class="iconClass[size]">{{ icon }}</div>
        <div>
            <p class="font-semibold text-foreground text-sm">{{ title }}</p>
            <p v-if="description" class="text-xs text-muted-foreground mt-1 max-w-xs mx-auto">
                {{ description }}
            </p>
        </div>
        <div v-if="actionLabel">
            <component
                :is="actionHref ? 'a' : 'button'"
                :href="actionHref || undefined"
                @click="!actionHref && emit('action')"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-white transition-all duration-200 hover:shadow-md hover:-translate-y-0.5 active:scale-[0.97]"
                style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));"
            >
                {{ actionLabel }}
            </component>
        </div>
        <slot />
    </div>
</template>