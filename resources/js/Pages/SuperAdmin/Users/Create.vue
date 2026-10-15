<script setup>
import { ref, computed, watch } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    companies: Array,
    roles:     Array,
})

const form = useForm({
    company_id:  '',
    name:        '',
    email:       '',
    password:    '',
    role:        '',
    employee_id: '',
    department:  '',
    phone:       '',
    language:    'id',
    is_active:   true,
})

const showPassword = ref(false)

// Role to company type mapping
const roleCompanyType = {
    super_admin:        'none',
    bim_admin:          'regulator',
    bim_viewer:         'regulator',
    government_auditor: 'regulator',
    company_admin:      'operational',
    operator_lab:       'operational',
    operator_process:   'operational',
    company_auditor:    'operational',
}

const filteredCompanies = computed(() => {
    const type = roleCompanyType[form.role]
    if (!type || type === 'none') return []
    if (type === 'regulator') return props.companies.filter(c => c.is_regulator)
    return props.companies.filter(c => !c.is_regulator)
})

// Auto-clear company jika role berubah
watch(() => form.role, () => {
    form.company_id = ''
})

const needsCompany = computed(() => {
    const type = roleCompanyType[form.role]
    return type && type !== 'none'
})

function submit() {
    form.post('/super-admin/users')
}

const roleBadgeColor = computed(() => {
    const m = {
        super_admin:  ' ',
        bim_admin:    ' ',
        company_admin:' ',
        operator_lab: ' ',
        operator_process: ' ',
        company_auditor:  ' ',
        bim_viewer:       ' ',
        government_auditor: ' ',
    }
    return m[form.role] ?? ' '
})
</script>

<template>
    <AppLayout>
        <Head title="Buat User Baru" />
        <template #header>User Management</template>

        <div class="max-w-2xl mx-auto space-y-6">

            <!-- Breadcrumb -->
            <div class="flex items-center gap-2 text-sm text-muted-foreground animate-fade-in">
                <Link href="/super-admin/users" class="hover:text-foreground transition-colors">User Management</Link>
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                <span class="text-foreground font-medium">Buat User Baru</span>
            </div>

            <!-- Form -->
            <form @submit.prevent="submit" class="space-y-5 animate-scale-in">

                <!-- Role Selection (first) -->
                <div class="rounded-2xl border border-border bg-card p-6 space-y-4">
                    <h3 class="text-sm font-semibold text-foreground border-b border-border pb-3">
                        1. Pilih Role <span class="text-danger">*</span>
                    </h3>
                    <div class="grid grid-cols-2 gap-2">
                        <button
                            v-for="r in roles" :key="r"
                            type="button"
                            @click="form.role = r"
                            :class="form.role === r ? 'border-primary bg-primary/8 text-foreground shadow-sm' : 'border-border text-muted-foreground hover:border-primary/30 hover:bg-muted/30'"
                            class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl border-2 text-sm font-medium transition-all duration-150 text-left active:scale-[0.98]"
                        >
                            <div class="w-2 h-2 rounded-full flex-shrink-0 transition-all"
                                 :class="form.role === r ? 'bg-primary scale-125' : 'bg-muted-foreground/30'"/>
                            {{ r.replace(/_/g,' ').replace(/\b\w/g, l => l.toUpperCase()) }}
                        </button>
                    </div>
                    <p v-if="form.errors.role" class="text-xs text-danger">{{ form.errors.role }}</p>
                </div>

                <!-- Company Selection -->
                <div v-if="needsCompany" class="rounded-2xl border border-border bg-card p-6 space-y-3 animate-fade-in">
                    <h3 class="text-sm font-semibold text-foreground border-b border-border pb-3">
                        2. Pilih Company <span class="text-danger">*</span>
                    </h3>
                    <select v-model="form.company_id"
                            class="w-full h-11 px-4 rounded-xl text-sm border bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all cursor-pointer"
                            :class="form.errors.company_id ? 'border-danger/30' : 'border-input'">
                        <option value="">Pilih company...</option>
                        <option v-for="c in filteredCompanies" :key="c.id" :value="c.id">
                            [{{ c.code }}] {{ c.name }}
                        </option>
                    </select>
                    <p v-if="form.errors.company_id" class="text-xs text-danger">{{ form.errors.company_id }}</p>
                </div>

                <!-- User Info -->
                <div class="rounded-2xl border border-border bg-card p-6 space-y-4 animate-fade-in delay-100">
                    <h3 class="text-sm font-semibold text-foreground border-b border-border pb-3">
                        {{ needsCompany ? '3.' : '2.' }} Informasi User
                    </h3>

                    <div class="grid grid-cols-2 gap-4">
                        <!-- Nama -->
                        <div class="col-span-2 space-y-2">
                            <label class="block text-sm font-semibold text-foreground">
                                Nama Lengkap <span class="text-danger">*</span>
                            </label>
                            <input v-model="form.name" type="text" placeholder="Nama lengkap user"
                                   class="w-full h-11 px-4 rounded-xl text-sm border bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                                   :class="form.errors.name ? 'border-danger/30' : 'border-input'"/>
                            <p v-if="form.errors.name" class="text-xs text-danger">{{ form.errors.name }}</p>
                        </div>

                        <!-- Email -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">
                                Email <span class="text-danger">*</span>
                            </label>
                            <input v-model="form.email" type="email" placeholder="email@company.co.id"
                                   class="w-full h-11 px-4 rounded-xl text-sm border bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                                   :class="form.errors.email ? 'border-danger/30' : 'border-input'"/>
                            <p v-if="form.errors.email" class="text-xs text-danger">{{ form.errors.email }}</p>
                        </div>

                        <!-- Password -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">
                                Password <span class="text-danger">*</span>
                            </label>
                            <div class="relative">
                                <input v-model="form.password" :type="showPassword ? 'text' : 'password'"
                                       placeholder="Min 8 karakter + angka + simbol"
                                       class="w-full h-11 pl-4 pr-11 rounded-xl text-sm border bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"
                                       :class="form.errors.password ? 'border-danger/30' : 'border-input'"/>
                                <button type="button" @click="showPassword = !showPassword"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path v-if="!showPassword" stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path v-else stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/>
                                    </svg>
                                </button>
                            </div>
                            <p v-if="form.errors.password" class="text-xs text-danger">{{ form.errors.password }}</p>
                        </div>

                        <!-- Employee ID -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">NIP / Employee ID</label>
                            <input v-model="form.employee_id" type="text" placeholder="TMH-ADM-001"
                                   class="w-full h-11 px-4 rounded-xl text-sm border border-input bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                        </div>

                        <!-- Department -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">Departemen</label>
                            <input v-model="form.department" type="text" placeholder="Produksi, Lab Kimia, IT..."
                                   class="w-full h-11 px-4 rounded-xl text-sm border border-input bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                        </div>

                        <!-- Phone -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">Nomor HP</label>
                            <input v-model="form.phone" type="text" placeholder="+62812xxxxx"
                                   class="w-full h-11 px-4 rounded-xl text-sm border border-input bg-background text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                        </div>

                        <!-- Language -->
                        <div class="space-y-2">
                            <label class="block text-sm font-semibold text-foreground">Bahasa Default</label>
                            <div class="flex gap-2">
                                <button type="button" @click="form.language = 'id'"
                                        :class="form.language === 'id' ? 'border-primary bg-primary/8 font-bold' : 'border-border'"
                                        class="flex-1 h-11 rounded-xl text-sm border transition-all duration-150 active:scale-95">
                                    🇮🇩 Indonesia
                                </button>
                                <button type="button" @click="form.language = 'en'"
                                        :class="form.language === 'en' ? 'border-primary bg-primary/8 font-bold' : 'border-border'"
                                        class="flex-1 h-11 rounded-xl text-sm border transition-all duration-150 active:scale-95">
                                    🇬🇧 English
                                </button>
                            </div>
                        </div>

                        <!-- Active -->
                        <div class="col-span-2 flex items-center gap-3 p-4 rounded-xl bg-muted/30 border border-border">
                            <button type="button" @click="form.is_active = !form.is_active"
                                    class="w-10 h-6 rounded-full transition-all duration-200 flex items-center relative"
                                    :class="form.is_active ? 'bg-primary' : 'bg-muted'">
                                <div class="w-4 h-4 rounded-full bg-white shadow transition-all duration-200 absolute"
                                     :class="form.is_active ? 'left-5' : 'left-1'"/>
                            </button>
                            <div>
                                <p class="text-sm font-semibold text-foreground">
                                    {{ form.is_active ? 'User Aktif' : 'User Nonaktif' }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ form.is_active ? 'User dapat login ke sistem.' : 'User tidak dapat login.' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex gap-3">
                    <Link href="/super-admin/users"
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
                        {{ form.processing ? 'Membuat User...' : 'Buat User' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>