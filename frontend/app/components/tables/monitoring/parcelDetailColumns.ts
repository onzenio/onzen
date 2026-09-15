import { h, resolveComponent } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { formatDate, formatMoney } from '~/composables/useMonitoring'
import { selectColumn, sortableHeader } from '~/utils/table-chrome'
import type { Installment, Payment } from '~/types/monitoring'

export const INSTALLMENT_COLUMN_LABELS: Record<string, string> = {
  number: 'Nº',
  status: 'Estado',
  amount: 'Valor',
  due_date: 'Vencimento',
  guide_available: 'Guia',
  actions: 'Ações'
}

export const PAYMENT_COLUMN_LABELS: Record<string, string> = {
  status: 'Estado',
  amount: 'Valor',
  paid_at: 'Pago em',
  actions: 'Ações'
}

export const installmentStatusItems = [
  { label: 'Todos os estados', value: 'all' },
  { label: 'Em aberto', value: 'open' },
  { label: 'Pagas', value: 'paid' },
  { label: 'Vencidas', value: 'overdue' }
]

export const paymentStatusItems = [
  { label: 'Todos os estados', value: 'all' },
  { label: 'Confirmados', value: 'confirmed' },
  { label: 'Pendentes', value: 'pending' }
]

export interface InstallmentColumnsCtx {
  onViewPayments: (installment: Installment) => void
}

export function buildInstallmentColumns(ctx: InstallmentColumnsCtx): TableColumn<Installment>[] {
  const UButton = resolveComponent('UButton')
  const UDropdownMenu = resolveComponent('UDropdownMenu')

  return [
    selectColumn<Installment>(),
    {
      accessorKey: 'number',
      header: sortableHeader('Nº'),
      filterFn: (row, _columnId, value) => {
        const term = String(value ?? '').toLowerCase()
        if (!term) return true
        return String(row.original.number).toLowerCase().includes(term) || (row.original.status ?? '').toLowerCase().includes(term)
      },
      cell: ({ row }) => h('p', { class: 'font-medium text-highlighted' }, String(row.original.number))
    },
    {
      accessorKey: 'status',
      header: 'Estado',
      filterFn: 'equals',
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, row.original.status ?? '—')
    },
    {
      accessorKey: 'amount',
      header: sortableHeader('Valor'),
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatMoney(row.original.amount))
    },
    {
      accessorKey: 'due_date',
      header: sortableHeader('Vencimento'),
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDate(row.original.due_date))
    },
    {
      accessorKey: 'guide_available',
      header: 'Guia',
      cell: ({ row }) => row.original.guide_available
        ? h(UButton, {
            label: 'Baixar guia',
            size: 'xs',
            variant: 'outline',
            icon: 'i-lucide-download',
            to: `/api/monitoring/parcelas/${row.original.id}/guia`,
            target: '_blank',
            external: true
          })
        : h('p', { class: 'text-sm text-muted' }, 'Guia ainda não disponível')
    },
    {
      id: 'actions',
      cell: ({ row }) => h('div', { class: 'text-right' }, [
        h(UDropdownMenu, {
          content: { align: 'end' },
          items: [
            { type: 'label' as const, label: 'Ações' },
            { label: 'Ver pagamentos', icon: 'i-lucide-eye', onSelect: () => ctx.onViewPayments(row.original) },
            ...(row.original.guide_available
              ? [{
                  label: 'Baixar guia',
                  icon: 'i-lucide-download',
                  onSelect: () => window.open(`/api/monitoring/parcelas/${row.original.id}/guia`, '_blank')
                }]
              : [])
          ]
        }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações da parcela' }))
      ])
    }
  ]
}

export interface PaymentColumnsCtx {
  onCopyId: (payment: Payment) => void
}

export function buildPaymentColumns(ctx: PaymentColumnsCtx): TableColumn<Payment>[] {
  const UBadge = resolveComponent('UBadge')
  const UButton = resolveComponent('UButton')
  const UDropdownMenu = resolveComponent('UDropdownMenu')

  return [
    selectColumn<Payment>(),
    {
      accessorKey: 'status',
      header: 'Estado',
      filterFn: 'equals',
      cell: ({ row }) => h(UBadge, { color: 'neutral', variant: 'subtle' }, () => row.original.status ?? '—')
    },
    {
      accessorKey: 'amount',
      header: sortableHeader('Valor'),
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatMoney(row.original.amount))
    },
    {
      accessorKey: 'paid_at',
      header: sortableHeader('Pago em'),
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDate(row.original.paid_at))
    },
    {
      id: 'actions',
      cell: ({ row }) => h('div', { class: 'text-right' }, [
        h(UDropdownMenu, {
          content: { align: 'end' },
          items: [
            { type: 'label' as const, label: 'Ações' },
            {
              label: 'Copiar ID do pagamento',
              icon: 'i-lucide-copy',
              onSelect: () => ctx.onCopyId(row.original)
            }
          ]
        }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações do pagamento' }))
      ])
    }
  ]
}
