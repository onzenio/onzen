import { h, resolveComponent } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { selectColumn, sortableHeader } from '~/utils/table-chrome'

export interface PlanRow {
  id: number
  name: string
  price_cents: number
  max_users: number
  max_clients: number
  modules: string[]
  monthly_query_volume: number
  is_default: boolean
}

export const PLAN_COLUMN_LABELS: Record<string, string> = {
  name: 'Nome',
  price_cents: 'Preço',
  is_default: 'Padrão',
  max_users: 'Usuários',
  max_clients: 'Clientes',
  modules: 'Módulos',
  actions: 'Ações'
}

export const defaultItems = [
  { label: 'Todos os planos', value: 'all' },
  { label: 'Somente padrão', value: 'true' }
]

export function formatPlanPrice(cents: number): string {
  return (cents / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

export interface PlanColumnsCtx {
  onEdit: (plan: PlanRow) => void
}

export function buildPlanColumns(ctx: PlanColumnsCtx): TableColumn<PlanRow>[] {
  const UBadge = resolveComponent('UBadge')
  const UButton = resolveComponent('UButton')

  return [
    selectColumn<PlanRow>(),
    {
      accessorKey: 'name',
      header: sortableHeader('Nome'),
      cell: ({ row }) => h('span', { class: 'font-medium' }, row.original.name)
    },
    {
      accessorKey: 'price_cents',
      header: sortableHeader('Preço'),
      cell: ({ row }) => formatPlanPrice(row.original.price_cents)
    },
    {
      accessorKey: 'is_default',
      header: 'Padrão',
      filterFn: (row, _columnId, value) => {
        if (value === undefined || value === 'all') return true
        return String(row.original.is_default) === String(value)
      },
      cell: ({ row }) => row.original.is_default ? h(UBadge, { variant: 'subtle', color: 'success' }, () => 'Padrão') : h('p', { class: 'text-sm text-muted' }, '—')
    },
    { accessorKey: 'max_users', header: sortableHeader('Usuários') },
    { accessorKey: 'max_clients', header: sortableHeader('Clientes') },
    {
      id: 'modules',
      header: 'Módulos',
      cell: ({ row }) => row.original.modules.join(', ')
    },
    {
      id: 'actions',
      cell: ({ row }) => {
        return h('div', { class: 'text-right' }, h(UButton, {
          icon: 'i-lucide-pencil',
          color: 'neutral',
          variant: 'ghost',
          ariaLabel: `Editar plano ${row.original.name}`,
          onClick: () => ctx.onEdit(row.original)
        }))
      }
    }
  ]
}
