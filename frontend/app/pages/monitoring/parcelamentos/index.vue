<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Paginated, ParcelmentOrder } from '~/types/monitoring'

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')

const MODALITIES = ['PARCSN', 'PARCSN-ESP', 'PERTSN', 'RELPSN', 'PARCMEI', 'PARCMEI-ESP', 'PERTMEI', 'RELPMEI']

const page = ref(1)
const modality = ref('all')
const modalityQuery = computed(() => modality.value === 'all' ? undefined : modality.value)

const { data: orders, status, error } = await useFetch<Paginated<ParcelmentOrder>>('/api/monitoring/parcelamentos', {
  lazy: true,
  query: { page, modalidade: modalityQuery, per_page: 25 }
})

watch(modalityQuery, () => {
  page.value = 1
})

const columns: TableColumn<ParcelmentOrder>[] = [
  {
    accessorKey: 'client',
    header: 'Client',
    cell: ({ row }) => {
      const client = row.original.client
      return h('div', { class: 'flex flex-col' }, [
        h('p', { class: 'font-medium text-highlighted' }, client?.razao_social ?? `Client #${row.original.client_id}`),
        h('p', { class: 'text-sm text-muted' }, client ? formatCnpj(client.cnpj) : '—')
      ])
    }
  },
  {
    accessorKey: 'modality',
    header: 'Modalidade',
    cell: ({ row }) => h(UBadge, { color: 'info', variant: 'subtle' }, () => row.original.modality)
  },
  {
    accessorKey: 'status',
    header: 'Estado',
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, row.original.status ?? '—')
  },
  {
    accessorKey: 'installments',
    header: 'Parcelas',
    cell: ({ row }) => {
      const paid = row.original.paid_installments
      const total = row.original.installments_count
      return h('p', { class: 'text-sm text-muted' }, paid !== null && total !== null ? `${paid}/${total} pagas` : '—')
    }
  },
  {
    accessorKey: 'total_amount',
    header: 'Total',
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatMoney(row.original.total_amount))
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'text-right' }, [
      h(UButton, {
        label: 'Detalhe',
        size: 'xs',
        variant: 'outline',
        onClick: () => navigateTo(`/monitoring/parcelamentos/${row.original.id}`)
      })
    ])
  }
]
</script>

<template>
  <UDashboardPanel id="monitoring-parcelamentos">
    <template #header>
      <UDashboardNavbar title="Parcelamentos">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UCard>
        <div class="flex flex-col gap-4">
          <UAlert
            v-if="error"
            color="error"
            variant="subtle"
            title="Parcelamentos indisponíveis"
            :description="monitoringErrorMessage(backendErrorBody(error))"
          />

          <div class="flex flex-col gap-2 sm:flex-row">
            <USelect
              v-model="modality"
              :items="[{ label: 'Todas as modalidades', value: 'all' }, ...MODALITIES.map(m => ({ label: m, value: m }))]"
              class="w-56"
            />
          </div>

          <UTable
            :data="orders?.data ?? []"
            :columns="columns"
            :loading="status === 'pending'"
          />

          <div class="flex justify-end">
            <UPagination
              v-if="orders && orders.last_page > 1"
              :default-page="orders.current_page"
              :items-per-page="orders.per_page"
              :total="orders.total"
              @update:page="(p: number) => page = p"
            />
          </div>
        </div>
      </UCard>
    </template>
  </UDashboardPanel>
</template>
