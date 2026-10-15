<script setup>
/**
 * VerifyBatch — halaman publik hasil pindai QR karung. Tanpa login.
 * Hanya menampilkan data non-sensitif (kode, produk, perusahaan, tahap, status, lokasi, linimasa).
 */
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import { CheckCircle2, XCircle, AlertTriangle } from 'lucide-vue-next'
import PublicLayout from '@/Layouts/PublicLayout.vue'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    state:       { type: String, required: true },   // valid | invalid | malformed
    batch:       { type: Object, default: null },
    timeline:    { type: Array,  default: () => [] },
    verified_at: { type: String, default: null },
})

const { t, lang, formatDate } = useI18n()

const statusLabel = computed(() => t(`verify.status.${props.batch?.status}`, {}, props.batch?.status))
const stageLabel  = (s) => (lang.value === 'en' ? s.en : s.id)
</script>

<template>
    <Head :title="t('verify.title')">
        <meta name="robots" content="noindex, nofollow" />
    </Head>

    <PublicLayout>
        <!-- Valid -->
        <section v-if="state === 'valid'" aria-live="polite">
            <div class="flex items-start gap-3 rounded-lg border border-border bg-card p-4">
                <CheckCircle2 class="mt-0.5 h-8 w-8 flex-shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden="true" />
                <div>
                    <h1 class="text-lg font-semibold">{{ t('verify.valid_title') }}</h1>
                    <p class="text-sm text-muted-foreground">{{ t('verify.valid_desc') }}</p>
                </div>
            </div>

            <dl class="mt-4 divide-y divide-border rounded-lg border border-border bg-card text-sm">
                <div class="flex justify-between gap-4 px-4 py-3">
                    <dt class="text-muted-foreground">{{ t('verify.batch_code') }}</dt>
                    <dd class="break-all text-right font-mono font-medium">{{ batch.batch_code }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-4 py-3">
                    <dt class="text-muted-foreground">{{ t('verify.product') }}</dt>
                    <dd class="text-right font-medium">{{ batch.product || '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-4 py-3">
                    <dt class="text-muted-foreground">{{ t('verify.company') }}</dt>
                    <dd class="text-right font-medium">{{ batch.company || '—' }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-4 py-3">
                    <dt class="text-muted-foreground">{{ t('verify.stage') }}</dt>
                    <dd class="text-right font-medium">{{ stageLabel(batch.stage) }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-4 py-3">
                    <dt class="text-muted-foreground">{{ t('verify.status_label') }}</dt>
                    <dd class="text-right font-medium">{{ statusLabel }}</dd>
                </div>
                <div class="flex justify-between gap-4 px-4 py-3">
                    <dt class="text-muted-foreground">{{ t('verify.location') }}</dt>
                    <dd class="text-right font-medium">{{ batch.location || '—' }}</dd>
                </div>
            </dl>

            <div v-if="timeline.length" class="mt-6">
                <h2 class="mb-3 text-sm font-semibold">{{ t('verify.timeline') }}</h2>
                <ol class="space-y-3 border-l border-border pl-4">
                    <li v-for="(step, i) in timeline" :key="i" class="relative">
                        <span class="absolute -left-[21px] top-1.5 h-2.5 w-2.5 rounded-full bg-primary" aria-hidden="true" />
                        <p class="text-sm font-medium">{{ stageLabel(step.stage) }}</p>
                        <p class="text-xs text-muted-foreground">{{ formatDate(step.date) }}</p>
                    </li>
                </ol>
            </div>

            <p class="mt-6 text-xs text-muted-foreground">{{ t('verify.privacy_note') }}</p>
        </section>

        <!-- Tidak valid / parameter rusak -->
        <section v-else aria-live="polite">
            <div class="flex items-start gap-3 rounded-lg border border-border bg-card p-4">
                <component :is="state === 'malformed' ? AlertTriangle : XCircle"
                           class="mt-0.5 h-8 w-8 flex-shrink-0 text-red-600 dark:text-red-400" aria-hidden="true" />
                <div>
                    <h1 class="text-lg font-semibold">
                        {{ state === 'malformed' ? t('verify.malformed_title') : t('verify.invalid_title') }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ state === 'malformed' ? t('verify.malformed_desc') : t('verify.invalid_desc') }}
                    </p>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
