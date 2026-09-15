<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'
import { upperFirst } from 'scule'
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'
import type { SerproAdminOverview } from '~/types/monitoring'

definePageMeta({ middleware: ['super-admin'] })

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UCheckbox = resolveComponent('UCheckbox')
const UDropdownMenu = resolveComponent('UDropdownMenu')

const toast = useToast()

interface CredentialRow {
  environment: string
  identifier: string | null
  resolved: boolean
  has_certificate: boolean
  updated_at: string | null
}

interface CredentialTableApi {
  getFilteredSelectedRowModel: () => { rows: Row<CredentialRow>[] }
  getFilteredRowModel: () => { rows: Row<CredentialRow>[] }
  getColumn: (id: string) => { setFilterValue: (value: string | undefined) => void, getFilterValue: () => unknown, toggleVisibility: (value?: boolean) => void } | undefined
  getAllColumns: () => { id: string, getCanHide: () => boolean, getIsVisible: () => boolean }[]
  getState: () => { pagination: { pageIndex: number, pageSize: number } }
  setPageIndex: (index: number) => void
}

const { data: overview, status: overviewStatus, error: overviewError, refresh: refreshOverview } = await useFetch<{ data: SerproAdminOverview }>('/api/admin/serpro', { lazy: true })

const overviewRetryActions = [{
  label: 'Tentar novamente',
  color: 'error' as const,
  variant: 'outline' as const,
  onClick: () => refreshOverview()
}]

const credentialsOpen = ref(false)
const credentialErrors = ref<string[]>([])
const credentialSaving = ref(false)
const credentialCertFile = ref<File | null>(null)
const credentialState = reactive<{
  environment: 'homologacao' | 'producao'
  client_id: string
  consumer_secret: string
  contratante_doc: string
  certificate_password: string
}>({
  environment: 'homologacao',
  client_id: '',
  consumer_secret: '',
  contratante_doc: '',
  certificate_password: ''
})
const credentialSchema = z.object({
  environment: z.enum(['homologacao', 'producao']),
  client_id: z.string().min(1, 'Informe o client_id.'),
  consumer_secret: z.string().min(1, 'Informe o consumer_secret.'),
  contratante_doc: z.string().optional(),
  certificate_password: z.string().optional()
})
type CredentialSchema = z.output<typeof credentialSchema>

const environmentOpen = ref(false)
const environmentErrors = ref<string[]>([])
const environmentSaving = ref(false)
const environmentState = reactive({
  environment: 'homologacao',
  confirm_environment: false,
  confirm_impact: false,
  evidence: ''
})

const transportOpen = ref(false)
const transportErrors = ref<string[]>([])
const transportSaving = ref(false)
const transportState = reactive({
  enabled: false,
  confirm_transport: false,
  confirm_impact: false,
  evidence: ''
})

const environmentBadgeColor = computed(() => overview.value?.data.environment === 'producao' ? 'error' : 'info')

const credentialTable = useTemplateRef<{ tableApi?: CredentialTableApi | null }>('credentialTable')
const credentialFilters = ref([{ id: 'identifier', value: '' }])
const credentialVisibility = ref()
const credentialSelection = ref<Record<string, boolean>>({})
const credentialSorting = ref<{ id: string, desc: boolean }[]>([])
const credentialPagination = ref({ pageIndex: 0, pageSize: 10 })
const credentialValidity = ref('all')

const credentialRows = computed((): CredentialRow[] => overview.value ? Object.entries(overview.value.data.credentials).map(([env, cred]) => ({ environment: env, ...cred })) : [])

function credentialSortable(label: string) {
  return ({ column }: { column: { getIsSorted: () => false | 'asc' | 'desc', toggleSorting: (desc?: boolean) => void } }) => {
    const isSorted = column.getIsSorted()
    return h(UButton, {
      color: 'neutral',
      variant: 'ghost',
      label,
      icon: isSorted ? (isSorted === 'asc' ? 'i-lucide-arrow-up-narrow-wide' : 'i-lucide-arrow-down-wide-narrow') : 'i-lucide-arrow-up-down',
      class: '-mx-2.5',
      onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
    })
  }
}

const credentialColumns: TableColumn<CredentialRow>[] = [
  {
    id: 'select',
    header: ({ table: api }) => h(UCheckbox, {
      'modelValue': api.getIsSomePageRowsSelected() ? 'indeterminate' : api.getIsAllPageRowsSelected(),
      'onUpdate:modelValue': (value: boolean | 'indeterminate') => api.toggleAllPageRowsSelected(!!value),
      'ariaLabel': 'Selecionar todos'
    }),
    cell: ({ row }) => h(UCheckbox, {
      'modelValue': row.getIsSelected(),
      'onUpdate:modelValue': (value: boolean | 'indeterminate') => row.toggleSelected(!!value),
      'ariaLabel': 'Selecionar linha'
    })
  },
  {
    accessorKey: 'environment',
    header: credentialSortable('Ambiente'),
    cell: ({ row }) => h('p', { class: 'font-medium text-highlighted' }, row.original.environment === 'producao' ? 'Produção' : row.original.environment === 'homologacao' ? 'Homologação' : row.original.environment)
  },
  {
    accessorKey: 'identifier',
    header: credentialSortable('Identificador'),
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, row.original.identifier ?? '—')
  },
  {
    accessorKey: 'resolved',
    header: 'Válidas',
    filterFn: (row, _columnId, value) => {
      if (value === undefined || value === 'all') return true
      return String(row.original.resolved) === String(value)
    },
    cell: ({ row }) => h(UBadge, { color: row.original.resolved ? 'success' : 'neutral', variant: 'subtle' }, () => row.original.resolved ? 'Sim' : 'Não')
  },
  {
    accessorKey: 'has_certificate',
    header: 'Certificado mTLS',
    cell: ({ row }) => h(UBadge, { color: row.original.has_certificate ? 'success' : 'neutral', variant: 'subtle' }, () => row.original.has_certificate ? 'Sim' : 'Não')
  },
  {
    accessorKey: 'updated_at',
    header: credentialSortable('Atualizadas em'),
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.updated_at))
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'text-right' }, [
      h(UDropdownMenu, {
        content: { align: 'end' },
        items: [
          { type: 'label' as const, label: 'Ações' },
          {
            label: 'Copiar identificador',
            icon: 'i-lucide-copy',
            onSelect: () => {
              navigator.clipboard.writeText(row.original.identifier ?? '')
              toast.add({ title: 'Identificador copiado', color: 'success' })
            }
          }
        ]
      }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações da credencial' }))
    ])
  }
]

watch(() => credentialValidity.value, (newVal) => {
  const column = credentialTable.value?.tableApi?.getColumn('resolved')
  if (!column) return
  if (newVal === 'all') column.setFilterValue(undefined)
  else column.setFilterValue(newVal)
  credentialPagination.value.pageIndex = 0
})

const credentialSearch = computed({
  get: (): string => (credentialTable.value?.tableApi?.getColumn('identifier')?.getFilterValue() as string) || '',
  set: (value: string) => {
    credentialTable.value?.tableApi?.getColumn('identifier')?.setFilterValue(value || undefined)
    credentialPagination.value.pageIndex = 0
  }
})

const credentialSelected = computed((): Row<CredentialRow>[] => credentialTable.value?.tableApi?.getFilteredSelectedRowModel().rows ?? [])
const credentialFiltered = computed((): number => credentialTable.value?.tableApi?.getFilteredRowModel().rows.length ?? credentialRows.value.length)

function exportCredentials(): void {
  const list = (credentialSelected.value.length > 0 ? credentialSelected.value : (credentialTable.value?.tableApi?.getFilteredRowModel().rows ?? [])).map((r: Row<CredentialRow>) => r.original)
  if (list.length === 0) {
    toast.add({ title: 'Nada para exportar', description: 'Ajuste os filtros de credenciais.', color: 'warning' })
    return
  }
  exportToCsv('credenciais-serpro', list.map(c => ({
    ambiente: c.environment,
    identificador: c.identifier ?? '',
    validas: c.resolved ? 'Sim' : 'Não',
    certificado_mtls: c.has_certificate ? 'Sim' : 'Não',
    atualizadas_em: c.updated_at ?? ''
  })))
  toast.add({ title: 'CSV exportado', description: `${list.length} credencial(is) exportada(s).`, color: 'success' })
}

function readFileAsBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => {
      const result = typeof reader.result === 'string' ? reader.result : ''
      resolve(result.includes(',') ? (result.split(',')[1] ?? '') : result)
    }
    reader.onerror = () => reject(reader.error)
    reader.readAsDataURL(file)
  })
}

async function onCredentialsSubmit(event: FormSubmitEvent<CredentialSchema>) {
  credentialSaving.value = true
  credentialErrors.value = []
  try {
    const payload: Record<string, unknown> = { ...event.data }
    if (credentialCertFile.value) {
      payload.certificate = await readFileAsBase64(credentialCertFile.value)
    }
    await $fetch('/api/admin/serpro/credentials', { method: 'POST', body: payload })
    toast.add({ title: 'Credenciais atualizadas', color: 'success' })
    credentialsOpen.value = false
    credentialState.client_id = ''
    credentialState.consumer_secret = ''
    credentialState.contratante_doc = ''
    credentialState.certificate_password = ''
    credentialCertFile.value = null
    await refreshOverview()
  } catch (error: unknown) {
    credentialErrors.value = backendValidationMessages(backendErrorBody(error))
  } finally {
    credentialSaving.value = false
  }
}

async function submitEnvironment() {
  environmentSaving.value = true
  environmentErrors.value = []
  try {
    await $fetch('/api/admin/serpro/environment', { method: 'POST', body: { ...environmentState } })
    toast.add({ title: 'Ambiente atualizado', color: 'success' })
    environmentOpen.value = false
    await refreshOverview()
  } catch (error: unknown) {
    environmentErrors.value = backendValidationMessages(backendErrorBody(error))
  } finally {
    environmentSaving.value = false
  }
}

async function submitTransport() {
  transportSaving.value = true
  transportErrors.value = []
  try {
    await $fetch('/api/admin/serpro/transport', { method: 'POST', body: { ...transportState } })
    toast.add({ title: 'Transporte atualizado', color: 'success' })
    transportOpen.value = false
    await refreshOverview()
  } catch (error: unknown) {
    transportErrors.value = backendValidationMessages(backendErrorBody(error))
  } finally {
    transportSaving.value = false
  }
}

function openEnvironment() {
  environmentState.environment = overview.value?.data.environment ?? 'homologacao'
  environmentState.confirm_environment = false
  environmentState.confirm_impact = false
  environmentState.evidence = ''
  environmentErrors.value = []
  environmentOpen.value = true
}

function openTransport() {
  transportState.enabled = overview.value?.data.transport.open ?? false
  transportState.confirm_transport = false
  transportState.confirm_impact = false
  transportState.evidence = ''
  transportErrors.value = []
  transportOpen.value = true
}
</script>

<template>
  <UDashboardPanel id="monitoring-admin">
    <template #header>
      <UDashboardNavbar title="Administração SERPRO">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="flex flex-col gap-4">
        <div class="grid gap-4 sm:grid-cols-3">
          <UCard>
            <p class="text-sm text-muted">
              Ambiente vigente
            </p>
            <div class="mt-2 flex items-center gap-2">
              <UBadge
                :color="environmentBadgeColor"
                variant="subtle"
              >
                {{ overview ? (overview.data.environment === 'producao' ? 'Produção' : 'Homologação') : 'Carregando…' }}
              </UBadge>
            </div>
            <UButton
              label="Trocar ambiente"
              variant="outline"
              size="sm"
              class="mt-3"
              @click="openEnvironment"
            />
          </UCard>

          <UCard>
            <p class="text-sm text-muted">
              Transporte
            </p>
            <div class="mt-2 flex items-center gap-2">
              <UBadge
                :color="overview?.data.transport.open ? 'success' : 'neutral'"
                variant="subtle"
              >
                {{ overview ? (overview.data.transport.open ? 'Ligado' : 'Desligado') : 'Carregando…' }}
              </UBadge>
              <UBadge
                v-if="overview?.data.transport.dry_run"
                color="info"
                variant="subtle"
              >
                Dry-run
              </UBadge>
            </div>
            <UButton
              :label="overview?.data.transport.open ? 'Desligar transporte' : 'Ligar transporte'"
              variant="outline"
              size="sm"
              class="mt-3"
              @click="openTransport"
            />
          </UCard>

          <UCard>
            <p class="text-sm text-muted">
              Credenciais do Contratante
            </p>
            <p class="mt-2 text-2xl font-semibold text-highlighted">
              v{{ overview?.data.credential_version ?? '—' }}
            </p>
            <UButton
              label="Atualizar credenciais"
              variant="outline"
              size="sm"
              class="mt-3"
              @click="credentialsOpen = true"
            />
          </UCard>
        </div>

        <UAlert
          v-if="overviewError"
          color="error"
          variant="subtle"
          title="Credenciais indisponíveis"
          :description="monitoringErrorMessage(backendErrorBody(overviewError))"
          :actions="overviewRetryActions"
        />

        <UCard>
          <template #header>
            <p class="font-medium text-highlighted">
              Credenciais por ambiente
            </p>
          </template>
          <div class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-1.5">
              <UInput
                v-model="credentialSearch"
                class="max-w-sm"
                icon="i-lucide-search"
                placeholder="Filtrar por identificador..."
              />
              <div class="flex flex-wrap items-center gap-1.5">
                <UButton
                  label="Exportar CSV"
                  color="neutral"
                  variant="outline"
                  icon="i-lucide-download"
                  @click="exportCredentials"
                />
                <USelect
                  v-model="credentialValidity"
                  :items="[
                    { label: 'Todas', value: 'all' },
                    { label: 'Válidas', value: 'true' },
                    { label: 'Inválidas', value: 'false' }
                  ]"
                  placeholder="Filtrar validade"
                  class="min-w-28"
                />
                <UDropdownMenu
                  :items="credentialTable?.tableApi?.getAllColumns().filter((column: any) => column.getCanHide()).map((column: any) => ({
                    label: upperFirst(column.id),
                    type: 'checkbox' as const,
                    checked: column.getIsVisible(),
                    onUpdateChecked(checked: boolean) {
                      credentialTable?.tableApi?.getColumn(column.id)?.toggleVisibility(!!checked)
                    },
                    onSelect(e?: Event) {
                      e?.preventDefault()
                    }
                  }))"
                  :content="{ align: 'end' }"
                >
                  <UButton
                    label="Exibir"
                    color="neutral"
                    variant="outline"
                    trailing-icon="i-lucide-settings-2"
                  />
                </UDropdownMenu>
              </div>
            </div>
            <UTable
              ref="credentialTable"
              v-model:column-filters="credentialFilters"
              v-model:column-visibility="credentialVisibility"
              v-model:row-selection="credentialSelection"
              v-model:sorting="credentialSorting"
              v-model:pagination="credentialPagination"
              :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
              class="shrink-0"
              :data="credentialRows"
              :columns="credentialColumns"
              :loading="overviewStatus === 'pending'"
              :ui="{
                base: 'table-fixed border-separate border-spacing-0',
                thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
                tbody: '[&>tr]:last:[&>td]:border-b-0',
                th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
                td: 'border-b border-default',
                separator: 'h-0'
              }"
            >
              <template #empty>
                <div class="flex flex-col items-center justify-center gap-2 py-8 text-center">
                  <p class="font-medium text-highlighted">
                    Nenhuma credencial encontrada
                  </p>
                  <p class="text-sm text-muted">
                    Ajuste a busca ou o filtro de validade.
                  </p>
                </div>
              </template>
            </UTable>
            <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
              <div class="text-sm text-muted">
                {{ credentialSelected.length }} de {{ credentialFiltered }} credencial(is) selecionada(s).
              </div>
              <UPagination
                :page="(credentialTable?.tableApi?.getState().pagination.pageIndex || 0) + 1"
                :items-per-page="credentialTable?.tableApi?.getState().pagination.pageSize"
                :total="credentialFiltered"
                @update:page="(p: number) => credentialTable?.tableApi?.setPageIndex(p - 1)"
              />
            </div>
          </div>
          <p class="mt-2 text-xs text-muted">
            Identificadores mascarados. Segredos nunca são exibidos.
          </p>
        </UCard>

        <MonitoringCertificateManager />
      </div>

      <UModal
        v-model:open="credentialsOpen"
        title="Atualizar credenciais"
        description="As credenciais anteriores são descartadas do cofre e o token é renovado."
      >
        <template #body>
          <UForm
            :schema="credentialSchema"
            :state="credentialState"
            class="space-y-4"
            @submit="onCredentialsSubmit"
          >
            <UAlert
              v-if="credentialErrors.length"
              color="error"
              variant="subtle"
              :title="credentialErrors[0]"
              :description="credentialErrors.slice(1).join(' ')"
            />
            <UFormField
              label="Ambiente"
              name="environment"
            >
              <USelect
                v-model="credentialState.environment"
                :items="[{ label: 'Homologação', value: 'homologacao' }, { label: 'Produção', value: 'producao' }]"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Client ID (e-CNPJ)"
              name="client_id"
            >
              <UInput
                v-model="credentialState.client_id"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Consumer secret"
              name="consumer_secret"
            >
              <UInput
                v-model="credentialState.consumer_secret"
                type="password"
                class="w-full"
              />
            </UFormField>
            <UFormField
              label="Documento do contratante"
              name="contratante_doc"
            >
              <UInput
                v-model="credentialState.contratante_doc"
                class="w-full"
              />
            </UFormField>
            <UFormField label="Certificado mTLS (.pfx)">
              <UInput
                type="file"
                accept=".pfx,.p12"
                @change="(e: Event) => credentialCertFile = ((e.target as HTMLInputElement).files?.[0] ?? null)"
              />
            </UFormField>
            <UFormField
              label="Senha do certificado mTLS"
              name="certificate_password"
            >
              <UInput
                v-model="credentialState.certificate_password"
                type="password"
                class="w-full"
              />
            </UFormField>
            <div class="flex justify-end gap-2">
              <UButton
                label="Cancelar"
                color="neutral"
                variant="subtle"
                @click="credentialsOpen = false"
              />
              <UButton
                label="Salvar"
                type="submit"
                :loading="credentialSaving"
              />
            </div>
          </UForm>
        </template>
      </UModal>

      <UModal
        v-model:open="environmentOpen"
        title="Trocar ambiente"
        description="Produção exige dupla confirmação e evidência registrada em Audit."
      >
        <template #body>
          <div class="space-y-4">
            <UAlert
              v-if="environmentErrors.length"
              color="error"
              variant="subtle"
              :title="environmentErrors[0]"
              :description="environmentErrors.slice(1).join(' ')"
            />
            <UFormField label="Ambiente">
              <USelect
                v-model="environmentState.environment"
                :items="[{ label: 'Homologação', value: 'homologacao' }, { label: 'Produção', value: 'producao' }]"
                class="w-full"
              />
            </UFormField>
            <template v-if="environmentState.environment === 'producao'">
              <UCheckbox
                v-model="environmentState.confirm_environment"
                label="Confirmo a troca para produção"
              />
              <UCheckbox
                v-model="environmentState.confirm_impact"
                label="Estou ciente do impacto em tráfego real"
              />
              <UFormField label="Evidência">
                <UTextarea
                  v-model="environmentState.evidence"
                  placeholder="Motivo e referência da troca…"
                  class="w-full"
                />
              </UFormField>
            </template>
            <div class="flex justify-end gap-2">
              <UButton
                label="Cancelar"
                color="neutral"
                variant="subtle"
                @click="environmentOpen = false"
              />
              <UButton
                label="Aplicar"
                :loading="environmentSaving"
                @click="submitEnvironment"
              />
            </div>
          </div>
        </template>
      </UModal>

      <UModal
        v-model:open="transportOpen"
        title="Transporte SERPRO"
        description="Desligar é imediato. Ligar em produção exige dupla confirmação e evidência."
      >
        <template #body>
          <div class="space-y-4">
            <UAlert
              v-if="transportErrors.length"
              color="error"
              variant="subtle"
              :title="transportErrors[0]"
              :description="transportErrors.slice(1).join(' ')"
            />
            <UFormField label="Estado">
              <USwitch
                v-model="transportState.enabled"
                label="Transporte ligado"
              />
            </UFormField>
            <template v-if="transportState.enabled && overview?.data.environment === 'producao'">
              <UCheckbox
                v-model="transportState.confirm_transport"
                label="Confirmo ligar o transporte em produção"
              />
              <UCheckbox
                v-model="transportState.confirm_impact"
                label="Estou ciente do impacto em tráfego real"
              />
              <UFormField label="Evidência">
                <UTextarea
                  v-model="transportState.evidence"
                  placeholder="Motivo e referência…"
                  class="w-full"
                />
              </UFormField>
            </template>
            <div class="flex justify-end gap-2">
              <UButton
                label="Cancelar"
                color="neutral"
                variant="subtle"
                @click="transportOpen = false"
              />
              <UButton
                label="Aplicar"
                :loading="transportSaving"
                @click="submitTransport"
              />
            </div>
          </div>
        </template>
      </UModal>
    </template>
  </UDashboardPanel>
</template>
