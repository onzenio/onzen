<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'
import { upperFirst } from 'scule'
import { getPaginationRowModel } from '@tanstack/table-core'
import type { Row } from '@tanstack/table-core'
import type { CertificateState, RequestAuthor } from '~/types/monitoring'

const UBadge = resolveComponent('UBadge')
const UButton = resolveComponent('UButton')
const UCheckbox = resolveComponent('UCheckbox')
const UDropdownMenu = resolveComponent('UDropdownMenu')

const toast = useToast()

interface AuthorTableApi {
  getFilteredSelectedRowModel: () => { rows: Row<RequestAuthor>[] }
  getFilteredRowModel: () => { rows: Row<RequestAuthor>[] }
  getColumn: (id: string) => { setFilterValue: (value: string | undefined) => void, getFilterValue: () => unknown, toggleVisibility: (value?: boolean) => void } | undefined
  getAllColumns: () => { id: string, getCanHide: () => boolean, getIsVisible: () => boolean }[]
  getState: () => { pagination: { pageIndex: number, pageSize: number } }
  setPageIndex: (index: number) => void
}

const table = useTemplateRef<{ tableApi?: AuthorTableApi | null }>('table')
const columnFilters = ref([{ id: 'name', value: '' }])
const columnVisibility = ref()
const rowSelection = ref<Record<string, boolean>>({})
const sorting = ref<{ id: string, desc: boolean }[]>([])
const pagination = ref({ pageIndex: 0, pageSize: 10 })
const statusFilter = ref('all')

const { data: certificate, refresh: refreshCertificate } = await useFetch<{ data: CertificateState }>('/api/monitoring/certificate', { lazy: true })
const { data: authors, status: authorsStatus, error: authorsError, refresh: refreshAuthors } = await useFetch<{ data: RequestAuthor[] }>('/api/monitoring/authors', { lazy: true })

const authorsRetryActions = [{
  label: 'Tentar novamente',
  color: 'error' as const,
  variant: 'outline' as const,
  onClick: () => refreshAuthors()
}]

const certificateOpen = ref(false)
const certificateErrors = ref<string[]>([])
const certificateSaving = ref(false)
const certificateFile = ref<File | null>(null)
const certificatePassword = ref('')

const removeCertificateOpen = ref(false)
const removingCertificate = ref(false)

const authorOpen = ref(false)
const authorErrors = ref<string[]>([])
const authorSaving = ref(false)
const authorState = reactive({ document: '', name: '' })
const authorSchema = z.object({
  document: z.string().min(11, 'Informe CPF (11) ou CNPJ (14 dígitos).'),
  name: z.string().min(2, 'Informe o nome.')
})
type AuthorSchema = z.output<typeof authorSchema>

const submittingTerm = ref<number | null>(null)
const bulkTerm = ref(false)

const authorRows = computed((): RequestAuthor[] => authors.value?.data ?? [])

function sortableHeader(label: string) {
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

const authorColumns: TableColumn<RequestAuthor>[] = [
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
    accessorKey: 'name',
    header: sortableHeader('Nome'),
    filterFn: (row, _columnId, value) => {
      const term = String(value ?? '').toLowerCase()
      if (!term) return true
      return row.original.name.toLowerCase().includes(term) || (row.original.document ?? '').toLowerCase().includes(term)
    },
    cell: ({ row }) => h('p', { class: 'font-medium text-highlighted' }, row.original.name)
  },
  {
    accessorKey: 'document',
    header: 'Documento',
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, row.original.document ?? '—')
  },
  {
    accessorKey: 'status',
    header: 'Estado',
    filterFn: 'equals',
    cell: ({ row }) => h(UBadge, { color: row.original.status === 'active' ? 'success' : 'neutral', variant: 'subtle' }, () => row.original.status === 'active' ? 'Ativo' : 'Inelegível')
  },
  {
    accessorKey: 'certificate_expires_at',
    header: sortableHeader('Cert. válido até'),
    cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.certificate_expires_at))
  },
  {
    id: 'actions',
    cell: ({ row }) => h('div', { class: 'text-right' }, [
      h(UDropdownMenu, {
        content: { align: 'end' },
        items: [
          { type: 'label' as const, label: 'Ações' },
          { label: 'Enviar termo', icon: 'i-lucide-send', onSelect: () => void submitTerm(row.original.id) },
          {
            label: 'Copiar documento',
            icon: 'i-lucide-copy',
            onSelect: () => {
              navigator.clipboard.writeText(row.original.document ?? '')
              toast.add({ title: 'Documento copiado', color: 'success' })
            }
          }
        ]
      }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações do autor' }))
    ])
  }
]

watch(() => statusFilter.value, (newVal) => {
  const column = table.value?.tableApi?.getColumn('status')
  if (!column) return
  if (newVal === 'all') column.setFilterValue(undefined)
  else column.setFilterValue(newVal)
  pagination.value.pageIndex = 0
})

const search = computed({
  get: (): string => (table.value?.tableApi?.getColumn('name')?.getFilterValue() as string) || '',
  set: (value: string) => {
    table.value?.tableApi?.getColumn('name')?.setFilterValue(value || undefined)
    pagination.value.pageIndex = 0
  }
})

const selectedRows = computed((): Row<RequestAuthor>[] => table.value?.tableApi?.getFilteredSelectedRowModel().rows ?? [])
const filteredCount = computed((): number => table.value?.tableApi?.getFilteredRowModel().rows.length ?? authorRows.value.length)

function exportCsv(): void {
  const list = (selectedRows.value.length > 0 ? selectedRows.value : (table.value?.tableApi?.getFilteredRowModel().rows ?? [])).map((r: Row<RequestAuthor>) => r.original)
  if (list.length === 0) {
    toast.add({ title: 'Nada para exportar', description: 'Ajuste os filtros ou selecione ao menos um autor.', color: 'warning' })
    return
  }
  exportToCsv('autores', list.map(a => ({
    nome: a.name,
    documento: a.document ?? '',
    estado: a.status === 'active' ? 'Ativo' : 'Inelegível',
    certificado_valido_ate: a.certificate_expires_at ?? ''
  })))
  toast.add({ title: 'CSV exportado', description: `${list.length} autor(es) exportado(s).`, color: 'success' })
}

async function submitTermBulk(): Promise<void> {
  const list = selectedRows.value.map((r: Row<RequestAuthor>) => r.original)
  if (list.length === 0) return
  bulkTerm.value = true
  try {
    for (const author of list) {
      await $fetch(`/api/monitoring/authors/${author.id}/term`, { method: 'POST' })
    }
    toast.add({ title: 'Termos enviados', description: `${list.length} termo(s) enviado(s).`, color: 'success' })
    rowSelection.value = {}
    await refreshAuthors()
  } catch (error: unknown) {
    toast.add({ title: 'Envio recusado', description: monitoringErrorMessage(backendErrorBody(error)), color: 'error' })
  } finally {
    bulkTerm.value = false
  }
}

async function submitCertificate() {
  if (!certificateFile.value) {
    certificateErrors.value = ['Selecione o arquivo .pfx/.p12.']
    return
  }
  certificateSaving.value = true
  certificateErrors.value = []
  try {
    const form = new FormData()
    form.append('certificate', certificateFile.value)
    form.append('certificate_password', certificatePassword.value)
    await $fetch('/api/monitoring/certificate', { method: 'POST', body: form })
    toast.add({ title: 'Certificado Digital guardado', color: 'success' })
    certificateOpen.value = false
    certificateFile.value = null
    certificatePassword.value = ''
    await Promise.all([refreshCertificate(), refreshAuthors()])
  } catch (error: unknown) {
    certificateErrors.value = backendValidationMessages(backendErrorBody(error))
  } finally {
    certificateSaving.value = false
  }
}

async function removeCertificate() {
  removingCertificate.value = true
  try {
    await $fetch('/api/monitoring/certificate', { method: 'DELETE' })
    toast.add({ title: 'Certificado removido', color: 'success' })
    removeCertificateOpen.value = false
    await Promise.all([refreshCertificate(), refreshAuthors()])
  } catch (error: unknown) {
    toast.add({ title: 'Não foi possível remover', description: monitoringErrorMessage(backendErrorBody(error)), color: 'error' })
  } finally {
    removingCertificate.value = false
  }
}

async function onAuthorSubmit(event: FormSubmitEvent<AuthorSchema>) {
  authorSaving.value = true
  authorErrors.value = []
  try {
    await $fetch('/api/monitoring/authors', { method: 'POST', body: { ...event.data } })
    toast.add({ title: 'Autor cadastrado', color: 'success' })
    authorOpen.value = false
    authorState.document = ''
    authorState.name = ''
    await refreshAuthors()
  } catch (error: unknown) {
    authorErrors.value = backendValidationMessages(backendErrorBody(error))
  } finally {
    authorSaving.value = false
  }
}

async function submitTerm(authorId: number) {
  submittingTerm.value = authorId
  try {
    const { data } = await $fetch<{ data: { id: number, expires_at: string } }>(`/api/monitoring/authors/${authorId}/term`, { method: 'POST' })
    toast.add({ title: 'Termo enviado', description: `Token válido até ${formatDateTime(data.expires_at)}.`, color: 'success' })
    await refreshAuthors()
  } catch (error: unknown) {
    toast.add({ title: 'Envio recusado', description: monitoringErrorMessage(backendErrorBody(error)), color: 'error' })
  } finally {
    submittingTerm.value = null
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <UCard>
      <template #header>
        <div class="flex items-center justify-between">
          <p class="font-medium text-highlighted">
            Certificado Digital da Account
          </p>
          <div class="flex gap-2">
            <UButton
              v-if="certificate?.data.state === 'present'"
              label="Remover"
              color="error"
              variant="ghost"
              size="sm"
              @click="removeCertificateOpen = true"
            />
            <UButton
              :label="certificate?.data.state === 'present' ? 'Substituir' : 'Cadastrar'"
              variant="outline"
              size="sm"
              @click="certificateOpen = true"
            />
          </div>
        </div>
      </template>
      <div v-if="certificate?.data.state === 'present'">
        <div class="flex flex-wrap items-center gap-2">
          <p class="font-medium text-highlighted">
            {{ certificate.data.holder_name || 'Titular não informado' }}
          </p>
          <UBadge
            :color="certificate.data.expired ? 'error' : 'success'"
            variant="subtle"
          >
            {{ certificate.data.expired ? 'Vencido' : 'Válido' }}
          </UBadge>
        </div>
        <p class="mt-1 text-sm text-muted">
          {{ certificate.data.thumbprint }} · válido até {{ formatDateTime(certificate.data.expires_at) }}
        </p>
      </div>
      <UAlert
        v-else
        color="neutral"
        variant="subtle"
        title="Sem Certificado Digital"
        description="Cadastre o .pfx/.p12 da Account para habilitar autores e assinaturas."
      />
    </UCard>

    <UCard>
      <template #header>
        <div class="flex items-center justify-between">
          <p class="font-medium text-highlighted">
            Autores do Pedido de Dados
          </p>
          <UButton
            label="Novo autor"
            icon="i-lucide-plus"
            size="sm"
            @click="authorOpen = true"
          />
        </div>
      </template>
      <div class="flex flex-col gap-4">
        <UAlert
          v-if="authorsError"
          color="error"
          variant="subtle"
          title="Autores indisponíveis"
          :description="monitoringErrorMessage(backendErrorBody(authorsError))"
          :actions="authorsRetryActions"
        />
        <div class="flex flex-wrap items-center justify-between gap-1.5">
          <UInput
            v-model="search"
            class="max-w-sm"
            icon="i-lucide-search"
            placeholder="Buscar por nome ou documento..."
          />
          <div class="flex flex-wrap items-center gap-1.5">
            <UButton
              v-if="selectedRows.length"
              :label="`Enviar termo (${selectedRows.length})`"
              icon="i-lucide-send"
              :loading="bulkTerm"
              @click="submitTermBulk"
            />
            <UButton
              label="Exportar CSV"
              color="neutral"
              variant="outline"
              icon="i-lucide-download"
              @click="exportCsv"
            />
            <USelect
              v-model="statusFilter"
              :items="[
                { label: 'Todos os estados', value: 'all' },
                { label: 'Ativos', value: 'active' },
                { label: 'Inelegíveis', value: 'ineligible' }
              ]"
              placeholder="Filtrar estado"
              class="min-w-28"
            />
            <UDropdownMenu
              :items="table?.tableApi?.getAllColumns().filter((column: any) => column.getCanHide()).map((column: any) => ({
                label: upperFirst(column.id),
                type: 'checkbox' as const,
                checked: column.getIsVisible(),
                onUpdateChecked(checked: boolean) {
                  table?.tableApi?.getColumn(column.id)?.toggleVisibility(!!checked)
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
          ref="table"
          v-model:column-filters="columnFilters"
          v-model:column-visibility="columnVisibility"
          v-model:row-selection="rowSelection"
          v-model:sorting="sorting"
          v-model:pagination="pagination"
          :pagination-options="{ getPaginationRowModel: getPaginationRowModel() }"
          class="shrink-0"
          :data="authorRows"
          :columns="authorColumns"
          :loading="authorsStatus === 'pending'"
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
                Nenhum autor encontrado
              </p>
              <p class="text-sm text-muted">
                Ajuste a busca ou o filtro de estado.
              </p>
            </div>
          </template>
        </UTable>
        <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
          <div class="text-sm text-muted">
            {{ selectedRows.length }} de {{ filteredCount }} autor(es) selecionado(s).
          </div>
          <UPagination
            :page="(table?.tableApi?.getState().pagination.pageIndex || 0) + 1"
            :items-per-page="table?.tableApi?.getState().pagination.pageSize"
            :total="filteredCount"
            @update:page="(p: number) => table?.tableApi?.setPageIndex(p - 1)"
          />
        </div>
      </div>
      <div
        v-for="author in (authors?.data ?? [])"
        :key="author.id"
        class="mt-2 flex items-center justify-between border-t border-default pt-2"
      >
        <p class="text-sm text-muted">
          Termo de autorização — {{ author.name }}
        </p>
        <UButton
          label="Enviar termo"
          size="xs"
          variant="outline"
          :loading="submittingTerm === author.id"
          @click="submitTerm(author.id)"
        />
      </div>
    </UCard>

    <UModal
      v-model:open="certificateOpen"
      title="Certificado Digital"
      description="O .pfx e a senha vão para o cofre. Só titular, thumbprint mascarado e validade ficam visíveis."
    >
      <template #body>
        <div class="space-y-4">
          <UAlert
            v-if="certificateErrors.length"
            color="error"
            variant="subtle"
            :title="certificateErrors[0]"
            :description="certificateErrors.slice(1).join(' ')"
          />
          <UFormField label="Arquivo .pfx/.p12">
            <UInput
              type="file"
              accept=".pfx,.p12"
              @change="(e: Event) => certificateFile = ((e.target as HTMLInputElement).files?.[0] ?? null)"
            />
          </UFormField>
          <UFormField label="Senha">
            <UInput
              v-model="certificatePassword"
              type="password"
              class="w-full"
            />
          </UFormField>
          <div class="flex justify-end gap-2">
            <UButton
              label="Cancelar"
              color="neutral"
              variant="subtle"
              @click="certificateOpen = false"
            />
            <UButton
              label="Guardar no cofre"
              :loading="certificateSaving"
              @click="submitCertificate"
            />
          </div>
        </div>
      </template>
    </UModal>

    <UModal
      v-model:open="removeCertificateOpen"
      title="Remover Certificado Digital"
      description="Os autores vinculados ficam inelegíveis e o material é esquecido do cofre. Deseja continuar?"
    >
      <template #body>
        <div class="flex justify-end gap-2">
          <UButton
            label="Cancelar"
            color="neutral"
            variant="subtle"
            @click="removeCertificateOpen = false"
          />
          <UButton
            label="Remover"
            color="error"
            :loading="removingCertificate"
            @click="removeCertificate"
          />
        </div>
      </template>
    </UModal>

    <UModal
      v-model:open="authorOpen"
      title="Novo autor"
      description="O autor herda o thumbprint e a validade do Certificado Digital vigente."
    >
      <template #body>
        <UForm
          :schema="authorSchema"
          :state="authorState"
          class="space-y-4"
          @submit="onAuthorSubmit"
        >
          <UAlert
            v-if="authorErrors.length"
            color="error"
            variant="subtle"
            :title="authorErrors[0]"
            :description="authorErrors.slice(1).join(' ')"
          />
          <UFormField
            label="CPF/CNPJ"
            name="document"
          >
            <UInput
              v-model="authorState.document"
              placeholder="Somente dígitos"
              class="w-full"
            />
          </UFormField>
          <UFormField
            label="Nome"
            name="name"
          >
            <UInput
              v-model="authorState.name"
              class="w-full"
            />
          </UFormField>
          <div class="flex justify-end gap-2">
            <UButton
              label="Cancelar"
              color="neutral"
              variant="subtle"
              @click="authorOpen = false"
            />
            <UButton
              label="Cadastrar"
              type="submit"
              :loading="authorSaving"
            />
          </div>
        </UForm>
      </template>
    </UModal>
  </div>
</template>
