<script setup lang="ts">
import { getPaginationRowModel } from '@tanstack/table-core'
import { TABLE_UI as tableUi, type TableApi } from '~/utils/table-chrome'
import { useTableCsv } from '~/composables/tables/useTableCsv'
import { useTableState } from '~/composables/tables/useTableState'
import { ALERT_COLUMN_LABELS, alertStatusItems, buildAlertColumns, buildChangeColumns, buildSnapshotColumns, CHANGE_COLUMN_LABELS, changeNormalizedItems, SNAPSHOT_COLUMN_LABELS, snapshotFreshnessItems } from '~/components/tables/monitoring/enrollmentDetailColumns'
import type { AlertItem, ChangeItem, CndData, Enrollment, Paginated, Snapshot, SnapshotsResponse } from '~/types/monitoring'

const route = useRoute()
const toast = useToast()
const { canWrite } = useSession()
const enrollmentId = computed(() => route.params.id as string)

const snapshotsTable = useTemplateRef<{ tableApi?: TableApi<Snapshot> | null }>('snapshotsTable')
const changesTable = useTemplateRef<{ tableApi?: TableApi<ChangeItem> | null }>('changesTable')
const alertsTable = useTemplateRef<{ tableApi?: TableApi<AlertItem> | null }>('alertsTable')

const {
  columnFilters: snapshotFilters,
  columnVisibility: snapshotVisibility,
  rowSelection: snapshotSelection,
  sorting: snapshotSorting,
  pagination: snapshotPagination,
  search: snapshotSearch,
  filterValue: snapshotFreshness,
  selectedRows: snapshotSelected,
  filteredCount: snapshotFiltered
} = useTableState<Snapshot>(() => snapshotsTable.value?.tableApi, {
  searchColumn: 'operation_code',
  filterColumn: 'freshness',
  getTotal: () => snapshotRows.value.length
})

const {
  columnFilters: changeFilters,
  columnVisibility: changeVisibility,
  rowSelection: changeSelection,
  sorting: changeSorting,
  pagination: changePagination,
  search: changeSearch,
  filterValue: changeNormalized,
  selectedRows: changeSelected,
  filteredCount: changeFiltered
} = useTableState<ChangeItem>(() => changesTable.value?.tableApi, {
  searchColumn: 'operation_code',
  filterColumn: 'normalized',
  getTotal: () => changeRows.value.length
})

const {
  columnFilters: alertFilters,
  columnVisibility: alertVisibility,
  rowSelection: alertSelection,
  sorting: alertSorting,
  pagination: alertPagination,
  filterValue: alertStatus,
  selectedRows: alertSelected,
  filteredCount: alertFiltered,
  clearSelection: clearAlertSelection
} = useTableState<AlertItem>(() => alertsTable.value?.tableApi, {
  filterColumn: 'status',
  getTotal: () => alertRows.value.length
})

const { exportCsv: exportSnapshotCsv } = useTableCsv<Snapshot>(() => snapshotsTable.value?.tableApi)
const { exportCsv: exportChangeCsv } = useTableCsv<ChangeItem>(() => changesTable.value?.tableApi)
const { exportCsv: exportAlertCsv } = useTableCsv<AlertItem>(() => alertsTable.value?.tableApi)

const { data: enrollment, status: enrollmentStatus, error: enrollmentError, refresh: refreshEnrollment } = await useFetch<{ data: Enrollment }>(
  () => `/api/monitoring/enrollments/${enrollmentId.value}`,
  { lazy: true }
)

const clientId = computed(() => enrollment.value?.data.client?.id)
const cnd = ref<{ data: CndData } | null>(null)
const cndStatus = ref<'idle' | 'pending' | 'available' | 'absent' | 'error'>('idle')

watch(clientId, async (id) => {
  if (!id) {
    cnd.value = null
    cndStatus.value = 'idle'
    return
  }
  cndStatus.value = 'pending'
  try {
    const response = await $fetch<{ data: CndData }>(`/api/monitoring/clients/${id}/cnd`)
    cnd.value = response
    cndStatus.value = response.data.state === 'available' ? 'available' : 'absent'
  } catch {
    cnd.value = null
    cndStatus.value = 'error'
  }
}, { immediate: true })

const { data: snapshots, status: snapshotsStatus, error: snapshotsError, refresh: refreshSnapshots } = await useFetch<SnapshotsResponse>(
  () => `/api/monitoring/enrollments/${enrollmentId.value}/snapshots`,
  { lazy: true, query: { per_page: 100 } }
)

const { data: changes, status: changesStatus, error: changesError, refresh: refreshChanges } = await useFetch<Paginated<ChangeItem>>(
  () => `/api/monitoring/enrollments/${enrollmentId.value}/changes`,
  { lazy: true, query: { per_page: 100 } }
)

const { data: alerts, status: alertsStatus, error: alertsError, refresh: refreshAlerts } = await useFetch<Paginated<AlertItem>>('/api/monitoring/alerts', {
  lazy: true,
  query: { enrollment_id: enrollmentId, per_page: 100 }
})

const snapshotRows = computed((): Snapshot[] => snapshots.value?.data ?? [])
const changeRows = computed((): ChangeItem[] => changes.value?.data ?? [])
const alertRows = computed((): AlertItem[] => alerts.value?.data ?? [])

const enrollmentRetryActions = [{
  label: 'Tentar novamente',
  color: 'error' as const,
  variant: 'outline' as const,
  onClick: () => refreshEnrollment()
}]

const acknowledging = ref<number | null>(null)
const bulkAcknowledging = ref(false)

async function acknowledge(alertId: number): Promise<void> {
  acknowledging.value = alertId
  try {
    await $fetch(`/api/monitoring/alerts/${alertId}/acknowledge`, { method: 'POST' })
    toast.add({ title: 'Alerta reconhecido', color: 'success' })
    await refreshAlerts()
  } catch (error: unknown) {
    toast.add({ title: 'Não foi possível reconhecer', description: monitoringErrorMessage(backendErrorBody(error)), color: 'error' })
  } finally {
    acknowledging.value = null
  }
}

async function acknowledgeSelected(): Promise<void> {
  const list = alertSelected.value.map(r => r.original).filter(a => a.status === 'pending')
  if (list.length === 0) return
  bulkAcknowledging.value = true
  try {
    for (const alert of list) {
      await $fetch(`/api/monitoring/alerts/${alert.id}/acknowledge`, { method: 'POST' })
    }
    toast.add({ title: `${list.length} alerta(s) reconhecido(s)`, color: 'success' })
    clearAlertSelection()
    await refreshAlerts()
  } catch (error: unknown) {
    toast.add({ title: 'Não foi possível reconhecer a seleção', description: monitoringErrorMessage(backendErrorBody(error)), color: 'error' })
  } finally {
    bulkAcknowledging.value = false
  }
}

const snapshotColumns = buildSnapshotColumns({
  onCopyFingerprint: (snapshot) => {
    navigator.clipboard.writeText(snapshot.fingerprint)
    toast.add({ title: 'Fingerprint copiado', color: 'success' })
  }
})

const changeColumns = buildChangeColumns({
  onCopyId: (change) => {
    navigator.clipboard.writeText(String(change.id))
    toast.add({ title: 'ID copiado', color: 'success' })
  }
})

const alertColumns = buildAlertColumns({
  canWrite,
  onAcknowledge: alertId => void acknowledge(alertId)
})

function exportSnapshots(): void {
  exportSnapshotCsv('snapshots', s => ({
    id: s.id,
    operacao: s.operation_code,
    familia: s.family,
    atualidade: s.freshness,
    integridade: s.completeness,
    verificado_em: s.verified_at ?? ''
  }), {
    emptyDescription: 'Ajuste os filtros de snapshots.',
    exportedUnit: 'snapshot(s) exportado(s)'
  })
}

function exportChanges(): void {
  exportChangeCsv('mudancas', c => ({
    id: c.id,
    operacao: c.operation_code,
    normalizado: c.normalized ? 'Sim' : 'Não',
    detectada_em: c.created_at ?? ''
  }), {
    emptyDescription: 'Ajuste os filtros de mudanças.',
    exportedUnit: 'mudança(s) exportada(s)'
  })
}

function exportAlerts(): void {
  exportAlertCsv('alertas', a => ({
    id: a.id,
    estado: a.status === 'pending' ? 'Pendente' : 'Reconhecido',
    criado_em: a.created_at ?? '',
    reconhecido_em: a.acknowledged_at ?? ''
  }), {
    emptyDescription: 'Ajuste os filtros de alertas.',
    exportedUnit: 'alerta(s) exportado(s)'
  })
}
</script>

<template>
  <UDashboardPanel id="monitoring-enrollment">
    <template #header>
      <UDashboardNavbar :title="enrollment?.data.client?.razao_social ?? 'Painel do cliente'">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            label="Voltar"
            icon="i-lucide-arrow-left"
            color="neutral"
            variant="ghost"
            to="/monitoring"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div
        v-if="enrollment"
        class="flex flex-col gap-4"
      >
        <UCard>
          <div class="flex flex-wrap items-center gap-3">
            <div>
              <p class="text-sm text-muted">
                {{ enrollment.data.definition?.name ?? '—' }}
              </p>
              <p class="font-medium text-highlighted">
                {{ enrollment.data.client ? formatCnpj(enrollment.data.client.cnpj) : '—' }}
              </p>
            </div>
            <UBadge
              :color="enrollmentStatusMeta(enrollment.data.status).color"
              variant="subtle"
            >
              {{ enrollmentStatusMeta(enrollment.data.status).label }}
            </UBadge>
            <p
              v-if="enrollment.data.status === 'paused' && enrollment.data.pause_reason"
              class="text-sm text-muted"
            >
              Motivo: {{ enrollment.data.pause_reason }}
            </p>
          </div>
        </UCard>

        <UCard>
          <template #header>
            <p class="font-medium text-highlighted">
              CND — Situação Fiscal
            </p>
          </template>
          <div v-if="cnd?.data.state === 'available' && cnd.data.cnd">
            <div class="flex flex-wrap items-center gap-2">
              <UBadge
                color="success"
                variant="subtle"
              >
                Disponível no acervo local
              </UBadge>
              <UBadge
                :color="cnd.data.freshness === 'fresh' ? 'success' : 'warning'"
                variant="subtle"
              >
                {{ cnd.data.freshness === 'fresh' ? 'Atual' : 'Desatualizado' }}
              </UBadge>
            </div>
            <p class="mt-2 text-sm text-muted">
              Verificado em {{ formatDateTime(cnd.data.verified_at) }} · operação {{ cnd.data.cnd.operation_code }}
            </p>
          </div>
          <UAlert
            v-else-if="cndStatus === 'error'"
            color="warning"
            variant="subtle"
            title="CND indisponível"
            description="Não foi possível carregar a CND do acervo. Tente novamente."
          />
          <UAlert
            v-else-if="cndStatus === 'pending' || cndStatus === 'idle'"
            color="neutral"
            variant="subtle"
            title="Carregando CND…"
            description="Lendo o snapshot de Situação Fiscal."
          />
          <UAlert
            v-else
            color="neutral"
            variant="subtle"
            title="Sem CND no acervo"
            description="Nenhum snapshot de Situação Fiscal para este cliente. A CND aparece após a primeira consulta."
          />
        </UCard>

        <UCard>
          <template #header>
            <p class="font-medium text-highlighted">
              Snapshots
            </p>
          </template>
          <div class="flex flex-col gap-4">
            <TablesTableStates
              :error="snapshotsError"
              variant="subtle"
              error-title="Snapshots indisponíveis"
              :error-description="monitoringErrorMessage(backendErrorBody(snapshotsError))"
              @retry="refreshSnapshots"
            />
            <TablesTableToolbar
              v-model:search="snapshotSearch"
              v-model:filter-value="snapshotFreshness"
              search-placeholder="Filtrar por operação…"
              :filter-items="snapshotFreshnessItems"
              filter-placeholder="Filtrar atualidade"
              :table-api="snapshotsTable?.tableApi"
              :column-labels="SNAPSHOT_COLUMN_LABELS"
              @export="exportSnapshots"
            />
            <UTable
              ref="snapshotsTable"
              v-model:column-filters="snapshotFilters"
              v-model:column-visibility="snapshotVisibility"
              v-model:row-selection="snapshotSelection"
              v-model:sorting="snapshotSorting"
              v-model:pagination="snapshotPagination"
              :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
              class="shrink-0"
              :data="snapshotRows"
              :columns="snapshotColumns"
              :loading="snapshotsStatus === 'pending'"
              :ui="tableUi"
            >
              <template #empty>
                <TablesTableStates
                  empty-title="Nenhum snapshot encontrado"
                  empty-hint="Ajuste a busca ou o filtro de atualidade."
                />
              </template>
            </UTable>
            <TablesTableFooter
              :selected="snapshotSelected.length"
              :total="snapshotFiltered"
              unit="snapshot(s)"
              :table-api="snapshotsTable?.tableApi"
              @update:page="(p: number) => snapshotsTable?.tableApi?.setPageIndex(p - 1)"
            />
          </div>
        </UCard>

        <UCard>
          <template #header>
            <p class="font-medium text-highlighted">
              Mudanças
            </p>
          </template>
          <div class="flex flex-col gap-4">
            <TablesTableStates
              :error="changesError"
              variant="subtle"
              error-title="Mudanças indisponíveis"
              :error-description="monitoringErrorMessage(backendErrorBody(changesError))"
              @retry="refreshChanges"
            />
            <TablesTableToolbar
              v-model:search="changeSearch"
              v-model:filter-value="changeNormalized"
              search-placeholder="Filtrar por operação…"
              :filter-items="changeNormalizedItems"
              filter-placeholder="Filtrar normalização"
              :table-api="changesTable?.tableApi"
              :column-labels="CHANGE_COLUMN_LABELS"
              @export="exportChanges"
            />
            <UTable
              ref="changesTable"
              v-model:column-filters="changeFilters"
              v-model:column-visibility="changeVisibility"
              v-model:row-selection="changeSelection"
              v-model:sorting="changeSorting"
              v-model:pagination="changePagination"
              :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
              class="shrink-0"
              :data="changeRows"
              :columns="changeColumns"
              :loading="changesStatus === 'pending'"
              :ui="tableUi"
            >
              <template #empty>
                <TablesTableStates
                  empty-title="Nenhuma mudança encontrada"
                  empty-hint="Ajuste a busca ou o filtro de normalização."
                />
              </template>
            </UTable>
            <TablesTableFooter
              :selected="changeSelected.length"
              :total="changeFiltered"
              unit="mudança(s)"
              feminine
              :table-api="changesTable?.tableApi"
              @update:page="(p: number) => changesTable?.tableApi?.setPageIndex(p - 1)"
            />
          </div>
        </UCard>

        <UCard>
          <template #header>
            <p class="font-medium text-highlighted">
              Alertas
            </p>
          </template>
          <div class="flex flex-col gap-4">
            <TablesTableStates
              :error="alertsError"
              variant="subtle"
              error-title="Alertas indisponíveis"
              :error-description="monitoringErrorMessage(backendErrorBody(alertsError))"
              @retry="refreshAlerts"
            />
            <TablesTableToolbar
              :table-api="alertsTable?.tableApi"
              :column-labels="ALERT_COLUMN_LABELS"
              @export="exportAlerts"
            >
              <template #leading>
                <USelect
                  v-model="alertStatus"
                  :items="alertStatusItems"
                  placeholder="Filtrar estado"
                  class="min-w-28"
                />
              </template>
              <template #bulk>
                <UButton
                  v-if="canWrite && alertSelected.length"
                  :label="`Reconhecer (${alertSelected.length})`"
                  icon="i-lucide-check"
                  :loading="bulkAcknowledging"
                  @click="acknowledgeSelected"
                />
              </template>
            </TablesTableToolbar>
            <UTable
              ref="alertsTable"
              v-model:column-filters="alertFilters"
              v-model:column-visibility="alertVisibility"
              v-model:row-selection="alertSelection"
              v-model:sorting="alertSorting"
              v-model:pagination="alertPagination"
              :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
              class="shrink-0"
              :data="alertRows"
              :columns="alertColumns"
              :loading="alertsStatus === 'pending'"
              :ui="tableUi"
            >
              <template #empty>
                <TablesTableStates
                  empty-title="Nenhum alerta encontrado"
                  empty-hint="Ajuste o filtro de estado."
                />
              </template>
            </UTable>
            <TablesTableFooter
              :selected="alertSelected.length"
              :total="alertFiltered"
              unit="alerta(s)"
              :table-api="alertsTable?.tableApi"
              @update:page="(p: number) => alertsTable?.tableApi?.setPageIndex(p - 1)"
            />
          </div>
        </UCard>
      </div>

      <UAlert
        v-else-if="enrollmentStatus !== 'pending' && enrollmentError?.statusCode === 404"
        color="error"
        variant="subtle"
        title="Associação não encontrada"
        description="Verifique se a associação pertence à sua conta."
      />

      <UAlert
        v-else-if="enrollmentStatus !== 'pending'"
        color="warning"
        variant="subtle"
        title="Associação indisponível"
        description="Não foi possível carregar o painel do cliente agora. Tente novamente."
        :actions="enrollmentRetryActions"
      />
    </template>
  </UDashboardPanel>
</template>
