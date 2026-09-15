import type { Row } from '@tanstack/table-core'
import type { TableApi } from '~/utils/table-chrome'

export interface TableCsvLabels {
  /** Descrição do aviso "Nada para exportar" (PT-BR, específica do domínio). */
  emptyDescription: string
  /** Unidade do sucesso, ex. "cliente(s) exportado(s)". */
  exportedUnit: string
}

/**
 * Regra única de exportação CSV sobre o `exportToCsv` existente:
 * linhas filtradas por padrão, apenas selecionadas quando houver seleção,
 * valores como exibidos (rótulos PT-BR via mapRow do domínio) e aviso
 * "Nada para exportar" sem gerar arquivo vazio.
 */
export function useTableCsv<T>(getApi: () => TableApi<T> | null | undefined) {
  const toast = useToast()

  function exportCsv(
    filename: string,
    mapRow: (row: T) => Record<string, unknown>,
    labels: TableCsvLabels
  ): void {
    const api = getApi()
    const selected = api?.getFilteredSelectedRowModel().rows ?? []
    const source = selected.length > 0 ? selected : (api?.getFilteredRowModel().rows ?? [])
    const rows = source.map((r: Row<T>) => r.original)
    if (rows.length === 0) {
      toast.add({ title: 'Nada para exportar', description: labels.emptyDescription, color: 'warning' })
      return
    }
    exportToCsv(filename, rows.map(mapRow))
    toast.add({ title: 'CSV exportado', description: `${rows.length} ${labels.exportedUnit}`, color: 'success' })
  }

  return { exportCsv }
}
