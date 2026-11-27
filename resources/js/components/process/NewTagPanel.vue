<script setup>
/**
 * Receiving & Wet: UID baru yang terbaca reader gabungan. Reader tidak bisa membedakan timah basah yang baru datang
 * dari container hasil Wet (timbangan sama), jadi operator memilih di sini:
 *  - Batch baru: timah basah (kadar Sn sekitar 60%, diuji saat tiba). Kadar Sn boleh diisi nanti setelah uji.
 *  - Hasil proses Wet: container dari himpunan Wet (lalu ditimbang di panel himpunan).
 * Daftar disegarkan otomatis tiap 4 detik. Kartu hilang sendiri setelah 10 menit tanpa pembacaan.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppIcon from '@/components/app/AppIcon.vue'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    tags:        { type: Array, default: () => [] },
    rawProducts: { type: Array, default: () => [] },
    poolHasInput:{ type: Boolean, default: false },
    canOperate:  { type: Boolean, default: false },
})

const { lang } = useI18n()
const en = computed(() => lang.value === 'en')

const busy   = ref(false)
const open   = ref(null)          // UID yang form batch barunya terbuka
const form   = ref({ product_code_id: null, weight: '', sn_grade_pct: '' })

let timer = null
onMounted(() => {
    timer = setInterval(() => {
        if (busy.value || open.value || document.hidden) return
        router.reload({ only: ['new_tags', 'pool', 'intake'], preserveScroll: true, preserveState: true })
    }, 4000)
})
onUnmounted(() => clearInterval(timer))

function startBatch(t) {
    open.value = t.uid
    form.value = { product_code_id: props.rawProducts[0]?.id ?? null, weight: '', sn_grade_pct: '' }
}

function submitBatch(t) {
    busy.value = true
    router.post(`/company/process/receiving/new-tags/${encodeURIComponent(t.uid)}/batch`, {
        product_code_id: form.value.product_code_id,
        weight: form.value.weight,
        sn_grade_pct: form.value.sn_grade_pct === '' ? null : form.value.sn_grade_pct,
        reuse: !!t.recycled,   // tag bekas: memilih = memakainya ulang (server menandai tag sebagai baru lagi)
    }, {
        preserveScroll: true,
        onSuccess: () => { open.value = null },
        onFinish: () => { busy.value = false },
    })
}

function asWet(t) {
    busy.value = true
    router.post(`/company/process/receiving/new-tags/${encodeURIComponent(t.uid)}/wet`, { reuse: !!t.recycled }, {
        preserveScroll: true,
        onFinish: () => { busy.value = false },
    })
}

function dismiss(t) {
    busy.value = true
    router.delete(`/company/process/receiving/new-tags/${encodeURIComponent(t.uid)}`, {
        preserveScroll: true,
        onFinish: () => { busy.value = false; if (open.value === t.uid) open.value = null },
    })
}
</script>

<template>
    <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in">
        <div class="px-5 py-4 border-b border-border flex items-center gap-2">
            <h3 class="font-heading text-sm font-bold text-foreground">
                {{ en ? 'New tags at the reader' : 'Tag baru di reader' }}
            </h3>
            <span v-if="tags.length" class="px-2 py-0.5 rounded-full text-[10px] font-bold text-white" style="background:hsl(var(--warning))">
                {{ tags.length }}
            </span>
        </div>
        <p class="px-5 pt-3 text-xs text-muted-foreground">
            {{ en
                ? 'Choose what each new tag is: a new batch of wet tin that just arrived, or a container out of Wet Process. Tags marked "Used" were used before — choosing one reuses it (its history is kept).'
                : 'Pilih jenis tiap tag baru: batch baru timah basah yang baru datang, atau container hasil proses Wet. Tag bertanda "Bekas" pernah dipakai — memilihnya berarti memakai ulang (riwayat tetap tersimpan).' }}
        </p>

        <p v-if="!tags.length" class="px-5 py-4 text-xs text-muted-foreground">
            {{ en ? 'No new tags. Place a new tag near the reader.' : 'Tidak ada tag baru. Dekatkan tag baru ke reader.' }}
        </p>

        <div class="divide-y divide-border">
            <div v-for="t in tags" :key="t.uid" class="px-5 py-3">
                <div class="flex items-center gap-3 flex-wrap">
                    <div class="min-w-0 flex-1">
                        <p class="font-mono text-xs font-bold">
                            {{ t.uid }}
                            <span v-if="t.recycled" class="ml-1.5 inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 align-middle font-sans text-[10px] font-bold bg-warning-soft text-warning">
                                <AppIcon name="recycle" :size="11" />{{ en ? 'Used tag — will be reused' : 'Tag bekas — dipakai ulang' }}
                            </span>
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ en ? 'Last read' : 'Terakhir terbaca' }} {{ t.last_seen }} · {{ t.reads }}× ·
                            {{ en ? `clears in ${t.expires_in_min} min` : `hilang dalam ${t.expires_in_min} menit` }}
                        </p>
                    </div>
                    <template v-if="canOperate && open !== t.uid">
                        <button :disabled="busy" @click="startBatch(t)"
                                class="px-3 h-8 rounded-lg text-xs font-bold text-white disabled:opacity-50"
                                style="background:hsl(var(--primary))">
                            {{ en ? 'New batch' : 'Batch baru' }}
                        </button>
                        <button :disabled="busy || !poolHasInput" @click="asWet(t)"
                                :title="poolHasInput ? '' : (en ? 'Wet pool is empty' : 'Himpunan Wet kosong')"
                                class="px-3 h-8 rounded-lg text-xs font-bold text-white disabled:opacity-50"
                                style="background:hsl(var(--info))">
                            {{ en ? 'Wet output' : 'Hasil proses Wet' }}
                        </button>
                        <button :disabled="busy" @click="dismiss(t)"
                                class="px-3 h-8 rounded-lg text-xs font-semibold border border-border disabled:opacity-50">
                            {{ en ? 'Ignore' : 'Abaikan' }}
                        </button>
                    </template>
                </div>

                <form v-if="open === t.uid" class="mt-3 flex items-center gap-2 flex-wrap" @submit.prevent="submitBatch(t)">
                    <select v-model="form.product_code_id" class="h-8 px-2 rounded-lg border border-border bg-background text-xs">
                        <option v-for="p in rawProducts" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
                    </select>
                    <input v-model="form.weight" type="number" step="0.001" min="0.001" required
                           :placeholder="en ? 'Weight (kg)' : 'Berat (kg)'"
                           class="h-8 px-3 rounded-lg border border-border bg-background text-xs w-28"/>
                    <input v-model="form.sn_grade_pct" type="number" step="0.001" min="0" max="100"
                           :placeholder="en ? 'Sn grade % (optional)' : 'Kadar Sn % (boleh nanti)'"
                           class="h-8 px-3 rounded-lg border border-border bg-background text-xs w-40"/>
                    <button type="submit" :disabled="busy || !form.weight || !form.product_code_id"
                            class="px-3 h-8 rounded-lg text-xs font-bold text-white disabled:opacity-50"
                            style="background:hsl(var(--primary))">
                        {{ form.sn_grade_pct === '' ? (en ? 'Save, test later' : 'Simpan, uji nanti') : (en ? 'Save & pour to Wet' : 'Simpan & tuang ke Wet') }}
                    </button>
                    <button type="button" @click="open = null" class="px-3 h-8 rounded-lg text-xs border border-border">
                        {{ en ? 'Cancel' : 'Batal' }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
