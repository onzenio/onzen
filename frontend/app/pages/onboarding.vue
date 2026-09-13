<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'

definePageMeta({ layout: 'auth' })

const toast = useToast()
const form = useTemplateRef('form')
const { refresh } = useMe()

const { data: status } = await useFetch<{ available: boolean }>('/api/onboarding/status', {
  key: 'onboarding-status'
})

watchEffect(() => {
  if (status.value && !status.value.available) {
    void navigateTo('/login')
  }
})

const schema = z.object({
  account_name: z.string().min(2, 'Informe o nome da conta'),
  name: z.string().min(2, 'Informe seu nome'),
  email: z.string().email('Informe um e-mail válido'),
  password: z.string().min(8, 'Mínimo de 8 caracteres')
})

type Schema = z.output<typeof schema>

const state = reactive<Partial<Schema>>({
  account_name: '',
  name: '',
  email: '',
  password: ''
})

const loading = ref(false)

async function onSubmit(event: FormSubmitEvent<Schema>) {
  loading.value = true
  try {
    await $fetch('/api/onboarding', { method: 'POST', body: event.data })
    await refresh()
    toast.add({ title: 'Conta criada', description: 'Bem-vindo ao OneFisc.', color: 'success' })
    await navigateTo('/')
  } catch (error) {
    form.value?.setErrors(backendFormErrors(error))
    toast.add({ title: 'Não foi possível concluir o cadastro', description: backendMessage(error), color: 'error' })
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
          Criar conta inicial
        </h1>
        <p class="text-sm text-muted">
          Configure a conta A e o usuário super_admin.
        </p>
      </div>
    </template>

    <UForm
      ref="form"
      :schema="schema"
      :state="state"
      class="space-y-4"
      @submit="onSubmit"
    >
      <UFormField label="Nome da conta" name="account_name" required>
        <UInput v-model="state.account_name" autocomplete="off" class="w-full" />
      </UFormField>
      <UFormField label="Seu nome" name="name" required>
        <UInput v-model="state.name" autocomplete="name" class="w-full" />
      </UFormField>
      <UFormField label="E-mail" name="email" required>
        <UInput
          v-model="state.email"
          type="email"
          autocomplete="email"
          class="w-full"
        />
      </UFormField>
      <UFormField label="Senha" name="password" required>
        <UInput
          v-model="state.password"
          type="password"
          autocomplete="new-password"
          class="w-full"
        />
      </UFormField>
      <UButton
        label="Criar conta"
        type="submit"
        block
        :loading="loading"
      />
    </UForm>
  </UCard>
</template>
