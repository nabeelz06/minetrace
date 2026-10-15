<script setup>
import { ref, computed } from 'vue'
import { useForm, Head } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
})

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
})

const showPassword = ref(false)
const showConfirm   = ref(false)
const isHovered     = ref(false)

const rules = computed(() => ({
    length:  form.password.length >= 8,
    letter:  /[a-zA-Z]/.test(form.password),
    number:  /[0-9]/.test(form.password),
    symbol:  /[^a-zA-Z0-9]/.test(form.password),
}))

function submit() {
    form.post('/reset-password', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <Head title="Reset Password — MINETRACE" />

    <AuthLayout
        heading="Buat Password Baru"
        subheading="Pastikan password baru Anda kuat dan mudah diingat"
    >
        <form @submit.prevent="submit" class="space-y-5">

            <!-- Email (read-only, dari link) -->
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
                        class="w-full pl-10 pr-4 h-11 rounded-xl text-sm font-medium transition-all duration-200 outline-none"
                        :class="form.errors.email
                            ? 'border-2 border-red-500 focus:border-red-400'
                            : 'border border-transparent focus:border-[#3E5C74] focus:ring-2 focus:ring-[#3E5C74]/25'"
                        style="background: #0d1e2c; color: #e2edf3; caret-color: #5ea1c2;"
                        required
                    />
                </div>
                <Transition enter-active-class="animate-slide-up">
                    <p v-if="form.errors.email" class="text-xs font-medium flex items-center gap-1.5" style="color: #f87171;">
                        {{ form.errors.email }}
                    </p>
                </Transition>
            </div>

            <!-- Password baru -->
            <div class="space-y-2">
                <label for="password" class="block text-sm font-semibold" style="color: #a8ccd8;">
                    Password Baru
                </label>
                <div class="relative">
                    <div class="absolute left-3.5 top-1/2 -translate-y-1/2" style="color: #4a7d9c;">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </div>
                    <input
                        id="password"
                        v-model="form.password"
                        :type="showPassword ? 'text' : 'password'"
                        autocomplete="new-password"
                        placeholder="••••••••••"
                        class="w-full pl-10 pr-12 h-11 rounded-xl text-sm font-medium transition-all duration-200 outline-none"
                        :class="form.errors.password
                            ? 'border-2 border-red-500 focus:border-red-400'
                            : 'border border-transparent focus:border-[#3E5C74] focus:ring-2 focus:ring-[#3E5C74]/25'"
                        style="background: #0d1e2c; color: #e2edf3; caret-color: #5ea1c2; letter-spacing: 0.05em;"
                        required
                        autofocus
                    />
                    <button
                        type="button"
                        @click="showPassword = !showPassword"
                        class="absolute right-3.5 top-1/2 -translate-y-1/2 transition-all duration-200 hover:scale-110"
                        style="color: #4a7d9c;"
                    >
                        <svg v-if="!showPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                        </svg>
                    </button>
                </div>
                <Transition enter-active-class="animate-slide-up">
                    <p v-if="form.errors.password" class="text-xs font-medium flex items-center gap-1.5" style="color: #f87171;">
                        {{ form.errors.password }}
                    </p>
                </Transition>

                <!-- Indikator kekuatan password -->
                <div v-if="form.password.length > 0" class="grid grid-cols-2 gap-x-3 gap-y-1 pt-1">
                    <p
                        v-for="(ok, key) in { length: rules.length, letter: rules.letter, number: rules.number, symbol: rules.symbol }"
                        :key="key"
                        class="text-[11px] font-medium flex items-center gap-1.5 transition-colors duration-200"
                        :style="ok ? 'color: #6ee7b7;' : 'color: #4a7d9c;'"
                    >
                        <svg v-if="ok" class="w-3 h-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                        </svg>
                        <span v-else class="w-3 h-3 flex-shrink-0 rounded-full border" style="border-color: #4a7d9c;" />
                        {{ { length: 'Minimal 8 karakter', letter: 'Mengandung huruf', number: 'Mengandung angka', symbol: 'Mengandung simbol' }[key] }}
                    </p>
                </div>
            </div>

            <!-- Konfirmasi password -->
            <div class="space-y-2">
                <label for="password_confirmation" class="block text-sm font-semibold" style="color: #a8ccd8;">
                    Konfirmasi Password Baru
                </label>
                <div class="relative">
                    <div class="absolute left-3.5 top-1/2 -translate-y-1/2" style="color: #4a7d9c;">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        :type="showConfirm ? 'text' : 'password'"
                        autocomplete="new-password"
                        placeholder="••••••••••"
                        class="w-full pl-10 pr-12 h-11 rounded-xl text-sm font-medium transition-all duration-200 outline-none"
                        :class="form.errors.password_confirmation
                            ? 'border-2 border-red-500 focus:border-red-400'
                            : 'border border-transparent focus:border-[#3E5C74] focus:ring-2 focus:ring-[#3E5C74]/25'"
                        style="background: #0d1e2c; color: #e2edf3; caret-color: #5ea1c2; letter-spacing: 0.05em;"
                        required
                    />
                    <button
                        type="button"
                        @click="showConfirm = !showConfirm"
                        class="absolute right-3.5 top-1/2 -translate-y-1/2 transition-all duration-200 hover:scale-110"
                        style="color: #4a7d9c;"
                    >
                        <svg v-if="!showConfirm" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                        </svg>
                    </button>
                </div>
                <Transition enter-active-class="animate-slide-up">
                    <p v-if="form.errors.password_confirmation" class="text-xs font-medium flex items-center gap-1.5" style="color: #f87171;">
                        {{ form.errors.password_confirmation }}
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
                    <span>Menyimpan...</span>
                </template>
                <template v-else>
                    <span>Simpan Password Baru</span>
                    <svg class="w-4 h-4 transition-transform duration-300" :class="isHovered ? 'translate-x-0.5' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                    </svg>
                </template>
            </button>
        </form>
    </AuthLayout>
</template>
