import { h, resolveComponent } from 'vue'
import type { Row } from '@tanstack/table-core'

export interface TableColumnApi {
  setFilterValue: (value: string | undefined) => void
  getFilterValue: () => unknown
  toggleVisibility: (value?: boolean) => void
}

export interface TableVisibleColumn {
  id: string
  getCanHide: () => boolean
  getIsVisible: () => boolean
}

export interface TableApi<T = unknown> {
  getFilteredSelectedRowModel: () => { rows: Row<T>[] }
  getFilteredRowModel: () => { rows: Row<T>[] }
  getColumn: (id: string) => TableColumnApi | undefined
  getAllColumns: () => TableVisibleColumn[]
  getState: () => { pagination: { pageIndex: number, pageSize: number } }
  setPageIndex: (index: number) => void
}

interface SortableColumn {
  getIsSorted: () => false | 'asc' | 'desc'
  toggleSorting: (desc?: boolean) => void
}

export const TABLE_UI = {
  base: 'table-fixed border-separate border-spacing-0',
  thead: '[&>tr]:bg-elevated/50 [&>tr]:after:content-none',
  tbody: '[&>tr]:last:[&>td]:border-b-0',
  th: 'py-2 first:rounded-l-lg last:rounded-r-lg border-y border-default first:border-l last:border-r',
  td: 'border-b border-default',
  separator: 'h-0'
}

export function sortableHeader(label: string) {
  const UButton = resolveComponent('UButton')
  return ({ column }: { column: SortableColumn }) => {
    const isSorted = column.getIsSorted()
    return h(UButton, {
      color: 'neutral',
      variant: 'ghost',
      label,
      icon: isSorted ? (isSorted === 'asc' ? 'i-lucide-arrow-up-narrow-wide' : 'i-lucide-arrow-down-wide-narrow') : 'i-lucide-arrow-up-down',
      class: '-mx-2.5',
      onClick: () => column.toggleSorting(column.getIsSorted() === 'asc')
    })
  }
}

export function selectColumn<T>() {
  const UCheckbox = resolveComponent('UCheckbox')
  return {
    id: 'select',
    header: ({ table: api }: { table: { getIsSomePageRowsSelected: () => boolean, getIsAllPageRowsSelected: () => boolean, toggleAllPageRowsSelected: (v: boolean) => void } }) => h(UCheckbox, {
      'modelValue': api.getIsSomePageRowsSelected() ? 'indeterminate' : api.getIsAllPageRowsSelected(),
      'onUpdate:modelValue': (value: boolean | 'indeterminate') => api.toggleAllPageRowsSelected(!!value),
      'ariaLabel': 'Selecionar todos'
    }),
    cell: ({ row }: { row: Row<T> }) => h(UCheckbox, {
      'modelValue': row.getIsSelected(),
      'onUpdate:modelValue': (value: boolean | 'indeterminate') => row.toggleSelected(!!value),
      'ariaLabel': 'Selecionar linha'
    })
  }
}
