<script setup lang="ts">
definePageMeta({ middleware: 'super-admin', title: 'Administração SERPRO' })

const toast = useToast()

interface SerproAdmin {
  environment: string
  contractor_document_masked: string | null
  consumer_key_masked: string | null
  consumer_secret_masked: string | null
  transport_approved: boolean
  dry_run: boolean
  health: { status: string, environment: string, reason: string | null }
}

interface CertificateState {
  configured: boolean
  holder_name?: string
  thumbprint?: string
  expires_at?: string
  expired?: boolean
}

interface Author {
  document: string
  name: string
  status: string
  eligible: boolean
  certificate_thumbprint: string | null
}

const { data: admin, refresh: refreshAdmin } = await useFetch<SerproAdmin>('/api/admin/serpro', { key: 'serpro-admin' })
const { data: certificate, refresh: refreshCert } = await useFetch<CertificateState>('/api/account/certificate', { key: 'serpro-cert' })
const { data: authorsData, refresh: refreshAuthors } = await useFetch<{ data: Author[] }>('/api/monitoring/authors', { key: 'serpro-authors' })

const authors = computed(() => authorsData.value?.data ?? [])

const credForm = reactive({ consumer_key: '', consumer_secret: '', contractor_document: '' })
const envForm = reactive({ environment: 'homologacao', confirmed: false, evidence: '' })
const transportForm = reactive({ approved: false, confirmed: false, evidence: '' })
const authorForm = reactive({ document: '', name: '' })
const certForm = reactive({ password: '', holder_name: '', thumbprint: '', expires_at: '', file: null as File | null })

async function saveCredentials() {
  try {
    await $fetch('/api/admin/serpro/credentials', {
      method: 'PUT',
      body: {
        consumer_key: credForm.consumer_key,
        consumer_secret: credForm.consumer_secret,
        contractor_document: credForm.contractor_document || undefined
      }
    })
    toast.add({ title: 'Credenciais atualizadas', color: 'success' })
    credForm.consumer_key = ''
    credForm.consumer_secret = ''
    await refreshAdmin()
  } catch (error) {
    toast.add({ title: 'Não foi possível salvar', description: backendMessage(error), color: 'error' })
  }
}

async function switchEnvironment() {
  try {
    await $fetch('/api/admin/serpro/environment', {
      method: 'POST',
      body: { ...envForm }
    })
    toast.add({ title: 'Ambiente alternado', color: 'success' })
    await refreshAdmin()
  } catch (error) {
    toast.add({ title: 'Alternância recusada', description: backendMessage(error), color: 'error' })
  }
}

async function switchTransport() {
  try {
    await $fetch('/api/admin/serpro/transport', {
      method: 'POST',
      body: { ...transportForm }
    })
    toast.add({ title: 'Transporte atualizado', color: 'success' })
    await refreshAdmin()
  } catch (error) {
    toast.add({ title: 'Operação recusada', description: backendMessage(error), color: 'error' })
  }
}

async function createAuthor() {
  try {
    await $fetch('/api/monitoring/authors', { method: 'POST', body: { ...authorForm } })
    toast.add({ title: 'Autor cadastrado', color: 'success' })
    authorForm.document = ''
    authorForm.name = ''
    await refreshAuthors()
  } catch (error) {
    toast.add({ title: 'Não foi possível cadastrar', description: backendMessage(error), color: 'error' })
  }
}

function readFileAsBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => {
      const result = String(reader.result ?? '')
      resolve(result.includes(',') ? result.split(',')[1]! : result)
    }
    reader.onerror = () => reject(new Error('Falha ao ler o arquivo.'))
    reader.readAsDataURL(file)
  })
}

async function uploadCertificate(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) {
    return
  }
  try {
    const pfxBase64 = await readFileAsBase64(file)
    await $fetch('/api/account/certificate', {
      method: 'PUT',
      body: {
        pfx_base64: pfxBase64,
        password: certForm.password,
        holder_name: certForm.holder_name,
        thumbprint: certForm.thumbprint,
        expires_at: certForm.expires_at
      }
    })
    toast.add({ title: 'Certificado cadastrado', color: 'success' })
    await refreshCert()
    await refreshAuthors()
  } catch (error) {
    toast.add({ title: 'Não foi possível cadastrar o certificado', description: backendMessage(error), color: 'error' })
  }
}
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-xl font-semibold">
        Administração SERPRO
      </h1>
      <p class="text-sm text-muted">
        Credenciais do Contratante, ambiente, transporte, Certificado Digital e autores — Account A.
      </p>
    </div>

    <UCard>
      <template #header>
        <div class="flex items-center justify-between">
          <span class="font-semibold">Estado atual</span>
          <UBadge :color="admin?.health.status === 'configured' ? 'success' : 'warning'" variant="subtle">
            {{ admin?.health.status ?? '—' }}
          </UBadge>
        </div>
      </template>
      <dl class="grid gap-2 text-sm sm:grid-cols-2">
        <div>
          <dt class="text-muted">
            Ambiente
          </dt><dd>{{ admin?.environment }}</dd>
        </div>
        <div>
          <dt class="text-muted">
            Contratante
          </dt><dd>{{ admin?.contractor_document_masked ?? 'não configurado' }}</dd>
        </div>
        <div>
          <dt class="text-muted">
            Consumer key
          </dt><dd>{{ admin?.consumer_key_masked ?? 'não configurada' }}</dd>
        </div>
        <div>
          <dt class="text-muted">
            Transporte
          </dt><dd>{{ admin?.transport_approved ? 'ligado' : 'desligado' }} · dry-run {{ admin?.dry_run ? 'ativo' : 'inativo' }}</dd>
        </div>
      </dl>
      <p v-if="admin?.health.reason" class="mt-2 text-sm text-muted">
        {{ admin.health.reason }}
      </p>
    </UCard>

    <UCard>
      <template #header>
        <span class="font-semibold">Credenciais do Contratante</span>
      </template>
      <form class="grid gap-2 sm:grid-cols-3" @submit.prevent="() => void saveCredentials()">
        <UInput v-model="credForm.consumer_key" placeholder="Consumer key" />
        <UInput v-model="credForm.consumer_secret" type="password" placeholder="Consumer secret" />
        <UInput v-model="credForm.contractor_document" placeholder="CNPJ do contratante" />
        <UButton type="submit" label="Salvar (audita a troca)" class="sm:col-span-3 w-fit" />
      </form>
      <p class="mt-2 text-xs text-muted">
        Segredos nunca são exibidos: somente identificadores mascarados.
      </p>
    </UCard>

    <div class="grid gap-4 lg:grid-cols-2">
      <UCard>
        <template #header>
          <span class="font-semibold">Ambiente</span>
        </template>
        <div class="grid gap-2">
          <USelect v-model="envForm.environment" :items="[{ label: 'Homologação', value: 'homologacao' }, { label: 'Produção', value: 'producao' }]" />
          <UInput v-model="envForm.evidence" placeholder="Evidência (obrigatória p/ produção)" />
          <label class="flex items-center gap-2 text-sm"><input v-model="envForm.confirmed" type="checkbox"> Confirmo a alternância</label>
          <UButton label="Alternar ambiente" class="w-fit" @click="() => void switchEnvironment()" />
          <p class="text-xs text-muted">
            Produção exige confirmação + evidência; troca de ambiente desliga o transporte.
          </p>
        </div>
      </UCard>

      <UCard>
        <template #header>
          <span class="font-semibold">Transporte</span>
        </template>
        <div class="grid gap-2">
          <USelect v-model="transportForm.approved" :items="[{ label: 'Ligar', value: true }, { label: 'Desligar', value: false }]" />
          <UInput v-model="transportForm.evidence" placeholder="Evidência (obrigatória p/ religar em produção)" />
          <label class="flex items-center gap-2 text-sm"><input v-model="transportForm.confirmed" type="checkbox"> Confirmo a operação</label>
          <UButton label="Aplicar" class="w-fit" @click="() => void switchTransport()" />
          <p class="text-xs text-muted">
            Desligar é imediato. Religar em produção exige confirmação + evidência.
          </p>
        </div>
      </UCard>
    </div>

    <UCard>
      <template #header>
        <span class="font-semibold">Certificado Digital da Account</span>
      </template>
      <div v-if="certificate?.configured" class="text-sm">
        <p>Titular: {{ certificate.holder_name }}</p>
        <p>
          Validade: {{ certificate.expires_at }} <UBadge v-if="certificate.expired" color="error" variant="subtle">
            expirado
          </UBadge>
        </p>
      </div>
      <p v-else class="text-sm text-muted">
        Nenhum certificado cadastrado.
      </p>
      <div class="mt-2 grid gap-2 sm:grid-cols-2">
        <input
          type="file"
          accept=".pfx,.p12"
          class="text-sm"
          @change="(e) => void uploadCertificate(e)"
        >
        <UInput v-model="certForm.password" type="password" placeholder="Senha do PFX" />
        <UInput v-model="certForm.holder_name" placeholder="Titular" />
        <UInput v-model="certForm.thumbprint" placeholder="Thumbprint" />
        <UInput v-model="certForm.expires_at" type="date" />
      </div>
    </UCard>

    <UCard>
      <template #header>
        <span class="font-semibold">Autores do Pedido de Dados</span>
      </template>
      <ul class="space-y-2 text-sm">
        <li v-for="author in authors" :key="author.document" class="flex items-center gap-2">
          <UBadge :color="author.eligible ? 'success' : 'error'" variant="subtle">
            {{ author.eligible ? 'Elegível' : 'Inelegível' }}
          </UBadge>
          {{ author.name }} — {{ author.document }}
        </li>
        <li v-if="authors.length === 0" class="text-muted">
          Nenhum autor cadastrado.
        </li>
      </ul>
      <form class="mt-2 flex flex-wrap gap-2" @submit.prevent="() => void createAuthor()">
        <UInput v-model="authorForm.document" placeholder="CPF/CNPJ" class="w-44" />
        <UInput v-model="authorForm.name" placeholder="Nome" class="w-64" />
        <UButton type="submit" label="Cadastrar autor" />
      </form>
    </UCard>
  </div>
</template>
