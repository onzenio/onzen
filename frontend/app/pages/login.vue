<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'

definePageMeta({ layout: 'auth' })

const schema = z.object({
  email: z.string().email('Informe um e-mail válido'),
  password: z.string().min(1, 'Informe a senha')
})

type Schema = z.output<typeof schema>

const state = reactive<Partial<Schema>>({
  email: '',
  password: ''
})

const loading = ref(false)
const toast = useToast()
const form = useTemplateRef('form')
const { refresh } = useMe()

async function onSubmit(event: FormSubmitEvent<Schema>) {
  loading.value = true
  try {
    await $fetch('/api/auth/login', { method: 'POST', body: event.data })
    await refresh()
    await navigateTo('/')
  } catch (error) {
    form.value?.setErrors(backendFormErrors(error))
    toast.add({ title: 'Não foi possível entrar', description: backendMessage(error), color: 'error' })
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
          Entrar
        </h1>
        <p class="text-sm text-muted">
          Acesse sua conta do OneFisc.
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
          autocomplete="current-password"
          class="w-full"
        />
      </UFormField>
      <UButton
        label="Entrar"
        type="submit"
        block
        :loading="loading"
      />
    </UForm>

    <template #footer>
      <div class="flex items-center justify-between text-sm">
        <ULink to="/forgot-password">
          Esqueci minha senha
        </ULink>
      </div>
    </template>
  </UCard>
</template>
