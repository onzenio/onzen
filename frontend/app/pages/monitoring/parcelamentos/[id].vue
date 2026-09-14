<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Installment, Paginated, ParcelmentOrderDetail, Payment } from '~/types/monitoring'

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')

const route = useRoute()
const orderId = computed(() => route.params.id as string)

const { data: order, status: orderStatus } = await useFetch<{ data: ParcelmentOrderDetail }>(
  () => `/api/monitoring/parcelamentos/${orderId.value}`,
  { lazy: true }
)

const selectedInstallment = ref<Installment | null>(null)
const payments = ref<{ data: Paginated<Payment> } | null>(null)
const paymentsStatus = ref<'idle' | 'pending' | 'success' | 'error'>('idle')

watch(() => order.value?.data.installments, (installments) => {
  if (installments?.length && !selectedInstallment.value) {
    selectInstallment(installments[0] as Installment)
  }
}, { immediate: true })

async function selectInstallment(installment: Installment) {
  selectedInstallment.value = installment
  paymentsStatus.value = 'pending'
  try {
    payments.value = await $fetch<{ data: Paginated<Payment> }>(`/api/monitoring/parcelas/${installment.id}/pagamentos`, { query: { per_page: 50 } })
    paymentsStatus.value = 'success'
  } catch {
    paymentsStatus.value = 'error'
  }
}

const installmentColumns: TableColumn<Installment>[] = [
  {
    accessorKey: 'number',
    header: 'Nº',
    cell: ({ row }) => h('p', { class: 'font-medium text-highlighted' }, String(row.original.number))
  },
  {
    accessorKey: 'status',
    header: 'Estado',
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, row.original.status ?? '—')
  },
  {
    accessorKey: 'amount',
    header: 'Valor',
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatMoney(row.original.amount))
  },
  {
    accessorKey: 'due_date',
    header: 'Vencimento',
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
      h(UButton, {
        label: 'Pagamentos',
        size: 'xs',
        variant: 'ghost',
        onClick: () => selectInstallment(row.original)
      })
    ])
  }
]

const paymentColumns: TableColumn<Payment>[] = [
  {
    accessorKey: 'status',
    header: 'Estado',
    cell: ({ row }) => h(UBadge, { color: 'neutral', variant: 'subtle' }, () => row.original.status ?? '—')
  },
  {
    accessorKey: 'amount',
    header: 'Valor',
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatMoney(row.original.amount))
  },
  {
    accessorKey: 'paid_at',
    header: 'Pago em',
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDate(row.original.paid_at))
  }
]
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
          <UTable
            :data="order.data.installments ?? []"
            :columns="installmentColumns"
          />
        </UCard>

        <UCard v-if="selectedInstallment">
          <template #header>
            <p class="font-medium text-highlighted">
              Pagamentos — parcela {{ selectedInstallment.number }}
            </p>
          </template>
          <UTable
            :data="payments?.data.data ?? []"
            :columns="paymentColumns"
            :loading="paymentsStatus === 'pending'"
          />
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
        v-else-if="orderStatus !== 'pending'"
        color="error"
        variant="subtle"
        title="Parcelamento não encontrado"
        description="Verifique se o pedido pertence à sua Account."
      />
    </template>
  </UDashboardPanel>
</template>
