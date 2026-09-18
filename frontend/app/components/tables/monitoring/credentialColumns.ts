import { h, resolveComponent } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { formatDateTime } from '~/composables/useMonitoring'
import { selectColumn, sortableHeader } from '~/utils/table-chrome'

export interface CredentialRow {
  environment: string
  identifier: string | null
  resolved: boolean
  has_certificate: boolean
  updated_at: string | null
}

export const CREDENTIAL_COLUMN_LABELS: Record<string, string> = {
  environment: 'Ambiente',
  identifier: 'Identificador',
  resolved: 'Válidas',
  has_certificate: 'Certificado mTLS',
  updated_at: 'Atualizadas em',
  actions: 'Ações'
}

export const credentialValidityItems = [
  { label: 'Todas', value: 'all' },
  { label: 'Válidas', value: 'true' },
  { label: 'Inválidas', value: 'false' }
]

export function credentialEnvironmentLabel(environment: string): string {
  if (environment === 'producao') return 'Produção'
  if (environment === 'homologacao') return 'Homologação'
  return environment
}

export interface CredentialColumnsCtx {
  onCopyIdentifier: (identifier: string | null) => void
}

export function buildCredentialColumns(ctx: CredentialColumnsCtx): TableColumn<CredentialRow>[] {
  const UBadge = resolveComponent('UBadge')
  const UButton = resolveComponent('UButton')
  const UDropdownMenu = resolveComponent('UDropdownMenu')

  return [
    selectColumn<CredentialRow>(),
    {
      accessorKey: 'environment',
      header: sortableHeader('Ambiente'),
      cell: ({ row }) => h('p', { class: 'font-medium text-highlighted' }, credentialEnvironmentLabel(row.original.environment))
    },
    {
      accessorKey: 'identifier',
      header: sortableHeader('Identificador'),
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, row.original.identifier ?? '—')
    },
    {
      accessorKey: 'resolved',
      header: 'Válidas',
      filterFn: (row, _columnId, value) => {
        if (value === undefined || value === 'all') return true
        return String(row.original.resolved) === String(value)
      },
      cell: ({ row }) => h(UBadge, { color: row.original.resolved ? 'success' : 'neutral', variant: 'subtle' }, () => row.original.resolved ? 'Sim' : 'Não')
    },
    {
      accessorKey: 'has_certificate',
      header: 'Certificado mTLS',
      cell: ({ row }) => h(UBadge, { color: row.original.has_certificate ? 'success' : 'neutral', variant: 'subtle' }, () => row.original.has_certificate ? 'Sim' : 'Não')
    },
    {
      accessorKey: 'updated_at',
      header: sortableHeader('Atualizadas em'),
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.updated_at))
    },
    {
      id: 'actions',
      cell: ({ row }) => h('div', { class: 'text-right' }, [
        h(UDropdownMenu, {
          content: { align: 'end' },
          items: [
            { type: 'label' as const, label: 'Ações' },
            {
              label: 'Copiar identificador',
              icon: 'i-lucide-copy',
              onSelect: () => ctx.onCopyIdentifier(row.original.identifier)
            }
          ]
        }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações da credencial' }))
      ])
    }
  ]
}
