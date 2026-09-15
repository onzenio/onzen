<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'
import { upperFirst } from 'scule'
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'

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
const UCheckbox = resolveComponent('UCheckbox')
const UDropdownMenu = resolveComponent('UDropdownMenu')

const toast = useToast()

interface PlanTableApi {
  getFilteredSelectedRowModel: () => { rows: Row<PlanRow>[] }
  getFilteredRowModel: () => { rows: Row<PlanRow>[] }
  getColumn: (id: string) => { setFilterValue: (value: string | undefined) => void, getFilterValue: () => unknown, toggleVisibility: (value?: boolean) => void } | undefined
  getAllColumns: () => { id: string, getCanHide: () => boolean, getIsVisible: () => boolean }[]
  getState: () => { pagination: { pageIndex: number, pageSize: number } }
  setPageIndex: (index: number) => void
}

const table = useTemplateRef<{ tableApi?: PlanTableApi | null }>('table')
const columnFilters = ref([{ id: 'name', value: '' }])
const columnVisibility = ref()
const rowSelection = ref<Record<string, boolean>>({})
const sorting = ref<{ id: string, desc: boolean }[]>([])
const pagination = ref({ pageIndex: 0, pageSize: 10 })
const defaultFilter = ref('all')

const { data, error, status, refresh } = await useFetch<PlansResponse>('/api/plans', {
  key: 'plans-list',
  query: { per_page: 500 }
})

const { data: accountsData, refresh: refreshAccounts } = await useFetch<AccountsResponse>('/api/accounts', {
  key: 'plans-accounts'
})

const plans = computed(() => data.value?.data ?? [])
const accounts = computed(() => accountsData.value?.data ?? [])

const retryActions = [{
  label: 'Tentar novamente',
  color: 'error' as const,
  variant: 'outline' as const,
  onClick: () => refresh()
}]

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
    id: 'select',
    header: ({ table: api }) => h(UCheckbox, {
      'modelValue': api.getIsSomePageRowsSelected() ? 'indeterminate' : api.getIsAllPageRowsSelected(),
      'onUpdate:modelValue': (value: boolean | 'indeterminate') => api.toggleAllPageRowsSelected(!!value),
      'ariaLabel': 'Selecionar todos'
    }),
    cell: ({ row }) => h(UCheckbox, {
      'modelValue': row.getIsSelected(),
      'onUpdate:modelValue': (value: boolean | 'indeterminate') => row.toggleSelected(!!value),
      'ariaLabel': 'Selecionar linha'
    })
  },
  {
    accessorKey: 'name',
    header: sortableHeader('Nome'),
    cell: ({ row }) => h('span', { class: 'font-medium' }, row.original.name)
  },
  {
    accessorKey: 'price_cents',
    header: sortableHeader('Preço'),
    cell: ({ row }) => formatPrice(row.original.price_cents)
  },
  {
    accessorKey: 'is_default',
    header: 'Padrão',
    filterFn: (row, _columnId, value) => {
      if (value === undefined || value === 'all') return true
      return String(row.original.is_default) === String(value)
    },
    cell: ({ row }) => row.original.is_default ? h(UBadge, { variant: 'subtle', color: 'success' }, () => 'Padrão') : h('p', { class: 'text-sm text-muted' }, '—')
  },
  { accessorKey: 'max_users', header: sortableHeader('Usuários') },
  { accessorKey: 'max_clients', header: sortableHeader('Clientes') },
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
        ariaLabel: `Editar plano ${row.original.name}`,
        onClick: () => openEdit(row.original)
      }))
    }
  }
]

function sortableHeader(label: string) {
  return ({ column }: { column: { getIsSorted: () => false | 'asc' | 'desc', toggleSorting: (desc?: boolean) => void } }) => {
    const isSorted = column.getIsSorted()
    return h(UButton, {
      color: 'neutral',
      variant: 'ghost',
      label,
      icon: isSorted ? (isSorted === 'asc' ? 'i-lucide-arrow-up-narrow-wide' : 'i-lucide-arrow-down-wide-narrow') : 'i-lucide-arrow-up-down',
      class: '-mx-2.5',
      onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
    })
  }
}

const defaultItems = [
  { label: 'Todos os planos', value: 'all' },
  { label: 'Somente padrão', value: 'true' }
]

watch(() => defaultFilter.value, (newVal) => {
  if (!table?.value?.tableApi) return
  const column = table.value.tableApi.getColumn('is_default')
  if (!column) return
  if (newVal === 'all') column.setFilterValue(undefined)
  else column.setFilterValue(newVal)
  pagination.value.pageIndex = 0
})

const search = computed({
  get: (): string => (table.value?.tableApi?.getColumn('name')?.getFilterValue() as string) || '',
  set: (value: string) => {
    table.value?.tableApi?.getColumn('name')?.setFilterValue(value || undefined)
    pagination.value.pageIndex = 0
  }
})

const selectedRows = computed((): Row<PlanRow>[] => table.value?.tableApi?.getFilteredSelectedRowModel().rows ?? [])
const filteredCount = computed((): number => table.value?.tableApi?.getFilteredRowModel().rows.length ?? plans.value.length)

function exportCsv() {
  const rows = (selectedRows.value.length > 0 ? selectedRows.value : (table.value?.tableApi?.getFilteredRowModel().rows ?? [])).map((r: Row<PlanRow>) => r.original)
  if (rows.length === 0) {
    toast.add({ title: 'Nada para exportar', description: 'Ajuste os filtros ou selecione ao menos um plano.', color: 'warning' })
    return
  }
  exportToCsv('planos', rows.map((p: PlanRow) => ({
    id: p.id,
    nome: p.name,
    preco: formatPrice(p.price_cents),
    max_usuarios: p.max_users,
    max_clientes: p.max_clients,
    modulos: p.modules.join(', '),
    padrao: p.is_default ? 'Sim' : 'Não'
  })))
  toast.add({ title: 'CSV exportado', description: `${rows.length} plano(s) exportado(s).`, color: 'success' })
}

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
      <UDashboardNavbar title="Planos">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton label="Novo plano" icon="i-lucide-plus" @click="openCreate" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        v-if="error"
        color="error"
        title="Não foi possível carregar os planos"
        :description="backendMessage(error)"
        :actions="retryActions"
      />

      <div class="flex flex-wrap items-center justify-between gap-1.5">
        <UInput
          v-model="search"
          class="max-w-sm"
          icon="i-lucide-search"
          placeholder="Buscar por nome..."
        />

        <div class="flex flex-wrap items-center gap-1.5">
          <UButton
            label="Exportar CSV"
            color="neutral"
            variant="outline"
            icon="i-lucide-download"
            @click="exportCsv"
          />
          <USelect
            v-model="defaultFilter"
            :items="defaultItems"
            value-key="value"
            placeholder="Filtrar padrão"
            class="min-w-28"
          />
          <UDropdownMenu
            :items="table?.tableApi?.getAllColumns().filter((column: any) => column.getCanHide()).map((column: any) => ({
              label: upperFirst(column.id),
              type: 'checkbox' as const,
              checked: column.getIsVisible(),
              onUpdateChecked(checked: boolean) {
                table?.tableApi?.getColumn(column.id)?.toggleVisibility(!!checked)
              },
              onSelect(e?: Event) {
                e?.preventDefault()
              }
            }))"
            :content="{ align: 'end' }"
          >
            <UButton
              label="Exibir"
              color="neutral"
              variant="outline"
              trailing-icon="i-lucide-settings-2"
            />
          </UDropdownMenu>
        </div>
      </div>

      <UTable
        ref="table"
        v-model:column-filters="columnFilters"
        v-model:column-visibility="columnVisibility"
        v-model:row-selection="rowSelection"
        v-model:sorting="sorting"
        v-model:pagination="pagination"
        :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
        class="shrink-0"
        :data="plans"
        :columns="columns"
        :loading="status === 'pending'"
        :ui="{
          base: 'table-fixed border-separate border-spacing-0',
          thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
          tbody: '[&>tr]:last:[&>td]:border-b-0',
          th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
          td: 'border-b border-default',
          separator: 'h-0'
        }"
      >
        <template #empty>
          <div class="flex flex-col items-center justify-center gap-2 py-8 text-center">
            <p class="font-medium text-highlighted">
              Nenhum plano encontrado
            </p>
            <p class="text-sm text-muted">
              Ajuste a busca ou o filtro de padrão.
            </p>
          </div>
        </template>
      </UTable>

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4">
        <div class="text-sm text-muted">
          {{ selectedRows.length }} de {{ filteredCount }} plano(s) selecionado(s).
        </div>

        <UPagination
          :page="(table?.tableApi?.getState().pagination.pageIndex || 0) + 1"
          :items-per-page="table?.tableApi?.getState().pagination.pageSize"
          :total="filteredCount"
          @update:page="(p: number) => table?.tableApi?.setPageIndex(p - 1)"
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
