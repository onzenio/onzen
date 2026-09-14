export type UserRole = 'super_admin' | 'admin' | 'operator' | 'user'

export interface SessionUser {
  id: number
  name: string
  email: string
  role: UserRole
}

export interface SessionPlan {
  id: number
  name: string
  monthly_query_volume: number
  modules: string[]
}

export interface SessionAccount {
  id: number
  name: string
  profile: string
  plan: SessionPlan | null
}

export interface Session {
  user: SessionUser
  account: SessionAccount
  acting_as: { account_id: number } | null
}

export type HealthState = 'gated' | 'configured' | 'unavailable' | 'degraded'

export interface Health {
  state: HealthState
  environment: 'homologacao' | 'producao'
  dry_run: boolean
  gated: boolean
  transport_open: boolean
}

export interface DashboardData {
  associations: { active: number, paused: number, ended: number, total: number }
  alerts: { pending: number, acknowledged: number, total: number }
  last_run: {
    id: number
    definition_key: string
    operation_code: string | null
    trigger: 'manual' | 'automatic'
    status: string
    finished_at: string | null
    client_id: number | null
  } | null
  quota: { period: string, consumed: number, limit: number }
}

export interface Paginated<T> {
  current_page: number
  data: T[]
  last_page: number
  per_page: number
  total: number
}

export interface EnrollmentClient {
  id: number
  razao_social: string
  cnpj: string
  monitoring_enabled: boolean
}

export interface EnrollmentDefinition {
  id: string
  name: string
  category: string
  version: string
  availability: string
  is_active: boolean
}

export interface Enrollment {
  id: number
  status: 'active' | 'paused' | 'ended'
  pause_reason: string | null
  version: number
  configuration: Record<string, unknown> | null
  last_change_at: string | null
  client: EnrollmentClient | null
  definition: EnrollmentDefinition | null
  created_at: string | null
  updated_at: string | null
}

export interface Snapshot {
  id: number
  enrollment_id: number
  client_id: number
  run_id: number | null
  operation_code: string
  family: string
  normalized: boolean
  fingerprint: string
  freshness: 'fresh' | 'stale'
  completeness: 'complete' | 'incomplete' | 'blocked'
  verified_at: string | null
  created_at: string | null
  data?: Record<string, unknown>
}

export interface SnapshotsResponse extends Paginated<Snapshot> {
  state: {
    freshness: string
    completeness: string
    coverage: { operations: string[], families: string[] }
    verified_at: string | null
  }
}

export interface ChangeItem {
  id: number
  enrollment_id: number
  client_id: number
  snapshot_id: number
  previous_snapshot_id: number | null
  run_id: number | null
  operation_code: string
  kind: string
  normalized: boolean
  created_at: string | null
  data?: { before: Record<string, unknown>, after: Record<string, unknown> }
}

export interface AlertItem {
  id: number
  enrollment_id: number
  client_id: number
  change_id: number
  status: 'pending' | 'acknowledged'
  acknowledged_by_user_id: number | null
  acknowledged_at: string | null
  created_at: string | null
}

export interface CndData {
  client: { id: number, razao_social: string, cnpj: string }
  state: 'available' | 'absent'
  cnd: {
    enrollment_id: number
    operation_code: string
    family: string
    normalized: boolean
    fingerprint: string
    verified_at: string | null
    data?: Record<string, unknown>
  } | null
  freshness: string
  completeness: string
  verified_at: string | null
}

export interface ParcelmentOrder {
  id: number
  client_id: number
  client: { id: number, razao_social: string, cnpj: string } | null
  modality: string
  external_id: string
  status: string | null
  installments_count: number | null
  paid_installments: number | null
  next_due_date: string | null
  total_amount: string | null
  competence: string | null
  provenance: string
  operation_code: string | null
  created_at: string | null
  updated_at: string | null
}

export interface Payment {
  id: number
  installment_id: number
  client_id: number
  external_id: string | null
  status: string | null
  amount: string | null
  paid_at: string | null
  receipt_available: boolean
  provenance: string
  created_at: string | null
  updated_at: string | null
}

export interface Installment {
  id: number
  order_id: number
  client_id: number
  external_id: string
  number: number
  status: string | null
  amount: string | null
  due_date: string | null
  paid_at: string | null
  guide_available: boolean
  provenance: string
  created_at: string | null
  updated_at: string | null
  payments?: Payment[]
}

export interface ParcelmentOrderDetail extends ParcelmentOrder {
  installments: Installment[]
}

export interface CertificateState {
  state: 'absent' | 'present'
  holder_name?: string
  thumbprint?: string | null
  expires_at?: string | null
  expired?: boolean
  uploaded_at?: string | null
}

export interface RequestAuthor {
  id: number
  name: string
  document: string | null
  document_type: number
  status: 'active' | 'ineligible'
  certificate_thumbprint: string | null
  certificate_expires_at: string | null
  eligible: boolean
  created_at: string | null
  updated_at: string | null
}

export interface SerproAdminOverview {
  environment: 'homologacao' | 'producao'
  transport: { state: string, open: boolean, dry_run: boolean }
  credential_version: number
  credentials: Record<string, {
    present: boolean
    resolved: boolean
    identifier: string | null
    has_certificate: boolean
    version: number
    updated_at: string | null
  }>
}

export interface RunQueued {
  id: number
  enrollment_id: number
  trigger: 'manual' | 'automatic'
  status: string
  fencing_token: number
  created_at: string | null
}

export interface BackendErrorBody {
  message?: string
  error?: string
  errors?: Record<string, string[]>
  code?: string
}
