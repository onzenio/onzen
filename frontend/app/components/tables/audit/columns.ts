import { h, resolveComponent } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { formatDateTime } from '~/composables/useMonitoring'
import { selectColumn, sortableHeader } from '~/utils/table-chrome'

export interface AuditActor {
  id: number
  name: string
  email: string
}

export interface AuditRow {
  id: number
  actor_user_id: number | null
  origin_account_id: number
  target_account_id: number | null
  action: string
  metadata: Record<string, unknown> | null
  created_at: string
  actor?: AuditActor | null
}

export const AUDIT_COLUMN_LABELS: Record<string, string> = {
  id: 'ID',
  created_at: 'Data',
  action: 'Ação',
  actor: 'Ator',
  origin_account_id: 'Origem',
  target_account_id: 'Alvo',
  actions: 'Ações'
}

export function actorLabel(row: AuditRow): string {
  return row.actor?.email ?? (row.actor_user_id ? `#${row.actor_user_id}` : '—')
}

export function buildAuditActionItems(logs: AuditRow[]): { label: string, value: string }[] {
  return [
    { label: 'Todas as ações', value: 'all' },
    ...Array.from(new Set(logs.map(log => log.action))).sort((a, b) => a.localeCompare(b, 'pt-BR')).map(action => ({ label: action, value: action }))
  ]
}

export function buildAuditColumns(): TableColumn<AuditRow>[] {
  const UBadge = resolveComponent('UBadge')

  return [
    selectColumn<AuditRow>(),
    { accessorKey: 'id', header: 'ID' },
    {
      accessorKey: 'created_at',
      header: sortableHeader('Data'),
      cell: ({ row }) => formatDateTime(row.original.created_at)
    },
    {
      accessorKey: 'action',
      header: sortableHeader('Ação'),
      filterFn: 'equals',
      cell: ({ row }) => h(UBadge, { variant: 'subtle', color: 'neutral' }, () => row.original.action)
    },
    {
      id: 'actor',
      header: 'Ator',
      filterFn: (row, _columnId, value) => {
        const term = String(value ?? '').toLowerCase()
        if (!term) return true
        return row.original.action.toLowerCase().includes(term) || actorLabel(row.original).toLowerCase().includes(term)
      },
      cell: ({ row }) => actorLabel(row.original)
    },
    { accessorKey: 'origin_account_id', header: 'Origem' },
    {
      accessorKey: 'target_account_id',
      header: 'Alvo',
      cell: ({ row }) => row.original.target_account_id ?? '—'
    }
  ]
}
