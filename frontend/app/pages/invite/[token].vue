<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'

definePageMeta({ layout: 'auth' })

const route = useRoute()
const toast = useToast()
const form = useTemplateRef('form')

const token = computed(() => String(route.params.token ?? ''))

const schema = z.object({
  password: z.string().min(8, 'Mínimo de 8 caracteres'),
  password_confirmation: z.string().min(8, 'Mínimo de 8 caracteres')
}).refine(data => data.password === data.password_confirmation, {
  message: 'As senhas não conferem',
  path: ['password_confirmation']
})

type Schema = z.output<typeof schema>

const state = reactive<Partial<Schema>>({
  password: '',
  password_confirmation: ''
})

const loading = ref(false)

async function onSubmit(event: FormSubmitEvent<Schema>) {
  loading.value = true
  try {
    await $fetch(`/api/invitations/${token.value}/accept`, {
      method: 'POST',
      body: { password: event.data.password }
    })
    toast.add({ title: 'Convite aceito', description: 'Entre com sua nova senha.', color: 'success' })
    await navigateTo('/login')
  } catch (error) {
    form.value?.setErrors(backendFormErrors(error))
    toast.add({ title: 'Não foi possível aceitar o convite', description: backendMessage(error), color: 'error' })
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
          Aceitar convite
        </h1>
        <p class="text-sm text-muted">
          Defina sua senha para entrar no OneFisc.
        </p>
      </div>
    </template>

    <UAlert
      v-if="!token"
      title="Convite inválido"
      description="Solicite um novo convite ao administrador da conta."
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
      <UFormField label="Senha" name="password" required>
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
        label="Aceitar convite"
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
