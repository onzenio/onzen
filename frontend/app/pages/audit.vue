<script setup lang="ts">
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'
import { TABLE_UI as tableUi, type TableApi } from '~/utils/table-chrome'
import { actorLabel, AUDIT_COLUMN_LABELS, buildAuditActionItems, buildAuditColumns, type AuditRow } from '~/components/tables/audit/columns'

interface AuditResponse {
  data: AuditRow[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
}

const toast = useToast()

const table = useTemplateRef<{ tableApi?: TableApi<AuditRow> | null }>('table')
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

const columns = buildAuditColumns()

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

const actionItems = computed(() => buildAuditActionItems(logs.value))

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
    data: formatDateTime(log.created_at),
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
