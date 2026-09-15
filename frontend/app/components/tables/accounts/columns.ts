import { h, resolveComponent } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { selectColumn, sortableHeader } from '~/utils/table-chrome'

export interface AccountRow {
  id: number
  name: string
  profile: string
  plan?: { id: number, name: string } | null
}

export const ACCOUNT_COLUMN_LABELS: Record<string, string> = {
  name: 'Nome',
  profile: 'Tipo',
  plan: 'Plano',
  actions: 'Ações'
}

export const profileItems = [
  { label: 'Todos os tipos', value: 'all' },
  { label: 'Central OneFisc', value: 'A' },
  { label: 'Escritório', value: 'B' }
]

export function profileLabel(value: string): string {
  if (value === 'A') return 'Central OneFisc'
  if (value === 'B') return 'Escritório'
  return value
}

export function buildAccountColumns(): TableColumn<AccountRow>[] {
  const UBadge = resolveComponent('UBadge')

  return [
    selectColumn<AccountRow>(),
    {
      accessorKey: 'name',
      header: sortableHeader('Nome')
    },
    {
      accessorKey: 'profile',
      header: 'Tipo',
      filterFn: 'equals',
      cell: ({ row }) => {
        const central = row.original.profile === 'A'
        return h(UBadge, { variant: 'subtle', color: central ? 'primary' : 'neutral' }, () => profileLabel(row.original.profile))
      }
    },
    {
      id: 'plan',
      header: sortableHeader('Plano'),
      sortingFn: (rowA, rowB) => (rowA.original.plan?.name ?? '').localeCompare(rowB.original.plan?.name ?? '', 'pt-BR'),
      cell: ({ row }) => row.original.plan?.name ?? '—'
    }
  ]
}
