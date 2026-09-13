<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'

interface ClientRow {
  id: number
  cnpj: string
  razao_social: string
  regime: string
  contador_responsavel: string
  monitoring_enabled: boolean
}

interface ClientsResponse {
  data: ClientRow[]
  meta: { current_page: number, last_page: number, per_page: number, total: number }
}

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const USwitch = resolveComponent('USwitch')

const toast = useToast()
const { can } = usePermissions()
const canWrite = computed(() => can('clients.write'))

const search = ref('')
const regime = ref('all')
const page = ref(1)

const query = computed(() => ({
  search: search.value || undefined,
  regime: regime.value === 'all' ? undefined : regime.value,
  page: page.value
}))

const { data, status, refresh } = await useFetch<ClientsResponse>('/api/clients', {
  key: 'clients-list',
  query
})

const clients = computed(() => data.value?.data ?? [])
const total = computed(() => data.value?.meta.total ?? 0)

watch([search, regime], () => {
  page.value = 1
})

let searchTimer: ReturnType<typeof setTimeout> | null = null
watch(search, () => {
  if (searchTimer) {
    clearTimeout(searchTimer)
  }
  searchTimer = setTimeout(() => {
    void refresh()
  }, 400)
})
watch(regime, () => {
  void refresh()
})

const regimeItems = [
  { label: 'Todos os regimes', value: 'all' },
  { label: 'Simples Nacional', value: 'simples' },
  { label: 'Lucro Presumido', value: 'presumido' },
  { label: 'Lucro Real', value: 'real' },
  { label: 'MEI', value: 'mei' }
]

function regimeLabel(value: string): string {
  return regimeItems.find(item => item.value === value)?.label ?? value
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

const columns: TableColumn<ClientRow>[] = [
  { accessorKey: 'razao_social', header: 'Razão social' },
  { accessorKey: 'cnpj', header: 'CNPJ' },
  {
    accessorKey: 'regime',
    header: 'Regime',
    cell: ({ row }) => regimeLabel(row.original.regime)
  },
  { accessorKey: 'contador_responsavel', header: 'Contador' },
  {
    id: 'monitoring',
    header: 'Monitoramento',
    cell: ({ row }) => {
      const client = row.original
      if (!canWrite.value) {
        return h(UBadge, { variant: 'subtle', color: client.monitoring_enabled ? 'success' : 'neutral' }, () => client.monitoring_enabled ? 'Ativo' : 'Inativo')
      }
      return h(USwitch, {
        'modelValue': client.monitoring_enabled,
        'onUpdate:modelValue': (enabled: boolean) => void toggleMonitoring(client, enabled)
      })
    }
  },
  {
    id: 'actions',
    cell: ({ row }) => {
      if (!canWrite.value) {
        return null
      }
      return h('div', { class: 'flex justify-end gap-1' }, [
        h(UButton, {
          icon: 'i-lucide-pencil',
          color: 'neutral',
          variant: 'ghost',
          onClick: () => openEdit(row.original)
        }),
        h(UButton, {
          icon: 'i-lucide-trash',
          color: 'error',
          variant: 'ghost',
          onClick: () => askDelete(row.original)
        })
      ])
    }
  }
]
</script>

<template>
  <UDashboardPanel id="clients">
    <template #header>
      <UDashboardNavbar title="Clients">
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
      <div class="flex flex-wrap items-center justify-between gap-1.5">
        <UInput
          v-model="search"
          class="max-w-sm"
          icon="i-lucide-search"
          placeholder="Buscar por razão social ou CNPJ..."
        />

        <USelect
          v-model="regime"
          :items="regimeItems"
          value-key="value"
          placeholder="Filtrar regime"
          class="min-w-28"
        />
      </div>

      <UTable
        :data="clients"
        :columns="columns"
        :loading="status === 'pending'"
      />

      <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
        <div class="text-sm text-muted">
          {{ total }} cliente(s).
        </div>

        <UPagination
          v-model:page="page"
          :items-per-page="data?.meta.per_page ?? 15"
          :total="total"
          @update:page="() => refresh()"
        />
      </div>

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
