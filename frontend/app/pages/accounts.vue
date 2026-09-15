<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'
import { upperFirst } from 'scule'
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'

definePageMeta({ middleware: 'super-admin' })

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
const UButton = resolveComponent('UButton')
const UCheckbox = resolveComponent('UCheckbox')
const UDropdownMenu = resolveComponent('UDropdownMenu')

const toast = useToast()

interface AccountTableApi {
  getFilteredSelectedRowModel: () => { rows: Row<AccountRow>[] }
  getFilteredRowModel: () => { rows: Row<AccountRow>[] }
  getColumn: (id: string) => { setFilterValue: (value: string | undefined) => void, getFilterValue: () => unknown, toggleVisibility: (value?: boolean) => void } | undefined
  getAllColumns: () => { id: string, getCanHide: () => boolean, getIsVisible: () => boolean }[]
  getState: () => { pagination: { pageIndex: number, pageSize: number } }
  setPageIndex: (index: number) => void
}

const table = useTemplateRef<{ tableApi?: AccountTableApi | null }>('table')
const columnFilters = ref([{ id: 'name', value: '' }])
const columnVisibility = ref()
const rowSelection = ref<Record<string, boolean>>({})
const sorting = ref<{ id: string, desc: boolean }[]>([])
const pagination = ref({ pageIndex: 0, pageSize: 10 })
const profileFilter = ref('all')

const { data, error, status, refresh } = await useFetch<AccountsResponse>('/api/accounts', {
  key: 'accounts-list',
  query: { per_page: 500 }
})

const accounts = computed(() => data.value?.data ?? [])

function profileLabel(value: string): string {
  if (value === 'A') return 'Central OneFisc'
  if (value === 'B') return 'Escritório'
  return value
}

const profileItems = [
  { label: 'Todos os tipos', value: 'all' },
  { label: 'Central OneFisc', value: 'A' },
  { label: 'Escritório', value: 'B' }
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

const columns: TableColumn<AccountRow>[] = [
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
    header: sortableHeader('Nome')
  },
  {
    accessorKey: 'profile',
    header: 'Tipo',
    filterFn: 'equals',
    cell: ({ row }) => {
      const central = row.original.profile === 'A'
      return h(UBadge, { variant: 'subtle', color: central ? 'primary' : 'neutral' }, () => profileLabel(row.original.profile))
    }
  },
  {
    id: 'plan',
    header: sortableHeader('Plano'),
    sortingFn: (rowA, rowB) => (rowA.original.plan?.name ?? '').localeCompare(rowB.original.plan?.name ?? '', 'pt-BR'),
    cell: ({ row }) => row.original.plan?.name ?? '—'
  }
]

watch(() => profileFilter.value, (newVal) => {
  if (!table?.value?.tableApi) return
  const column = table.value.tableApi.getColumn('profile')
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

const selectedRows = computed((): Row<AccountRow>[] => table.value?.tableApi?.getFilteredSelectedRowModel().rows ?? [])
const filteredCount = computed((): number => table.value?.tableApi?.getFilteredRowModel().rows.length ?? accounts.value.length)

function exportCsv() {
  const rows = (selectedRows.value.length > 0 ? selectedRows.value : (table.value?.tableApi?.getFilteredRowModel().rows ?? [])).map((r: Row<AccountRow>) => r.original)
  if (rows.length === 0) {
    toast.add({ title: 'Nada para exportar', description: 'Ajuste os filtros ou selecione ao menos uma conta.', color: 'warning' })
    return
  }
  exportToCsv('contas', rows.map((a: AccountRow) => ({
    id: a.id,
    nome: a.name,
    tipo: profileLabel(a.profile),
    plano: a.plan?.name ?? ''
  })))
  toast.add({ title: 'CSV exportado', description: `${rows.length} conta(s) exportada(s).`, color: 'success' })
}

const retryActions = [{
  label: 'Tentar novamente',
  color: 'error' as const,
  variant: 'outline' as const,
  onClick: () => refresh()
}]

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

watch(open, (value) => {
  if (!value) {
    resetState()
    form.value?.clear()
  }
})

async function onSubmit(event: FormSubmitEvent<Schema>) {
  if (creating.value) {
    return
  }
  creating.value = true
  try {
    await $fetch('/api/accounts', { method: 'POST', body: event.data })
    toast.add({ title: 'Conta criada', description: `Convite enviado para ${event.data.admin_email}.`, color: 'success' })
    open.value = false
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
      <UDashboardNavbar title="Escritórios">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UModal v-model:open="open" title="Novo escritório" description="Cria um escritório (Account B) e envia o convite ao administrador">
            <UButton label="Novo escritório" icon="i-lucide-plus" />

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
      <UAlert
        v-if="error"
        color="error"
        title="Não foi possível carregar as contas"
        :description="backendMessage(error)"
        :actions="retryActions"
      />

      <div v-else class="flex flex-col gap-4">
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
              v-model="profileFilter"
              :items="profileItems"
              value-key="value"
              placeholder="Filtrar tipo"
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
          :data="accounts"
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
                Nenhuma conta encontrada
              </p>
              <p class="text-sm text-muted">
                Ajuste a busca ou o filtro de tipo.
              </p>
            </div>
          </template>
        </UTable>

        <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
          <div class="text-sm text-muted">
            {{ selectedRows.length }} de {{ filteredCount }} conta(s) selecionada(s).
          </div>

          <UPagination
            :page="(table?.tableApi?.getState().pagination.pageIndex || 0) + 1"
            :items-per-page="table?.tableApi?.getState().pagination.pageSize"
            :total="filteredCount"
            @update:page="(p: number) => table?.tableApi?.setPageIndex(p - 1)"
          />
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
