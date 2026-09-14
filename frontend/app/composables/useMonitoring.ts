import type { BackendErrorBody, HealthState } from '~/types/monitoring'

const ERROR_MESSAGES: Record<string, string> = {
  serpro_gated: 'Transporte SERPRO desligado. Nenhuma chamada foi feita.',
  quota_exceeded: 'Volume mensal de consultas esgotado.',
  enrollment_inactive: 'Associação pausada ou encerrada.',
  monitoring_disabled: 'Client com monitoramento desativado.',
  definition_unavailable: 'Definição indisponível no catálogo vigente.',
  outorga_pendente: 'Procuração pendente para este Client.',
  outorga_expirada: 'Procuração vencida para este Client.',
  author_pending: 'Nenhum Autor do Pedido de Dados cadastrado.',
  author_ineligible: 'Autor do Pedido de Dados inelegível (verifique o Certificado Digital).',
  account_certificate_unavailable: 'Certificado Digital da Account ausente.',
  account_certificate_expired: 'Certificado Digital da Account vencido.',
  BACKEND_UNAVAILABLE: 'Não foi possível falar com o backend. Tente novamente.'
}

export function monitoringErrorMessage(body?: BackendErrorBody | null): string {
  const code = body?.error ?? body?.code
  if (code && ERROR_MESSAGES[code]) {
    return ERROR_MESSAGES[code]
  }
  return body?.message ?? 'Não foi possível concluir a operação.'
}

export function backendErrorBody(error: unknown): BackendErrorBody | null {
  const data = (error as { data?: unknown })?.data
  if (data && typeof data === 'object') {
    return data as BackendErrorBody
  }
  return null
}

export function enrollmentStatusMeta(status: string): { label: string, color: 'success' | 'warning' | 'neutral' } {
  switch (status) {
    case 'active': return { label: 'Ativa', color: 'success' }
    case 'paused': return { label: 'Pausada', color: 'warning' }
    default: return { label: 'Encerrada', color: 'neutral' }
  }
}

export function healthStateMeta(state: HealthState): { label: string, color: 'success' | 'warning' | 'error' | 'neutral' } {
  switch (state) {
    case 'configured': return { label: 'Configurado', color: 'success' }
    case 'degraded': return { label: 'Degradado', color: 'warning' }
    case 'unavailable': return { label: 'Indisponível', color: 'error' }
    default: return { label: 'Bloqueado', color: 'neutral' }
  }
}

export function formatCnpj(cnpj: string): string {
  const digits = cnpj.replace(/\D/g, '')
  if (digits.length === 14) {
    return digits.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5')
  }
  if (digits.length === 11) {
    return digits.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4')
  }
  return cnpj
}

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) {
    return '—'
  }
  return new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

export function formatDate(iso: string | null | undefined): string {
  if (!iso) {
    return '—'
  }
  return new Date(`${iso}T12:00:00`).toLocaleDateString('pt-BR')
}

export function formatMoney(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') {
    return '—'
  }
  const amount = Number(value)
  if (Number.isNaN(amount)) {
    return value
  }
  return amount.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}
