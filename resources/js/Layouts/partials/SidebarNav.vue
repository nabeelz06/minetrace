<script setup>
/**
 * SidebarNav — isi sidebar (logo, kartu perusahaan, menu). Dipakai di sidebar desktop & drawer ponsel.
 * Semantik: <nav aria-label>, aria-current="page" pada item aktif, accordion dengan aria-expanded.
 */
import { computed, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import AppIcon from '@/components/app/AppIcon.vue'
import BrandLogo from '@/components/shared/BrandLogo.vue'
import { isActivePath } from '@/lib/navigation'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    items:     { type: Array, required: true },
    path:      { type: String, required: true },
    collapsed: { type: Boolean, default: false },
    company:   { type: Object, default: null },
    roleLabel: { type: String, default: '' },
})
const emit = defineEmits(['navigate'])

const { t, lang } = useI18n()
const label = item => item.labelKey ? t(item.labelKey) : (lang.value === 'en' ? item.label_en : item.label)

// Accordion: terbuka otomatis bila anak aktif
const open = ref(null)
watch(() => props.path, () => {
    const parent = props.items.find(i => i.children?.some(c => isActivePath(props.path, c.href)))
    if (parent) open.value = parent.key
}, { immediate: true })

const showText = computed(() => !props.collapsed)
const baseItem = 'group flex min-h-[44px] w-full items-center gap-3 rounded-lg px-3 text-sm font-medium'
const stateClass = active => active
    ? 'bg-primary/10 text-sidebar-primary'
    : 'text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-foreground'
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="flex h-14 flex-shrink-0 items-center border-b border-sidebar-border px-3">
            <BrandLogo :size="32" :show-name="showText" :tagline="showText ? t('brand.tagline_short') : ''" class="text-sidebar-foreground" />
        </div>

        <div v-if="company && showText" class="mx-3 mt-3 rounded-lg border border-sidebar-border bg-sidebar-accent/50 px-3 py-2">
            <p class="text-xs font-medium text-muted-foreground">{{ t('nav.company') }}</p>
            <p class="truncate text-sm font-semibold text-sidebar-foreground">{{ company.name }}</p>
            <p v-if="roleLabel" class="truncate text-xs text-muted-foreground">{{ roleLabel }}</p>
        </div>

        <nav :aria-label="t('nav.main')" class="mt-3 flex-1 space-y-1 overflow-y-auto px-2 pb-3">
            <template v-for="item in items" :key="item.key">
                <!-- Dengan anak: accordion -->
                <div v-if="item.children?.length">
                    <button type="button" :class="[baseItem, stateClass(item.children.some(c => isActivePath(path, c.href)))]"
                            :aria-expanded="open === item.key" :aria-controls="`sub-${item.key}`" :title="collapsed ? label(item) : undefined"
                            @click="open = open === item.key ? null : item.key">
                        <AppIcon :name="item.icon" :size="20" />
                        <span v-if="showText" class="flex-1 truncate text-left">{{ label(item) }}</span>
                        <AppIcon v-if="showText" name="chevron-down" :size="16" :class="open === item.key ? 'rotate-180' : ''" />
                    </button>
                    <ul v-show="open === item.key && showText" :id="`sub-${item.key}`" class="mt-1 space-y-1 border-l border-sidebar-border pl-3 ml-5">
                        <li v-for="child in item.children" :key="child.key">
                            <Link :href="child.href" :class="[baseItem, 'min-h-[40px] text-[0.8125rem]', stateClass(isActivePath(path, child.href))]"
                                  :aria-current="isActivePath(path, child.href) ? 'page' : undefined" @click="emit('navigate')">
                                <AppIcon :name="child.icon" :size="18" />
                                <span class="truncate">{{ label(child) }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>

                <!-- Tanpa anak -->
                <Link v-else :href="item.href" :class="[baseItem, stateClass(isActivePath(path, item.href))]"
                      :aria-current="isActivePath(path, item.href) ? 'page' : undefined" :title="collapsed ? label(item) : undefined"
                      @click="emit('navigate')">
                    <AppIcon :name="item.icon" :size="20" :label="collapsed ? label(item) : undefined" />
                    <span v-if="showText" class="truncate">{{ label(item) }}</span>
                </Link>
            </template>
        </nav>
    </div>
</template>
