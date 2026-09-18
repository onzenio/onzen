<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { getPaginationRowModel } from '@tanstack/table-core'
import { TABLE_UI as tableUi, type TableApi } from '~/utils/table-chrome'
import { useTableCsv } from '~/composables/tables/useTableCsv'
import { useTableState } from '~/composables/tables/useTableState'
import { buildAccountColumns, ACCOUNT_COLUMN_LABELS, profileItems, profileLabel, type AccountRow } from '~/components/tables/accounts/columns'

definePageMeta({ middleware: 'super-admin' })

interface AccountsResponse {
  data: AccountRow[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

const toast = useToast()

const table = useTemplateRef<{ tableApi?: TableApi<AccountRow> | null }>('table')
const {
  columnFilters,
  columnVisibility,
  rowSelection,
  sorting,
  pagination,
  search,
  filterValue: profileFilter,
  selectedRows,
  filteredCount
} = useTableState<AccountRow>(() => table.value?.tableApi, {
  searchColumn: 'name',
  filterColumn: 'profile',
  getTotal: () => accounts.value.length
})
const { exportCsv: exportTableCsv } = useTableCsv<AccountRow>(() => table.value?.tableApi)

const { data, error, status, refresh } = await useFetch<AccountsResponse>('/api/accounts', {
  key: 'accounts-list',
  query: { per_page: 500 }
})

const accounts = computed(() => data.value?.data ?? [])

const columns = buildAccountColumns()

function exportCsv() {
  exportTableCsv('contas', (a: AccountRow) => ({
    id: a.id,
    nome: a.name,
    tipo: profileLabel(a.profile),
    plano: a.plan?.name ?? ''
  }), {
    emptyDescription: 'Ajuste os filtros ou selecione ao menos uma conta.',
    exportedUnit: 'conta(s) exportada(s)'
  })
}

const schema = z.object({
  name: z.string().min(2, 'Informe o nome da conta'),
  admin_name: z.string().optional(),
  admin_email: z.string().email('Informe um e-mail válido')
})

type Schema = z.output<typeof schema>

const open = ref(false)
const creating = ref(false)
const form = useTemplateRef('form')

const state = reactive<Partial<Schema>>({
  name: '',
  admin_name: '',
  admin_email: ''
})

function resetState() {
  state.name = ''
  state.admin_name = ''
  state.admin_email = ''
}

watch(open, (value) => {
  if (!value) {
    resetState()
    form.value?.clear()
  }
})

async function onSubmit(event: FormSubmitEvent<Schema>) {
  if (creating.value) {
    return
  }
  creating.value = true
  try {
    await $fetch('/api/accounts', { method: 'POST', body: event.data })
    toast.add({ title: 'Conta criada', description: `Convite enviado para ${event.data.admin_email}.`, color: 'success' })
    open.value = false
    await refresh()
  } catch (error) {
    form.value?.setErrors(backendFormErrors(error))
    toast.add({ title: 'Não foi possível criar a conta', description: backendMessage(error), color: 'error' })
  } finally {
    creating.value = false
  }
}
</script>

<template>
  <UDashboardPanel id="accounts">
    <template #header>
      <UDashboardNavbar title="Escritórios">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>

        <template #right>
          <UModal v-model:open="open" title="Novo escritório" description="Cria um escritório (Account B) e envia o convite ao administrador">
            <UButton label="Novo escritório" icon="i-lucide-plus" />

            <template #body>
              <UForm
                ref="form"
                :schema="schema"
                :state="state"
                class="space-y-4"
                @submit="onSubmit"
              >
                <UFormField label="Nome da conta" name="name" required>
                  <UInput v-model="state.name" class="w-full" />
                </UFormField>
                <UFormField label="Nome do administrador" name="admin_name">
                  <UInput v-model="state.admin_name" class="w-full" />
                </UFormField>
                <UFormField label="E-mail do administrador" name="admin_email" required>
                  <UInput v-model="state.admin_email" type="email" class="w-full" />
                </UFormField>
                <div class="flex justify-end gap-2">
                  <UButton
                    label="Cancelar"
                    color="neutral"
                    variant="subtle"
                    @click="open = false"
                  />
                  <UButton
                    label="Criar"
                    color="primary"
                    variant="solid"
                    type="submit"
                    :loading="creating"
                  />
                </div>
              </UForm>
            </template>
          </UModal>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <TablesTableStates
        :error="error"
        error-title="Não foi possível carregar as contas"
        :error-description="backendMessage(error)"
        @retry="refresh"
      />

      <div class="flex flex-col gap-4">
        <TablesTableToolbar
          v-model:search="search"
          v-model:filter-value="profileFilter"
          search-placeholder="Buscar por nome…"
          :filter-items="profileItems"
          filter-placeholder="Filtrar tipo"
          :table-api="table?.tableApi"
          :column-labels="ACCOUNT_COLUMN_LABELS"
          @export="exportCsv"
        />

        <UTable
          ref="table"
          v-model:column-filters="columnFilters"
          v-model:column-visibility="columnVisibility"
          v-model:row-selection="rowSelection"
          v-model:sorting="sorting"
          v-model:pagination="pagination"
          :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
          class="shrink-0"
          :data="accounts"
          :columns="columns"
          :loading="status === 'pending'"
          :ui="tableUi"
        >
          <template #empty>
            <TablesTableStates
              empty-title="Nenhuma conta encontrada"
              empty-hint="Ajuste a busca ou o filtro de tipo."
            />
          </template>
        </UTable>

        <TablesTableFooter
          :selected="selectedRows.length"
          :total="filteredCount"
          unit="conta(s)"
          feminine
          :table-api="table?.tableApi"
          @update:page="(p: number) => table?.tableApi?.setPageIndex(p - 1)"
        />
      </div>
    </template>
  </UDashboardPanel>
</template>
