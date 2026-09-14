<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { AlertItem, ChangeItem, CndData, Enrollment, Paginated, Snapshot, SnapshotsResponse } from '~/types/monitoring'

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')

const route = useRoute()
const toast = useToast()
const { canWrite } = useSession()
const enrollmentId = computed(() => route.params.id as string)

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
  { lazy: true, query: { per_page: 25 } }
)

const { data: changes, status: changesStatus } = await useFetch<Paginated<ChangeItem>>(
  () => `/api/monitoring/enrollments/${enrollmentId.value}/changes`,
  { lazy: true, query: { per_page: 25 } }
)

const { data: alerts, status: alertsStatus, refresh: refreshAlerts } = await useFetch<Paginated<AlertItem>>('/api/monitoring/alerts', {
  lazy: true,
  query: { enrollment_id: enrollmentId, per_page: 25 }
})

const acknowledging = ref<number | null>(null)

async function acknowledge(alertId: number) {
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

const snapshotColumns: TableColumn<Snapshot>[] = [
  {
    accessorKey: 'operation_code',
    header: 'Operação',
    cell: ({ row }) => h('p', { class: 'font-medium text-highlighted' }, row.original.operation_code)
  },
  {
    accessorKey: 'family',
    header: 'Família'
  },
  {
    accessorKey: 'freshness',
    header: 'Atualidade',
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
    header: 'Verificado em',
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.verified_at))
  }
]

const changeColumns: TableColumn<ChangeItem>[] = [
  {
    accessorKey: 'operation_code',
    header: 'Operação',
    cell: ({ row }) => h('p', { class: 'font-medium text-highlighted' }, row.original.operation_code)
  },
  {
    accessorKey: 'normalized',
    header: 'Normalizado',
    cell: ({ row }) => h(UBadge, {
      color: row.original.normalized ? 'success' : 'neutral',
      variant: 'subtle'
    }, () => row.original.normalized ? 'Sim' : 'Não')
  },
  {
    accessorKey: 'created_at',
    header: 'Detectada em',
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.created_at))
  }
]

const alertColumns: TableColumn<AlertItem>[] = [
  {
    accessorKey: 'status',
    header: 'Estado',
    cell: ({ row }) => h(UBadge, {
      color: row.original.status === 'pending' ? 'warning' : 'neutral',
      variant: 'subtle'
    }, () => row.original.status === 'pending' ? 'Pendente' : 'Reconhecido')
  },
  {
    accessorKey: 'created_at',
    header: 'Criado em',
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
          h(UButton, {
            label: 'Reconhecer',
            size: 'xs',
            variant: 'outline',
            loading: acknowledging.value === row.original.id,
            onClick: () => acknowledge(row.original.id)
          })
        ])
      : null
  }
]
</script>

<template>
  <UDashboardPanel id="monitoring-enrollment">
    <template #header>
      <UDashboardNavbar :title="enrollment?.data.client?.razao_social ?? 'Painel do Client'">
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
            description="Nenhum snapshot de Situação Fiscal para este Client. A CND aparece após a primeira consulta."
          />
        </UCard>

        <UCard>
          <template #header>
            <p class="font-medium text-highlighted">
              Snapshots
            </p>
          </template>
          <UTable
            :data="snapshots?.data ?? []"
            :columns="snapshotColumns"
            :loading="snapshotsStatus === 'pending'"
          />
        </UCard>

        <UCard>
          <template #header>
            <p class="font-medium text-highlighted">
              Mudanças
            </p>
          </template>
          <UTable
            :data="changes?.data ?? []"
            :columns="changeColumns"
            :loading="changesStatus === 'pending'"
          />
        </UCard>

        <UCard>
          <template #header>
            <p class="font-medium text-highlighted">
              Alertas
            </p>
          </template>
          <UTable
            :data="alerts?.data ?? []"
            :columns="alertColumns"
            :loading="alertsStatus === 'pending'"
          />
        </UCard>
      </div>

      <UAlert
        v-else-if="enrollmentStatus !== 'pending' && enrollmentError?.statusCode === 404"
        color="error"
        variant="subtle"
        title="Associação não encontrada"
        description="Verifique se a associação pertence à sua Account."
      />

      <UAlert
        v-else-if="enrollmentStatus !== 'pending'"
        color="warning"
        variant="subtle"
        title="Associação indisponível"
        description="Não foi possível carregar o painel do Client agora. Tente novamente."
      />
    </template>
  </UDashboardPanel>
</template>
