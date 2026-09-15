<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'
import { upperFirst } from 'scule'
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'

interface ClientRow {
  id: number
  cnpj: string
  razao_social: string
  regime: string
  contador_responsavel: string
  monitoring_enabled: boolean
}

interface ClientsResponse {
  data: ClientRow[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
}

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UCheckbox = resolveComponent('UCheckbox')
const UDropdownMenu = resolveComponent('UDropdownMenu')
const USwitch = resolveComponent('USwitch')

const toast = useToast()
const { can } = usePermissions()
const canWrite = computed(() => can('clients.write'))

interface ClientTableApi {
  getFilteredSelectedRowModel: () => { rows: Row<ClientRow>[] }
  getFilteredRowModel: () => { rows: Row<ClientRow>[] }
  getColumn: (id: string) => { setFilterValue: (value: string | undefined) => void, getFilterValue: () => unknown, toggleVisibility: (value?: boolean) => void } | undefined
  getAllColumns: () => { id: string, getCanHide: () => boolean, getIsVisible: () => boolean }[]
  getState: () => { pagination: { pageIndex: number, pageSize: number } }
  setPageIndex: (index: number) => void
}

const table = useTemplateRef<{ tableApi?: ClientTableApi | null }>('table')
const columnFilters = ref([{ id: 'razao_social', value: '' }])
const columnVisibility = ref()
const rowSelection = ref<Record<string, boolean>>({})
const sorting = ref<{ id: string, desc: boolean }[]>([])
const pagination = ref({ pageIndex: 0, pageSize: 10 })
const regimeFilter = ref('all')

const { data, error, status, refresh } = await useFetch<ClientsResponse>('/api/clients', {
  key: 'clients-list',
  query: { per_page: 500 }
})

const clients = computed(() => data.value?.data ?? [])

const retryActions = [{
  label: 'Tentar novamente',
  color: 'error' as const,
  variant: 'outline' as const,
  onClick: () => refresh()
}]

function getRowItems(row: Row<ClientRow>) {
  const client = row.original
  const items: Record<string, unknown>[] = [
    { type: 'label', label: 'Ações' },
    { label: 'Editar cliente', icon: 'i-lucide-pencil', onSelect: () => openEdit(client) },
    {
      label: client.monitoring_enabled ? 'Desativar monitoramento' : 'Ativar monitoramento',
      icon: client.monitoring_enabled ? 'i-lucide-pause' : 'i-lucide-play',
      onSelect: () => void toggleMonitoring(client, !client.monitoring_enabled)
    },
    { type: 'separator' },
    { label: 'Excluir cliente', icon: 'i-lucide-trash', color: 'error', onSelect: () => askDelete(client) }
  ]
  return canWrite.value ? items : [{ label: 'Ver detalhes', icon: 'i-lucide-eye', onSelect: () => openEdit(client) }]
}

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

const columns: TableColumn<ClientRow>[] = [
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
    accessorKey: 'razao_social',
    header: sortableHeader('Razão social'),
    filterFn: (row, _columnId, value) => {
      const term = String(value ?? '').toLowerCase()
      if (!term) return true
      return row.original.razao_social.toLowerCase().includes(term) || row.original.cnpj.toLowerCase().includes(term)
    },
    cell: ({ row }) => h('div', { class: 'flex flex-col' }, [
      h('p', { class: 'font-medium text-highlighted' }, row.original.razao_social),
      h('p', { class: 'text-sm text-muted' }, formatCnpj(row.original.cnpj))
    ])
  },
  {
    accessorKey: 'cnpj',
    header: sortableHeader('CNPJ'),
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatCnpj(row.original.cnpj))
  },
  {
    accessorKey: 'regime',
    header: 'Regime',
    filterFn: 'equals',
    cell: ({ row }) => h(UBadge, { variant: 'subtle', color: 'neutral', class: 'capitalize' }, () => regimeLabel(row.original.regime))
  },
  {
    accessorKey: 'contador_responsavel',
    header: sortableHeader('Contador'),
    cell: ({ row }) => h('p', { class: 'text-sm' }, row.original.contador_responsavel)
  },
  {
    accessorKey: 'monitoring_enabled',
    header: 'Monitoramento',
    cell: ({ row }) => {
      const client = row.original
      if (!canWrite.value) {
        return h(UBadge, { variant: 'subtle', color: client.monitoring_enabled ? 'success' : 'neutral' }, () => client.monitoring_enabled ? 'Ativo' : 'Inativo')
      }
      return h(USwitch, {
        'modelValue': client.monitoring_enabled,
        'onUpdate:modelValue': (enabled: boolean) => void toggleMonitoring(client, enabled),
        'ariaLabel': `Monitoramento de ${client.razao_social}`
      })
    }
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'text-right' }, [
      h(UDropdownMenu, {
        content: { align: 'end' },
        items: getRowItems(row)
      }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações do cliente' }))
    ])
  }
]

watch(() => regimeFilter.value, (newVal) => {
  if (!table?.value?.tableApi) return
  const column = table.value.tableApi.getColumn('regime')
  if (!column) return
  if (newVal === 'all') column.setFilterValue(undefined)
  else column.setFilterValue(newVal)
  pagination.value.pageIndex = 0
})

const search = computed({
  get: (): string => (table.value?.tableApi?.getColumn('razao_social')?.getFilterValue() as string) || '',
  set: (value: string) => {
    table.value?.tableApi?.getColumn('razao_social')?.setFilterValue(value || undefined)
    pagination.value.pageIndex = 0
  }
})

const selectedRows = computed((): Row<ClientRow>[] => table.value?.tableApi?.getFilteredSelectedRowModel().rows ?? [])
const filteredCount = computed((): number => table.value?.tableApi?.getFilteredRowModel().rows.length ?? clients.value.length)

function exportCsv() {
  const rows = (selectedRows.value.length > 0 ? selectedRows.value : (table.value?.tableApi?.getFilteredRowModel().rows ?? [])).map((r: Row<ClientRow>) => r.original)
  if (rows.length === 0) {
    toast.add({ title: 'Nada para exportar', description: 'Ajuste os filtros ou selecione ao menos um cliente.', color: 'warning' })
    return
  }
  exportToCsv('clientes', rows.map((c: ClientRow) => ({
    id: c.id,
    razao_social: c.razao_social,
    cnpj: c.cnpj,
    regime: regimeLabel(c.regime),
    contador_responsavel: c.contador_responsavel,
    monitoramento: c.monitoring_enabled ? 'Ativo' : 'Inativo'
  })))
  toast.add({ title: 'CSV exportado', description: `${rows.length} cliente(s) exportado(s).`, color: 'success' })
}

const bulkDeleting = ref(false)
async function removeSelected() {
  const rows = selectedRows.value.map((r: Row<ClientRow>) => r.original)
  if (rows.length === 0) return
  bulkDeleting.value = true
  try {
    for (const client of rows) {
      await $fetch(`/api/clients/${client.id}`, { method: 'DELETE' })
    }
    toast.add({ title: `${rows.length} cliente(s) excluído(s)`, color: 'success' })
    rowSelection.value = {}
    await refresh()
  } catch (error) {
    toast.add({ title: 'Não foi possível excluir a seleção', description: backendMessage(error), color: 'error' })
  } finally {
    bulkDeleting.value = false
  }
}

const regimeItems = [
  { label: 'Todos os regimes', value: 'all' },
  { label: 'Simples Nacional', value: 'simples' },
  { label: 'Lucro Presumido', value: 'presumido' },
  { label: 'Lucro Real', value: 'real' },
  { label: 'MEI', value: 'mei' }
]

function regimeLabel(value: string): string {
  return regimeItems.find(item => item.value === value)?.label ?? value
}

async function toggleMonitoring(client: ClientRow, enabled: boolean) {
  try {
    await $fetch(`/api/clients/${client.id}/monitoring`, {
      method: 'PATCH',
      body: { monitoring_enabled: enabled }
    })
    toast.add({
      title: enabled ? 'Monitoramento ativado' : 'Monitoramento desativado',
      description: client.razao_social,
      color: 'success'
    })
    await refresh()
  } catch (error) {
    toast.add({ title: 'Não foi possível alterar o monitoramento', description: backendMessage(error), color: 'error' })
    await refresh()
  }
}

async function removeClient(client: ClientRow) {
  try {
    await $fetch(`/api/clients/${client.id}`, { method: 'DELETE' })
    toast.add({ title: 'Cliente excluído', description: client.razao_social, color: 'success' })
    deleteOpen.value = false
    deleting.value = null
    await refresh()
  } catch (error) {
    toast.add({ title: 'Não foi possível excluir', description: backendMessage(error), color: 'error' })
  }
}

const schema = z.object({
  cnpj: z.string().regex(/^\d{14}$/, 'CNPJ deve ter 14 dígitos'),
  razao_social: z.string().min(2, 'Informe a razão social'),
  regime: z.string().min(1, 'Informe o regime'),
  contador_responsavel: z.string().min(2, 'Informe o contador responsável')
})

type Schema = z.output<typeof schema>

const open = ref(false)
const saving = ref(false)
const editing = ref<ClientRow | null>(null)
const deleteOpen = ref(false)
const deleting = ref<ClientRow | null>(null)
const form = useTemplateRef('form')

const state = reactive<Schema>({
  cnpj: '',
  razao_social: '',
  regime: 'simples',
  contador_responsavel: ''
})

const formRegimeItems = regimeItems.filter(item => item.value !== 'all')

function openCreate() {
  editing.value = null
  state.cnpj = ''
  state.razao_social = ''
  state.regime = 'simples'
  state.contador_responsavel = ''
  open.value = true
}

function openEdit(client: ClientRow) {
  editing.value = client
  state.cnpj = client.cnpj
  state.razao_social = client.razao_social
  state.regime = client.regime
  state.contador_responsavel = client.contador_responsavel
  open.value = true
}

function askDelete(client: ClientRow) {
  deleting.value = client
  deleteOpen.value = true
}

async function onSubmit(event: FormSubmitEvent<Schema>) {
  saving.value = true
  try {
    if (editing.value) {
      await $fetch(`/api/clients/${editing.value.id}`, { method: 'PATCH', body: event.data })
      toast.add({ title: 'Cliente atualizado', color: 'success' })
    } else {
      await $fetch('/api/clients', { method: 'POST', body: event.data })
      toast.add({ title: 'Cliente criado', color: 'success' })
    }
    open.value = false
    await refresh()
  } catch (error) {
    const fieldErrors = backendFormErrors(error)
    form.value?.setErrors(fieldErrors)
    toast.add({
      title: 'Não foi possível salvar o cliente',
      description: fieldErrors[0]?.message ?? backendMessage(error),
      color: 'error'
    })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="clients">
    <template #header>
      <UDashboardNavbar title="Clientes">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="canWrite"
            label="Novo cliente"
            icon="i-lucide-plus"
            @click="openCreate"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        v-if="error"
        color="error"
        title="Não foi possível carregar os clientes"
        :description="backendMessage(error)"
        :actions="retryActions"
      />

      <div class="flex flex-wrap items-center justify-between gap-1.5">
        <UInput
          v-model="search"
          class="max-w-sm"
          icon="i-lucide-search"
          placeholder="Buscar por razão social ou CNPJ..."
        />

        <div class="flex flex-wrap items-center gap-1.5">
          <UButton
            v-if="selectedRows.length"
            :label="`Excluir (${selectedRows.length})`"
            color="error"
            variant="subtle"
            icon="i-lucide-trash"
            :loading="bulkDeleting"
            @click="removeSelected"
          />
          <UButton
            label="Exportar CSV"
            color="neutral"
            variant="outline"
            icon="i-lucide-download"
            @click="exportCsv"
          />
          <USelect
            v-model="regimeFilter"
            :items="regimeItems"
            value-key="value"
            placeholder="Filtrar regime"
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
        :data="clients"
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
              Nenhum cliente encontrado
            </p>
            <p class="text-sm text-muted">
              Ajuste a busca ou o filtro de regime.
            </p>
          </div>
        </template>
      </UTable>

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <div class="text-sm text-muted">
          {{ selectedRows.length }} de {{ filteredCount }} cliente(s) selecionado(s).
        </div>

        <div class="flex items-center gap-1.5">
          <UPagination
            :page="(table?.tableApi?.getState().pagination.pageIndex || 0) + 1"
            :items-per-page="table?.tableApi?.getState().pagination.pageSize"
            :total="filteredCount"
            @update:page="(p: number) => table?.tableApi?.setPageIndex(p - 1)"
          />
        </div>
      </div>

      <UModal v-model:open="open" :title="editing ? 'Editar cliente' : 'Novo cliente'" description="Dados cadastrais do cliente">
        <template #body>
          <UForm
            ref="form"
            :schema="schema"
            :state="state"
            class="space-y-4"
            @submit="onSubmit"
          >
            <UFormField label="Razão social" name="razao_social" required>
              <UInput v-model="state.razao_social" class="w-full" />
            </UFormField>
            <UFormField label="CNPJ (14 dígitos)" name="cnpj" required>
              <UInput
                v-model="state.cnpj"
                inputmode="numeric"
                maxlength="14"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Regime" name="regime" required>
              <USelect
                v-model="state.regime"
                :items="formRegimeItems"
                value-key="value"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Contador responsável" name="contador_responsavel" required>
              <UInput v-model="state.contador_responsavel" class="w-full" />
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

      <UModal v-model:open="deleteOpen" title="Excluir cliente" description="Esta ação não pode ser desfeita">
        <template #body>
          <p class="text-sm text-muted">
            Excluir <span class="font-medium text-highlighted">{{ deleting?.razao_social }}</span>?
          </p>
          <div class="flex justify-end gap-2 mt-4">
            <UButton
              label="Cancelar"
              color="neutral"
              variant="subtle"
              @click="deleteOpen = false"
            />
            <UButton
              v-if="deleting"
              label="Excluir"
              color="error"
              variant="solid"
              @click="() => removeClient(deleting!)"
            />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
