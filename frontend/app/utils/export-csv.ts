export function exportToCsv(filename: string, rows: Record<string, unknown>[]): void {
  const header = rows.length > 0 ? Object.keys(rows[0] as Record<string, unknown>) : []
  const escape = (value: unknown): string => {
    const text = value === null || value === undefined ? '' : String(value)
    return `"${text.replace(/"/g, '""')}"`
  }
  const lines = [header.map(escape).join(','), ...rows.map(row => header.map(key => escape((row as Record<string, unknown>)[key])).join(','))]
  const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename.endsWith('.csv') ? filename : `${filename}.csv`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
}
