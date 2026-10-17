<script setup>
import { ref } from 'vue'
import { useForm, Head, Link } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import FormField from '@/components/app/FormField.vue'
import FormInput from '@/components/app/FormInput.vue'
import AppIcon from '@/components/app/AppIcon.vue'
import { useI18n } from '@/composables/core/useI18n.js'

defineProps({ status: String, canResetPassword: Boolean })

const { t } = useI18n()
const form = useForm({ email: '', password: '', remember: false })
const showPassword = ref(false)

function submit() {
    form.post('/login', { onFinish: () => form.reset('password') })
}
</script>

<template>
    <Head :title="`${t('auth.login')}`" />

    <AuthLayout :heading="t('auth.welcome')" :subheading="t('auth.login_subtitle')" :status="status">
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <FormField :label="t('auth.email')" :error="form.errors.email" required v-slot="{ id, describedby, invalid }">
                <FormInput :id="id" v-model="form.email" type="email" icon="mail" autocomplete="username" inputmode="email"
                           :placeholder="t('auth.email_placeholder')" :invalid="invalid" :describedby="describedby" required />
            </FormField>

            <FormField :label="t('auth.password')" :error="form.errors.password" required v-slot="{ id, describedby, invalid }">
                <FormInput :id="id" v-model="form.password" :type="showPassword ? 'text' : 'password'" icon="lock" autocomplete="current-password"
                           :placeholder="t('auth.password_placeholder')" :invalid="invalid" :describedby="describedby" required>
                    <template #trailing>
                        <button type="button" class="touch-target inline-flex items-center justify-center rounded-lg text-muted-foreground hover:text-foreground"
                                :aria-label="showPassword ? t('auth.hide_password') : t('auth.show_password')" :aria-pressed="showPassword"
                                @click="showPassword = !showPassword">
                            <AppIcon :name="showPassword ? 'eye-off' : 'eye'" :size="18" />
                        </button>
                    </template>
                </FormInput>
            </FormField>

            <div class="flex items-center justify-between gap-3">
                <label class="flex min-h-[44px] cursor-pointer items-center gap-2 text-sm">
                    <input v-model="form.remember" type="checkbox" class="h-5 w-5 rounded border-input text-primary" />
                    {{ t('auth.remember_me') }}
                </label>
                <Link v-if="canResetPassword" href="/forgot-password" class="inline-flex min-h-[44px] items-center text-sm font-medium text-primary underline-offset-4 hover:underline">
                    {{ t('auth.forgot_link') }}
                </Link>
            </div>

            <button type="submit" :disabled="form.processing"
                    class="inline-flex min-h-[48px] w-full items-center justify-center gap-2 rounded-lg bg-primary text-base font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60">
                <AppIcon v-if="form.processing" name="loader" :size="18" spin />
                {{ form.processing ? t('auth.signing_in') : t('auth.sign_in') }}
            </button>
        </form>
    </AuthLayout>
</template>
