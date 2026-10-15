<script setup>
import { ref }       from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import AppLayout     from '@/Layouts/AppLayout.vue'
import { useI18n }  from '@/composables/core/useI18n.js'

const { t, lang } = useI18n()

const props = defineProps({
    users:     Object,
    stats:     Object,
    companies: Array,
    roles:     Array,
    filters:   Object,
})

const search    = ref(props.filters?.search    ?? '')
const companyId = ref(props.filters?.company_id ?? '')
const role      = ref(props.filters?.role      ?? '')
const status    = ref(props.filters?.status    ?? '')

let timeout = null
function applyFilters() {
    router.get('/super-admin/users', {
        search:     search.value     || undefined,
        company_id: companyId.value  || undefined,
        role:       role.value       || undefined,
        status:     status.value     || undefined,
    }, { preserveState: true, replace: true })
}

function onSearch() {
    clearTimeout(timeout)
    timeout = setTimeout(applyFilters, 400)
}

function toggleActive(userId) {
    router.patch(`/super-admin/users/${userId}/toggle-active`, {}, { preserveScroll: true })
}

function destroy(user) {
    if (!confirm(`Hapus user ${user.name}?`)) return
    router.delete(`/super-admin/users/${user.id}`, { preserveScroll: true })
}

function restore(userId) {
    router.patch(`/super-admin/users/${userId}/restore`, {}, { preserveScroll: true })
}

function roleBadge(role) {
    const m = {
        super_admin:        { class: 'bg-info-soft text-info  ', label: 'Super Admin' },
        bim_admin:          { class: 'bg-danger-soft text-danger  ',             label: 'BIM Admin' },
        bim_viewer:         { class: 'bg-danger-soft text-danger  ',         label: 'BIM Viewer' },
        government_auditor: { class: 'bg-warning-soft text-warning  ', label: 'Gov Auditor' },
        company_admin:      { class: 'bg-info-soft text-info  ',         label: 'Company Admin' },
        operator_lab:       { class: 'bg-info-soft text-info  ', label: 'Operator Lab' },
        operator_process:   { class: 'bg-info-soft text-info  ',         label: 'Operator Process' },
        company_auditor:    { class: 'bg-success-soft text-success  ',         label: 'Auditor' },
    }
    return m[role] ?? { class: 'bg-muted text-muted-foreground', label: role }
}
</script>

<template>
    <AppLayout>
        <Head title="User Management" />
        <template #header>User Management</template>

        <div class="space-y-6">

            <!-- Header -->
            <div class="flex items-center justify-between animate-fade-in">
                <div>
                    <h1 class="text-2xl font-bold text-foreground tracking-tight">User Management</h1>
                    <p class="text-sm text-muted-foreground mt-0.5">Kelola semua pengguna sistem MINETRACE</p>
                </div>
                <Link
                    href="/super-admin/users/create"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white transition-all duration-200 hover:shadow-lg hover:shadow-primary/25 hover:-translate-y-0.5 active:scale-[0.97]"
                    style="background: linear-gradient(135deg, hsl(var(--primary)), hsl(207 30% 28%));"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                    </svg>
                    Buat User Baru
                </Link>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div v-for="(item, i) in [
                    { label:'Total User',    value: stats?.total,    color:'text-foreground',    bg:'bg-muted/50' },
                    { label:'Aktif',         value: stats?.active,   color:'text-success',   bg:'bg-success-soft ' },
                    { label:'Nonaktif',      value: stats?.inactive, color:'text-warning',     bg:'bg-warning-soft ' },
                    { label:'Dihapus',       value: stats?.deleted,  color:'text-danger',       bg:'bg-danger-soft ' },
                ]" :key="item.label"
                     class="rounded-2xl border border-border p-4 animate-fade-in"
                     :class="item.bg"
                     :style="`animation-delay: ${i*60}ms`">
                    <p class="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1">{{ item.label }}</p>
                    <p class="text-3xl font-bold" :class="item.color">{{ item.value ?? 0 }}</p>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3 animate-fade-in delay-150">
                <div class="relative flex-1 min-w-[200px] max-w-xs">
                    <div class="absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                    </div>
                    <input v-model="search" @input="onSearch" type="text" placeholder="Cari nama, email, NIP..."
                           class="w-full pl-9 pr-4 h-9 rounded-xl text-sm bg-card border border-border text-foreground focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all"/>
                </div>

                <select v-model="companyId" @change="applyFilters"
                        class="h-9 px-3 rounded-xl text-sm bg-card border border-border text-foreground focus:ring-2 focus:ring-primary/20 cursor-pointer">
                    <option value="">Semua Company</option>
                    <option v-for="c in companies" :key="c.id" :value="c.id">[{{ c.code }}] {{ c.name }}</option>
                </select>

                <select v-model="role" @change="applyFilters"
                        class="h-9 px-3 rounded-xl text-sm bg-card border border-border text-foreground focus:ring-2 focus:ring-primary/20 cursor-pointer">
                    <option value="">Semua Role</option>
                    <option v-for="r in roles" :key="r" :value="r">{{ r.replace(/_/g,' ') }}</option>
                </select>

                <select v-model="status" @change="applyFilters"
                        class="h-9 px-3 rounded-xl text-sm bg-card border border-border text-foreground focus:ring-2 focus:ring-primary/20 cursor-pointer">
                    <option value="">Semua Status</option>
                    <option value="active">Aktif</option>
                    <option value="inactive">Nonaktif</option>
                    <option value="deleted">Dihapus</option>
                </select>
            </div>

            <!-- Table -->
            <div class="rounded-2xl border border-border bg-card overflow-hidden animate-fade-in delay-200">
                <div v-if="!users?.data?.length" class="py-16 text-center space-y-3">
                    <div class="text-4xl"></div>
                    <p class="text-sm font-medium text-foreground">Tidak ada user ditemukan</p>
                    <p class="text-xs text-muted-foreground">Coba ubah filter atau buat user baru.</p>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border bg-muted/30">
                                <th class="text-left px-5 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">User</th>
                                <th class="text-left px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Company</th>
                                <th class="text-left px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Role</th>
                                <th class="text-left px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Status</th>
                                <th class="text-left px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Login Terakhir</th>
                                <th class="px-4 py-3.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="(user, i) in users.data" :key="user.id"
                                class="group transition-colors duration-150 hover:bg-muted/20"
                                :class="user.is_deleted ? 'opacity-50' : ''"
                                :style="`animation: fade-in 0.3s ease-out ${i*40}ms both;`">

                                <!-- User info -->
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <img :src="user.avatar_url" :alt="user.name"
                                             class="w-8 h-8 rounded-lg object-cover flex-shrink-0"/>
                                        <div>
                                            <p class="font-semibold text-foreground text-sm">{{ user.name }}</p>
                                            <p class="text-xs text-muted-foreground">{{ user.email }}</p>
                                            <p v-if="user.employee_id" class="text-xs text-muted-foreground font-mono">{{ user.employee_id }}</p>
                                        </div>
                                    </div>
                                </td>

                                <!-- Company -->
                                <td class="px-4 py-4">
                                    <div v-if="user.company">
                                        <p class="text-xs font-bold text-foreground">{{ user.company.code }}</p>
                                        <p class="text-xs text-muted-foreground truncate max-w-[120px]">{{ user.company.name }}</p>
                                    </div>
                                    <span v-else class="text-xs text-muted-foreground italic">System</span>
                                </td>

                                <!-- Role -->
                                <td class="px-4 py-4">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-semibold"
                                          :class="roleBadge(user.role).class">
                                        {{ roleBadge(user.role).label }}
                                    </span>
                                </td>

                                <!-- Status -->
                                <td class="px-4 py-4">
                                    <div class="flex items-center gap-2">
                                        <div class="w-2 h-2 rounded-full flex-shrink-0"
                                             :class="user.is_deleted ? 'bg-muted-foreground' : user.is_active ? 'bg-success' : 'bg-warning'"/>
                                        <span class="text-xs font-medium"
                                              :class="user.is_deleted ? 'text-muted-foreground' : user.is_active ? 'text-success ' : 'text-warning '">
                                            {{ user.is_deleted ? 'Dihapus' : user.is_active ? 'Aktif' : 'Nonaktif' }}
                                        </span>
                                    </div>
                                </td>

                                <!-- Last login -->
                                <td class="px-4 py-4">
                                    <p class="text-xs text-muted-foreground">{{ user.last_login_at ?? '—' }}</p>
                                </td>

                                <!-- Actions -->
                                <td class="px-4 py-4">
                                    <div class="flex items-center justify-end gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <template v-if="user.is_deleted">
                                            <button @click="restore(user.id)"
                                                    class="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-success bg-success-soft hover:bg-success-soft transition-all active:scale-95">
                                                Pulihkan
                                            </button>
                                        </template>
                                        <template v-else>
                                            <Link :href="`/super-admin/users/${user.id}/edit`"
                                                  class="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-muted-foreground hover:bg-muted hover:text-foreground transition-all active:scale-95">
                                                Edit
                                            </Link>
                                            <button @click="toggleActive(user.id)"
                                                    class="px-2.5 py-1.5 rounded-lg text-xs font-semibold transition-all active:scale-95"
                                                    :class="user.is_active ? 'text-warning bg-warning-soft hover:bg-warning-soft ' : 'text-success bg-success-soft hover:bg-success-soft '">
                                                {{ user.is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                            </button>
                                            <button @click="destroy(user)"
                                                    class="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-danger bg-danger-soft hover:bg-danger-soft transition-all active:scale-95">
                                                Hapus
                                            </button>
                                        </template>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="users?.links?.length > 3" class="flex items-center justify-between px-5 py-4 border-t border-border">
                    <p class="text-sm text-muted-foreground">
                        Menampilkan <span class="font-semibold text-foreground">{{ users.from }}–{{ users.to }}</span>
                        dari <span class="font-semibold text-foreground">{{ users.total }}</span> user
                    </p>
                    <div class="flex items-center gap-1">
                        <template v-for="link in users.links" :key="link.label">
                            <Link v-if="link.url" :href="link.url"
                                  class="w-8 h-8 rounded-lg text-xs font-semibold flex items-center justify-center transition-all duration-150"
                                  :class="link.active ? 'text-white shadow-sm' : 'text-muted-foreground hover:bg-muted'"
                                  :style="link.active ? 'background: hsl(var(--primary));' : ''"
                                  v-html="link.label" preserve-scroll/>
                            <span v-else class="w-8 h-8 text-xs flex items-center justify-center text-muted-foreground/40" v-html="link.label"/>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>