<script setup lang="ts">
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'
import { TABLE_UI as tableUi, type TableApi } from '~/utils/table-chrome'
import { useTableCsv } from '~/composables/tables/useTableCsv'
import { useTableState } from '~/composables/tables/useTableState'
import { buildEnrollmentColumns, ENROLLMENT_COLUMN_LABELS, enrollmentStatusItems } from '~/components/tables/monitoring/enrollmentColumns'
import type { DashboardData, Enrollment, Health, Paginated, RunQueued } from '~/types/monitoring'

const toast = useToast()
const { canWrite } = useSession()

const table = useTemplateRef<{ tableApi?: TableApi<Enrollment> | null }>('table')
const {
  columnFilters,
  columnVisibility,
  rowSelection,
  sorting,
  pagination,
  search,
  filterValue: statusFilter,
  selectedRows,
  filteredCount,
  clearSelection
} = useTableState<Enrollment>(() => table.value?.tableApi, {
  searchColumn: 'client',
  filterColumn: 'status',
  getTotal: () => rows.value.length
})
const { exportCsv: exportTableCsv } = useTableCsv<Enrollment>(() => table.value?.tableApi)

const { data: health, error: healthError } = await useFetch<{ data: Health }>('/api/monitoring/health', { lazy: true })
const { data: dashboard, status: dashboardStatus, error: dashboardError, refresh: refreshDashboard } = await useFetch<{ data: DashboardData }>('/api/monitoring/dashboard', { lazy: true })

const { data: enrollments, status: tableStatus, error: enrollmentsError, refresh: refreshEnrollments } = await useFetch<Paginated<Enrollment>>('/api/monitoring/enrollments', {
  lazy: true,
  query: { per_page: 500 }
})

const rows = computed((): Enrollment[] => enrollments.value?.data ?? [])

const runTarget = ref<Enrollment | null>(null)
const runOpen = computed({
  get: () => runTarget.value !== null,
  set: value => !value && (runTarget.value = null)
})
const running = ref(false)
const bulkRunning = ref(false)
const syncing = ref(false)

const columns = buildEnrollmentColumns({
  canWrite,
  onOpen: enrollment => navigateTo(`/monitoring/enrollments/${enrollment.id}`),
  onRun: (enrollment) => { runTarget.value = enrollment }
})

const quota = computed(() => dashboard.value?.data.quota)
const quotaExhausted = computed(() => quota.value !== undefined && quota.value.limit > 0 && quota.value.consumed >= quota.value.limit)
const quotaPercent = computed((): number => {
  if (!quota.value || quota.value.limit <= 0) {
    return 0
  }
  return Math.min(100, Math.round((quota.value.consumed / quota.value.limit) * 100))
})

function exportCsv(): void {
  exportTableCsv('associacoes', e => ({
    id: e.id,
    cliente: e.client?.razao_social ?? '',
    cnpj: e.client?.cnpj ?? '',
    definicao: e.definition?.name ?? '',
    estado: enrollmentStatusMeta(e.status).label,
    ultima_mudanca: e.last_change_at ?? ''
  }), {
    emptyDescription: 'Ajuste os filtros ou selecione ao menos uma associação.',
    exportedUnit: 'associação(ões) exportada(s)'
  })
}

async function bulkRun(): Promise<void> {
  const list = selectedRows.value.map((r: Row<Enrollment>) => r.original)
  if (list.length === 0) return
  bulkRunning.value = true
  try {
    for (const enrollment of list) {
      await $fetch(`/api/monitoring/enrollments/${enrollment.id}/run`, { method: 'POST' })
    }
    toast.add({ title: 'Consultas enfileiradas', description: `${list.length} associação(ões) na fila.`, color: 'success' })
    clearSelection()
    await Promise.all([refreshEnrollments(), refreshDashboard()])
  } catch (error: unknown) {
    toast.add({ title: 'Disparo recusado', description: monitoringErrorMessage(backendErrorBody(error)), color: 'error' })
  } finally {
    bulkRunning.value = false
  }
}

async function confirmRun(): Promise<void> {
  const target = runTarget.value
  if (!target) {
    return
  }
  running.value = true
  try {
    const { data } = await $fetch<{ data: RunQueued }>(`/api/monitoring/enrollments/${target.id}/run`, { method: 'POST' })
    toast.add({ title: 'Consulta enfileirada', description: `Execução #${data.id} na fila.`, color: 'success' })
    runTarget.value = null
    await Promise.all([refreshEnrollments(), refreshDashboard()])
  } catch (error: unknown) {
    toast.add({ title: 'Disparo recusado', description: monitoringErrorMessage(backendErrorBody(error)), color: 'error' })
  } finally {
    running.value = false
  }
}

async function syncNow(): Promise<void> {
  syncing.value = true
  try {
    const { data } = await $fetch<{ data: { queued: number, blocked: number, skipped: number } }>('/api/monitoring/sync', { method: 'POST' })
    toast.add({ title: 'Sincronização solicitada', description: `${data.queued} enfileiradas, ${data.blocked} bloqueadas, ${data.skipped} ignoradas.`, color: 'success' })
    await Promise.all([refreshEnrollments(), refreshDashboard()])
  } catch (error: unknown) {
    toast.add({ title: 'Sincronização recusada', description: monitoringErrorMessage(backendErrorBody(error)), color: 'error' })
  } finally {
    syncing.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="monitoring">
    <template #header>
      <UDashboardNavbar title="Monitoramento">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            v-if="canWrite"
            label="Sincronizar carteira"
            icon="i-lucide-refresh-cw"
            :loading="syncing"
            @click="syncNow"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4">
        <UAlert
          v-if="quotaExhausted"
          color="warning"
          variant="subtle"
          title="Volume mensal esgotado"
          description="As consultas estão bloqueadas até o próximo ciclo. Troque de plano para continuar consultando."
        />

        <UAlert
          v-if="healthError"
          color="error"
          variant="subtle"
          title="Estado do transporte indisponível"
          :description="monitoringErrorMessage(backendErrorBody(healthError))"
        />

        <UAlert
          v-if="dashboardError"
          color="error"
          variant="subtle"
          title="Resumo do monitoramento indisponível"
          :description="monitoringErrorMessage(backendErrorBody(dashboardError))"
        />

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <UCard>
            <p class="text-sm text-muted">
              Transporte SERPRO
            </p>
            <div class="mt-2 flex items-center gap-2">
              <UBadge
                :color="health ? healthStateMeta(health.data.state).color : 'neutral'"
                variant="subtle"
              >
                {{ health ? healthStateMeta(health.data.state).label : 'Carregando…' }}
              </UBadge>
              <UBadge
                v-if="health"
                :color="health.data.environment === 'producao' ? 'error' : 'info'"
                variant="subtle"
              >
                {{ health.data.environment === 'producao' ? 'Produção' : 'Homologação' }}
              </UBadge>
            </div>
          </UCard>

          <UCard>
            <p class="text-sm text-muted">
              Consultas no mês
            </p>
            <p class="mt-2 text-2xl font-semibold text-highlighted">
              {{ quota ? `${quota.consumed}/${quota.limit}` : '—' }}
            </p>
            <UProgress
              :model-value="quotaPercent"
              class="mt-2"
            />
          </UCard>

          <UCard>
            <p class="text-sm text-muted">
              Associações
            </p>
            <p class="mt-2 text-2xl font-semibold text-highlighted">
              {{ dashboard?.data.associations.total ?? '—' }}
            </p>
            <p class="mt-1 text-sm text-muted">
              {{ dashboard ? `${dashboard.data.associations.active} ativas · ${dashboard.data.associations.paused} pausadas` : '—' }}
            </p>
          </UCard>

          <UCard>
            <p class="text-sm text-muted">
              Alertas pendentes
            </p>
            <p class="mt-2 text-2xl font-semibold text-highlighted">
              {{ dashboard?.data.alerts.pending ?? '—' }}
            </p>
            <p class="mt-1 text-sm text-muted">
              {{ dashboard && dashboard.data.last_run ? `Última execução: ${formatDateTime(dashboard.data.last_run.finished_at)}` : 'Sem execuções' }}
            </p>
          </UCard>
        </div>

        <UCard>
          <div class="flex flex-col gap-4">
            <TablesTableStates
              :error="enrollmentsError"
              variant="subtle"
              error-title="Associações indisponíveis"
              :error-description="monitoringErrorMessage(backendErrorBody(enrollmentsError))"
              @retry="refreshEnrollments"
            />

            <TablesTableToolbar
              v-model:search="search"
              v-model:filter-value="statusFilter"
              search-placeholder="Buscar por nome ou CNPJ…"
              :filter-items="enrollmentStatusItems"
              :table-api="table?.tableApi"
              :column-labels="ENROLLMENT_COLUMN_LABELS"
              @export="exportCsv"
            >
              <template #bulk>
                <UButton
                  v-if="canWrite && selectedRows.length"
                  :label="`Disparar (${selectedRows.length})`"
                  icon="i-lucide-play"
                  :loading="bulkRunning"
                  @click="bulkRun"
                />
              </template>
            </TablesTableToolbar>

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
              :loading="tableStatus === 'pending' || dashboardStatus === 'pending'"
              :ui="tableUi"
            >
              <template #empty>
                <TablesTableStates
                  empty-title="Nenhuma associação encontrada"
                  empty-hint="Ajuste a busca ou o filtro de estado."
                />
              </template>
            </UTable>

            <TablesTableFooter
              :selected="selectedRows.length"
              :total="filteredCount"
              unit="associação(ões)"
              feminine
              :table-api="table?.tableApi"
              @update:page="(p: number) => table?.tableApi?.setPageIndex(p - 1)"
            />
          </div>
        </UCard>
      </div>

      <UModal
        v-model:open="runOpen"
        title="Disparar consulta"
        :description="runTarget?.client ? `Enfileirar consulta de ${runTarget.definition?.name ?? 'monitoramento'} para ${runTarget.client.razao_social}? A execução consome 1 unidade da quota mensal.` : 'Enfileirar consulta?'"
      >
        <template #body>
          <div class="flex justify-end gap-2">
            <UButton
              label="Cancelar"
              color="neutral"
              variant="subtle"
              @click="runTarget = null"
            />
            <UButton
              label="Disparar"
              icon="i-lucide-play"
              :loading="running"
              @click="confirmRun"
            />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
