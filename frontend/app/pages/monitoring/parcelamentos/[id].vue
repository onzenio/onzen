<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import { upperFirst } from 'scule'
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'
import type { Installment, Paginated, ParcelmentOrderDetail, Payment } from '~/types/monitoring'

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UCheckbox = resolveComponent('UCheckbox')
const UDropdownMenu = resolveComponent('UDropdownMenu')

const toast = useToast()
const route = useRoute()
const orderId = computed(() => route.params.id as string)

interface SimpleTableApi<T> {
  getFilteredSelectedRowModel: () => { rows: Row<T>[] }
  getFilteredRowModel: () => { rows: Row<T>[] }
  getColumn: (id: string) => { setFilterValue: (value: string | undefined) => void, getFilterValue: () => unknown, toggleVisibility: (value?: boolean) => void } | undefined
  getAllColumns: () => { id: string, getCanHide: () => boolean, getIsVisible: () => boolean }[]
  getState: () => { pagination: { pageIndex: number, pageSize: number } }
  setPageIndex: (index: number) => void
}

const installmentsTable = useTemplateRef<{ tableApi?: SimpleTableApi<Installment> | null }>('installmentsTable')
const paymentsTable = useTemplateRef<{ tableApi?: SimpleTableApi<Payment> | null }>('paymentsTable')

const installmentFilters = ref<{ id: string, value: unknown }[]>([])
const installmentVisibility = ref()
const installmentSelection = ref<Record<string, boolean>>({})
const installmentSorting = ref<{ id: string, desc: boolean }[]>([])
const installmentPagination = ref({ pageIndex: 0, pageSize: 10 })
const installmentStatus = ref('all')
const installmentSearchText = ref('')

const paymentFilters = ref<{ id: string, value: unknown }[]>([])
const paymentVisibility = ref()
const paymentSelection = ref<Record<string, boolean>>({})
const paymentSorting = ref<{ id: string, desc: boolean }[]>([])
const paymentPagination = ref({ pageIndex: 0, pageSize: 10 })
const paymentStatus = ref('all')

const { data: order, status: orderStatus, error: orderError } = await useFetch<{ data: ParcelmentOrderDetail }>(
  () => `/api/monitoring/parcelamentos/${orderId.value}`,
  { lazy: true }
)

const selectedInstallment = ref<Installment | null>(null)
const payments = ref<Paginated<Payment> | null>(null)
const paymentsStatus = ref<'idle' | 'pending' | 'success' | 'error'>('idle')

watch(() => order.value?.data.installments, (installments) => {
  if (installments?.length && !selectedInstallment.value) {
    selectInstallment(installments[0] as Installment)
  }
}, { immediate: true })

async function selectInstallment(installment: Installment): Promise<void> {
  selectedInstallment.value = installment
  paymentsStatus.value = 'pending'
  try {
    payments.value = await $fetch<Paginated<Payment>>(`/api/monitoring/parcelas/${installment.id}/pagamentos`, { query: { per_page: 50 } })
    paymentsStatus.value = 'success'
  } catch {
    paymentsStatus.value = 'error'
  }
}

const installmentRows = computed((): Installment[] => order.value?.data.installments ?? [])
const paymentRows = computed((): Payment[] => payments.value?.data ?? [])

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

const installmentColumns: TableColumn<Installment>[] = [
  selectColumn<Installment>() as TableColumn<Installment>,
  {
    accessorKey: 'number',
    header: sortableHeader('Nº'),
    filterFn: (row, _columnId, value) => {
      const term = String(value ?? '').toLowerCase()
      if (!term) return true
      return String(row.original.number).toLowerCase().includes(term) || (row.original.status ?? '').toLowerCase().includes(term)
    },
    cell: ({ row }) => h('p', { class: 'font-medium text-highlighted' }, String(row.original.number))
  },
  {
    accessorKey: 'status',
    header: 'Estado',
    filterFn: 'equals',
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, row.original.status ?? '—')
  },
  {
    accessorKey: 'amount',
    header: sortableHeader('Valor'),
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatMoney(row.original.amount))
  },
  {
    accessorKey: 'due_date',
    header: sortableHeader('Vencimento'),
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDate(row.original.due_date))
  },
  {
    accessorKey: 'guide_available',
    header: 'Guia',
    cell: ({ row }) => row.original.guide_available
      ? h(UButton, {
          label: 'Baixar guia',
          size: 'xs',
          variant: 'outline',
          icon: 'i-lucide-download',
          to: `/api/monitoring/parcelas/${row.original.id}/guia`,
          target: '_blank',
          external: true
        })
      : h('p', { class: 'text-sm text-muted' }, 'Guia ainda não disponível')
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'text-right' }, [
      h(UDropdownMenu, {
        content: { align: 'end' },
        items: [
          { type: 'label' as const, label: 'Ações' },
          { label: 'Ver pagamentos', icon: 'i-lucide-eye', onSelect: () => void selectInstallment(row.original) },
          ...(row.original.guide_available
            ? [{
                label: 'Baixar guia',
                icon: 'i-lucide-download',
                onSelect: () => window.open(`/api/monitoring/parcelas/${row.original.id}/guia`, '_blank')
              }]
            : [])
        ]
      }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações da parcela' }))
    ])
  }
]

const paymentColumns: TableColumn<Payment>[] = [
  selectColumn<Payment>() as TableColumn<Payment>,
  {
    accessorKey: 'status',
    header: 'Estado',
    filterFn: 'equals',
    cell: ({ row }) => h(UBadge, { color: 'neutral', variant: 'subtle' }, () => row.original.status ?? '—')
  },
  {
    accessorKey: 'amount',
    header: sortableHeader('Valor'),
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatMoney(row.original.amount))
  },
  {
    accessorKey: 'paid_at',
    header: sortableHeader('Pago em'),
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDate(row.original.paid_at))
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'text-right' }, [
      h(UDropdownMenu, {
        content: { align: 'end' },
        items: [
          { type: 'label' as const, label: 'Ações' },
          {
            label: 'Copiar ID do pagamento',
            icon: 'i-lucide-copy',
            onSelect: () => {
              navigator.clipboard.writeText(String(row.original.id))
              toast.add({ title: 'ID copiado', color: 'success' })
            }
          }
        ]
      }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações do pagamento' }))
    ])
  }
]

const installmentSearch = computed({
  get: (): string => installmentSearchText.value,
  set: (value: string) => {
    installmentSearchText.value = value
    installmentsTable.value?.tableApi?.getColumn('number')?.setFilterValue(value || undefined)
    installmentPagination.value.pageIndex = 0
  }
})

watch(() => installmentStatus.value, (newVal) => {
  const column = installmentsTable.value?.tableApi?.getColumn('status')
  if (!column) return
  if (newVal === 'all') column.setFilterValue(undefined)
  else column.setFilterValue(newVal)
  installmentPagination.value.pageIndex = 0
})

watch(() => paymentStatus.value, (newVal) => {
  const column = paymentsTable.value?.tableApi?.getColumn('status')
  if (!column) return
  if (newVal === 'all') column.setFilterValue(undefined)
  else column.setFilterValue(newVal)
  paymentPagination.value.pageIndex = 0
})

const installmentSelected = computed((): Row<Installment>[] => installmentsTable.value?.tableApi?.getFilteredSelectedRowModel().rows ?? [])
const installmentFiltered = computed((): number => installmentsTable.value?.tableApi?.getFilteredRowModel().rows.length ?? installmentRows.value.length)
const paymentSelected = computed((): Row<Payment>[] => paymentsTable.value?.tableApi?.getFilteredSelectedRowModel().rows ?? [])
const paymentFiltered = computed((): number => paymentsTable.value?.tableApi?.getFilteredRowModel().rows.length ?? paymentRows.value.length)

function exportInstallments(): void {
  const list = (installmentSelected.value.length > 0 ? installmentSelected.value : (installmentsTable.value?.tableApi?.getFilteredRowModel().rows ?? [])).map((r: Row<Installment>) => r.original)
  if (list.length === 0) {
    toast.add({ title: 'Nada para exportar', description: 'Ajuste os filtros de parcelas.', color: 'warning' })
    return
  }
  exportToCsv('parcelas', list.map(i => ({ numero: i.number, estado: i.status ?? '', valor: i.amount ?? '', vencimento: i.due_date ?? '', guia: i.guide_available ? 'Sim' : 'Não' })))
  toast.add({ title: 'CSV exportado', description: `${list.length} parcela(s) exportada(s).`, color: 'success' })
}

function exportPayments(): void {
  const list = (paymentSelected.value.length > 0 ? paymentSelected.value : (paymentsTable.value?.tableApi?.getFilteredRowModel().rows ?? [])).map((r: Row<Payment>) => r.original)
  if (list.length === 0) {
    toast.add({ title: 'Nada para exportar', description: 'Ajuste os filtros de pagamentos.', color: 'warning' })
    return
  }
  exportToCsv('pagamentos', list.map(p => ({ id: p.id, estado: p.status ?? '', valor: p.amount ?? '', pago_em: p.paid_at ?? '' })))
  toast.add({ title: 'CSV exportado', description: `${list.length} pagamento(s) exportado(s).`, color: 'success' })
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
  <UDashboardPanel id="monitoring-parcelamento">
    <template #header>
      <UDashboardNavbar :title="order ? `${order.data.modality} · ${order.data.client?.razao_social ?? ''}` : 'Parcelamento'">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template #right>
          <UButton
            label="Voltar"
            icon="i-lucide-arrow-left"
            color="neutral"
            variant="ghost"
            to="/monitoring/parcelamentos"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div
        v-if="order"
        class="flex flex-col gap-4"
      >
        <UCard>
          <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
            <div>
              <p class="text-sm text-muted">
                Pedido externo
              </p>
              <p class="font-medium text-highlighted">
                {{ order.data.external_id }}
              </p>
            </div>
            <div>
              <p class="text-sm text-muted">
                Estado
              </p>
              <p class="font-medium text-highlighted">
                {{ order.data.status ?? '—' }}
              </p>
            </div>
            <div>
              <p class="text-sm text-muted">
                Total
              </p>
              <p class="font-medium text-highlighted">
                {{ formatMoney(order.data.total_amount) }}
              </p>
            </div>
            <div>
              <p class="text-sm text-muted">
                Próximo vencimento
              </p>
              <p class="font-medium text-highlighted">
                {{ formatDate(order.data.next_due_date) }}
              </p>
            </div>
          </div>
        </UCard>

        <UCard>
          <template #header>
            <p class="font-medium text-highlighted">
              Parcelas
            </p>
          </template>
          <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-1.5">
              <UInput
                v-model="installmentSearch"
                class="max-w-sm"
                icon="i-lucide-search"
                placeholder="Filtrar por nº ou estado..."
              />
              <div class="flex flex-wrap items-center gap-1.5">
                <UButton
                  label="Exportar CSV"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-download"
                  @click="exportInstallments"
                />
                <USelect
                  v-model="installmentStatus"
                  :items="[
                    { label: 'Todos os estados', value: 'all' },
                    { label: 'Em aberto', value: 'open' },
                    { label: 'Pagas', value: 'paid' },
                    { label: 'Vencidas', value: 'overdue' }
                  ]"
                  placeholder="Filtrar estado"
                  class="min-w-28"
                />
                <UDropdownMenu
                  :items="installmentsTable?.tableApi?.getAllColumns().filter((column: any) => column.getCanHide()).map((column: any) => ({
                    label: upperFirst(column.id),
                    type: 'checkbox' as const,
                    checked: column.getIsVisible(),
                    onUpdateChecked(checked: boolean) {
                      installmentsTable?.tableApi?.getColumn(column.id)?.toggleVisibility(!!checked)
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
              ref="installmentsTable"
              v-model:column-filters="installmentFilters"
              v-model:column-visibility="installmentVisibility"
              v-model:row-selection="installmentSelection"
              v-model:sorting="installmentSorting"
              v-model:pagination="installmentPagination"
              :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
              class="shrink-0"
              :data="installmentRows"
              :columns="installmentColumns"
              :ui="tableUi"
            >
              <template #empty>
                <div class="flex flex-col items-center justify-center gap-2 py-8 text-center">
                  <p class="font-medium text-highlighted">
                    Nenhuma parcela encontrada
                  </p>
                  <p class="text-sm text-muted">
                    Ajuste a busca ou o filtro de estado.
                  </p>
                </div>
              </template>
            </UTable>
            <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
              <div class="text-sm text-muted">
                {{ installmentSelected.length }} de {{ installmentFiltered }} parcela(s) selecionada(s).
              </div>
              <UPagination
                :page="(installmentsTable?.tableApi?.getState().pagination.pageIndex || 0) + 1"
                :items-per-page="installmentsTable?.tableApi?.getState().pagination.pageSize"
                :total="installmentFiltered"
                @update:page="(p: number) => installmentsTable?.tableApi?.setPageIndex(p - 1)"
              />
            </div>
          </div>
        </UCard>

        <UCard v-if="selectedInstallment">
          <template #header>
            <p class="font-medium text-highlighted">
              Pagamentos — parcela {{ selectedInstallment.number }}
            </p>
          </template>
          <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-1.5">
              <USelect
                v-model="paymentStatus"
                :items="[
                  { label: 'Todos os estados', value: 'all' },
                  { label: 'Confirmados', value: 'confirmed' },
                  { label: 'Pendentes', value: 'pending' }
                ]"
                placeholder="Filtrar estado"
                class="min-w-28"
              />
              <div class="flex flex-wrap items-center gap-1.5">
                <UButton
                  label="Exportar CSV"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-download"
                  @click="exportPayments"
                />
                <UDropdownMenu
                  :items="paymentsTable?.tableApi?.getAllColumns().filter((column: any) => column.getCanHide()).map((column: any) => ({
                    label: upperFirst(column.id),
                    type: 'checkbox' as const,
                    checked: column.getIsVisible(),
                    onUpdateChecked(checked: boolean) {
                      paymentsTable?.tableApi?.getColumn(column.id)?.toggleVisibility(!!checked)
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
              ref="paymentsTable"
              v-model:column-filters="paymentFilters"
              v-model:column-visibility="paymentVisibility"
              v-model:row-selection="paymentSelection"
              v-model:sorting="paymentSorting"
              v-model:pagination="paymentPagination"
              :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
              class="shrink-0"
              :data="paymentRows"
              :columns="paymentColumns"
              :loading="paymentsStatus === 'pending'"
              :ui="tableUi"
            >
              <template #empty>
                <div class="flex flex-col items-center justify-center gap-2 py-8 text-center">
                  <p class="font-medium text-highlighted">
                    Nenhum pagamento encontrado
                  </p>
                  <p class="text-sm text-muted">
                    Ajuste o filtro de estado.
                  </p>
                </div>
              </template>
            </UTable>
            <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
              <div class="text-sm text-muted">
                {{ paymentSelected.length }} de {{ paymentFiltered }} pagamento(s) selecionado(s).
              </div>
              <UPagination
                :page="(paymentsTable?.tableApi?.getState().pagination.pageIndex || 0) + 1"
                :items-per-page="paymentsTable?.tableApi?.getState().pagination.pageSize"
                :total="paymentFiltered"
                @update:page="(p: number) => paymentsTable?.tableApi?.setPageIndex(p - 1)"
              />
            </div>
          </div>
          <UAlert
            v-if="paymentsStatus === 'error'"
            class="mt-4"
            color="error"
            variant="subtle"
            title="Pagamentos indisponíveis"
            description="Não foi possível carregar os pagamentos desta parcela."
          />
        </UCard>
      </div>

      <UAlert
        v-else-if="orderStatus !== 'pending' && orderError?.statusCode === 404"
        color="error"
        variant="subtle"
        title="Parcelamento não encontrado"
        description="Verifique se o pedido pertence à sua conta."
      />

      <UAlert
        v-else-if="orderStatus !== 'pending'"
        color="warning"
        variant="subtle"
        title="Parcelamento indisponível"
        description="Não foi possível carregar o parcelamento agora. Tente novamente."
      />
    </template>
  </UDashboardPanel>
</template>
