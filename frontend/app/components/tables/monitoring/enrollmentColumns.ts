import { h, resolveComponent } from 'vue'
import type { ComputedRef } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import type { Row } from '@tanstack/table-core'
import { enrollmentStatusMeta, formatCnpj, formatDateTime } from '~/composables/useMonitoring'
import { selectColumn, sortableHeader } from '~/utils/table-chrome'
import type { Enrollment } from '~/types/monitoring'

export const ENROLLMENT_COLUMN_LABELS: Record<string, string> = {
  client: 'Cliente',
  definition: 'Definição',
  status: 'Estado',
  last_change_at: 'Última mudança',
  actions: 'Ações'
}

export const enrollmentStatusItems = [
  { label: 'Todos os estados', value: 'all' },
  { label: 'Ativas', value: 'active' },
  { label: 'Pausadas', value: 'paused' },
  { label: 'Encerradas', value: 'ended' }
]

export interface EnrollmentColumnsCtx {
  canWrite: ComputedRef<boolean>
  onOpen: (enrollment: Enrollment) => void
  onRun: (enrollment: Enrollment) => void
}

export function buildEnrollmentColumns(ctx: EnrollmentColumnsCtx): TableColumn<Enrollment>[] {
  const UBadge = resolveComponent('UBadge')
  const UButton = resolveComponent('UButton')
  const UDropdownMenu = resolveComponent('UDropdownMenu')

  function getRowItems(row: Row<Enrollment>) {
    const items = [{
      type: 'label' as const,
      label: 'Ações'
    }, {
      label: 'Abrir painel',
      icon: 'i-lucide-panel-right-open',
      onSelect() {
        ctx.onOpen(row.original)
      }
    }]
    if (ctx.canWrite.value) {
      items.push({
        label: 'Disparar consulta',
        icon: 'i-lucide-play',
        onSelect() {
          ctx.onRun(row.original)
        }
      })
    }
    return items
  }

  return [
    selectColumn<Enrollment>(),
    {
      accessorKey: 'client',
      header: sortableHeader('Cliente'),
      filterFn: (row, _columnId, value) => {
        const term = String(value ?? '').toLowerCase()
        if (!term) return true
        const client = row.original.client
        const name = (client?.razao_social ?? '').toLowerCase()
        const cnpj = (client?.cnpj ?? '').toLowerCase()
        return name.includes(term) || cnpj.includes(term)
      },
      cell: ({ row }) => {
        const client = row.original.client
        return h('div', { class: 'flex flex-col' }, [
          h('p', { class: 'font-medium text-highlighted' }, client?.razao_social ?? `Cliente #${row.original.id}`),
          h('p', { class: 'text-sm text-muted' }, client ? formatCnpj(client.cnpj) : '—')
        ])
      }
    },
    {
      accessorKey: 'definition',
      header: 'Definição',
      cell: ({ row }) => {
        const definition = row.original.definition
        return h('div', { class: 'flex flex-col gap-1' }, [
          h('p', { class: 'font-medium text-highlighted' }, definition?.name ?? String(row.original.id)),
          definition && !definition.is_active
            ? h(UBadge, { color: 'neutral', variant: 'subtle' }, () => 'Indisponível')
            : null
        ])
      }
    },
    {
      accessorKey: 'status',
      header: 'Estado',
      filterFn: 'equals',
      cell: ({ row }) => {
        const meta = enrollmentStatusMeta(row.original.status)
        return h('div', { class: 'flex flex-col gap-1' }, [
          h(UBadge, { color: meta.color, variant: 'subtle', class: 'capitalize w-fit' }, () => meta.label),
          row.original.status === 'paused' && row.original.pause_reason
            ? h('p', { class: 'text-xs text-muted' }, row.original.pause_reason)
            : null
        ])
      }
    },
    {
      accessorKey: 'last_change_at',
      header: sortableHeader('Última mudança'),
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.last_change_at))
    },
    {
      id: 'actions',
      cell: ({ row }) => h('div', { class: 'text-right' }, [
        h(UDropdownMenu, {
          content: { align: 'end' },
          items: getRowItems(row)
        }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações da associação' }))
      ])
    }
  ]
}
