<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui'

interface AuditActor {
  id: number
  name: string
  email: string
}

interface AuditRow {
  id: number
  actor_user_id: number | null
  origin_account_id: number
  target_account_id: number | null
  action: string
  metadata: Record<string, unknown> | null
  created_at: string
  actor?: AuditActor | null
}

interface AuditResponse {
  data: AuditRow[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
}

const UBadge = resolveComponent('UBadge')

const accountId = ref('')
const actorUserId = ref('')
const from = ref('')
const to = ref('')
const page = ref(1)

const query = computed(() => ({
  account_id: accountId.value || undefined,
  actor_user_id: actorUserId.value || undefined,
  from: from.value || undefined,
  to: to.value || undefined,
  page: page.value
}))

const { data, status, refresh } = await useFetch<AuditResponse>('/api/audit', {
  key: 'audit-list',
  query
})

const logs = computed(() => data.value?.data ?? [])
const total = computed(() => data.value?.meta.total ?? 0)

function applyFilters() {
  page.value = 1
  void refresh()
}

function clearFilters() {
  accountId.value = ''
  actorUserId.value = ''
  from.value = ''
  to.value = ''
  page.value = 1
  void refresh()
}

function formatDate(value: string): string {
  return new Date(value).toLocaleString('pt-BR')
}

const columns: TableColumn<AuditRow>[] = [
  { accessorKey: 'id', header: 'ID' },
  {
    accessorKey: 'created_at',
    header: 'Data',
    cell: ({ row }) => formatDate(row.original.created_at)
  },
  {
    accessorKey: 'action',
    header: 'Ação',
    cell: ({ row }) => h(UBadge, { variant: 'subtle', color: 'neutral' }, () => row.original.action)
  },
  {
    id: 'actor',
    header: 'Ator',
    cell: ({ row }) => row.original.actor?.email ?? (row.original.actor_user_id ? `#${row.original.actor_user_id}` : '—')
  },
  { accessorKey: 'origin_account_id', header: 'Origem' },
  {
    accessorKey: 'target_account_id',
    header: 'Alvo',
    cell: ({ row }) => row.original.target_account_id ?? '—'
  }
]
</script>

<template>
  <UDashboardPanel id="audit">
    <template #header>
      <UDashboardNavbar title="Audit">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-wrap items-end gap-1.5">
        <UFormField label="Conta" class="min-w-28">
          <UInput
            v-model="accountId"
            type="number"
            min="1"
            placeholder="ID da conta"
          />
        </UFormField>
        <UFormField label="Ator" class="min-w-28">
          <UInput
            v-model="actorUserId"
            type="number"
            min="1"
            placeholder="ID do usuário"
          />
        </UFormField>
        <UFormField label="De" class="min-w-28">
          <UInput v-model="from" type="date" />
        </UFormField>
        <UFormField label="Até" class="min-w-28">
          <UInput v-model="to" type="date" />
        </UFormField>
        <div class="flex gap-1.5">
          <UButton label="Filtrar" icon="i-lucide-filter" @click="applyFilters" />
          <UButton
            label="Limpar"
            color="neutral"
            variant="outline"
            @click="clearFilters"
          />
        </div>
      </div>

      <UTable
        :data="logs"
        :columns="columns"
        :loading="status === 'pending'"
      />

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <div class="text-sm text-muted">
          {{ total }} registro(s).
        </div>

        <UPagination
          v-model:page="page"
          :items-per-page="data?.meta.per_page ?? 15"
          :total="total"
          @update:page="() => refresh()"
        />
      </div>
    </template>
  </UDashboardPanel>
</template>
