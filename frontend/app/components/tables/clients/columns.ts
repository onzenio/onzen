import { h, resolveComponent } from 'vue'
import type { ComputedRef } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import type { Row } from '@tanstack/table-core'
import { formatCnpj } from '~/composables/useMonitoring'
import { selectColumn, sortableHeader } from '~/utils/table-chrome'

export interface ClientRow {
  id: number
  cnpj: string
  razao_social: string
  regime: string
  contador_responsavel: string
  monitoring_enabled: boolean
}

export const CLIENT_COLUMN_LABELS: Record<string, string> = {
  razao_social: 'Razão social',
  cnpj: 'CNPJ',
  regime: 'Regime',
  contador_responsavel: 'Contador',
  monitoring_enabled: 'Monitoramento',
  actions: 'Ações'
}

export const regimeItems = [
  { label: 'Todos os regimes', value: 'all' },
  { label: 'Simples Nacional', value: 'simples' },
  { label: 'Lucro Presumido', value: 'presumido' },
  { label: 'Lucro Real', value: 'real' },
  { label: 'MEI', value: 'mei' }
]

export function regimeLabel(value: string): string {
  return regimeItems.find(item => item.value === value)?.label ?? value
}

export interface ClientColumnsCtx {
  canWrite: ComputedRef<boolean>
  onEdit: (client: ClientRow) => void
  onToggleMonitoring: (client: ClientRow, enabled: boolean) => void
  onAskDelete: (client: ClientRow) => void
}

export function buildClientColumns(ctx: ClientColumnsCtx): TableColumn<ClientRow>[] {
  const UBadge = resolveComponent('UBadge')
  const UButton = resolveComponent('UButton')
  const UDropdownMenu = resolveComponent('UDropdownMenu')
  const USwitch = resolveComponent('USwitch')

  function getRowItems(row: Row<ClientRow>) {
    const client = row.original
    if (!ctx.canWrite.value) {
      return [{ label: 'Ver detalhes', icon: 'i-lucide-eye', onSelect: () => ctx.onEdit(client) }]
    }
    return [
      { type: 'label', label: 'Ações' },
      { label: 'Editar cliente', icon: 'i-lucide-pencil', onSelect: () => ctx.onEdit(client) },
      {
        label: client.monitoring_enabled ? 'Desativar monitoramento' : 'Ativar monitoramento',
        icon: client.monitoring_enabled ? 'i-lucide-pause' : 'i-lucide-play',
        onSelect: () => ctx.onToggleMonitoring(client, !client.monitoring_enabled)
      },
      { type: 'separator' },
      { label: 'Excluir cliente', icon: 'i-lucide-trash', color: 'error', onSelect: () => ctx.onAskDelete(client) }
    ]
  }

  return [
    selectColumn<ClientRow>(),
    {
      accessorKey: 'razao_social',
      header: sortableHeader('Razão social'),
      filterFn: (row, _columnId, value) => {
        const term = String(value ?? '').toLowerCase()
        if (!term) return true
        return row.original.razao_social.toLowerCase().includes(term) || row.original.cnpj.toLowerCase().includes(term)
      },
      cell: ({ row }) => h('div', { class: 'flex flex-col' }, [
        h('p', { class: 'font-medium text-highlighted' }, row.original.razao_social),
        h('p', { class: 'text-sm text-muted' }, formatCnpj(row.original.cnpj))
      ])
    },
    {
      accessorKey: 'cnpj',
      header: sortableHeader('CNPJ'),
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatCnpj(row.original.cnpj))
    },
    {
      accessorKey: 'regime',
      header: 'Regime',
      filterFn: 'equals',
      cell: ({ row }) => h(UBadge, { variant: 'subtle', color: 'neutral', class: 'capitalize' }, () => regimeLabel(row.original.regime))
    },
    {
      accessorKey: 'contador_responsavel',
      header: sortableHeader('Contador'),
      cell: ({ row }) => h('p', { class: 'text-sm' }, row.original.contador_responsavel)
    },
    {
      accessorKey: 'monitoring_enabled',
      header: 'Monitoramento',
      cell: ({ row }) => {
        const client = row.original
        if (!ctx.canWrite.value) {
          return h(UBadge, { variant: 'subtle', color: client.monitoring_enabled ? 'success' : 'neutral' }, () => client.monitoring_enabled ? 'Ativo' : 'Inativo')
        }
        return h(USwitch, {
          'modelValue': client.monitoring_enabled,
          'onUpdate:modelValue': (enabled: boolean) => ctx.onToggleMonitoring(client, enabled),
          'ariaLabel': `Monitoramento de ${client.razao_social}`
        })
      }
    },
    {
      id: 'actions',
      cell: ({ row }) => h('div', { class: 'text-right' }, [
        h(UDropdownMenu, {
          content: { align: 'end' },
          items: getRowItems(row)
        }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações do cliente' }))
      ])
    }
  ]
}
