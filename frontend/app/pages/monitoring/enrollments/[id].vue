<script setup lang="ts">
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'
import { TABLE_UI as tableUi, type TableApi } from '~/utils/table-chrome'
import { ALERT_COLUMN_LABELS, alertStatusItems, buildAlertColumns, buildChangeColumns, buildSnapshotColumns, CHANGE_COLUMN_LABELS, changeNormalizedItems, SNAPSHOT_COLUMN_LABELS, snapshotFreshnessItems } from '~/components/tables/monitoring/enrollmentDetailColumns'
import type { AlertItem, ChangeItem, CndData, Enrollment, Paginated, Snapshot, SnapshotsResponse } from '~/types/monitoring'

const route = useRoute()
const toast = useToast()
const { canWrite } = useSession()
const enrollmentId = computed(() => route.params.id as string)

const snapshotsTable = useTemplateRef<{ tableApi?: TableApi<Snapshot> | null }>('snapshotsTable')
const changesTable = useTemplateRef<{ tableApi?: TableApi<ChangeItem> | null }>('changesTable')
const alertsTable = useTemplateRef<{ tableApi?: TableApi<AlertItem> | null }>('alertsTable')

const snapshotFilters = ref([{ id: 'operation_code', value: '' }])
const snapshotVisibility = ref()
const snapshotSelection = ref<Record<string, boolean>>({})
const snapshotSorting = ref<{ id: string, desc: boolean }[]>([])
const snapshotPagination = ref({ pageIndex: 0, pageSize: 10 })
const snapshotFreshness = ref('all')

const changeFilters = ref([{ id: 'operation_code', value: '' }])
const changeVisibility = ref()
const changeSelection = ref<Record<string, boolean>>({})
const changeSorting = ref<{ id: string, desc: boolean }[]>([])
const changePagination = ref({ pageIndex: 0, pageSize: 10 })
const changeNormalized = ref('all')

const alertFilters = ref<{ id: string, value: unknown }[]>([])
const alertVisibility = ref()
const alertSelection = ref<Record<string, boolean>>({})
const alertSorting = ref<{ id: string, desc: boolean }[]>([])
const alertPagination = ref({ pageIndex: 0, pageSize: 10 })
const alertStatus = ref('all')

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
  const list = (alertsTable.value?.tableApi?.getFilteredSelectedRowModel().rows ?? []).map((r: Row<AlertItem>) => r.original).filter(a => a.status === 'pending')
  if (list.length === 0) return
  bulkAcknowledging.value = true
  try {
    for (const alert of list) {
      await $fetch(`/api/monitoring/alerts/${alert.id}/acknowledge`, { method: 'POST' })
    }
    toast.add({ title: `${list.length} alerta(s) reconhecido(s)`, color: 'success' })
    alertSelection.value = {}
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

watch(() => snapshotFreshness.value, (newVal) => {
  const column = snapshotsTable.value?.tableApi?.getColumn('freshness')
  if (!column) return
  if (newVal === 'all') column.setFilterValue(undefined)
  else column.setFilterValue(newVal)
  snapshotPagination.value.pageIndex = 0
})

watch(() => changeNormalized.value, (newVal) => {
  const column = changesTable.value?.tableApi?.getColumn('normalized')
  if (!column) return
  if (newVal === 'all') column.setFilterValue(undefined)
  else column.setFilterValue(newVal)
  changePagination.value.pageIndex = 0
})

watch(() => alertStatus.value, (newVal) => {
  const column = alertsTable.value?.tableApi?.getColumn('status')
  if (!column) return
  if (newVal === 'all') column.setFilterValue(undefined)
  else column.setFilterValue(newVal)
  alertPagination.value.pageIndex = 0
})

const snapshotSearch = computed({
  get: (): string => (snapshotsTable.value?.tableApi?.getColumn('operation_code')?.getFilterValue() as string) || '',
  set: (value: string) => {
    snapshotsTable.value?.tableApi?.getColumn('operation_code')?.setFilterValue(value || undefined)
    snapshotPagination.value.pageIndex = 0
  }
})

const changeSearch = computed({
  get: (): string => (changesTable.value?.tableApi?.getColumn('operation_code')?.getFilterValue() as string) || '',
  set: (value: string) => {
    changesTable.value?.tableApi?.getColumn('operation_code')?.setFilterValue(value || undefined)
    changePagination.value.pageIndex = 0
  }
})

const snapshotSelected = computed((): Row<Snapshot>[] => snapshotsTable.value?.tableApi?.getFilteredSelectedRowModel().rows ?? [])
const snapshotFiltered = computed((): number => snapshotsTable.value?.tableApi?.getFilteredRowModel().rows.length ?? snapshotRows.value.length)
const changeSelected = computed((): Row<ChangeItem>[] => changesTable.value?.tableApi?.getFilteredSelectedRowModel().rows ?? [])
const changeFiltered = computed((): number => changesTable.value?.tableApi?.getFilteredRowModel().rows.length ?? changeRows.value.length)
const alertSelected = computed((): Row<AlertItem>[] => alertsTable.value?.tableApi?.getFilteredSelectedRowModel().rows ?? [])
const alertFiltered = computed((): number => alertsTable.value?.tableApi?.getFilteredRowModel().rows.length ?? alertRows.value.length)

function exportSnapshots(): void {
  const list = (snapshotSelected.value.length > 0 ? snapshotSelected.value : (snapshotsTable.value?.tableApi?.getFilteredRowModel().rows ?? [])).map((r: Row<Snapshot>) => r.original)
  if (list.length === 0) {
    toast.add({ title: 'Nada para exportar', description: 'Ajuste os filtros de snapshots.', color: 'warning' })
    return
  }
  exportToCsv('snapshots', list.map(s => ({ id: s.id, operacao: s.operation_code, familia: s.family, atualidade: s.freshness, integridade: s.completeness, verificado_em: s.verified_at ?? '' })))
  toast.add({ title: 'CSV exportado', description: `${list.length} snapshot(s) exportado(s).`, color: 'success' })
}

function exportChanges(): void {
  const list = (changeSelected.value.length > 0 ? changeSelected.value : (changesTable.value?.tableApi?.getFilteredRowModel().rows ?? [])).map((r: Row<ChangeItem>) => r.original)
  if (list.length === 0) {
    toast.add({ title: 'Nada para exportar', description: 'Ajuste os filtros de mudanças.', color: 'warning' })
    return
  }
  exportToCsv('mudancas', list.map(c => ({ id: c.id, operacao: c.operation_code, normalizado: c.normalized ? 'Sim' : 'Não', detectada_em: c.created_at ?? '' })))
  toast.add({ title: 'CSV exportado', description: `${list.length} mudança(s) exportada(s).`, color: 'success' })
}

function exportAlerts(): void {
  const list = (alertSelected.value.length > 0 ? alertSelected.value : (alertsTable.value?.tableApi?.getFilteredRowModel().rows ?? [])).map((r: Row<AlertItem>) => r.original)
  if (list.length === 0) {
    toast.add({ title: 'Nada para exportar', description: 'Ajuste os filtros de alertas.', color: 'warning' })
    return
  }
  exportToCsv('alertas', list.map(a => ({ id: a.id, estado: a.status === 'pending' ? 'Pendente' : 'Reconhecido', criado_em: a.created_at ?? '', reconhecido_em: a.acknowledged_at ?? '' })))
  toast.add({ title: 'CSV exportado', description: `${list.length} alerta(s) exportado(s).`, color: 'success' })
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
