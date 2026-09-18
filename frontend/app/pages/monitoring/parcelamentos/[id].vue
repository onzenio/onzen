<script setup lang="ts">
import { getPaginationRowModel } from '@tanstack/table-core'
import { TABLE_UI as tableUi, type TableApi } from '~/utils/table-chrome'
import { useTableCsv } from '~/composables/tables/useTableCsv'
import { useTableState } from '~/composables/tables/useTableState'
import { buildInstallmentColumns, buildPaymentColumns, INSTALLMENT_COLUMN_LABELS, installmentStatusItems, PAYMENT_COLUMN_LABELS, paymentStatusItems } from '~/components/tables/monitoring/parcelDetailColumns'
import type { Installment, Paginated, ParcelmentOrderDetail, Payment } from '~/types/monitoring'

const toast = useToast()
const route = useRoute()
const orderId = computed(() => route.params.id as string)

const installmentsTable = useTemplateRef<{ tableApi?: TableApi<Installment> | null }>('installmentsTable')
const paymentsTable = useTemplateRef<{ tableApi?: TableApi<Payment> | null }>('paymentsTable')

const {
  columnFilters: installmentFilters,
  columnVisibility: installmentVisibility,
  rowSelection: installmentSelection,
  sorting: installmentSorting,
  pagination: installmentPagination,
  search: installmentSearch,
  filterValue: installmentStatus,
  selectedRows: installmentSelected,
  filteredCount: installmentFiltered
} = useTableState<Installment>(() => installmentsTable.value?.tableApi, {
  searchColumn: 'number',
  filterColumn: 'status',
  getTotal: () => installmentRows.value.length
})

const {
  columnFilters: paymentFilters,
  columnVisibility: paymentVisibility,
  rowSelection: paymentSelection,
  sorting: paymentSorting,
  pagination: paymentPagination,
  filterValue: paymentStatus,
  selectedRows: paymentSelected,
  filteredCount: paymentFiltered
} = useTableState<Payment>(() => paymentsTable.value?.tableApi, {
  filterColumn: 'status',
  getTotal: () => paymentRows.value.length
})

const { exportCsv: exportInstallmentCsv } = useTableCsv<Installment>(() => installmentsTable.value?.tableApi)
const { exportCsv: exportPaymentCsv } = useTableCsv<Payment>(() => paymentsTable.value?.tableApi)

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

function exportInstallments(): void {
  exportInstallmentCsv('parcelas', i => ({
    numero: i.number,
    estado: i.status ?? '',
    valor: i.amount ?? '',
    vencimento: i.due_date ?? '',
    guia: i.guide_available ? 'Sim' : 'Não'
  }), {
    emptyDescription: 'Ajuste os filtros de parcelas.',
    exportedUnit: 'parcela(s) exportada(s)'
  })
}

function exportPayments(): void {
  exportPaymentCsv('pagamentos', p => ({
    id: p.id,
    estado: p.status ?? '',
    valor: p.amount ?? '',
    pago_em: p.paid_at ?? ''
  }), {
    emptyDescription: 'Ajuste os filtros de pagamentos.',
    exportedUnit: 'pagamento(s) exportado(s)'
  })
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
