<script setup lang="ts">
import { getPaginationRowModel } from '@tanstack/table-core'
import { TABLE_UI as tableUi, type TableApi } from '~/utils/table-chrome'
import { useTableCsv } from '~/composables/tables/useTableCsv'
import { useTableState } from '~/composables/tables/useTableState'
import { buildParcelModalityItems, buildParcelOrderColumns, PARCEL_ORDER_COLUMN_LABELS } from '~/components/tables/monitoring/parcelOrderColumns'
import type { Paginated, ParcelmentOrder } from '~/types/monitoring'

const toast = useToast()

const table = useTemplateRef<{ tableApi?: TableApi<ParcelmentOrder> | null }>('table')
const {
  columnFilters,
  columnVisibility,
  rowSelection,
  sorting,
  pagination,
  search,
  filterValue: modality,
  selectedRows,
  filteredCount
} = useTableState<ParcelmentOrder>(() => table.value?.tableApi, {
  searchColumn: 'client',
  filterColumn: 'modality',
  getTotal: () => rows.value.length
})
const { exportCsv: exportTableCsv } = useTableCsv<ParcelmentOrder>(() => table.value?.tableApi)

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

function exportCsv(): void {
  exportTableCsv('parcelamentos', o => ({
    id: o.id,
    cliente: o.client?.razao_social ?? '',
    cnpj: o.client?.cnpj ?? '',
    modalidade: o.modality,
    estado: o.status ?? '',
    parcelas_pagas: o.paid_installments ?? '',
    parcelas_total: o.installments_count ?? '',
    total: o.total_amount ?? ''
  }), {
    emptyDescription: 'Ajuste os filtros ou selecione ao menos um pedido.',
    exportedUnit: 'pedido(s) exportado(s)'
  })
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
