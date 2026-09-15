<script setup lang="ts">
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'
import { TABLE_UI as tableUi, type TableApi } from '~/utils/table-chrome'
import { buildParcelModalityItems, buildParcelOrderColumns, PARCEL_ORDER_COLUMN_LABELS } from '~/components/tables/monitoring/parcelOrderColumns'
import type { Paginated, ParcelmentOrder } from '~/types/monitoring'

const toast = useToast()

const table = useTemplateRef<{ tableApi?: TableApi<ParcelmentOrder> | null }>('table')
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

const modalityItems = buildParcelModalityItems()

const columns = buildParcelOrderColumns({
  onOpen: order => navigateTo(`/monitoring/parcelamentos/${order.id}`),
  onCopyId: (order) => {
    navigator.clipboard.writeText(String(order.id))
    toast.add({ title: 'ID copiado', color: 'success' })
  }
})

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
          <TablesTableStates
            :error="error"
            variant="subtle"
            error-title="Parcelamentos indisponíveis"
            :error-description="monitoringErrorMessage(backendErrorBody(error))"
            @retry="refresh"
          />

          <TablesTableToolbar
            v-model:search="search"
            v-model:filter-value="modality"
            search-placeholder="Buscar por cliente ou CNPJ…"
            :filter-items="modalityItems"
            :table-api="table?.tableApi"
            :column-labels="PARCEL_ORDER_COLUMN_LABELS"
            @export="exportCsv"
          />

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
            :ui="tableUi"
          >
            <template #empty>
              <TablesTableStates
                empty-title="Nenhum parcelamento encontrado"
                empty-hint="Ajuste a busca ou o filtro de modalidade."
              />
            </template>
          </UTable>

          <TablesTableFooter
            :selected="selectedRows.length"
            :total="filteredCount"
            unit="pedido(s)"
            :table-api="table?.tableApi"
            @update:page="(p: number) => table?.tableApi?.setPageIndex(p - 1)"
          />
        </div>
      </UCard>
    </template>
  </UDashboardPanel>
</template>
