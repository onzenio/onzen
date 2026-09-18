import type { Row } from '@tanstack/table-core'
import type { TableApi } from '~/utils/table-chrome'

export interface UseTableStateOptions {
  /** Coluna ligada à busca textual (v-model:search). Omitir em tabelas sem busca. */
  searchColumn?: string
  /** Coluna ligada ao filtro do toolbar/USelect (filterValue). Omitir quando não houver. */
  filterColumn?: string
  /** Total exibido no rodapé antes da tabela montar. */
  getTotal?: () => number
  /** Tamanho inicial da página. */
  pageSize?: number
}

export interface UseTableStateReturn<T> {
  columnFilters: Ref<{ id: string, value: unknown }[]>
  columnVisibility: Ref<Record<string, boolean> | undefined>
  rowSelection: Ref<Record<string, boolean>>
  sorting: Ref<{ id: string, desc: boolean }[]>
  pagination: Ref<{ pageIndex: number, pageSize: number }>
  search: WritableComputedRef<string> | Ref<string>
  filterValue: Ref<string>
  selectedRows: ComputedRef<Row<T>[]>
  filteredCount: ComputedRef<number>
  resetPage: () => void
  clearSelection: () => void
}

/**
 * Estado client-side compartilhado das tabelas reais (extraído de clients.vue).
 * Busca filtra e volta à página 1; filtro por coluna idem; seleção e contagem
 * seguem a mesma regra; paginação preserva busca/filtros/ordenação/seleção.
 */
export function useTableState<T>(
  getApi: () => TableApi<T> | null | undefined,
  options: UseTableStateOptions = {}
): UseTableStateReturn<T> {
  const columnFilters = ref<{ id: string, value: unknown }[]>(
    options.searchColumn ? [{ id: options.searchColumn, value: '' }] : []
  )
  const columnVisibility = ref<Record<string, boolean> | undefined>(undefined)
  const rowSelection = ref<Record<string, boolean>>({})
  const sorting = ref<{ id: string, desc: boolean }[]>([])
  const pagination = ref({ pageIndex: 0, pageSize: options.pageSize ?? 10 })
  const filterValue = ref('all')

  function resetPage(): void {
    pagination.value.pageIndex = 0
  }

  function clearSelection(): void {
    rowSelection.value = {}
  }

  watch(filterValue, (newVal) => {
    if (!options.filterColumn) {
      resetPage()
      return
    }
    const column = getApi()?.getColumn(options.filterColumn)
    if (!column) return
    if (newVal === 'all') column.setFilterValue(undefined)
    else column.setFilterValue(newVal)
    resetPage()
  })

  let search: WritableComputedRef<string> | Ref<string>
  if (options.searchColumn) {
    const columnId = options.searchColumn
    search = computed({
      get: (): string => (getApi()?.getColumn(columnId)?.getFilterValue() as string) || '',
      set: (value: string) => {
        getApi()?.getColumn(columnId)?.setFilterValue(value || undefined)
        resetPage()
      }
    })
  } else {
    search = ref('')
  }

  const selectedRows = computed((): Row<T>[] => getApi()?.getFilteredSelectedRowModel().rows ?? [])
  const filteredCount = computed((): number => getApi()?.getFilteredRowModel().rows.length ?? options.getTotal?.() ?? 0)

  return {
    columnFilters,
    columnVisibility,
    rowSelection,
    sorting,
    pagination,
    search,
    filterValue,
    selectedRows,
    filteredCount,
    resetPage,
    clearSelection
  }
}
