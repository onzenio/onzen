<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import type { CertificateState, RequestAuthor } from '~/types/monitoring'

const toast = useToast()

const { data: certificate, refresh: refreshCertificate } = await useFetch<{ data: CertificateState }>('/api/monitoring/certificate', { lazy: true })
const { data: authors, refresh: refreshAuthors } = await useFetch<{ data: RequestAuthor[] }>('/api/monitoring/authors', { lazy: true })

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
