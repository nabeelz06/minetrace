<script setup>
/**
 * Panel penghimpunan Wet/Dry: batch yang sedang dihimpun, drum keluar (tanpa UR4), drum menunggu timbang,
 * "Selesai himpunan" (Dry) dan "Batalkan drum". Aturan ada di server (PoolService).
 */
import { ref, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import WeightDisplay from '@/components/shared/WeightDisplay.vue'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    stage:           { type: String, required: true },
    pool:            { type: Object, required: true },
    outputProducts:  { type: Array,  default: () => [] },
    canOperate:      { type: Boolean, default: false },
})

const { lang } = useI18n()
const en = computed(() => lang.value === 'en')
const isDry = computed(() => props.stage === 'dry_process')

const collecting = computed(() => props.pool?.collecting)
const outputting = computed(() => props.pool?.outputting)
const drums      = computed(() => props.pool?.drums ?? [])

// Pool yang sedang dikeluarkan (Dry) atau yang akan menjadi drum berikutnya.
const activePool = computed(() => outputting.value ?? collecting.value)
const outputtingDrums = computed(() => drums.value.filter(d => outputting.value && d.pool_id === outputting.value.id))
const canClose = computed(() => isDry.value && outputting.value
    && outputtingDrums.value.length > 0 && outputtingDrums.value.every(d => d.weight))

const busy = ref(false)
const uid  = ref('')
const weights  = ref({})
const products = ref({})
const cancelFor    = ref(null)
const cancelReason = ref('')

function post(url, data, after) {
    busy.value = true
    router.post(url, data, {
        preserveScroll: true,
        onSuccess: () => after?.(),
        onFinish: () => { busy.value = false },
    })
}

function submitDrum() {
    if (!uid.value.trim()) return
    post(`/company/process/${props.stage}/pool/drum`, { uid: uid.value.trim() }, () => { uid.value = '' })
}

function submitWeight(drum) {
    post(`/company/process/pool/drums/${drum.id}/weight`, {
        weight: weights.value[drum.id],
        product_code_id: isDry.value ? products.value[drum.id] : null,
    })
}

function submitClose() {
    post(`/company/process/pool/${outputting.value.id}/close`, {})
}

function submitCancel(drum) {
    post(`/company/process/pool/drums/${drum.id}/cancel`, { reason: cancelReason.value }, () => {
        cancelFor.value = null
        cancelReason.value = ''
    })
}
</script>

<template>
    <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in">
        <div class="px-5 py-4 border-b border-border flex items-center justify-between gap-3 flex-wrap">
            <div>
                <h3 class="font-heading text-sm font-bold text-foreground">
                    {{ en ? 'Pooling & new drums' : 'Penghimpunan & drum baru' }}
                </h3>
                <p class="text-xs text-muted-foreground mt-0.5">
                    {{ en
                        ? (isDry
                            ? 'Received drums are pooled (old tags released). Each product leaves in its own drum with a new tag (max 3).'
                            : 'Received batches are pooled (old tags released). One new tagged drum takes the whole pool.')
                        : (isDry
                            ? 'Drum yang diterima dihimpun (tag lama dilepas). Tiap produk keluar dalam drum sendiri dengan tag baru (maks 3).'
                            : 'Batch yang diterima dihimpun (tag lama dilepas). Satu drum bertag baru mengambil seluruh himpunan.') }}
                </p>
            </div>
            <form v-if="canOperate" class="flex items-center gap-2" @submit.prevent="submitDrum">
                <input v-model="uid" type="text" :disabled="busy || !activePool"
                       :placeholder="isDry ? (en ? 'New product drum UID' : 'UID drum produk baru') : (en ? 'New container UID' : 'UID container baru')"
                       class="h-8 px-3 rounded-lg border border-border bg-background text-xs font-mono w-52"/>
                <button type="submit" :disabled="busy || !uid.trim() || !activePool"
                        class="px-3 h-8 rounded-lg text-xs font-bold text-white disabled:opacity-50"
                        style="background:hsl(var(--primary))">
                    {{ isDry ? (en ? 'Product drum out' : 'Drum produk keluar') : (en ? 'Container out' : 'Container keluar') }}
                </button>
            </form>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 divide-y lg:divide-y-0 lg:divide-x divide-border">
            <!-- Himpunan -->
            <div class="p-5 space-y-4">
                <template v-for="p in [outputting, collecting].filter(Boolean)" :key="p.id">
                    <div>
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-bold text-foreground">
                                {{ p.status === 'outputting'
                                    ? (en ? 'Pool #' + p.id + ' — draining drums' : 'Himpunan #' + p.id + ' — sedang mengeluarkan drum')
                                    : (en ? 'Pool #' + p.id + ' — collecting' : 'Himpunan #' + p.id + ' — sedang dihimpun') }}
                            </p>
                            <WeightDisplay :kg="p.total_weight" size="xs"/>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ p.inputs.length }} batch · {{ en ? 'since' : 'sejak' }} {{ p.opened_at }}
                        </p>
                        <ul class="mt-2 divide-y divide-border max-h-48 overflow-y-auto">
                            <li v-for="i in p.inputs" :key="i.batch_id" class="py-1.5 flex items-center justify-between gap-2">
                                <Link :href="`/company/batches/${i.batch_id}`" class="font-mono text-xs hover:text-primary">{{ i.batch_code }}</Link>
                                <span class="text-xs text-muted-foreground flex items-center gap-2">
                                    <span v-if="i.old_tag" class="font-mono" :title="en ? 'Released tag' : 'Tag yang dilepas'">{{ i.old_tag }}</span>
                                    <WeightDisplay :kg="i.weight" size="xs"/>
                                </span>
                            </li>
                        </ul>
                    </div>
                </template>
                <p v-if="!outputting && !collecting" class="text-xs text-muted-foreground">
                    {{ en ? 'Nothing pooled yet. Receive batches to start a pool.' : 'Belum ada yang dihimpun. Terima batch untuk memulai himpunan.' }}
                </p>
            </div>

            <!-- Drum menunggu timbang -->
            <div class="p-5 space-y-3">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold text-foreground">{{ isDry ? (en ? 'Product drums awaiting weighing' : 'Drum produk menunggu timbang') : (en ? 'Containers awaiting weighing' : 'Container menunggu timbang') }}</p>
                    <button v-if="isDry && canOperate && outputting" :disabled="busy || !canClose" @click="submitClose"
                            class="px-3 h-8 rounded-lg text-xs font-bold text-white disabled:opacity-50"
                            style="background:hsl(var(--success))">
                        {{ en ? 'Finish pool' : 'Selesai himpunan' }}
                    </button>
                </div>
                <p v-if="!drums.length" class="text-xs text-muted-foreground">
                    {{ en ? 'No drums waiting.' : 'Tidak ada drum yang menunggu.' }}
                </p>
                <div v-for="d in drums" :key="d.id" class="rounded-xl border border-border p-3 space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <Link :href="`/company/batches/${d.id}`" class="font-mono text-xs font-bold hover:text-primary">{{ d.batch_code }}</Link>
                        <span class="font-mono text-xs text-muted-foreground">{{ d.rfid_tag }}</span>
                    </div>
                    <p v-if="d.weight" class="text-xs text-success">
                        {{ en ? 'Weighed' : 'Ditimbang' }}: {{ d.product }} · <WeightDisplay :kg="d.weight" size="xs"/>
                    </p>
                    <form v-if="canOperate" class="flex items-center gap-2 flex-wrap" @submit.prevent="submitWeight(d)">
                        <select v-if="isDry" v-model="products[d.id]" class="h-8 px-2 rounded-lg border border-border bg-background text-xs">
                            <option :value="undefined" disabled>{{ en ? 'Product' : 'Produk' }}</option>
                            <option v-for="p in outputProducts" :key="p.id" :value="p.id">{{ p.code }}</option>
                        </select>
                        <input v-model="weights[d.id]" type="number" step="0.001" min="0.001"
                               :placeholder="en ? 'Weight (kg)' : 'Berat (kg)'"
                               class="h-8 px-3 rounded-lg border border-border bg-background text-xs w-32"/>
                        <button type="submit" :disabled="busy || !weights[d.id] || (isDry && !products[d.id])"
                                class="px-3 h-8 rounded-lg text-xs font-bold text-white disabled:opacity-50"
                                style="background:hsl(var(--primary))">
                            {{ en ? 'Save weight' : 'Simpan berat' }}
                        </button>
                        <button v-if="!d.weight" type="button" @click="cancelFor = cancelFor === d.id ? null : d.id"
                                class="px-3 h-8 rounded-lg text-xs font-bold border border-border text-danger">
                            {{ isDry ? (en ? 'Cancel drum' : 'Batalkan drum') : (en ? 'Cancel container' : 'Batalkan container') }}
                        </button>
                    </form>
                    <form v-if="cancelFor === d.id" class="flex items-center gap-2" @submit.prevent="submitCancel(d)">
                        <input v-model="cancelReason" type="text" minlength="3" maxlength="200"
                               :placeholder="en ? 'Reason (e.g. stray tag)' : 'Alasan (mis. tag nyasar)'"
                               class="h-8 px-3 rounded-lg border border-border bg-background text-xs flex-1"/>
                        <button type="submit" :disabled="busy || cancelReason.trim().length < 3"
                                class="px-3 h-8 rounded-lg text-xs font-bold text-white disabled:opacity-50"
                                style="background:hsl(var(--danger))">
                            {{ en ? 'Confirm cancel' : 'Ya, batalkan' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>
