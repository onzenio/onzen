<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { Row } from '@tanstack/table-core'
import { refDebounced } from '@vueuse/core'
import type { DashboardData, Enrollment, Health, Paginated, RunQueued } from '~/types/monitoring'

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UDropdownMenu = resolveComponent('UDropdownMenu')

const toast = useToast()
const { canWrite } = useSession()

const page = ref(1)
const search = ref('')
const searchDebounced = refDebounced(search, 400)
const statusFilter = ref('all')

const statusQuery = computed(() => statusFilter.value === 'all' ? undefined : statusFilter.value)

const { data: health, error: healthError } = await useFetch<{ data: Health }>('/api/monitoring/health', { lazy: true })
const { data: dashboard, status: dashboardStatus, error: dashboardError, refresh: refreshDashboard } = await useFetch<{ data: DashboardData }>('/api/monitoring/dashboard', { lazy: true })

const { data: enrollments, status: tableStatus, error: enrollmentsError, refresh: refreshEnrollments } = await useFetch<Paginated<Enrollment>>('/api/monitoring/enrollments', {
  lazy: true,
  query: { page, search: searchDebounced, status: statusQuery, per_page: 25 }
})

watch([searchDebounced, statusQuery], () => {
  page.value = 1
})

const runTarget = ref<Enrollment | null>(null)
const runOpen = computed({
  get: () => runTarget.value !== null,
  set: value => !value && (runTarget.value = null)
})
const running = ref(false)
const syncing = ref(false)

const quota = computed(() => dashboard.value?.data.quota)
const quotaExhausted = computed(() => quota.value !== undefined && quota.value.limit > 0 && quota.value.consumed >= quota.value.limit)
const quotaPercent = computed(() => {
  if (!quota.value || quota.value.limit <= 0) {
    return 0
  }
  return Math.min(100, Math.round((quota.value.consumed / quota.value.limit) * 100))
})

function getRowItems(row: Row<Enrollment>) {
  const items = [{
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

const columns: TableColumn<Enrollment>[] = [
  {
    accessorKey: 'client',
    header: 'Client',
    cell: ({ row }) => {
      const client = row.original.client
      return h('div', { class: 'flex flex-col' }, [
        h('p', { class: 'font-medium text-highlighted' }, client?.razao_social ?? `Client #${row.original.id}`),
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
        h('p', { class: 'font-medium text-highlighted' }, definition?.name ?? row.original.id),
        definition && !definition.is_active
          ? h(UBadge, { color: 'neutral', variant: 'subtle' }, () => 'Indisponível')
          : null
      ])
    }
  },
  {
    accessorKey: 'status',
    header: 'Estado',
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
    header: 'Última mudança',
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.last_change_at))
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'text-right' }, [
      h(UDropdownMenu, {
        content: { align: 'end' },
        items: getRowItems(row)
      }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost' }))
    ])
  }
]

async function confirmRun() {
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

async function syncNow() {
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

            <div class="flex flex-col gap-2 sm:flex-row">
              <UInput
                v-model="search"
                icon="i-lucide-search"
                placeholder="Buscar por nome ou CNPJ…"
                class="max-w-sm"
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
            </div>

            <UTable
              :data="enrollments?.data ?? []"
              :columns="columns"
              :loading="tableStatus === 'pending' || dashboardStatus === 'pending'"
              :ui="{
                base: 'table-fixed border-separate border-spacing-0',
                thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
                tbody: '[&>tr]:last:[&>td]:border-b-0',
                th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
                td: 'border-b border-default'
              }"
            />

            <div class="flex justify-end">
              <UPagination
                v-if="enrollments && enrollments.last_page > 1"
                :default-page="enrollments.current_page"
                :items-per-page="enrollments.per_page"
                :total="enrollments.total"
                @update:page="(p: number) => page = p"
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
