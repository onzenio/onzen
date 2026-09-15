<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { upperFirst } from 'scule'
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'

interface AuditActor {
  id: number
  name: string
  email: string
}

interface AuditRow {
  id: number
  actor_user_id: number | null
  origin_account_id: number
  target_account_id: number | null
  action: string
  metadata: Record<string, unknown> | null
  created_at: string
  actor?: AuditActor | null
}

interface AuditResponse {
  data: AuditRow[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
}

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UCheckbox = resolveComponent('UCheckbox')
const UDropdownMenu = resolveComponent('UDropdownMenu')

const toast = useToast()

interface AuditTableApi {
  getFilteredSelectedRowModel: () => { rows: Row<AuditRow>[] }
  getFilteredRowModel: () => { rows: Row<AuditRow>[] }
  getColumn: (id: string) => { setFilterValue: (value: string | undefined) => void, getFilterValue: () => unknown, toggleVisibility: (value?: boolean) => void } | undefined
  getAllColumns: () => { id: string, getCanHide: () => boolean, getIsVisible: () => boolean }[]
  getState: () => { pagination: { pageIndex: number, pageSize: number } }
  setPageIndex: (index: number) => void
}

const table = useTemplateRef<{ tableApi?: AuditTableApi | null }>('table')
const columnFilters = ref([{ id: 'actor', value: '' }])
const columnVisibility = ref()
const rowSelection = ref<Record<string, boolean>>({})
const sorting = ref<{ id: string, desc: boolean }[]>([])
const pagination = ref({ pageIndex: 0, pageSize: 10 })
const actionFilter = ref('all')

const accountId = ref('')
const actorUserId = ref('')
const from = ref('')
const to = ref('')

const query = computed(() => ({
  account_id: accountId.value || undefined,
  actor_user_id: actorUserId.value || undefined,
  from: from.value || undefined,
  to: to.value || undefined,
  per_page: 500
}))

const { data, error, status, refresh } = await useFetch<AuditResponse>('/api/audit', {
  key: 'audit-list',
  query
})

const logs = computed(() => data.value?.data ?? [])

const retryActions = [{
  label: 'Tentar novamente',
  color: 'error' as const,
  variant: 'outline' as const,
  onClick: () => refresh()
}]

function applyFilters() {
  pagination.value.pageIndex = 0
  void refresh()
}

function clearFilters() {
  accountId.value = ''
  actorUserId.value = ''
  from.value = ''
  to.value = ''
  actionFilter.value = 'all'
  pagination.value.pageIndex = 0
  void refresh()
}

function formatDate(value: string): string {
  return new Date(value).toLocaleString('pt-BR')
}

function actorLabel(row: AuditRow): string {
  return row.actor?.email ?? (row.actor_user_id ? `#${row.actor_user_id}` : '—')
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

const columns: TableColumn<AuditRow>[] = [
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
  { accessorKey: 'id', header: 'ID' },
  {
    accessorKey: 'created_at',
    header: sortableHeader('Data'),
    cell: ({ row }) => formatDate(row.original.created_at)
  },
  {
    accessorKey: 'action',
    header: sortableHeader('Ação'),
    filterFn: 'equals',
    cell: ({ row }) => h(UBadge, { variant: 'subtle', color: 'neutral' }, () => row.original.action)
  },
  {
    id: 'actor',
    header: 'Ator',
    filterFn: (row, _columnId, value) => {
      const term = String(value ?? '').toLowerCase()
      if (!term) return true
      return row.original.action.toLowerCase().includes(term) || actorLabel(row.original).toLowerCase().includes(term)
    },
    cell: ({ row }) => actorLabel(row.original)
  },
  { accessorKey: 'origin_account_id', header: 'Origem' },
  {
    accessorKey: 'target_account_id',
    header: 'Alvo',
    cell: ({ row }) => row.original.target_account_id ?? '—'
  }
]

const actionItems = computed(() => [
  { label: 'Todas as ações', value: 'all' },
  ...Array.from(new Set(logs.value.map(log => log.action))).sort((a, b) => a.localeCompare(b, 'pt-BR')).map(action => ({ label: action, value: action }))
])

watch(() => actionFilter.value, (newVal) => {
  if (!table?.value?.tableApi) return
  const column = table.value.tableApi.getColumn('action')
  if (!column) return
  if (newVal === 'all') column.setFilterValue(undefined)
  else column.setFilterValue(newVal)
  pagination.value.pageIndex = 0
})

const search = computed({
  get: (): string => (table.value?.tableApi?.getColumn('actor')?.getFilterValue() as string) || '',
  set: (value: string) => {
    table.value?.tableApi?.getColumn('actor')?.setFilterValue(value || undefined)
    pagination.value.pageIndex = 0
  }
})

const selectedRows = computed((): Row<AuditRow>[] => table.value?.tableApi?.getFilteredSelectedRowModel().rows ?? [])
const filteredCount = computed((): number => table.value?.tableApi?.getFilteredRowModel().rows.length ?? logs.value.length)

function exportCsv() {
  const rows = (selectedRows.value.length > 0 ? selectedRows.value : (table.value?.tableApi?.getFilteredRowModel().rows ?? [])).map((r: Row<AuditRow>) => r.original)
  if (rows.length === 0) {
    toast.add({ title: 'Nada para exportar', description: 'Ajuste os filtros ou selecione ao menos um registro.', color: 'warning' })
    return
  }
  exportToCsv('auditoria', rows.map((log: AuditRow) => ({
    id: log.id,
    data: formatDate(log.created_at),
    acao: log.action,
    ator: actorLabel(log),
    origem: log.origin_account_id,
    alvo: log.target_account_id ?? ''
  })))
  toast.add({ title: 'CSV exportado', description: `${rows.length} registro(s) exportado(s).`, color: 'success' })
}
</script>

<template>
  <UDashboardPanel id="audit">
    <template #header>
      <UDashboardNavbar title="Auditoria">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UAlert
        v-if="error"
        color="error"
        title="Não foi possível carregar os registros"
        :description="backendMessage(error)"
        :actions="retryActions"
      />

      <div class="flex flex-wrap items-end gap-1.5">
        <UFormField label="Conta" class="min-w-28">
          <UInput
            v-model="accountId"
            type="number"
            min="1"
            placeholder="ID da conta"
          />
        </UFormField>
        <UFormField label="Ator" class="min-w-28">
          <UInput
            v-model="actorUserId"
            type="number"
            min="1"
            placeholder="ID do usuário"
          />
        </UFormField>
        <UFormField label="De" class="min-w-28">
          <UInput v-model="from" type="date" />
        </UFormField>
        <UFormField label="Até" class="min-w-28">
          <UInput v-model="to" type="date" />
        </UFormField>
        <div class="flex gap-1.5">
          <UButton label="Filtrar" icon="i-lucide-filter" @click="applyFilters" />
          <UButton
            label="Limpar"
            color="neutral"
            variant="outline"
            @click="clearFilters"
          />
        </div>
      </div>

      <div class="flex flex-wrap items-center justify-between gap-1.5">
        <UInput
          v-model="search"
          class="max-w-sm"
          icon="i-lucide-search"
          placeholder="Buscar por ação ou ator..."
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
            v-model="actionFilter"
            :items="actionItems"
            value-key="value"
            placeholder="Filtrar ação"
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
        :data="logs"
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
              Nenhum registro encontrado
            </p>
            <p class="text-sm text-muted">
              Ajuste a busca ou os filtros.
            </p>
          </div>
        </template>
      </UTable>

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <div class="text-sm text-muted">
          {{ selectedRows.length }} de {{ filteredCount }} registro(s) selecionado(s).
        </div>

        <UPagination
          :page="(table?.tableApi?.getState().pagination.pageIndex || 0) + 1"
          :items-per-page="table?.tableApi?.getState().pagination.pageSize"
          :total="filteredCount"
          @update:page="(p: number) => table?.tableApi?.setPageIndex(p - 1)"
        />
      </div>
    </template>
  </UDashboardPanel>
</template>
