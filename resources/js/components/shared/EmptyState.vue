<script setup>
/**
 * EmptyState — tampilan kosong yang membimbing (apa yang terjadi + apa yang bisa dilakukan).
 * `icon` = nama ikon lucide (mis. 'package'); teks lama (emoji) tetap dirender apa adanya.
 */
import { computed } from 'vue'
import AppIcon from '@/components/app/AppIcon.vue'

const props = defineProps({
    icon:        { type: String, default: 'package' },
    title:       { type: String, required: true },
    description: { type: String, default: null },
    actionLabel: { type: String, default: null },
    actionHref:  { type: String, default: null },
    size:        { type: String, default: 'md' }, // 'sm' | 'md' | 'lg'
})

const emit = defineEmits(['action'])
const paddingClass = { sm: 'py-8', md: 'py-12', lg: 'py-16' }
const isIconName = computed(() => /^[a-z0-9-]+$/.test(props.icon))
</script>

<template>
    <div class="text-center space-y-3" :class="paddingClass[size]">
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
            <AppIcon v-if="isIconName" :name="icon" :size="24" />
            <span v-else class="text-2xl" aria-hidden="true">{{ icon }}</span>
        </div>
        <div>
            <p class="font-semibold text-foreground">{{ title }}</p>
            <p v-if="description" class="text-sm text-muted-foreground mt-1 max-w-sm mx-auto">{{ description }}</p>
        </div>
        <div v-if="actionLabel">
            <component :is="actionHref ? 'a' : 'button'" :href="actionHref || undefined" type="button"
                       class="inline-flex items-center justify-center gap-2 min-h-[44px] px-4 rounded-lg bg-primary text-primary-foreground text-sm font-semibold hover:opacity-90"
                       @click="!actionHref && emit('action')">
                {{ actionLabel }}
            </component>
        </div>
        <slot />
    </div>
</template>
