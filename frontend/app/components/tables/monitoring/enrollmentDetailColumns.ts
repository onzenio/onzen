import { h, resolveComponent } from 'vue'
import type { ComputedRef } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { formatDateTime } from '~/composables/useMonitoring'
import { selectColumn, sortableHeader } from '~/utils/table-chrome'
import type { AlertItem, ChangeItem, Snapshot } from '~/types/monitoring'

export const SNAPSHOT_COLUMN_LABELS: Record<string, string> = {
  operation_code: 'Operação',
  family: 'Família',
  freshness: 'Atualidade',
  completeness: 'Integridade',
  verified_at: 'Verificado em',
  actions: 'Ações'
}

export const CHANGE_COLUMN_LABELS: Record<string, string> = {
  operation_code: 'Operação',
  normalized: 'Normalizado',
  created_at: 'Detectada em',
  actions: 'Ações'
}

export const ALERT_COLUMN_LABELS: Record<string, string> = {
  status: 'Estado',
  created_at: 'Criado em',
  acknowledged_at: 'Reconhecido em',
  actions: 'Ações'
}

export const snapshotFreshnessItems = [
  { label: 'Todas', value: 'all' },
  { label: 'Atual', value: 'fresh' },
  { label: 'Desatualizado', value: 'stale' }
]

export const changeNormalizedItems = [
  { label: 'Todos', value: 'all' },
  { label: 'Normalizados', value: 'true' },
  { label: 'Não normalizados', value: 'false' }
]

export const alertStatusItems = [
  { label: 'Todos os estados', value: 'all' },
  { label: 'Pendentes', value: 'pending' },
  { label: 'Reconhecidos', value: 'acknowledged' }
]

export interface SnapshotColumnsCtx {
  onCopyFingerprint: (snapshot: Snapshot) => void
}

export function buildSnapshotColumns(ctx: SnapshotColumnsCtx): TableColumn<Snapshot>[] {
  const UBadge = resolveComponent('UBadge')
  const UButton = resolveComponent('UButton')
  const UDropdownMenu = resolveComponent('UDropdownMenu')

  return [
    selectColumn<Snapshot>(),
    {
      accessorKey: 'operation_code',
      header: sortableHeader('Operação'),
      cell: ({ row }) => h('p', { class: 'font-medium text-highlighted' }, row.original.operation_code)
    },
    {
      accessorKey: 'family',
      header: 'Família'
    },
    {
      accessorKey: 'freshness',
      header: 'Atualidade',
      filterFn: 'equals',
      cell: ({ row }) => h(UBadge, {
        color: row.original.freshness === 'fresh' ? 'success' : 'warning',
        variant: 'subtle'
      }, () => row.original.freshness === 'fresh' ? 'Atual' : 'Desatualizado')
    },
    {
      accessorKey: 'completeness',
      header: 'Integridade',
      cell: ({ row }) => h(UBadge, {
        color: row.original.completeness === 'complete' ? 'success' : 'warning',
        variant: 'subtle'
      }, () => row.original.completeness === 'complete' ? 'Completo' : row.original.completeness === 'blocked' ? 'Bloqueado' : 'Incompleto')
    },
    {
      accessorKey: 'verified_at',
      header: sortableHeader('Verificado em'),
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.verified_at))
    },
    {
      id: 'actions',
      cell: ({ row }) => h('div', { class: 'text-right' }, [
        h(UDropdownMenu, {
          content: { align: 'end' },
          items: [
            { type: 'label' as const, label: 'Ações' },
            {
              label: 'Copiar fingerprint',
              icon: 'i-lucide-copy',
              onSelect: () => ctx.onCopyFingerprint(row.original)
            }
          ]
        }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações do snapshot' }))
      ])
    }
  ]
}

export interface ChangeColumnsCtx {
  onCopyId: (change: ChangeItem) => void
}

export function buildChangeColumns(ctx: ChangeColumnsCtx): TableColumn<ChangeItem>[] {
  const UBadge = resolveComponent('UBadge')
  const UButton = resolveComponent('UButton')
  const UDropdownMenu = resolveComponent('UDropdownMenu')

  return [
    selectColumn<ChangeItem>(),
    {
      accessorKey: 'operation_code',
      header: sortableHeader('Operação'),
      cell: ({ row }) => h('p', { class: 'font-medium text-highlighted' }, row.original.operation_code)
    },
    {
      accessorKey: 'normalized',
      header: 'Normalizado',
      filterFn: (row, _columnId, value) => {
        if (value === undefined || value === 'all') return true
        return String(row.original.normalized) === String(value)
      },
      cell: ({ row }) => h(UBadge, {
        color: row.original.normalized ? 'success' : 'neutral',
        variant: 'subtle'
      }, () => row.original.normalized ? 'Sim' : 'Não')
    },
    {
      accessorKey: 'created_at',
      header: sortableHeader('Detectada em'),
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.created_at))
    },
    {
      id: 'actions',
      cell: ({ row }) => h('div', { class: 'text-right' }, [
        h(UDropdownMenu, {
          content: { align: 'end' },
          items: [
            { type: 'label' as const, label: 'Ações' },
            {
              label: 'Copiar ID da mudança',
              icon: 'i-lucide-copy',
              onSelect: () => ctx.onCopyId(row.original)
            }
          ]
        }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações da mudança' }))
      ])
    }
  ]
}

export interface AlertColumnsCtx {
  canWrite: ComputedRef<boolean>
  onAcknowledge: (alertId: number) => void
}

export function buildAlertColumns(ctx: AlertColumnsCtx): TableColumn<AlertItem>[] {
  const UBadge = resolveComponent('UBadge')
  const UButton = resolveComponent('UButton')
  const UDropdownMenu = resolveComponent('UDropdownMenu')

  return [
    selectColumn<AlertItem>(),
    {
      accessorKey: 'status',
      header: 'Estado',
      filterFn: 'equals',
      cell: ({ row }) => h(UBadge, {
        color: row.original.status === 'pending' ? 'warning' : 'neutral',
        variant: 'subtle'
      }, () => row.original.status === 'pending' ? 'Pendente' : 'Reconhecido')
    },
    {
      accessorKey: 'created_at',
      header: sortableHeader('Criado em'),
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.created_at))
    },
    {
      accessorKey: 'acknowledged_at',
      header: 'Reconhecido em',
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.acknowledged_at))
    },
    {
      id: 'actions',
      cell: ({ row }) => row.original.status === 'pending' && ctx.canWrite.value
        ? h('div', { class: 'text-right' }, [
            h(UDropdownMenu, {
              content: { align: 'end' },
              items: [
                { type: 'label' as const, label: 'Ações' },
                { label: 'Reconhecer alerta', icon: 'i-lucide-check', onSelect: () => ctx.onAcknowledge(row.original.id) }
              ]
            }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações do alerta' }))
          ])
        : null
    }
  ]
}
