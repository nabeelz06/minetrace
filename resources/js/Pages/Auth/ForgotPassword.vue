<script setup>
import { useForm, Head, Link } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import FormField from '@/components/app/FormField.vue'
import FormInput from '@/components/app/FormInput.vue'
import AppIcon from '@/components/app/AppIcon.vue'
import { useI18n } from '@/composables/core/useI18n.js'

defineProps({ status: String })

const { t } = useI18n()
const form = useForm({ email: '' })
const submit = () => form.post('/forgot-password')
</script>

<template>
    <Head :title="`${t('auth.forgot_title')} — MINETRACE`" />

    <AuthLayout :heading="t('auth.forgot_title')" :subheading="t('auth.forgot_subtitle')" :status="status">
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <FormField :label="t('auth.email')" :error="form.errors.email" required v-slot="{ id, describedby, invalid }">
                <FormInput :id="id" v-model="form.email" type="email" icon="mail" autocomplete="username" inputmode="email"
                           :placeholder="t('auth.email_placeholder')" :invalid="invalid" :describedby="describedby" required />
            </FormField>

            <button type="submit" :disabled="form.processing"
                    class="inline-flex min-h-[48px] w-full items-center justify-center gap-2 rounded-lg bg-primary text-base font-semibold text-primary-foreground hover:opacity-90 disabled:opacity-60">
                <AppIcon v-if="form.processing" name="loader" :size="18" spin />
                {{ t('auth.send_reset') }}
            </button>

            <Link href="/login" class="flex min-h-[44px] items-center justify-center gap-2 text-sm font-medium text-primary underline-offset-4 hover:underline">
                <AppIcon name="chevron-left" :size="16" />{{ t('auth.back_to_login') }}
            </Link>
        </form>
    </AuthLayout>
</template>
