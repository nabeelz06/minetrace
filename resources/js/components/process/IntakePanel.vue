<script setup>
/**
 * Receiving & Wet: drum besi yang sudah tiba ditimbang + dicek kadar Sn, lalu langsung masuk himpunan Wet.
 * Juga: batch lama yang sudah dikirim ke Wet sebelum rilis diterima ke Wet tanpa kadar Sn.
 */
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import WeightDisplay from '@/components/shared/WeightDisplay.vue'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    intake:      { type: Array, default: () => [] },
    legacyToWet: { type: Array, default: () => [] },
    canOperate:  { type: Boolean, default: false },
})

const { lang } = useI18n()
const en = computed(() => lang.value === 'en')

const busy    = ref(false)
const weights = ref({})
const sn      = ref({})

function submitIntake(b) {
    busy.value = true
    router.post(`/company/process/receiving/intake/${b.id}`, { weight: weights.value[b.id], sn_grade_pct: sn.value[b.id] }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false },
    })
}

function receiveIntoWet(b) {
    busy.value = true
    router.post('/company/process/wet_process/receive-batch', { batch_id: b.id }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false },
    })
}
</script>

<template>
    <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in">
        <div class="px-5 py-4 border-b border-border">
            <h3 class="font-heading text-sm font-bold text-foreground">
                {{ en ? 'Steel drums — weigh & Sn grade' : 'Drum besi — timbang & kadar Sn' }}
            </h3>
            <p class="text-xs text-muted-foreground mt-0.5">
                {{ en
                    ? 'After saving weight and Sn grade the drum is poured into Wet Process (its tag is released).'
                    : 'Setelah berat dan kadar Sn disimpan, isi drum dituang ke Wet Process (tag-nya dilepas).' }}
            </p>
        </div>

        <p v-if="!intake.length && !legacyToWet.length" class="px-5 py-4 text-xs text-muted-foreground">
            {{ en ? 'No steel drums waiting. Tap a drum at the reader or receive it from the incoming list.' : 'Tidak ada drum besi yang menunggu. Tap drum di reader atau terima dari daftar Batch Masuk.' }}
        </p>

        <div class="divide-y divide-border">
            <div v-for="b in intake" :key="b.id" class="px-5 py-3 flex items-center gap-3 flex-wrap">
                <div class="min-w-0 flex-1">
                    <Link :href="`/company/batches/${b.id}`" class="font-mono text-xs font-bold hover:text-primary">{{ b.batch_code }}</Link>
                    <p class="text-xs text-muted-foreground">
                        {{ b.container_label }} · {{ b.rfid_tag ?? '—' }} · Lab: <WeightDisplay :kg="b.current_weight" size="xs"/>
                    </p>
                </div>
                <form v-if="canOperate" class="flex items-center gap-2" @submit.prevent="submitIntake(b)">
                    <input v-model="weights[b.id]" type="number" step="0.001" min="0.001"
                           :placeholder="en ? 'Weight (kg)' : 'Berat (kg)'"
                           class="h-8 px-3 rounded-lg border border-border bg-background text-xs w-28"/>
                    <input v-model="sn[b.id]" type="number" step="0.001" min="0" max="100"
                           :placeholder="en ? 'Sn grade (%)' : 'Kadar Sn (%)'"
                           class="h-8 px-3 rounded-lg border border-border bg-background text-xs w-28"/>
                    <button type="submit" :disabled="busy || !weights[b.id] || sn[b.id] === undefined || sn[b.id] === ''"
                            class="px-3 h-8 rounded-lg text-xs font-bold text-white disabled:opacity-50"
                            style="background:hsl(var(--primary))">
                        {{ en ? 'Save & pour to Wet' : 'Simpan & tuang ke Wet' }}
                    </button>
                </form>
            </div>

            <div v-for="b in legacyToWet" :key="`l-${b.id}`" class="px-5 py-3 flex items-center gap-3">
                <div class="min-w-0 flex-1">
                    <Link :href="`/company/batches/${b.id}`" class="font-mono text-xs font-bold hover:text-primary">{{ b.batch_code }}</Link>
                    <p class="text-xs text-muted-foreground">
                        {{ en ? 'Sent to Wet before the merge' : 'Dikirim ke Wet sebelum penggabungan' }} · <WeightDisplay :kg="b.current_weight" size="xs"/>
                    </p>
                </div>
                <button v-if="canOperate" :disabled="busy" @click="receiveIntoWet(b)"
                        class="px-3 h-8 rounded-lg text-xs font-bold text-white disabled:opacity-50"
                        style="background:hsl(var(--info))">
                    {{ en ? 'Receive into Wet' : 'Terima ke Wet' }}
                </button>
            </div>
        </div>
    </div>
</template>
