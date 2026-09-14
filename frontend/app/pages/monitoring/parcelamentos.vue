<script setup lang="ts">
definePageMeta({ title: 'Parcelamentos' })

const toast = useToast()

interface Installment {
  id: number
  number: number
  due_date: string | null
  value: string | null
  status: string
}

interface Payment {
  id: number
  paid_at: string | null
  value: string | null
  receipt: string | null
}

interface ParcelOrder {
  id: number
  modality_code: string
  order_number: string
  status: string
  total_value: string | null
  installments_count: number
  payments_count: number
  has_guia: boolean
}

interface OrderDetail extends ParcelOrder {
  installments: Installment[]
  payments: Payment[]
}

const modalities = [
  { label: 'Simples Nacional (012)', value: '012' },
  { label: 'MEI (021)', value: '021' },
  { label: 'IRPF (022)', value: '022' },
  { label: 'DCTFWeb (023)', value: '023' },
  { label: 'Transação PGFN (024)', value: '024' },
  { label: 'Demais débitos RFB (025)', value: '025' }
]

const clientId = ref('')
const modality = ref('012')
const loadedClient = ref('')
const loadedModality = ref('')

const orders = ref<ParcelOrder[]>([])
const loading = ref(false)

async function search() {
  if (!clientId.value) {
    toast.add({ title: 'Informe o ID do cliente', color: 'warning' })
    return
  }
  loadedClient.value = clientId.value
  loadedModality.value = modality.value
  loading.value = true
  try {
    const response = await $fetch<{ data: ParcelOrder[] }>(`/api/monitoring/clients/${loadedClient.value}/parcelamentos/${loadedModality.value}`)
    orders.value = response.data
  } catch (error) {
    toast.add({ title: 'Não foi possível consultar', description: backendMessage(error), color: 'error' })
    orders.value = []
  } finally {
    loading.value = false
  }
}

const selected = ref<OrderDetail | null>(null)
const detailOpen = ref(false)

async function openDetail(order: ParcelOrder) {
  try {
    selected.value = await $fetch<OrderDetail>(`/api/monitoring/clients/${loadedClient.value}/parcelamentos/orders/${order.id}`)
    detailOpen.value = true
  } catch (error) {
    toast.add({ title: 'Não foi possível abrir o detalhe', description: backendMessage(error), color: 'error' })
  }
}

function guiaUrl(order: ParcelOrder) {
  return `/api/monitoring/clients/${loadedClient.value}/parcelamentos/orders/${order.id}/guia`
}
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-xl font-semibold">
        Parcelamentos
      </h1>
      <p class="text-sm text-muted">
        Pedidos, parcelas, pagamentos e guias já geradas — sem reemissão.
      </p>
    </div>

    <UCard>
      <div class="flex flex-wrap items-end gap-2">
        <div>
          <label class="text-sm font-medium">Cliente (ID)</label>
          <UInput v-model="clientId" placeholder="Ex.: 1" class="w-40" />
        </div>
        <div>
          <label class="text-sm font-medium">Modalidade</label>
          <USelect v-model="modality" :items="modalities" class="w-64" />
        </div>
        <UButton label="Consultar" :loading="loading" @click="search" />
      </div>
    </UCard>

    <UCard v-if="loadedClient">
      <template #header>
        <span class="font-semibold">Pedidos ({{ orders.length }})</span>
      </template>
      <ul class="divide-y">
        <li v-for="order in orders" :key="order.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
          <div class="text-sm">
            <p class="font-medium">
              {{ order.order_number }} — {{ order.status }}
            </p>
            <p class="text-muted">
              {{ order.installments_count }} parcelas · {{ order.payments_count }} pagamentos
              <span v-if="!order.has_guia"> · sem guia gerada</span>
            </p>
          </div>
          <div class="flex gap-2">
            <UButton
              size="xs"
              variant="outline"
              label="Detalhe"
              @click="() => void openDetail(order)"
            />
            <UButton
              v-if="order.has_guia"
              size="xs"
              color="primary"
              label="Baixar guia"
              :to="guiaUrl(order)"
              target="_blank"
              external
            />
          </div>
        </li>
        <li v-if="orders.length === 0" class="py-3 text-sm text-muted">
          Nenhum pedido nesta modalidade.
        </li>
      </ul>
    </UCard>

    <UModal v-model:open="detailOpen" title="Detalhe do parcelamento">
      <template #body>
        <div v-if="selected" class="space-y-4 text-sm">
          <div>
            <h3 class="font-semibold">
              Parcelas
            </h3>
            <ul class="mt-1 space-y-1">
              <li v-for="installment in selected.installments" :key="installment.id">
                #{{ installment.number }} — {{ installment.value ?? '—' }} — venc. {{ installment.due_date ?? '—' }} — {{ installment.status }}
              </li>
            </ul>
          </div>
          <div>
            <h3 class="font-semibold">
              Pagamentos
            </h3>
            <ul class="mt-1 space-y-1">
              <li v-for="payment in selected.payments" :key="payment.id">
                {{ payment.paid_at ?? '—' }} — {{ payment.value ?? '—' }} — recibo {{ payment.receipt ?? '—' }}
              </li>
              <li v-if="selected.payments.length === 0" class="text-muted">
                Nenhum pagamento.
              </li>
            </ul>
          </div>
        </div>
      </template>
    </UModal>
  </div>
</template>
