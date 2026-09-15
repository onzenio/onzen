<script setup lang="ts">
import { getPaginationRowModel } from '@tanstack/table-core'
import { TABLE_UI as tableUi, type TableApi } from '~/utils/table-chrome'
import { useTableCsv } from '~/composables/tables/useTableCsv'
import { useTableState } from '~/composables/tables/useTableState'
import { actorLabel, AUDIT_COLUMN_LABELS, buildAuditActionItems, buildAuditColumns, type AuditRow } from '~/components/tables/audit/columns'

interface AuditResponse {
  data: AuditRow[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
}

const table = useTemplateRef<{ tableApi?: TableApi<AuditRow> | null }>('table')
const {
  columnFilters,
  columnVisibility,
  rowSelection,
  sorting,
  pagination,
  search,
  filterValue: actionFilter,
  selectedRows,
  filteredCount,
  resetPage
} = useTableState<AuditRow>(() => table.value?.tableApi, {
  searchColumn: 'actor',
  filterColumn: 'action',
  getTotal: () => logs.value.length
})
const { exportCsv: exportTableCsv } = useTableCsv<AuditRow>(() => table.value?.tableApi)

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

const columns = buildAuditColumns()

function applyFilters() {
  resetPage()
  void refresh()
}

function clearFilters() {
  accountId.value = ''
  actorUserId.value = ''
  from.value = ''
  to.value = ''
  actionFilter.value = 'all'
  resetPage()
  void refresh()
}

const actionItems = computed(() => buildAuditActionItems(logs.value))

function exportCsv() {
  exportTableCsv('auditoria', (log: AuditRow) => ({
    id: log.id,
    data: formatDateTime(log.created_at),
    acao: log.action,
    ator: actorLabel(log),
    origem: log.origin_account_id,
    alvo: log.target_account_id ?? ''
  }), {
    emptyDescription: 'Ajuste os filtros ou selecione ao menos um registro.',
    exportedUnit: 'registro(s) exportado(s)'
  })
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
      <TablesTableStates
        :error="error"
        error-title="Não foi possível carregar os registros"
        :error-description="backendMessage(error)"
        @retry="refresh"
      />

      <TablesAuditAuditQueryFilters
        v-model:account-id="accountId"
        v-model:actor-user-id="actorUserId"
        v-model:from="from"
        v-model:to="to"
        @apply="applyFilters"
        @clear="clearFilters"
      />

      <TablesTableToolbar
        v-model:search="search"
        v-model:filter-value="actionFilter"
        search-placeholder="Buscar por ação ou ator…"
        :filter-items="actionItems"
        filter-placeholder="Filtrar ação"
        :table-api="table?.tableApi"
        :column-labels="AUDIT_COLUMN_LABELS"
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
        :data="logs"
        :columns="columns"
        :loading="status === 'pending'"
        :ui="tableUi"
      >
        <template #empty>
          <TablesTableStates
            empty-title="Nenhum registro encontrado"
            empty-hint="Ajuste a busca ou os filtros."
          />
        </template>
      </UTable>

      <TablesTableFooter
        :selected="selectedRows.length"
        :total="filteredCount"
        unit="registro(s)"
        :table-api="table?.tableApi"
        @update:page="(p: number) => table?.tableApi?.setPageIndex(p - 1)"
      />
    </template>
  </UDashboardPanel>
</template>
