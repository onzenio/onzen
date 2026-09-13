<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'

interface AccountRow {
  id: number
  name: string
  profile: string
  plan?: { id: number, name: string } | null
}

interface AccountsResponse {
  data: AccountRow[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

const UBadge = resolveComponent('UBadge')

const toast = useToast()
const page = ref(1)

const { data, status, refresh } = await useFetch<AccountsResponse>('/api/accounts', {
  key: 'accounts-list',
  query: { page }
})

const accounts = computed(() => data.value?.data ?? [])
const total = computed(() => data.value?.total ?? 0)

const columns: TableColumn<AccountRow>[] = [
  { accessorKey: 'id', header: 'ID' },
  { accessorKey: 'name', header: 'Nome' },
  {
    accessorKey: 'profile',
    header: 'Perfil',
    cell: ({ row }) => {
      const profile = row.original.profile
      return h(UBadge, { variant: 'subtle', color: profile === 'A' ? 'primary' : 'neutral' }, () => `Conta ${profile}`)
    }
  },
  {
    id: 'plan',
    header: 'Plano',
    cell: ({ row }) => row.original.plan?.name ?? '—'
  }
]

const schema = z.object({
  name: z.string().min(2, 'Informe o nome da conta'),
  admin_name: z.string().optional(),
  admin_email: z.string().email('Informe um e-mail válido')
})

type Schema = z.output<typeof schema>

const open = ref(false)
const creating = ref(false)
const form = useTemplateRef('form')

const state = reactive<Partial<Schema>>({
  name: '',
  admin_name: '',
  admin_email: ''
})

function resetState() {
  state.name = ''
  state.admin_name = ''
  state.admin_email = ''
}

async function onSubmit(event: FormSubmitEvent<Schema>) {
  creating.value = true
  try {
    await $fetch('/api/accounts', { method: 'POST', body: event.data })
    toast.add({ title: 'Conta criada', description: `Convite enviado para ${event.data.admin_email}.`, color: 'success' })
    open.value = false
    resetState()
    await refresh()
  } catch (error) {
    form.value?.setErrors(backendFormErrors(error))
    toast.add({ title: 'Não foi possível criar a conta', description: backendMessage(error), color: 'error' })
  } finally {
    creating.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="accounts">
    <template #header>
      <UDashboardNavbar title="Accounts">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UModal v-model:open="open" title="Nova conta" description="Criar uma conta B e convidar o administrador">
            <UButton label="Nova conta" icon="i-lucide-plus" />

            <template #body>
              <UForm
                ref="form"
                :schema="schema"
                :state="state"
                class="space-y-4"
                @submit="onSubmit"
              >
                <UFormField label="Nome da conta" name="name" required>
                  <UInput v-model="state.name" class="w-full" />
                </UFormField>
                <UFormField label="Nome do administrador" name="admin_name">
                  <UInput v-model="state.admin_name" class="w-full" />
                </UFormField>
                <UFormField label="E-mail do administrador" name="admin_email" required>
                  <UInput v-model="state.admin_email" type="email" class="w-full" />
                </UFormField>
                <div class="flex justify-end gap-2">
                  <UButton
                    label="Cancelar"
                    color="neutral"
                    variant="subtle"
                    @click="open = false"
                  />
                  <UButton
                    label="Criar"
                    color="primary"
                    variant="solid"
                    type="submit"
                    :loading="creating"
                  />
                </div>
              </UForm>
            </template>
          </UModal>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UTable
        :data="accounts"
        :columns="columns"
        :loading="status === 'pending'"
      />

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <div class="text-sm text-muted">
          {{ total }} conta(s).
        </div>

        <UPagination
          v-model:page="page"
          :items-per-page="data?.per_page ?? 15"
          :total="total"
          @update:page="() => refresh()"
        />
      </div>
    </template>
  </UDashboardPanel>
</template>
