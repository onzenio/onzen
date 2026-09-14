<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { CertificateState, RequestAuthor, SerproAdminOverview } from '~/types/monitoring'

definePageMeta({ middleware: ['super-admin'] })

const toast = useToast()

const { data: overview, refresh: refreshOverview } = await useFetch<{ data: SerproAdminOverview }>('/api/admin/serpro', { lazy: true })
const { data: certificate, refresh: refreshCertificate } = await useFetch<{ data: CertificateState }>('/api/monitoring/certificate', { lazy: true })
const { data: authors, refresh: refreshAuthors } = await useFetch<{ data: RequestAuthor[] }>('/api/monitoring/authors', { lazy: true })

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

const environmentBadgeColor = computed(() => overview.value?.data.environment === 'producao' ? 'error' : 'info')

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

        <UCard>
          <template #header>
            <p class="font-medium text-highlighted">
              Credenciais por ambiente
            </p>
          </template>
          <UTable
            :data="overview ? Object.entries(overview.data.credentials).map(([env, cred]) => ({ environment: env, ...cred })) : []"
            :columns="[
              { accessorKey: 'environment', header: 'Ambiente' },
              { accessorKey: 'identifier', header: 'Identificador', cell: ({ row }) => row.original.identifier ?? '—' },
              { accessorKey: 'resolved', header: 'Válidas', cell: ({ row }) => row.original.resolved ? 'Sim' : 'Não' },
              { accessorKey: 'has_certificate', header: 'Certificado mTLS', cell: ({ row }) => row.original.has_certificate ? 'Sim' : 'Não' },
              { accessorKey: 'updated_at', header: 'Atualizadas em', cell: ({ row }) => formatDateTime(row.original.updated_at) }
            ]"
          />
          <p class="mt-2 text-xs text-muted">
            Identificadores mascarados. Segredos nunca são exibidos.
          </p>
        </UCard>

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
          <UTable
            :data="authors?.data ?? []"
            :columns="[
              { accessorKey: 'name', header: 'Nome' },
              { accessorKey: 'document', header: 'Documento', cell: ({ row }) => row.original.document ?? '—' },
              { accessorKey: 'status', header: 'Estado', cell: ({ row }) => row.original.status === 'active' ? 'Ativo' : 'Inelegível' },
              { accessorKey: 'certificate_expires_at', header: 'Cert. válido até', cell: ({ row }) => formatDateTime(row.original.certificate_expires_at) }
            ]"
          />
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
    </template>
  </UDashboardPanel>
</template>
