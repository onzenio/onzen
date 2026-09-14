<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'

definePageMeta({ middleware: 'super-admin' })

interface PlanRow {
  id: number
  name: string
  price_cents: number
  max_users: number
  max_clients: number
  modules: string[]
  monthly_query_volume: number
  is_default: boolean
}

interface PlansResponse {
  data: PlanRow[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
}

interface AccountOption {
  id: number
  name: string
  profile: string
  plan?: { id: number, name: string } | null
}

interface AccountsResponse {
  data: AccountOption[]
}

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')

const toast = useToast()
const page = ref(1)

const { data, status, refresh } = await useFetch<PlansResponse>('/api/plans', {
  key: 'plans-list',
  query: { page }
})

const { data: accountsData, refresh: refreshAccounts } = await useFetch<AccountsResponse>('/api/accounts', {
  key: 'plans-accounts'
})

const plans = computed(() => data.value?.data ?? [])
const total = computed(() => data.value?.meta.total ?? 0)
const accounts = computed(() => accountsData.value?.data ?? [])

function formatPrice(cents: number): string {
  return (cents / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

const editing = ref<PlanRow | null>(null)
const open = ref(false)
const saving = ref(false)
const form = useTemplateRef('form')

const schema = z.object({
  name: z.string().min(2, 'Informe o nome do plano'),
  price_cents: z.coerce.number().min(0, 'Valor inválido'),
  max_users: z.coerce.number().min(1, 'Mínimo de 1 usuário'),
  max_clients: z.coerce.number().min(0, 'Valor inválido'),
  modules_text: z.string().min(1, 'Informe ao menos um módulo'),
  monthly_query_volume: z.coerce.number().min(0, 'Valor inválido'),
  is_default: z.boolean()
})

type Schema = z.output<typeof schema>

const state = reactive<Schema>({
  name: '',
  price_cents: 0,
  max_users: 3,
  max_clients: 10,
  modules_text: 'clients',
  monthly_query_volume: 100,
  is_default: false
})

function openCreate() {
  editing.value = null
  state.name = ''
  state.price_cents = 0
  state.max_users = 3
  state.max_clients = 10
  state.modules_text = 'clients'
  state.monthly_query_volume = 100
  state.is_default = false
  open.value = true
}

function openEdit(plan: PlanRow) {
  editing.value = plan
  state.name = plan.name
  state.price_cents = plan.price_cents
  state.max_users = plan.max_users
  state.max_clients = plan.max_clients
  state.modules_text = plan.modules.join(', ')
  state.monthly_query_volume = plan.monthly_query_volume
  state.is_default = plan.is_default
  open.value = true
}

function payload(data: Schema) {
  return {
    name: data.name,
    price_cents: data.price_cents,
    max_users: data.max_users,
    max_clients: data.max_clients,
    modules: data.modules_text.split(',').map(item => item.trim()).filter(item => item.length > 0),
    monthly_query_volume: data.monthly_query_volume,
    is_default: data.is_default
  }
}

async function onSubmit(event: FormSubmitEvent<Schema>) {
  saving.value = true
  try {
    if (editing.value) {
      await $fetch(`/api/plans/${editing.value.id}`, { method: 'PATCH', body: payload(event.data) })
      toast.add({ title: 'Plano atualizado', color: 'success' })
    } else {
      await $fetch('/api/plans', { method: 'POST', body: payload(event.data) })
      toast.add({ title: 'Plano criado', color: 'success' })
    }
    open.value = false
    await refresh()
  } catch (error) {
    form.value?.setErrors(backendFormErrors(error))
    toast.add({ title: 'Não foi possível salvar o plano', description: backendMessage(error), color: 'error' })
  } finally {
    saving.value = false
  }
}

const columns: TableColumn<PlanRow>[] = [
  {
    accessorKey: 'name',
    header: 'Nome',
    cell: ({ row }) => {
      const plan = row.original
      return h('div', { class: 'flex items-center gap-2' }, [
        h('span', { class: 'font-medium' }, plan.name),
        plan.is_default ? h(UBadge, { variant: 'subtle', color: 'success' }, () => 'Padrão') : null
      ])
    }
  },
  {
    accessorKey: 'price_cents',
    header: 'Preço',
    cell: ({ row }) => formatPrice(row.original.price_cents)
  },
  { accessorKey: 'max_users', header: 'Usuários' },
  { accessorKey: 'max_clients', header: 'Clientes' },
  {
    id: 'modules',
    header: 'Módulos',
    cell: ({ row }) => row.original.modules.join(', ')
  },
  {
    id: 'actions',
    cell: ({ row }) => {
      return h('div', { class: 'text-right' }, h(UButton, {
        icon: 'i-lucide-pencil',
        color: 'neutral',
        variant: 'ghost',
        onClick: () => openEdit(row.original)
      }))
    }
  }
]

const switchAccountId = ref<number | undefined>(undefined)
const switchPlanId = ref<number | undefined>(undefined)
const switching = ref(false)

const accountItems = computed(() => accounts.value.map(account => ({
  label: `${account.name} (Conta ${account.profile})`,
  value: account.id
})))
const planItems = computed(() => plans.value.map(plan => ({
  label: `${plan.name}${plan.is_default ? ' (padrão)' : ''}`,
  value: plan.id
})))

async function onSwitchPlan() {
  if (!switchAccountId.value || !switchPlanId.value) {
    toast.add({ title: 'Selecione a conta e o plano', color: 'warning' })
    return
  }
  switching.value = true
  try {
    await $fetch(`/api/accounts/${switchAccountId.value}/plan`, {
      method: 'PATCH',
      body: { plan_id: switchPlanId.value }
    })
    toast.add({ title: 'Plano da conta atualizado', color: 'success' })
    switchAccountId.value = undefined
    switchPlanId.value = undefined
    await refreshAccounts()
  } catch (error) {
    toast.add({ title: 'Não foi possível trocar o plano', description: backendMessage(error), color: 'error' })
  } finally {
    switching.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="plans">
    <template #header>
      <UDashboardNavbar title="Plans">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton label="Novo plano" icon="i-lucide-plus" @click="openCreate" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UTable
        :data="plans"
        :columns="columns"
        :loading="status === 'pending'"
      />

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4">
        <div class="text-sm text-muted">
          {{ total }} plano(s).
        </div>

        <UPagination
          v-model:page="page"
          :items-per-page="data?.meta.per_page ?? 15"
          :total="total"
          @update:page="() => refresh()"
        />
      </div>

      <UPageCard
        title="Trocar plano da conta"
        description="A troca entra em vigor imediatamente."
        variant="subtle"
        class="mt-6"
      >
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
          <UFormField label="Conta" class="flex-1">
            <USelect
              v-model="switchAccountId"
              :items="accountItems"
              value-key="value"
              placeholder="Selecione a conta"
              class="w-full"
            />
          </UFormField>
          <UFormField label="Plano" class="flex-1">
            <USelect
              v-model="switchPlanId"
              :items="planItems"
              value-key="value"
              placeholder="Selecione o plano"
              class="w-full"
            />
          </UFormField>
          <UButton label="Trocar plano" :loading="switching" @click="onSwitchPlan" />
        </div>
      </UPageCard>

      <UModal v-model:open="open" :title="editing ? 'Editar plano' : 'Novo plano'" description="Defina limites, módulos e preço">
        <template #body>
          <UForm
            ref="form"
            :schema="schema"
            :state="state"
            class="space-y-4"
            @submit="onSubmit"
          >
            <UFormField label="Nome" name="name" required>
              <UInput v-model="state.name" class="w-full" />
            </UFormField>
            <UFormField label="Preço (centavos)" name="price_cents" required>
              <UInput
                v-model="state.price_cents"
                type="number"
                min="0"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Máximo de usuários" name="max_users" required>
              <UInput
                v-model="state.max_users"
                type="number"
                min="1"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Máximo de clientes" name="max_clients" required>
              <UInput
                v-model="state.max_clients"
                type="number"
                min="0"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Módulos (separados por vírgula)" name="modules_text" required>
              <UInput v-model="state.modules_text" placeholder="clients" class="w-full" />
            </UFormField>
            <UFormField label="Volume mensal de consultas" name="monthly_query_volume" required>
              <UInput
                v-model="state.monthly_query_volume"
                type="number"
                min="0"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Plano padrão" name="is_default">
              <USwitch v-model="state.is_default" />
            </UFormField>
            <div class="flex justify-end gap-2">
              <UButton
                label="Cancelar"
                color="neutral"
                variant="subtle"
                @click="open = false"
              />
              <UButton
                label="Salvar"
                color="primary"
                variant="solid"
                type="submit"
                :loading="saving"
              />
            </div>
          </UForm>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
