import { h, resolveComponent } from 'vue'
import type { ComputedRef } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { formatDateTime } from '~/composables/useMonitoring'
import { selectColumn, sortableHeader } from '~/utils/table-chrome'
import type { RequestAuthor } from '~/types/monitoring'

export const AUTHOR_COLUMN_LABELS: Record<string, string> = {
  name: 'Nome',
  document: 'Documento',
  status: 'Estado',
  certificate_expires_at: 'Cert. válido até',
  actions: 'Ações'
}

export const authorStatusItems = [
  { label: 'Todos os estados', value: 'all' },
  { label: 'Ativos', value: 'active' },
  { label: 'Inelegíveis', value: 'ineligible' }
]

export interface AuthorColumnsCtx {
  canManage: ComputedRef<boolean>
  onSendTerm: (authorId: number) => void
  onCopyDocument: (author: RequestAuthor) => void
}

export function buildAuthorColumns(ctx: AuthorColumnsCtx): TableColumn<RequestAuthor>[] {
  const UBadge = resolveComponent('UBadge')
  const UButton = resolveComponent('UButton')
  const UDropdownMenu = resolveComponent('UDropdownMenu')

  return [
    selectColumn<RequestAuthor>(),
    {
      accessorKey: 'name',
      header: sortableHeader('Nome'),
      filterFn: (row, _columnId, value) => {
        const term = String(value ?? '').toLowerCase()
        if (!term) return true
        return row.original.name.toLowerCase().includes(term) || (row.original.document ?? '').toLowerCase().includes(term)
      },
      cell: ({ row }) => h('p', { class: 'font-medium text-highlighted' }, row.original.name)
    },
    {
      accessorKey: 'document',
      header: 'Documento',
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, row.original.document ?? '—')
    },
    {
      accessorKey: 'status',
      header: 'Estado',
      filterFn: 'equals',
      cell: ({ row }) => h(UBadge, { color: row.original.status === 'active' ? 'success' : 'neutral', variant: 'subtle' }, () => row.original.status === 'active' ? 'Ativo' : 'Inelegível')
    },
    {
      accessorKey: 'certificate_expires_at',
      header: sortableHeader('Cert. válido até'),
      cell: ({ row }) => h('p', { class: 'text-sm text-muted' }, formatDateTime(row.original.certificate_expires_at))
    },
    {
      id: 'actions',
      cell: ({ row }) => h('div', { class: 'text-right' }, [
        h(UDropdownMenu, {
          content: { align: 'end' },
          items: [
            { type: 'label' as const, label: 'Ações' },
            ...(ctx.canManage.value
              ? [{
                  label: 'Enviar termo',
                  icon: 'i-lucide-send',
                  onSelect: () => ctx.onSendTerm(row.original.id)
                }]
              : []),
            {
              label: 'Copiar documento',
              icon: 'i-lucide-copy',
              onSelect: () => ctx.onCopyDocument(row.original)
            }
          ]
        }, () => h(UButton, { icon: 'i-lucide-ellipsis-vertical', color: 'neutral', variant: 'ghost', class: 'ml-auto', ariaLabel: 'Ações do autor' }))
      ])
    }
  ]
}
