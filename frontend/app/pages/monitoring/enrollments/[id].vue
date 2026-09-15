<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { upperFirst } from 'scule'
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'
import type { AlertItem, ChangeItem, CndData, Enrollment, Paginated, Snapshot, SnapshotsResponse } from '~/types/monitoring'

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UCheckbox = resolveComponent('UCheckbox')
const UDropdownMenu = resolveComponent('UDropdownMenu')

const route = useRoute()
const toast = useToast()
const { canWrite } = useSession()
const enrollmentId = computed(() => route.params.id as string)

interface SimpleTableApi<T> {
  getFilteredSelectedRowModel: () => { rows: Row<T>[] }
  getFilteredRowModel: () => { rows: Row<T>[] }
  getColumn: (id: string) => { setFilterValue: (value: string | undefined) => void, getFilterValue: () => unknown, toggleVisibility: (value?: boolean) => void } | undefined
  getAllColumns: () => { id: string, getCanHide: () => boolean, getIsVisible: () => boolean }[]
  getState: () => { pagination: { pageIndex: number, pageSize: number } }
  setPageIndex: (index: number) => void
}

const snapshotsTable = useTemplateRef<{ tableApi?: SimpleTableApi<Snapshot> | null }>('snapshotsTable')
const changesTable = useTemplateRef<{ tableApi?: SimpleTableApi<ChangeItem> | null }>('changesTable')
const alertsTable = useTemplateRef<{ tableApi?: SimpleTableApi<AlertItem> | null }>('alertsTable')

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

const alertFilters = ref([{ id: 'status', value: '' }])
const alertVisibility = ref()
const alertSelection = ref<Record<string, boolean>>({})
const alertSorting = ref<{ id: string, desc: boolean }[]>([])
const alertPagination = ref({ pageIndex: 0, pageSize: 10 })
const alertStatus = ref('all')

const { data: enrollment, status: enrollmentStatus, error: enrollmentError } = await useFetch<{ data: Enrollment }>(
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

const { data: snapshots, status: snapshotsStatus } = await useFetch<SnapshotsResponse>(
  () => `/api/monitoring/enrollments/${enrollmentId.value}/snapshots`,
  { lazy: true, query: { per_page: 100 } }
)

const { data: changes, status: changesStatus } = await useFetch<Paginated<ChangeItem>>(
  () => `/api/monitoring/enrollments/${enrollmentId.value}/changes`,
  { lazy: true, query: { per_page: 100 } }
)

const { data: alerts, status: alertsStatus, refresh: refreshAlerts } = await useFetch<Paginated<AlertItem>>('/api/monitoring/alerts', {
  lazy: true,
  query: { enrollment_id: enrollmentId, per_page: 100 }
})

const snapshotRows = computed((): Snapshot[] => snapshots.value?.data ?? [])
const changeRows = computed((): ChangeItem[] => changes.value?.data ?? [])
const alertRows = computed((): AlertItem[] => alerts.value?.data ?? [])

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

function selectColumn<T>() {
  return {
    id: 'select',
    header: ({ table: api }: { table: { getIsSomePageRowsSelected: () => boolean, getIsAllPageRowsSelected: () => boolean, toggleAllPageRowsSelected: (v: boolean) => void } }) => h(UCheckbox, {
      'modelValue': api.getIsSomePageRowsSelected() ? 'indeterminate' : api.getIsAllPageRowsSelected(),
      'onUpdate:modelValue': (value: boolean | 'indeterminate') => api.toggleAllPageRowsSelected(!!value),
      'ariaLabel': 'Selecionar todos'
    }),
    cell: ({ row }: { row: Row<T> }) => h(UCheckbox, {
      'modelValue': row.getIsSelected(),
      'onUpdate:modelValue': (value: boolean | 'indeterminate') => row.toggleSelected(!!value),
      'ariaLabel': 'Selecionar linha'
    })
  }
}

function visibilityMenu(tableApi: SimpleTableApi<never> | null | undefined) {
  return (tableApi?.getAllColumns() ?? []).filter(c => c.id !== 'select')
}

const snapshotColumns: TableColumn<Snapshot>[] = [
  selectColumn<Snapshot>() as TableColumn<Snapshot>,
  {
    accessorKey: 'operation_code',
    header: sortableHeader('Operação'),
    cell: ({ row }) => h('p', { class: 'font-medium text-highlighted' }, row.original.operation_code)
  },
  {
    accessorKey: 'family',
    header: 'Família'
  },
  {
    accessorKey: 'freshness',
    header: 'Atualidade',
    filterFn: 'equals',
    cell: ({ row }) => h(UBadge, {
      color: row.original.freshness === 'fresh' ? 'success' : 'warning',
      variant: 'subtle'
    }, () => row.original.freshness === 'fresh' ? 'Atual' : 'Desatualizado')
  },
  {
    accessorKey: 'completeness',
    header: 'Integridade',
    cell: ({ row }) => h(UBadge, {
      color: row.original.completeness === 'complete' ? 'success' : 'warning',
      variant: 'subtle'
    }, () => row.original.completeness === 'complete' ? 'Completo' : row.original.completeness === 'blocked' ? 'Bloqueado' : 'Incompleto')
  },
  {
    accessorKey: 'verified_at',
    header: sortableHeader('Verificado em'),
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.verified_at))
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'text-right' }, [
      h(UDropdownMenu, {
        content: { align: 'end' },
        items: [
          { type: 'label' as const, label: 'Ações' },
          {
            label: 'Copiar fingerprint',
            icon: 'i-lucide-copy',
            onSelect: () => {
              navigator.clipboard.writeText(row.original.fingerprint)
              toast.add({ title: 'Fingerprint copiado', color: 'success' })
            }
          }
        ]
      }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações do snapshot' }))
    ])
  }
]

const changeColumns: TableColumn<ChangeItem>[] = [
  selectColumn<ChangeItem>() as TableColumn<ChangeItem>,
  {
    accessorKey: 'operation_code',
    header: sortableHeader('Operação'),
    cell: ({ row }) => h('p', { class: 'font-medium text-highlighted' }, row.original.operation_code)
  },
  {
    accessorKey: 'normalized',
    header: 'Normalizado',
    filterFn: (row, _columnId, value) => {
      if (value === undefined || value === 'all') return true
      return String(row.original.normalized) === String(value)
    },
    cell: ({ row }) => h(UBadge, {
      color: row.original.normalized ? 'success' : 'neutral',
      variant: 'subtle'
    }, () => row.original.normalized ? 'Sim' : 'Não')
  },
  {
    accessorKey: 'created_at',
    header: sortableHeader('Detectada em'),
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.created_at))
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'text-right' }, [
      h(UDropdownMenu, {
        content: { align: 'end' },
        items: [
          { type: 'label' as const, label: 'Ações' },
          {
            label: 'Copiar ID da mudança',
            icon: 'i-lucide-copy',
            onSelect: () => {
              navigator.clipboard.writeText(String(row.original.id))
              toast.add({ title: 'ID copiado', color: 'success' })
            }
          }
        ]
      }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações da mudança' }))
    ])
  }
]

const alertColumns: TableColumn<AlertItem>[] = [
  selectColumn<AlertItem>() as TableColumn<AlertItem>,
  {
    accessorKey: 'status',
    header: 'Estado',
    filterFn: 'equals',
    cell: ({ row }) => h(UBadge, {
      color: row.original.status === 'pending' ? 'warning' : 'neutral',
      variant: 'subtle'
    }, () => row.original.status === 'pending' ? 'Pendente' : 'Reconhecido')
  },
  {
    accessorKey: 'created_at',
    header: sortableHeader('Criado em'),
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.created_at))
  },
  {
    accessorKey: 'acknowledged_at',
    header: 'Reconhecido em',
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.acknowledged_at))
  },
  {
    id: 'actions',
    cell: ({ row }) => row.original.status === 'pending' && canWrite.value
      ? h('div', { class: 'text-right' }, [
          h(UDropdownMenu, {
            content: { align: 'end' },
            items: [
              { type: 'label' as const, label: 'Ações' },
              { label: 'Reconhecer alerta', icon: 'i-lucide-check', onSelect: () => void acknowledge(row.original.id) }
            ]
          }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações do alerta' }))
        ])
      : null
  }
]

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

const tableUi = {
  base: 'table-fixed border-separate border-spacing-0',
  thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
  tbody: '[&>tr]:last:[&>td]:border-b-0',
  th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
  td: 'border-b border-default',
  separator: 'h-0'
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
            <div class="flex flex-wrap items-center justify-between gap-1.5">
              <UInput
                v-model="snapshotSearch"
                class="max-w-sm"
                icon="i-lucide-search"
                placeholder="Filtrar por operação..."
              />
              <div class="flex flex-wrap items-center gap-1.5">
                <UButton
                  label="Exportar CSV"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-download"
                  @click="exportSnapshots"
                />
                <USelect
                  v-model="snapshotFreshness"
                  :items="[
                    { label: 'Todas', value: 'all' },
                    { label: 'Atual', value: 'fresh' },
                    { label: 'Desatualizado', value: 'stale' }
                  ]"
                  placeholder="Filtrar atualidade"
                  class="min-w-28"
                />
                <UDropdownMenu
                  :items="visibilityMenu(snapshotsTable?.tableApi as SimpleTableApi<never> | null | undefined).map(column => ({
                    label: upperFirst(column.id),
                    type: 'checkbox' as const,
                    checked: snapshotsTable?.tableApi?.getAllColumns().find(c => c.id === column.id)?.getIsVisible() ?? true,
                    onUpdateChecked(checked: boolean) {
                      snapshotsTable?.tableApi?.getColumn(column.id)?.toggleVisibility(!!checked)
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
                <div class="flex flex-col items-center justify-center gap-2 py-8 text-center">
                  <p class="font-medium text-highlighted">
                    Nenhum snapshot encontrado
                  </p>
                  <p class="text-sm text-muted">
                    Ajuste a busca ou o filtro de atualidade.
                  </p>
                </div>
              </template>
            </UTable>
            <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
              <div class="text-sm text-muted">
                {{ snapshotSelected.length }} de {{ snapshotFiltered }} snapshot(s) selecionado(s).
              </div>
              <UPagination
                :page="(snapshotsTable?.tableApi?.getState().pagination.pageIndex || 0) + 1"
                :items-per-page="snapshotsTable?.tableApi?.getState().pagination.pageSize"
                :total="snapshotFiltered"
                @update:page="(p: number) => snapshotsTable?.tableApi?.setPageIndex(p - 1)"
              />
            </div>
          </div>
        </UCard>

        <UCard>
          <template #header>
            <p class="font-medium text-highlighted">
              Mudanças
            </p>
          </template>
          <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-1.5">
              <UInput
                v-model="changeSearch"
                class="max-w-sm"
                icon="i-lucide-search"
                placeholder="Filtrar por operação..."
              />
              <div class="flex flex-wrap items-center gap-1.5">
                <UButton
                  label="Exportar CSV"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-download"
                  @click="exportChanges"
                />
                <USelect
                  v-model="changeNormalized"
                  :items="[
                    { label: 'Todos', value: 'all' },
                    { label: 'Normalizados', value: 'true' },
                    { label: 'Não normalizados', value: 'false' }
                  ]"
                  placeholder="Filtrar normalização"
                  class="min-w-28"
                />
                <UDropdownMenu
                  :items="visibilityMenu(changesTable?.tableApi as SimpleTableApi<never> | null | undefined).map(column => ({
                    label: upperFirst(column.id),
                    type: 'checkbox' as const,
                    checked: changesTable?.tableApi?.getAllColumns().find(c => c.id === column.id)?.getIsVisible() ?? true,
                    onUpdateChecked(checked: boolean) {
                      changesTable?.tableApi?.getColumn(column.id)?.toggleVisibility(!!checked)
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
                <div class="flex flex-col items-center justify-center gap-2 py-8 text-center">
                  <p class="font-medium text-highlighted">
                    Nenhuma mudança encontrada
                  </p>
                  <p class="text-sm text-muted">
                    Ajuste a busca ou o filtro de normalização.
                  </p>
                </div>
              </template>
            </UTable>
            <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
              <div class="text-sm text-muted">
                {{ changeSelected.length }} de {{ changeFiltered }} mudança(s) selecionada(s).
              </div>
              <UPagination
                :page="(changesTable?.tableApi?.getState().pagination.pageIndex || 0) + 1"
                :items-per-page="changesTable?.tableApi?.getState().pagination.pageSize"
                :total="changeFiltered"
                @update:page="(p: number) => changesTable?.tableApi?.setPageIndex(p - 1)"
              />
            </div>
          </div>
        </UCard>

        <UCard>
          <template #header>
            <p class="font-medium text-highlighted">
              Alertas
            </p>
          </template>
          <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-1.5">
              <USelect
                v-model="alertStatus"
                :items="[
                  { label: 'Todos os estados', value: 'all' },
                  { label: 'Pendentes', value: 'pending' },
                  { label: 'Reconhecidos', value: 'acknowledged' }
                ]"
                placeholder="Filtrar estado"
                class="min-w-28"
              />
              <div class="flex flex-wrap items-center gap-1.5">
                <UButton
                  v-if="canWrite && alertSelected.length"
                  :label="`Reconhecer (${alertSelected.length})`"
                  icon="i-lucide-check"
                  :loading="bulkAcknowledging"
                  @click="acknowledgeSelected"
                />
                <UButton
                  label="Exportar CSV"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-download"
                  @click="exportAlerts"
                />
                <UDropdownMenu
                  :items="visibilityMenu(alertsTable?.tableApi as SimpleTableApi<never> | null | undefined).map(column => ({
                    label: upperFirst(column.id),
                    type: 'checkbox' as const,
                    checked: alertsTable?.tableApi?.getAllColumns().find(c => c.id === column.id)?.getIsVisible() ?? true,
                    onUpdateChecked(checked: boolean) {
                      alertsTable?.tableApi?.getColumn(column.id)?.toggleVisibility(!!checked)
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
                <div class="flex flex-col items-center justify-center gap-2 py-8 text-center">
                  <p class="font-medium text-highlighted">
                    Nenhum alerta encontrado
                  </p>
                  <p class="text-sm text-muted">
                    Ajuste o filtro de estado.
                  </p>
                </div>
              </template>
            </UTable>
            <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
              <div class="text-sm text-muted">
                {{ alertSelected.length }} de {{ alertFiltered }} alerta(s) selecionado(s).
              </div>
              <UPagination
                :page="(alertsTable?.tableApi?.getState().pagination.pageIndex || 0) + 1"
                :items-per-page="alertsTable?.tableApi?.getState().pagination.pageSize"
                :total="alertFiltered"
                @update:page="(p: number) => alertsTable?.tableApi?.setPageIndex(p - 1)"
              />
            </div>
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
      />
    </template>
  </UDashboardPanel>
</template>
