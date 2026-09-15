<script setup lang="ts">
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'
import { TABLE_UI as tableUi, type TableApi } from '~/utils/table-chrome'
import { buildInstallmentColumns, buildPaymentColumns, INSTALLMENT_COLUMN_LABELS, installmentStatusItems, PAYMENT_COLUMN_LABELS, paymentStatusItems } from '~/components/tables/monitoring/parcelDetailColumns'
import type { Installment, Paginated, ParcelmentOrderDetail, Payment } from '~/types/monitoring'

const toast = useToast()
const route = useRoute()
const orderId = computed(() => route.params.id as string)

const installmentsTable = useTemplateRef<{ tableApi?: TableApi<Installment> | null }>('installmentsTable')
const paymentsTable = useTemplateRef<{ tableApi?: TableApi<Payment> | null }>('paymentsTable')

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

const { data: order, status: orderStatus, error: orderError, refresh: refreshOrder } = await useFetch<{ data: ParcelmentOrderDetail }>(
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

const orderRetryActions = [{
  label: 'Tentar novamente',
  color: 'error' as const,
  variant: 'outline' as const,
  onClick: () => refreshOrder()
}]

function retryPayments() {
  if (selectedInstallment.value) void selectInstallment(selectedInstallment.value)
}

const installmentColumns = buildInstallmentColumns({
  onViewPayments: installment => void selectInstallment(installment)
})

const paymentColumns = buildPaymentColumns({
  onCopyId: (payment) => {
    navigator.clipboard.writeText(String(payment.id))
    toast.add({ title: 'ID copiado', color: 'success' })
  }
})

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
            <TablesTableToolbar
              v-model:search="installmentSearch"
              v-model:filter-value="installmentStatus"
              search-placeholder="Filtrar por nº ou estado…"
              :filter-items="installmentStatusItems"
              filter-placeholder="Filtrar estado"
              :table-api="installmentsTable?.tableApi"
              :column-labels="INSTALLMENT_COLUMN_LABELS"
              @export="exportInstallments"
            />
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
              :loading="orderStatus === 'pending'"
              :ui="tableUi"
            >
              <template #empty>
                <TablesTableStates
                  empty-title="Nenhuma parcela encontrada"
                  empty-hint="Ajuste a busca ou o filtro de estado."
                />
              </template>
            </UTable>
            <TablesTableFooter
              :selected="installmentSelected.length"
              :total="installmentFiltered"
              unit="parcela(s)"
              feminine
              :table-api="installmentsTable?.tableApi"
              @update:page="(p: number) => installmentsTable?.tableApi?.setPageIndex(p - 1)"
            />
          </div>
        </UCard>

        <UCard v-if="selectedInstallment">
          <template #header>
            <p class="font-medium text-highlighted">
              Pagamentos — parcela {{ selectedInstallment.number }}
            </p>
          </template>
          <div class="flex flex-col gap-4">
            <TablesTableToolbar
              :table-api="paymentsTable?.tableApi"
              :column-labels="PAYMENT_COLUMN_LABELS"
              @export="exportPayments"
            >
              <template #leading>
                <USelect
                  v-model="paymentStatus"
                  :items="paymentStatusItems"
                  placeholder="Filtrar estado"
                  class="min-w-28"
                />
              </template>
            </TablesTableToolbar>
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
                <TablesTableStates
                  empty-title="Nenhum pagamento encontrado"
                  empty-hint="Ajuste o filtro de estado."
                />
              </template>
            </UTable>
            <TablesTableFooter
              :selected="paymentSelected.length"
              :total="paymentFiltered"
              unit="pagamento(s)"
              :table-api="paymentsTable?.tableApi"
              @update:page="(p: number) => paymentsTable?.tableApi?.setPageIndex(p - 1)"
            />
          </div>
          <div v-if="paymentsStatus === 'error'" class="mt-4">
            <TablesTableStates
              :error="true"
              variant="subtle"
              error-title="Pagamentos indisponíveis"
              error-description="Não foi possível carregar os pagamentos desta parcela."
              @retry="retryPayments"
            />
          </div>
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
        :actions="orderRetryActions"
      />
    </template>
  </UDashboardPanel>
</template>
