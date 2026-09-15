<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'
import { TABLE_UI as tableUi, type TableApi } from '~/utils/table-chrome'
import { buildClientColumns, CLIENT_COLUMN_LABELS, regimeItems, regimeLabel, type ClientRow } from '~/components/tables/clients/columns'

interface ClientsResponse {
  data: ClientRow[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
}

const toast = useToast()
const { can } = usePermissions()
const canWrite = computed(() => can('clients.write'))

const table = useTemplateRef<{ tableApi?: TableApi<ClientRow> | null }>('table')
const columnFilters = ref([{ id: 'razao_social', value: '' }])
const columnVisibility = ref()
const rowSelection = ref<Record<string, boolean>>({})
const sorting = ref<{ id: string, desc: boolean }[]>([])
const pagination = ref({ pageIndex: 0, pageSize: 10 })
const regimeFilter = ref('all')

const { data, error, status, refresh } = await useFetch<ClientsResponse>('/api/clients', {
  key: 'clients-list',
  query: { per_page: 500 }
})

const clients = computed(() => data.value?.data ?? [])

const columns = buildClientColumns({
  canWrite,
  onEdit: openEdit,
  onToggleMonitoring: (client, enabled) => void toggleMonitoring(client, enabled),
  onAskDelete: askDelete
})

watch(() => regimeFilter.value, (newVal) => {
  if (!table?.value?.tableApi) return
  const column = table.value.tableApi.getColumn('regime')
  if (!column) return
  if (newVal === 'all') column.setFilterValue(undefined)
  else column.setFilterValue(newVal)
  pagination.value.pageIndex = 0
})

const search = computed({
  get: (): string => (table.value?.tableApi?.getColumn('razao_social')?.getFilterValue() as string) || '',
  set: (value: string) => {
    table.value?.tableApi?.getColumn('razao_social')?.setFilterValue(value || undefined)
    pagination.value.pageIndex = 0
  }
})

const selectedRows = computed((): Row<ClientRow>[] => table.value?.tableApi?.getFilteredSelectedRowModel().rows ?? [])
const filteredCount = computed((): number => table.value?.tableApi?.getFilteredRowModel().rows.length ?? clients.value.length)

function exportCsv() {
  const rows = (selectedRows.value.length > 0 ? selectedRows.value : (table.value?.tableApi?.getFilteredRowModel().rows ?? [])).map((r: Row<ClientRow>) => r.original)
  if (rows.length === 0) {
    toast.add({ title: 'Nada para exportar', description: 'Ajuste os filtros ou selecione ao menos um cliente.', color: 'warning' })
    return
  }
  exportToCsv('clientes', rows.map((c: ClientRow) => ({
    id: c.id,
    razao_social: c.razao_social,
    cnpj: c.cnpj,
    regime: regimeLabel(c.regime),
    contador_responsavel: c.contador_responsavel,
    monitoramento: c.monitoring_enabled ? 'Ativo' : 'Inativo'
  })))
  toast.add({ title: 'CSV exportado', description: `${rows.length} cliente(s) exportado(s).`, color: 'success' })
}

const bulkDeleting = ref(false)
async function removeSelected() {
  const rows = selectedRows.value.map((r: Row<ClientRow>) => r.original)
  if (rows.length === 0) return
  bulkDeleting.value = true
  try {
    for (const client of rows) {
      await $fetch(`/api/clients/${client.id}`, { method: 'DELETE' })
    }
    toast.add({ title: `${rows.length} cliente(s) excluído(s)`, color: 'success' })
    rowSelection.value = {}
    await refresh()
  } catch (error) {
    toast.add({ title: 'Não foi possível excluir a seleção', description: backendMessage(error), color: 'error' })
  } finally {
    bulkDeleting.value = false
  }
}

async function toggleMonitoring(client: ClientRow, enabled: boolean) {
  try {
    await $fetch(`/api/clients/${client.id}/monitoring`, {
      method: 'PATCH',
      body: { monitoring_enabled: enabled }
    })
    toast.add({
      title: enabled ? 'Monitoramento ativado' : 'Monitoramento desativado',
      description: client.razao_social,
      color: 'success'
    })
    await refresh()
  } catch (error) {
    toast.add({ title: 'Não foi possível alterar o monitoramento', description: backendMessage(error), color: 'error' })
    await refresh()
  }
}

async function removeClient(client: ClientRow) {
  try {
    await $fetch(`/api/clients/${client.id}`, { method: 'DELETE' })
    toast.add({ title: 'Cliente excluído', description: client.razao_social, color: 'success' })
    deleteOpen.value = false
    deleting.value = null
    await refresh()
  } catch (error) {
    toast.add({ title: 'Não foi possível excluir', description: backendMessage(error), color: 'error' })
  }
}

const schema = z.object({
  cnpj: z.string().regex(/^\d{14}$/, 'CNPJ deve ter 14 dígitos'),
  razao_social: z.string().min(2, 'Informe a razão social'),
  regime: z.string().min(1, 'Informe o regime'),
  contador_responsavel: z.string().min(2, 'Informe o contador responsável')
})

type Schema = z.output<typeof schema>

const open = ref(false)
const saving = ref(false)
const editing = ref<ClientRow | null>(null)
const deleteOpen = ref(false)
const deleting = ref<ClientRow | null>(null)
const form = useTemplateRef('form')

const state = reactive<Schema>({
  cnpj: '',
  razao_social: '',
  regime: 'simples',
  contador_responsavel: ''
})

const formRegimeItems = regimeItems.filter(item => item.value !== 'all')

function openCreate() {
  editing.value = null
  state.cnpj = ''
  state.razao_social = ''
  state.regime = 'simples'
  state.contador_responsavel = ''
  open.value = true
}

function openEdit(client: ClientRow) {
  editing.value = client
  state.cnpj = client.cnpj
  state.razao_social = client.razao_social
  state.regime = client.regime
  state.contador_responsavel = client.contador_responsavel
  open.value = true
}

function askDelete(client: ClientRow) {
  deleting.value = client
  deleteOpen.value = true
}

async function onSubmit(event: FormSubmitEvent<Schema>) {
  saving.value = true
  try {
    if (editing.value) {
      await $fetch(`/api/clients/${editing.value.id}`, { method: 'PATCH', body: event.data })
      toast.add({ title: 'Cliente atualizado', color: 'success' })
    } else {
      await $fetch('/api/clients', { method: 'POST', body: event.data })
      toast.add({ title: 'Cliente criado', color: 'success' })
    }
    open.value = false
    await refresh()
  } catch (error) {
    const fieldErrors = backendFormErrors(error)
    form.value?.setErrors(fieldErrors)
    toast.add({
      title: 'Não foi possível salvar o cliente',
      description: fieldErrors[0]?.message ?? backendMessage(error),
      color: 'error'
    })
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="clients">
    <template #header>
      <UDashboardNavbar title="Clientes">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UButton
            v-if="canWrite"
            label="Novo cliente"
            icon="i-lucide-plus"
            @click="openCreate"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <TablesTableStates
        :error="error"
        error-title="Não foi possível carregar os clientes"
        :error-description="backendMessage(error)"
        @retry="refresh"
      />

      <TablesTableToolbar
        v-model:search="search"
        v-model:filter-value="regimeFilter"
        search-placeholder="Buscar por razão social ou CNPJ…"
        :filter-items="regimeItems"
        filter-placeholder="Filtrar regime"
        :table-api="table?.tableApi"
        :column-labels="CLIENT_COLUMN_LABELS"
        @export="exportCsv"
      >
        <template #bulk>
          <UButton
            v-if="selectedRows.length"
            :label="`Excluir (${selectedRows.length})`"
            color="error"
            variant="subtle"
            icon="i-lucide-trash"
            :loading="bulkDeleting"
            @click="removeSelected"
          />
        </template>
      </TablesTableToolbar>

      <UTable
        ref="table"
        v-model:column-filters="columnFilters"
        v-model:column-visibility="columnVisibility"
        v-model:row-selection="rowSelection"
        v-model:sorting="sorting"
        v-model:pagination="pagination"
        :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
        class="shrink-0"
        :data="clients"
        :columns="columns"
        :loading="status === 'pending'"
        :ui="tableUi"
      >
        <template #empty>
          <TablesTableStates
            empty-title="Nenhum cliente encontrado"
            empty-hint="Ajuste a busca ou o filtro de regime."
          />
        </template>
      </UTable>

      <TablesTableFooter
        :selected="selectedRows.length"
        :total="filteredCount"
        unit="cliente(s)"
        :table-api="table?.tableApi"
        @update:page="(p: number) => table?.tableApi?.setPageIndex(p - 1)"
      />

      <UModal v-model:open="open" :title="editing ? 'Editar cliente' : 'Novo cliente'" description="Dados cadastrais do cliente">
        <template #body>
          <UForm
            ref="form"
            :schema="schema"
            :state="state"
            class="space-y-4"
            @submit="onSubmit"
          >
            <UFormField label="Razão social" name="razao_social" required>
              <UInput v-model="state.razao_social" class="w-full" />
            </UFormField>
            <UFormField label="CNPJ (14 dígitos)" name="cnpj" required>
              <UInput
                v-model="state.cnpj"
                inputmode="numeric"
                maxlength="14"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Regime" name="regime" required>
              <USelect
                v-model="state.regime"
                :items="formRegimeItems"
                value-key="value"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Contador responsável" name="contador_responsavel" required>
              <UInput v-model="state.contador_responsavel" class="w-full" />
            </UFormField>
            <div class="flex justify-end gap-2">
              <UButton
                label="Cancelar"
                color="neutral"
                variant="subtle"
                @click="open = false"
              />
              <UButton
                label="Salvar"
                color="primary"
                variant="solid"
                type="submit"
                :loading="saving"
              />
            </div>
          </UForm>
        </template>
      </UModal>

      <UModal v-model:open="deleteOpen" title="Excluir cliente" description="Esta ação não pode ser desfeita">
        <template #body>
          <p class="text-sm text-muted">
            Excluir <span class="font-medium text-highlighted">{{ deleting?.razao_social }}</span>?
          </p>
          <div class="flex justify-end gap-2 mt-4">
            <UButton
              label="Cancelar"
              color="neutral"
              variant="subtle"
              @click="deleteOpen = false"
            />
            <UButton
              v-if="deleting"
              label="Excluir"
              color="error"
              variant="solid"
              @click="() => removeClient(deleting!)"
            />
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
