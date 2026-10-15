<script setup>
import { computed, ref } from 'vue'
import { useForm, Head } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import FormField from '@/components/app/FormField.vue'
import FormInput from '@/components/app/FormInput.vue'
import AppIcon from '@/components/app/AppIcon.vue'
import { useI18n } from '@/composables/core/useI18n.js'

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
})

const { t } = useI18n()
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' })
const show = ref(false)

const rules = computed(() => [
    { key: 'rule_length', ok: form.password.length >= 8 },
    { key: 'rule_letter', ok: /[a-zA-Z]/.test(form.password) },
    { key: 'rule_number', ok: /[0-9]/.test(form.password) },
    { key: 'rule_symbol', ok: /[^a-zA-Z0-9]/.test(form.password) },
])
const mismatch = computed(() => form.password_confirmation.length > 0 && form.password !== form.password_confirmation)

const submit = () => form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') })
</script>

<template>
    <Head :title="`${t('auth.reset_title')} — MINETRACE`" />

    <AuthLayout :heading="t('auth.reset_title')" :subheading="t('auth.reset_subtitle')">
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <FormField :label="t('auth.email')" v-slot="{ id }">
                <FormInput :id="id" v-model="form.email" type="email" icon="mail" readonly autocomplete="username" />
            </FormField>

            <FormField :label="t('auth.new_password')" :error="form.errors.password" required v-slot="{ id, describedby, invalid }">
                <FormInput :id="id" v-model="form.password" :type="show ? 'text' : 'password'" icon="lock" autocomplete="new-password"
                           :invalid="invalid" :describedby="describedby" required>
                    <template #trailing>
                        <button type="button" class="touch-target inline-flex items-center justify-center rounded-lg text-muted-foreground hover:text-foreground"
                                :aria-label="show ? t('auth.hide_password') : t('auth.show_password')" :aria-pressed="show" @click="show = !show">
                            <AppIcon :name="show ? 'eye-off' : 'eye'" :size="18" />
                        </button>
                    </template>
                </FormInput>
            </FormField>

            <ul class="grid grid-cols-2 gap-x-3 gap-y-1.5 text-sm" :aria-label="t('auth.password_rules')">
                <li v-for="r in rules" :key="r.key" class="flex items-center gap-1.5" :class="r.ok ? 'text-success' : 'text-muted-foreground'">
                    <AppIcon :name="r.ok ? 'circle-check' : 'circle-dashed'" :size="16" />
                    {{ t(`auth.${r.key}`) }}
                    <span class="sr-only">{{ r.ok ? t('auth.rule_ok') : t('auth.rule_todo') }}</span>
                </li>
            </ul>

            <FormField :label="t('auth.confirm_password')" :error="mismatch ? t('auth.passwords_mismatch') : null" required v-slot="{ id, describedby, invalid }">
                <FormInput :id="id" v-model="form.password_confirmation" :type="show ? 'text' : 'password'" icon="lock" autocomplete="new-password"
                           :invalid="invalid" :describedby="describedby" required />
            </FormField>

            <button type="submit" :disabled="form.processing || mismatch"
                    class="inline-flex min-h-[48px] w-full items-center justify-center gap-2 rounded-lg bg-primary text-base font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60">
                <AppIcon v-if="form.processing" name="loader" :size="18" spin />
                {{ t('auth.save_new_password') }}
            </button>
        </form>
    </AuthLayout>
</template>
