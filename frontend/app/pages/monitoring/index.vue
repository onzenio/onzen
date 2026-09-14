<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { CatalogResponse, DashboardResponse, Enrollment, MonitoringDefinition } from '~/composables/useMonitoring'
import { triggerEnrollment } from '~/composables/useMonitoring'

definePageMeta({ title: 'Monitoramento' })

const toast = useToast()
const { can } = usePermissions()
const canTrigger = computed(() => can('clients.write'))

const search = ref('')
const page = ref(1)

const { data: catalog } = await useFetch<CatalogResponse>('/api/monitoring/catalog', { key: 'monitoring-catalog' })
const { data: dashboard, refresh: refreshDashboard } = await useFetch<DashboardResponse>('/api/monitoring/dashboard', { key: 'monitoring-dashboard' })

const definitions = computed<MonitoringDefinition[]>(() => catalog.value?.data ?? [])

const enrollmentsQuery = computed(() => ({
  q: search.value || undefined,
  page: page.value
}))

interface EnrollmentsResponse {
  data: Enrollment[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
}

const { data: enrollmentsData, status: enrollmentsStatus, refresh: refreshEnrollments } = await useFetch<EnrollmentsResponse>('/api/monitoring/enrollments', {
  key: 'monitoring-enrollments',
  query: enrollmentsQuery
})

const enrollments = computed(() => enrollmentsData.value?.data ?? [])
const total = computed(() => enrollmentsData.value?.meta.total ?? 0)

let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(search, () => {
  page.value = 1
  if (searchTimer) {
    clearTimeout(searchTimer)
  }
  searchTimer = setTimeout(() => {
    void refreshEnrollments()
  }, 400)
})

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')

function availabilityBadge(availability: MonitoringDefinition['availability']) {
  return availability === 'available'
    ? { color: 'success' as const, label: 'Disponível' }
    : availability === 'prospecting'
      ? { color: 'info' as const, label: 'Em prospecção' }
      : { color: 'neutral' as const, label: 'Indisponível' }
}

const moduleColumns: TableColumn<MonitoringDefinition>[] = [
  { accessorKey: 'name', header: 'Módulo' },
  { accessorKey: 'family', header: 'Família' },
  {
    id: 'availability',
    header: 'Situação',
    cell: ({ row }) => {
      const badge = availabilityBadge(row.original.availability)
      return h(UBadge, { variant: 'subtle', color: badge.color }, () => badge.label)
    }
  },
  {
    accessorKey: 'automatic',
    header: 'Ciclo automático',
    cell: ({ row }) => row.original.automatic ? 'Sim' : 'Manual'
  }
]

const enrollmentColumns: TableColumn<Enrollment>[] = [
  {
    accessorKey: 'client',
    header: 'Cliente',
    cell: ({ row }) => row.original.client?.razao_social ?? `#${row.original.client_id}`
  },
  { accessorKey: 'definition_code', header: 'Definição' },
  {
    id: 'status',
    header: 'Estado',
    cell: ({ row }) => {
      const status = row.original.status
      const color = status === 'active' ? 'success' : status === 'paused' ? 'warning' : 'neutral'
      const label = status === 'active' ? 'Ativa' : status === 'paused' ? 'Pausada' : 'Encerrada'
      return h(UBadge, { variant: 'subtle', color }, () => label)
    }
  },
  {
    id: 'actions',
    header: '',
    cell: ({ row }) => {
      const enrollment = row.original
      return h('div', { class: 'flex justify-end gap-2' }, [
        h(UButton, {
          size: 'xs',
          variant: 'ghost',
          label: 'Abrir',
          to: `/monitoring/${enrollment.client_id}`
        }),
        canTrigger.value && enrollment.status === 'active'
          ? h(UButton, {
              size: 'xs',
              color: 'primary',
              label: 'Consultar',
              onClick: () => void trigger(enrollment.id)
            })
          : null
      ])
    }
  }
]

async function trigger(id: number) {
  try {
    const run = await triggerEnrollment(id)
    toast.add({ title: 'Consulta disparada', description: `Execução #${run.run_id} na fila.`, color: 'success' })
    await refreshDashboard()
  } catch (error) {
    toast.add({ title: 'Não foi possível disparar a consulta', description: backendMessage(error), color: 'error' })
  }
}
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-xl font-semibold">
        Monitoramento
      </h1>
      <p class="text-sm text-muted">
        Situação fiscal da carteira via SERPRO. Catálogo {{ catalog?.version }}.
      </p>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
      <MonitoringQuota :quota="dashboard?.quota ?? null" />
      <UCard>
        <template #header>
          <span class="font-semibold">Associações</span>
        </template>
        <p class="text-2xl font-semibold">
          {{ dashboard?.enrollments.active ?? 0 }}
          <span class="text-sm font-normal text-muted">ativas</span>
        </p>
        <p class="text-sm text-muted">
          {{ dashboard?.enrollments.paused ?? 0 }} pausadas · {{ dashboard?.open_alerts ?? 0 }} alertas abertos
        </p>
      </UCard>
    </div>

    <UCard>
      <template #header>
        <span class="font-semibold">Módulos do catálogo</span>
      </template>
      <UTable :data="definitions" :columns="moduleColumns" />
    </UCard>

    <UCard>
      <template #header>
        <div class="flex flex-wrap items-center justify-between gap-2">
          <span class="font-semibold">Associações da carteira</span>
          <UInput v-model="search" placeholder="Buscar por nome ou CNPJ" class="w-64" />
        </div>
      </template>
      <UTable :data="enrollments" :columns="enrollmentColumns" :loading="enrollmentsStatus === 'pending'" />
      <p class="mt-2 text-sm text-muted">
        {{ total }} associações
      </p>
    </UCard>
  </div>
</template>
