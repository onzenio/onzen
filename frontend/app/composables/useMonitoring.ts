export interface MonitoringDefinition {
  code: string
  family: string
  name: string
  availability: 'available' | 'unavailable' | 'prospecting'
  strategy: string
  automatic: boolean
  unavailability_reason: string | null
}

export interface CatalogResponse {
  version: string
  data: MonitoringDefinition[]
  procuration_allowlist: string[]
}

export interface Enrollment {
  id: number
  account_id: number
  client_id: number
  definition_code: string
  status: 'active' | 'paused' | 'ended'
  pause_reason: string | null
  version: number
  client?: { id: number, razao_social: string, cnpj: string }
}

export interface DashboardResponse {
  enrollments: { active: number, paused: number, ended: number }
  open_alerts: number
  quota: { volume: number, used: number, remaining: number, period: string }
}

export interface Snapshot {
  id: number
  family: string
  normalized: Record<string, unknown>
  version: number
  completeness: string
  checked_at: string | null
}

export interface AlertItem {
  id: number
  family: string
  status: 'open' | 'acknowledged'
  acknowledged_at: string | null
}

export interface ChangeItem {
  id: number
  family: string
  change_type: string
  summary: Record<string, unknown> | null
}

export interface CndResponse {
  available: boolean
  situacao_fiscal?: string | null
  cnd_disponivel?: boolean | null
  checked_at?: string | null
  reason?: string
}

export async function fetchCatalog() {
  return $fetch<CatalogResponse>('/api/monitoring/catalog')
}

export async function fetchDashboard() {
  return $fetch<DashboardResponse>('/api/monitoring/dashboard')
}

export async function triggerEnrollment(id: number, idempotencyKey?: string) {
  return $fetch<{ run_id: number, status: string }>(`/api/monitoring/enrollments/${id}/trigger`, {
    method: 'POST',
    body: idempotencyKey ? { idempotency_key: idempotencyKey } : {}
  })
}
