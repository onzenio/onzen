<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { upperFirst } from 'scule'
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'
import type { DashboardData, Enrollment, Health, Paginated, RunQueued } from '~/types/monitoring'

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UCheckbox = resolveComponent('UCheckbox')
const UDropdownMenu = resolveComponent('UDropdownMenu')

const toast = useToast()
const { canWrite } = useSession()

interface EnrollmentTableApi {
  getFilteredSelectedRowModel: () => { rows: Row<Enrollment>[] }
  getFilteredRowModel: () => { rows: Row<Enrollment>[] }
  getColumn: (id: string) => { setFilterValue: (value: string | undefined) => void, getFilterValue: () => unknown, toggleVisibility: (value?: boolean) => void } | undefined
  getAllColumns: () => { id: string, getCanHide: () => boolean, getIsVisible: () => boolean }[]
  getState: () => { pagination: { pageIndex: number, pageSize: number } }
  setPageIndex: (index: number) => void
}

const table = useTemplateRef<{ tableApi?: EnrollmentTableApi | null }>('table')
const columnFilters = ref([{ id: 'client', value: '' }])
const columnVisibility = ref()
const rowSelection = ref<Record<string, boolean>>({})
const sorting = ref<{ id: string, desc: boolean }[]>([])
const pagination = ref({ pageIndex: 0, pageSize: 10 })
const statusFilter = ref('all')

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

const quota = computed(() => dashboard.value?.data.quota)
const quotaExhausted = computed(() => quota.value !== undefined && quota.value.limit > 0 && quota.value.consumed >= quota.value.limit)
const quotaPercent = computed((): number => {
  if (!quota.value || quota.value.limit <= 0) {
    return 0
  }
  return Math.min(100, Math.round((quota.value.consumed / quota.value.limit) * 100))
})

function getRowItems(row: Row<Enrollment>) {
  const items = [{
    type: 'label' as const,
    label: 'Ações'
  }, {
    label: 'Abrir painel',
    icon: 'i-lucide-panel-right-open',
    onSelect() {
      navigateTo(`/monitoring/enrollments/${row.original.id}`)
    }
  }]
  if (canWrite.value) {
    items.push({
      label: 'Disparar consulta',
      icon: 'i-lucide-play',
      onSelect() {
        runTarget.value = row.original
      }
    })
  }
  return items
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

const columns: TableColumn<Enrollment>[] = [
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
      const name = (client?.razao_social ?? '').toLowerCase()
      const cnpj = (client?.cnpj ?? '').toLowerCase()
      return name.includes(term) || cnpj.includes(term)
    },
    cell: ({ row }) => {
      const client = row.original.client
      return h('div', { class: 'flex flex-col' }, [
        h('p', { class: 'font-medium text-highlighted' }, client?.razao_social ?? `Cliente #${row.original.id}`),
        h('p', { class: 'text-sm text-muted' }, client ? formatCnpj(client.cnpj) : '—')
      ])
    }
  },
  {
    accessorKey: 'definition',
    header: 'Definição',
    cell: ({ row }) => {
      const definition = row.original.definition
      return h('div', { class: 'flex flex-col gap-1' }, [
        h('p', { class: 'font-medium text-highlighted' }, definition?.name ?? String(row.original.id)),
        definition && !definition.is_active
          ? h(UBadge, { color: 'neutral', variant: 'subtle' }, () => 'Indisponível')
          : null
      ])
    }
  },
  {
    accessorKey: 'status',
    header: 'Estado',
    filterFn: 'equals',
    cell: ({ row }) => {
      const meta = enrollmentStatusMeta(row.original.status)
      return h('div', { class: 'flex flex-col gap-1' }, [
        h(UBadge, { color: meta.color, variant: 'subtle', class: 'capitalize w-fit' }, () => meta.label),
        row.original.status === 'paused' && row.original.pause_reason
          ? h('p', { class: 'text-xs text-muted' }, row.original.pause_reason)
          : null
      ])
    }
  },
  {
    accessorKey: 'last_change_at',
    header: sortableHeader('Última mudança'),
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.last_change_at))
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'text-right' }, [
      h(UDropdownMenu, {
        content: { align: 'end' },
        items: getRowItems(row)
      }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações da associação' }))
    ])
  }
]

watch(() => statusFilter.value, (newVal) => {
  const column = table.value?.tableApi?.getColumn('status')
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

const selectedRows = computed((): Row<Enrollment>[] => table.value?.tableApi?.getFilteredSelectedRowModel().rows ?? [])
const filteredCount = computed((): number => table.value?.tableApi?.getFilteredRowModel().rows.length ?? rows.value.length)

function exportCsv(): void {
  const list = (selectedRows.value.length > 0 ? selectedRows.value : (table.value?.tableApi?.getFilteredRowModel().rows ?? [])).map((r: Row<Enrollment>) => r.original)
  if (list.length === 0) {
    toast.add({ title: 'Nada para exportar', description: 'Ajuste os filtros ou selecione ao menos uma associação.', color: 'warning' })
    return
  }
  exportToCsv('associacoes', list.map(e => ({
    id: e.id,
    cliente: e.client?.razao_social ?? '',
    cnpj: e.client?.cnpj ?? '',
    definicao: e.definition?.name ?? '',
    estado: enrollmentStatusMeta(e.status).label,
    ultima_mudanca: e.last_change_at ?? ''
  })))
  toast.add({ title: 'CSV exportado', description: `${list.length} associação(ões) exportada(s).`, color: 'success' })
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
    rowSelection.value = {}
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
            <UAlert
              v-if="enrollmentsError"
              color="error"
              variant="subtle"
              title="Associações indisponíveis"
              :description="monitoringErrorMessage(backendErrorBody(enrollmentsError))"
            />

            <div class="flex flex-wrap items-center justify-between gap-1.5">
              <UInput
                v-model="search"
                icon="i-lucide-search"
                placeholder="Buscar por nome ou CNPJ…"
                class="max-w-sm"
              />
              <div class="flex flex-wrap items-center gap-1.5">
                <UButton
                  v-if="canWrite && selectedRows.length"
                  :label="`Disparar (${selectedRows.length})`"
                  icon="i-lucide-play"
                  :loading="bulkRunning"
                  @click="bulkRun"
                />
                <UButton
                  label="Exportar CSV"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-download"
                  @click="exportCsv"
                />
                <USelect
                  v-model="statusFilter"
                  :items="[
                    { label: 'Todos os estados', value: 'all' },
                    { label: 'Ativas', value: 'active' },
                    { label: 'Pausadas', value: 'paused' },
                    { label: 'Encerradas', value: 'ended' }
                  ]"
                  class="w-48"
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
              :loading="tableStatus === 'pending' || dashboardStatus === 'pending'"
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
                    Nenhuma associação encontrada
                  </p>
                  <p class="text-sm text-muted">
                    Ajuste a busca ou o filtro de estado.
                  </p>
                </div>
              </template>
            </UTable>

            <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
              <div class="text-sm text-muted">
                {{ selectedRows.length }} de {{ filteredCount }} associação(ões) selecionada(s).
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
