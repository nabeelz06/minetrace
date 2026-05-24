<script setup>
import { ref, computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    industry_options: Array,
})

const form = useForm({
    code:          '',
    name:          '',
    legal_name:    '',
    industry:      '',
    primary_color: '#3E5C74',
    city:          '',
    province:      '',
    phone:         '',
    email:         '',
    website:       '',
    is_regulator:  false,
    template:      'tin_mining',
})

const showPreview = ref(false)
const codePreview = computed(() => form.code ? form.code.toUpperCase().replace(/[^A-Z0-9_]/g, '') : '')

function submit() {
    form.code = codePreview.value
    form.post('/super-admin/companies')
}

const templates = [
    {
        id:   'tin_mining',
        name: 'Tin Mining',
        desc: 'PT Timah style — Lab Kimia, Wet/Dry Process, Project Plant, Warehouse, Smelter. 11 product codes.',
        icon: '⛏',
    },
    {
        id:   'nickel_mining',
        name: 'Nickel Mining',
        desc: 'PT Vale style — Mine Site, Smelting, Refinery, Warehouse. 5 product codes.',
        icon: '🔩',
    },
    {
        id:   'blank',
        name: 'Blank (Manual)',
        desc: 'Tidak ada template. Setup locations, products, dan workflow secara manual.',
        icon: '📋',
    },
]

const provinces = [
    'Bangka Belitung', 'DKI Jakarta', 'Jawa Barat', 'Jawa Tengah', 'Jawa Timur',
    'Kalimantan Timur', 'Kalimantan Selatan', 'Sulawesi Selatan', 'Papua',
    'Kepulauan Riau', 'Sumatera Selatan', 'Sumatera Utara',
]
</script>

<template>
    <AppLayout>
        <Head title="Onboard Company Baru" />
        <template #header>Company Management</template>

        <div class="max-w-2xl mx-auto space-y-6">

            <!-- Breadcrumb -->
            <div class="flex items-center gap-2 text-sm text-muted-foreground animate-fade-in">
                <Link href="/super-admin/companies" class="hover:text-foreground transition-colors">Company Management</Link>
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                <span class="text-foreground font-medium">Onboard Company Baru</span>
            </div>

            <!-- Info -->
            <div class="rounded-2xl border p-4 flex items-start gap-3 animate-fade-in delay-75"
                 style="border-color: hsl(var(--primary)/0.25); background: hsl(var(--primary)/0.06);">
                <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
                     style="background: hsl(var(--primary)/0.15);">
                    <svg class="w-4 h-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-foreground">Auto-Onboarding</p>
                    <p class="text-xs text-muted-foreground mt-0.5 leading-relaxed">
                        Pilih template untuk auto-setup locations, product codes, mass balance thresholds, dan workflow secara otomatis. Semua dapat diubah setelah company dibuat.
                    </p>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-5 animate-scale-in delay-100">

                <!-- Template Selection -->
                <div class="rounded-2xl border border-border bg-card p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-foreground border-b border-border pb-3">1. Pilih Template</h3>
                    <div class="space-y-2">
                        <label
                            v-for="tmpl in templates"
                            :key="tmpl.id"
                            :class="form.template === tmpl.id
                                ? 'border-primary bg-primary/5 shadow-sm'
                                : 'border-border hover:border-primary/30'"
                            class="flex items-start gap-3 p-4 rounded-xl border-2 cursor-pointer transition-all duration-150 active:scale-[0.99]"
                        >
                            <div
                                class="w-4 h-4 rounded-full border-2 flex items-center justify-center flex-shrink-0 mt-0.5 transition-all"
                                :class="form.template === tmpl.id ? 'border-primary bg-primary' : 'border-input'"
                                @click="form.template = tmpl.id"
                            >
                                <div v-if="form.template === tmpl.id" class="w-1.5 h-1.5 rounded-full bg-white"/>
                            </div>
                            <div @click="form.template = tmpl.id" class="flex-1">
                                <div class="flex items-center gap-2">
                                    <span style="font-size: 18px;">{{ tmpl.icon }}</span>
                                    <p class="text-sm font-semibold text-foreground">{{ tmpl.name }}</p>
                                </div>
                                <p class="text-xs text-muted-foreground mt-1">{{ tmpl.desc }}</p>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Basic Info -->
                <div class="rounded-2xl border border-border bg-card p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-foreground border-b border-border pb-3">2. Informasi Company</h3>

                    <div class="grid grid-cols-2 gap-4">
                        <!-- Code -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">
                                Kode Company <span class="text-red-500">*</span>
                            </label>
                            <div class="relative">
                                <input
                                    v-model="form.code"
                                    type="text" maxlength="20"
                                    placeholder="VALE, MINDID, ..."
                                    class="w-full h-11 px-4 rounded-xl text-sm border bg-background text-foreground uppercase font-mono outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                                    :class="form.errors.code ? 'border-red-500' : 'border-input'"
                                />
                            </div>
                            <p class="text-[10px] text-muted-foreground">Hanya huruf kapital, angka, underscore. Preview: <span class="font-mono font-bold text-primary">{{ codePreview || '—' }}</span></p>
                            <p v-if="form.errors.code" class="text-xs text-red-500">{{ form.errors.code }}</p>
                        </div>

                        <!-- Color picker -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">Warna Primary</label>
                            <div class="flex items-center gap-2">
                                <div class="w-11 h-11 rounded-xl border border-input overflow-hidden flex-shrink-0">
                                    <input v-model="form.primary_color" type="color" class="w-full h-full cursor-pointer border-none outline-none p-0"/>
                                </div>
                                <input v-model="form.primary_color" type="text" maxlength="7"
                                       class="flex-1 h-11 px-3 rounded-xl text-sm border border-input bg-background text-foreground font-mono outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                            </div>
                        </div>

                        <!-- Name -->
                        <div class="col-span-2 space-y-2">
                            <label class="block text-sm font-semibold text-foreground">
                                Nama Company <span class="text-red-500">*</span>
                            </label>
                            <input v-model="form.name" type="text" placeholder="PT Vale Indonesia Tbk"
                                   class="w-full h-11 px-4 rounded-xl text-sm border bg-background text-foreground outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                                   :class="form.errors.name ? 'border-red-500' : 'border-input'"/>
                            <p v-if="form.errors.name" class="text-xs text-red-500">{{ form.errors.name }}</p>
                        </div>

                        <!-- Legal Name -->
                        <div class="col-span-2 space-y-2">
                            <label class="block text-sm font-semibold text-foreground">Nama Legal</label>
                            <input v-model="form.legal_name" type="text" placeholder="PT Vale Indonesia Tbk (Persero)"
                                   class="w-full h-11 px-4 rounded-xl text-sm border border-input bg-background text-foreground outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                        </div>

                        <!-- Industry -->
                        <div class="col-span-2 space-y-2">
                            <label class="block text-sm font-semibold text-foreground">
                                Industri <span class="text-red-500">*</span>
                            </label>
                            <select v-model="form.industry"
                                    class="w-full h-11 px-4 rounded-xl text-sm border bg-background text-foreground outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all cursor-pointer"
                                    :class="form.errors.industry ? 'border-red-500' : 'border-input'">
                                <option value="">Pilih industri...</option>
                                <option v-for="opt in industry_options" :key="opt" :value="opt">{{ opt }}</option>
                            </select>
                        </div>

                        <!-- City & Province -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">Kota</label>
                            <input v-model="form.city" type="text" placeholder="Sorowako"
                                   class="w-full h-11 px-4 rounded-xl text-sm border border-input bg-background text-foreground outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">Provinsi</label>
                            <select v-model="form.province"
                                    class="w-full h-11 px-4 rounded-xl text-sm border border-input bg-background text-foreground outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all cursor-pointer">
                                <option value="">Pilih...</option>
                                <option v-for="p in ['Bangka Belitung','DKI Jakarta','Jawa Barat','Jawa Timur','Kalimantan Timur','Kepulauan Riau','Sulawesi Selatan','Sumatera Selatan','Sumatera Utara','Papua']" :key="p" :value="p">{{ p }}</option>
                            </select>
                        </div>

                        <!-- Phone & Email -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">Telepon</label>
                            <input v-model="form.phone" type="text" placeholder="+62..."
                                   class="w-full h-11 px-4 rounded-xl text-sm border border-input bg-background text-foreground outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                        </div>
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">Email</label>
                            <input v-model="form.email" type="email" placeholder="info@company.com"
                                   class="w-full h-11 px-4 rounded-xl text-sm border border-input bg-background text-foreground outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                        </div>

                        <!-- Is Regulator toggle -->
                        <div class="col-span-2 flex items-center gap-3 p-4 rounded-xl bg-muted/30 border border-border">
                            <button type="button" @click="form.is_regulator = !form.is_regulator"
                                    class="w-10 h-6 rounded-full transition-all duration-200 flex items-center relative"
                                    :class="form.is_regulator ? 'bg-red-500' : 'bg-muted'">
                                <div class="w-4 h-4 rounded-full bg-white shadow transition-all duration-200 absolute"
                                     :class="form.is_regulator ? 'left-5' : 'left-1'"/>
                            </button>
                            <div>
                                <p class="text-sm font-semibold text-foreground">
                                    {{ form.is_regulator ? '🔴 Company Regulator (BIM)' : 'Company Operasional' }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ form.is_regulator ? 'Company ini adalah regulator — tidak punya data operasional.' : 'Company mining/processing dengan data batch dan ekspor.' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex gap-3">
                    <Link href="/super-admin/companies"
                          class="flex-1 h-11 rounded-xl text-sm font-semibold text-muted-foreground border border-border flex items-center justify-center gap-2 hover:bg-muted hover:text-foreground transition-all duration-200 active:scale-[0.97]">
                        Batal
                    </Link>
                    <button type="submit" :disabled="form.processing"
                            class="flex-[2] h-11 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 transition-all duration-200 disabled:opacity-50 hover:shadow-lg hover:shadow-primary/25 hover:-translate-y-0.5 active:scale-[0.97]"
                            style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));">
                        <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                        </svg>
                        {{ form.processing ? 'Mendaftarkan...' : 'Onboard Company' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>