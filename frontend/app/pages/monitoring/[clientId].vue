<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'
import type { AlertItem, ChangeItem, CndResponse, Snapshot } from '~/composables/useMonitoring'

definePageMeta({ title: 'Painel do cliente' })

const route = useRoute()
const toast = useToast()
const clientId = computed(() => route.params.clientId as string)

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')

const { data: snapshotsData } = await useFetch<{ data: Snapshot[] }>(`/api/monitoring/clients/${clientId.value}/snapshots`, { key: `snapshots-${clientId.value}` })
const { data: changesData } = await useFetch<{ data: ChangeItem[], meta: { total: number } }>(`/api/monitoring/clients/${clientId.value}/changes`, { key: `changes-${clientId.value}` })
const { data: alertsData, refresh: refreshAlerts } = await useFetch<{ data: AlertItem[], meta: { total: number } }>(`/api/monitoring/clients/${clientId.value}/alerts`, { key: `alerts-${clientId.value}` })
const { data: cnd } = await useFetch<CndResponse>(`/api/monitoring/clients/${clientId.value}/cnd`, { key: `cnd-${clientId.value}` })

const snapshots = computed(() => snapshotsData.value?.data ?? [])
const changes = computed(() => changesData.value?.data ?? [])
const alerts = computed(() => alertsData.value?.data ?? [])

const snapshotColumns: TableColumn<Snapshot>[] = [
  { accessorKey: 'family', header: 'Família' },
  { accessorKey: 'version', header: 'Versão' },
  {
    id: 'completeness',
    header: 'Estado',
    cell: ({ row }) => h(UBadge, { variant: 'subtle', color: row.original.completeness === 'complete' ? 'success' : 'warning' }, () => row.original.completeness)
  },
  { accessorKey: 'checked_at', header: 'Verificado em' }
]

async function acknowledge(alert: AlertItem) {
  try {
    await $fetch(`/api/monitoring/clients/${clientId.value}/alerts/${alert.id}/acknowledge`, { method: 'POST' })
    toast.add({ title: 'Alerta reconhecido', color: 'success' })
    await refreshAlerts()
  } catch (error) {
    toast.add({ title: 'Não foi possível reconhecer o alerta', description: backendMessage(error), color: 'error' })
  }
}
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-xl font-semibold">
        Painel do cliente
      </h1>
      <p class="text-sm text-muted">
        Snapshots, mudanças, alertas e CND — leitura do último resultado, sem nova consulta.
      </p>
    </div>

    <UCard>
      <template #header>
        <span class="font-semibold">CND / Situação fiscal</span>
      </template>
      <div v-if="cnd?.available">
        <p class="text-sm">
          Situação: <strong>{{ cnd.situacao_fiscal ?? '—' }}</strong>
        </p>
        <p class="text-sm text-muted">
          Verificado em {{ cnd.checked_at ?? '—' }}. Lido do snapshot vigente, sem consulta ao abrir.
        </p>
      </div>
      <UAlert
        v-else
        color="neutral"
        variant="subtle"
        title="Sem snapshot de Situação Fiscal"
        :description="cnd?.reason ?? 'Nenhum resultado disponível para este cliente.'"
      />
    </UCard>

    <UCard>
      <template #header>
        <span class="font-semibold">Snapshots ({{ snapshots.length }})</span>
      </template>
      <UTable :data="snapshots" :columns="snapshotColumns" />
    </UCard>

    <UCard>
      <template #header>
        <span class="font-semibold">Mudanças ({{ changes.length }})</span>
      </template>
      <ul class="space-y-2 text-sm">
        <li v-for="change in changes" :key="change.id">
          <UBadge variant="subtle" color="info">
            {{ change.family }}
          </UBadge>
          {{ change.change_type }}
        </li>
        <li v-if="changes.length === 0" class="text-muted">
          Nenhuma mudança registrada.
        </li>
      </ul>
    </UCard>

    <UCard>
      <template #header>
        <span class="font-semibold">Alertas ({{ alerts.length }})</span>
      </template>
      <ul class="space-y-2">
        <li v-for="alert in alerts" :key="alert.id" class="flex items-center justify-between gap-2">
          <div class="flex items-center gap-2 text-sm">
            <UBadge :variant="'subtle'" :color="alert.status === 'open' ? 'error' : 'success'">
              {{ alert.status === 'open' ? 'Aberto' : 'Reconhecido' }}
            </UBadge>
            {{ alert.family }}
          </div>
          <UButton
            v-if="alert.status === 'open'"
            size="xs"
            variant="outline"
            label="Reconhecer"
            @click="() => void acknowledge(alert)"
          />
        </li>
        <li v-if="alerts.length === 0" class="text-sm text-muted">
          Nenhum alerta.
        </li>
      </ul>
    </UCard>
  </div>
</template>
