<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { upperFirst } from 'scule'
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'
import type { Paginated, ParcelmentOrder } from '~/types/monitoring'

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UCheckbox = resolveComponent('UCheckbox')
const UDropdownMenu = resolveComponent('UDropdownMenu')

const toast = useToast()

const MODALITIES = ['PARCSN', 'PARCSN-ESP', 'PERTSN', 'RELPSN', 'PARCMEI', 'PARCMEI-ESP', 'PERTMEI', 'RELPMEI']

interface ParcelTableApi {
  getFilteredSelectedRowModel: () => { rows: Row<ParcelmentOrder>[] }
  getFilteredRowModel: () => { rows: Row<ParcelmentOrder>[] }
  getColumn: (id: string) => { setFilterValue: (value: string | undefined) => void, getFilterValue: () => unknown, toggleVisibility: (value?: boolean) => void } | undefined
  getAllColumns: () => { id: string, getCanHide: () => boolean, getIsVisible: () => boolean }[]
  getState: () => { pagination: { pageIndex: number, pageSize: number } }
  setPageIndex: (index: number) => void
}

const table = useTemplateRef<{ tableApi?: ParcelTableApi | null }>('table')
const columnFilters = ref([{ id: 'client', value: '' }])
const columnVisibility = ref()
const rowSelection = ref<Record<string, boolean>>({})
const sorting = ref<{ id: string, desc: boolean }[]>([])
const pagination = ref({ pageIndex: 0, pageSize: 10 })
const modality = ref('all')

const { data: orders, status, error, refresh } = await useFetch<Paginated<ParcelmentOrder>>('/api/monitoring/parcelamentos', {
  lazy: true,
  query: { per_page: 500 }
})

const rows = computed((): ParcelmentOrder[] => orders.value?.data ?? [])

const retryActions = [{
  label: 'Tentar novamente',
  color: 'error' as const,
  variant: 'outline' as const,
  onClick: () => refresh()
}]

function getRowItems(row: Row<ParcelmentOrder>) {
  return [
    { type: 'label' as const, label: 'Ações' },
    {
      label: 'Abrir detalhe',
      icon: 'i-lucide-eye',
      onSelect: () => navigateTo(`/monitoring/parcelamentos/${row.original.id}`)
    },
    {
      label: 'Copiar ID do pedido',
      icon: 'i-lucide-copy',
      onSelect: () => {
        navigator.clipboard.writeText(String(row.original.id))
        toast.add({ title: 'ID copiado', color: 'success' })
      }
    }
  ]
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

const columns: TableColumn<ParcelmentOrder>[] = [
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
    accessorKey: 'client',
    header: sortableHeader('Cliente'),
    filterFn: (row, _columnId, value) => {
      const term = String(value ?? '').toLowerCase()
      if (!term) return true
      const client = row.original.client
      return (client?.razao_social ?? '').toLowerCase().includes(term) || (client?.cnpj ?? '').toLowerCase().includes(term)
    },
    cell: ({ row }) => {
      const client = row.original.client
      return h('div', { class: 'flex flex-col' }, [
        h('p', { class: 'font-medium text-highlighted' }, client?.razao_social ?? `Cliente #${row.original.client_id}`),
        h('p', { class: 'text-sm text-muted' }, client ? formatCnpj(client.cnpj) : '—')
      ])
    }
  },
  {
    accessorKey: 'modality',
    header: 'Modalidade',
    filterFn: 'equals',
    cell: ({ row }) => h(UBadge, { color: 'info', variant: 'subtle' }, () => row.original.modality)
  },
  {
    accessorKey: 'status',
    header: sortableHeader('Estado'),
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, row.original.status ?? '—')
  },
  {
    accessorKey: 'installments',
    header: 'Parcelas',
    cell: ({ row }) => {
      const paid = row.original.paid_installments
      const total = row.original.installments_count
      return h('p', { class: 'text-sm text-muted' }, paid !== null && total !== null ? `${paid}/${total} pagas` : '—')
    }
  },
  {
    accessorKey: 'total_amount',
    header: sortableHeader('Total'),
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatMoney(row.original.total_amount))
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'text-right' }, [
      h(UDropdownMenu, {
        content: { align: 'end' },
        items: getRowItems(row)
      }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações do parcelamento' }))
    ])
  }
]

watch(() => modality.value, (newVal) => {
  const column = table.value?.tableApi?.getColumn('modality')
  if (!column) return
  if (newVal === 'all') column.setFilterValue(undefined)
  else column.setFilterValue(newVal)
  pagination.value.pageIndex = 0
})

const search = computed({
  get: (): string => (table.value?.tableApi?.getColumn('client')?.getFilterValue() as string) || '',
  set: (value: string) => {
    table.value?.tableApi?.getColumn('client')?.setFilterValue(value || undefined)
    pagination.value.pageIndex = 0
  }
})

const selectedRows = computed((): Row<ParcelmentOrder>[] => table.value?.tableApi?.getFilteredSelectedRowModel().rows ?? [])
const filteredCount = computed((): number => table.value?.tableApi?.getFilteredRowModel().rows.length ?? rows.value.length)

function exportCsv(): void {
  const list = (selectedRows.value.length > 0 ? selectedRows.value : (table.value?.tableApi?.getFilteredRowModel().rows ?? [])).map((r: Row<ParcelmentOrder>) => r.original)
  if (list.length === 0) {
    toast.add({ title: 'Nada para exportar', description: 'Ajuste os filtros ou selecione ao menos um pedido.', color: 'warning' })
    return
  }
  exportToCsv('parcelamentos', list.map(o => ({
    id: o.id,
    cliente: o.client?.razao_social ?? '',
    cnpj: o.client?.cnpj ?? '',
    modalidade: o.modality,
    estado: o.status ?? '',
    parcelas_pagas: o.paid_installments ?? '',
    parcelas_total: o.installments_count ?? '',
    total: o.total_amount ?? ''
  })))
  toast.add({ title: 'CSV exportado', description: `${list.length} pedido(s) exportado(s).`, color: 'success' })
}
</script>

<template>
  <UDashboardPanel id="monitoring-parcelamentos">
    <template #header>
      <UDashboardNavbar title="Parcelamentos">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UCard>
        <div class="flex flex-col gap-4">
          <UAlert
            v-if="error"
            color="error"
            variant="subtle"
            title="Parcelamentos indisponíveis"
            :description="monitoringErrorMessage(backendErrorBody(error))"
            :actions="retryActions"
          />

          <div class="flex flex-wrap items-center justify-between gap-1.5">
            <UInput
              v-model="search"
              icon="i-lucide-search"
              placeholder="Buscar por cliente ou CNPJ…"
              class="max-w-sm"
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
                v-model="modality"
                :items="[{ label: 'Todas as modalidades', value: 'all' }, ...MODALITIES.map(m => ({ label: m, value: m }))]"
                class="w-56"
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
            :data="rows"
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
                  Nenhum parcelamento encontrado
                </p>
                <p class="text-sm text-muted">
                  Ajuste a busca ou o filtro de modalidade.
                </p>
              </div>
            </template>
          </UTable>

          <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
            <div class="text-sm text-muted">
              {{ selectedRows.length }} de {{ filteredCount }} pedido(s) selecionado(s).
            </div>
            <UPagination
              :page="(table?.tableApi?.getState().pagination.pageIndex || 0) + 1"
              :items-per-page="table?.tableApi?.getState().pagination.pageSize"
              :total="filteredCount"
              @update:page="(p: number) => table?.tableApi?.setPageIndex(p - 1)"
            />
          </div>
        </div>
      </UCard>
    </template>
  </UDashboardPanel>
</template>
