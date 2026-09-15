import { h, resolveComponent } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { formatCnpj, formatMoney } from '~/composables/useMonitoring'
import { selectColumn, sortableHeader } from '~/utils/table-chrome'
import type { ParcelmentOrder } from '~/types/monitoring'

export const PARCEL_ORDER_COLUMN_LABELS: Record<string, string> = {
  client: 'Cliente',
  modality: 'Modalidade',
  status: 'Estado',
  installments: 'Parcelas',
  total_amount: 'Total',
  actions: 'Ações'
}

export const PARCEL_MODALITIES = ['PARCSN', 'PARCSN-ESP', 'PERTSN', 'RELPSN', 'PARCMEI', 'PARCMEI-ESP', 'PERTMEI', 'RELPMEI']

export function buildParcelModalityItems(): { label: string, value: string }[] {
  return [{ label: 'Todas as modalidades', value: 'all' }, ...PARCEL_MODALITIES.map(m => ({ label: m, value: m }))]
}

export interface ParcelOrderColumnsCtx {
  onOpen: (order: ParcelmentOrder) => void
  onCopyId: (order: ParcelmentOrder) => void
}

export function buildParcelOrderColumns(ctx: ParcelOrderColumnsCtx): TableColumn<ParcelmentOrder>[] {
  const UBadge = resolveComponent('UBadge')
  const UButton = resolveComponent('UButton')
  const UDropdownMenu = resolveComponent('UDropdownMenu')

  return [
    selectColumn<ParcelmentOrder>(),
    {
      accessorKey: 'client',
      header: sortableHeader('Cliente'),
      filterFn: (row, _columnId, value) => {
        const term = String(value ?? '').toLowerCase()
        if (!term) return true
        const client = row.original.client
        return (client?.razao_social ?? '').toLowerCase().includes(term) || (client?.cnpj ?? '').toLowerCase().includes(term)
      },
      cell: ({ row }) => {
        const client = row.original.client
        return h('div', { class: 'flex flex-col' }, [
          h('p', { class: 'font-medium text-highlighted' }, client?.razao_social ?? `Cliente #${row.original.client_id}`),
          h('p', { class: 'text-sm text-muted' }, client ? formatCnpj(client.cnpj) : '—')
        ])
      }
    },
    {
      accessorKey: 'modality',
      header: 'Modalidade',
      filterFn: 'equals',
      cell: ({ row }) => h(UBadge, { color: 'info', variant: 'subtle' }, () => row.original.modality)
    },
    {
      accessorKey: 'status',
      header: sortableHeader('Estado'),
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, row.original.status ?? '—')
    },
    {
      accessorKey: 'installments',
      header: 'Parcelas',
      cell: ({ row }) => {
        const paid = row.original.paid_installments
        const total = row.original.installments_count
        return h('p', { class: 'text-sm text-muted' }, paid !== null && total !== null ? `${paid}/${total} pagas` : '—')
      }
    },
    {
      accessorKey: 'total_amount',
      header: sortableHeader('Total'),
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatMoney(row.original.total_amount))
    },
    {
      id: 'actions',
      cell: ({ row }) => h('div', { class: 'text-right' }, [
        h(UDropdownMenu, {
          content: { align: 'end' },
          items: [
            { type: 'label' as const, label: 'Ações' },
            {
              label: 'Abrir detalhe',
              icon: 'i-lucide-eye',
              onSelect: () => ctx.onOpen(row.original)
            },
            {
              label: 'Copiar ID do pedido',
              icon: 'i-lucide-copy',
              onSelect: () => ctx.onCopyId(row.original)
            }
          ]
        }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações do parcelamento' }))
      ])
    }
  ]
}
