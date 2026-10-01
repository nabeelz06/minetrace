<script setup>
import { ref } from 'vue'
import { useForm, Head, Link } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'

defineProps({
    status: String,
})

const form = useForm({
    email: '',
})

const isHovered = ref(false)

function submit() {
    form.post('/forgot-password')
}
</script>

<template>
    <Head title="Lupa Password — MINETRA" />

    <AuthLayout
        heading="Lupa Password?"
        subheading="Masukkan email Anda, kami akan kirimkan link reset password"
        :status="status"
    >
        <form @submit.prevent="submit" class="space-y-5">

            <!-- Email -->
            <div class="space-y-2">
                <label for="email" class="block text-sm font-semibold" style="color: #a8ccd8;">
                    Alamat Email
                </label>
                <div class="relative">
                    <div class="absolute left-3.5 top-1/2 -translate-y-1/2" style="color: #4a7d9c;">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                        </svg>
                    </div>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="username"
                        placeholder="nama@perusahaan.co.id"
                        class="w-full pl-10 pr-4 h-11 rounded-xl text-sm font-medium transition-all duration-200 outline-none"
                        :class="form.errors.email
                            ? 'border-2 border-red-500 focus:border-red-400'
                            : 'border border-transparent focus:border-[#3E5C74] focus:ring-2 focus:ring-[#3E5C74]/25'"
                        style="background: #0d1e2c; color: #e2edf3; caret-color: #5ea1c2;"
                        required
                        autofocus
                    />
                </div>
                <Transition enter-active-class="animate-slide-up">
                    <p v-if="form.errors.email" class="text-xs font-medium flex items-center gap-1.5" style="color: #f87171;">
                        <svg class="w-3 h-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        {{ form.errors.email }}
                    </p>
                </Transition>
            </div>

            <!-- Submit Button -->
            <button
                type="submit"
                :disabled="form.processing"
                @mouseover="isHovered = true"
                @mouseleave="isHovered = false"
                class="w-full h-11 rounded-xl font-semibold text-sm text-white flex items-center justify-center gap-2.5 transition-all duration-300 relative overflow-hidden mt-2"
                :class="form.processing ? 'opacity-70 cursor-not-allowed' : 'hover:shadow-lg hover:shadow-[#3E5C74]/30 active:scale-[0.98]'"
                style="background: linear-gradient(135deg, #3E5C74 0%, #2a4052 100%);"
            >
                <div
                    class="absolute inset-0 transition-opacity duration-300"
                    :class="isHovered && !form.processing ? 'opacity-100' : 'opacity-0'"
                    style="background: linear-gradient(90deg, transparent, rgba(255,255,255,0.08), transparent); transform: skewX(-20deg);"
                />

                <template v-if="form.processing">
                    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                    </svg>
                    <span>Mengirim...</span>
                </template>
                <template v-else>
                    <span>Kirim Link Reset</span>
                    <svg class="w-4 h-4 transition-transform duration-300" :class="isHovered ? 'translate-x-0.5' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                    </svg>
                </template>
            </button>

            <Link
                href="/login"
                class="flex items-center justify-center gap-1.5 text-sm font-medium transition-colors duration-200 hover:underline pt-1"
                style="color: #5ea1c2;"
            >
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                Kembali ke halaman login
            </Link>
        </form>
    </AuthLayout>
</template>
