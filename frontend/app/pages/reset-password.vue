<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'

definePageMeta({ layout: 'auth' })

const route = useRoute()
const toast = useToast()
const form = useTemplateRef('form')

const schema = z.object({
  email: z.string().email('Informe um e-mail válido'),
  password: z.string().min(8, 'Mínimo de 8 caracteres'),
  password_confirmation: z.string().min(8, 'Mínimo de 8 caracteres')
}).refine(data => data.password === data.password_confirmation, {
  message: 'As senhas não conferem',
  path: ['password_confirmation']
})

type Schema = z.output<typeof schema>

const state = reactive<Partial<Schema>>({
  email: typeof route.query.email === 'string' ? route.query.email : '',
  password: '',
  password_confirmation: ''
})

const loading = ref(false)
const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))

async function onSubmit(event: FormSubmitEvent<Schema>) {
  loading.value = true
  try {
    await $fetch('/api/auth/reset-password', {
      method: 'POST',
      body: { ...event.data, token: token.value }
    })
    toast.add({ title: 'Senha redefinida', description: 'Entre com sua nova senha.', color: 'success' })
    await navigateTo('/login')
  } catch (error) {
    form.value?.setErrors(backendFormErrors(error))
    toast.add({ title: 'Não foi possível redefinir a senha', description: backendMessage(error), color: 'error' })
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex flex-col gap-1">
        <h1 class="text-lg font-semibold">
          Nova senha
        </h1>
        <p class="text-sm text-muted">
          Defina sua nova senha de acesso.
        </p>
      </div>
    </template>

    <UAlert
      v-if="!token"
      title="Link inválido"
      description="Este link de redefinição é inválido. Solicite um novo e-mail de recuperação."
      color="error"
      variant="subtle"
    />
    <UForm
      v-else
      ref="form"
      :schema="schema"
      :state="state"
      class="space-y-4"
      @submit="onSubmit"
    >
      <UFormField label="E-mail" name="email" required>
        <UInput
          v-model="state.email"
          type="email"
          autocomplete="email"
          class="w-full"
        />
      </UFormField>
      <UFormField label="Nova senha" name="password" required>
        <UInput
          v-model="state.password"
          type="password"
          autocomplete="new-password"
          class="w-full"
        />
      </UFormField>
      <UFormField label="Confirmar senha" name="password_confirmation" required>
        <UInput
          v-model="state.password_confirmation"
          type="password"
          autocomplete="new-password"
          class="w-full"
        />
      </UFormField>
      <UButton
        label="Redefinir senha"
        type="submit"
        block
        :loading="loading"
      />
    </UForm>

    <template #footer>
      <ULink to="/login" class="text-sm">
        Voltar para o login
      </ULink>
    </template>
  </UCard>
</template>
