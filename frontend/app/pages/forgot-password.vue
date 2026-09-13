<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'

definePageMeta({ layout: 'auth' })

const schema = z.object({
  email: z.string().email('Informe um e-mail válido')
})

type Schema = z.output<typeof schema>

const state = reactive<Partial<Schema>>({
  email: ''
})

const loading = ref(false)
const sent = ref(false)
const toast = useToast()
const form = useTemplateRef('form')

async function onSubmit(event: FormSubmitEvent<Schema>) {
  loading.value = true
  try {
    await $fetch('/api/auth/forgot-password', { method: 'POST', body: event.data })
    sent.value = true
  } catch (error) {
    form.value?.setErrors(backendFormErrors(error))
    toast.add({ title: 'Não foi possível enviar o e-mail', description: backendMessage(error), color: 'error' })
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
          Recuperar senha
        </h1>
        <p class="text-sm text-muted">
          Informe seu e-mail para receber o link de redefinição.
        </p>
      </div>
    </template>

    <UAlert
      v-if="sent"
      title="Verifique seu e-mail"
      description="Se o e-mail estiver cadastrado, você receberá o link de redefinição."
      color="success"
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
      <UButton
        label="Enviar link"
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
