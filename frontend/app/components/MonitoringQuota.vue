<script setup lang="ts">
import type { DashboardResponse } from '~/composables/useMonitoring'

const props = defineProps<{
  quota: DashboardResponse['quota'] | null
}>()

const exhausted = computed(() => (props.quota?.remaining ?? 0) <= 0)
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex items-center justify-between">
        <span class="font-semibold">Volume mensal de consultas</span>
        <UBadge :color="exhausted ? 'error' : 'success'" variant="subtle">
          {{ exhausted ? 'Esgotado' : 'Disponível' }}
        </UBadge>
      </div>
    </template>

    <div v-if="quota" class="space-y-2">
      <UProgress :model-value="quota.volume === 0 ? 0 : (quota.used / quota.volume) * 100" />
      <p class="text-sm text-muted">
        {{ quota.used }} de {{ quota.volume }} consultas usadas no ciclo {{ quota.period }}.
        Restam {{ quota.remaining }}.
      </p>
      <UAlert
        v-if="exhausted"
        color="warning"
        variant="subtle"
        title="Volume esgotado"
        description="Novas consultas serão recusadas. Troque de Plan para ampliar o volume e continuar monitorando."
      />
    </div>
    <p v-else class="text-sm text-muted">
      Sem plano com volume de consultas. Novas consultas serão recusadas.
    </p>
  </UCard>
</template>
