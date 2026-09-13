import type { FetchError } from 'ofetch'

interface BackendErrorData {
  message?: string
  errors?: Record<string, string[]>
}

function errorData(error: unknown): BackendErrorData | null {
  const data = (error as FetchError<BackendErrorData> | null)?.data
  return data && typeof data === 'object' ? data : null
}

export function backendMessage(error: unknown, fallback = 'Algo deu errado. Tente novamente.'): string {
  const message = errorData(error)?.message
  return typeof message === 'string' && message.length > 0 ? message : fallback
}

export function backendFormErrors(error: unknown): { name: string, message: string }[] {
  const errors = errorData(error)?.errors
  if (!errors || typeof errors !== 'object') {
    return []
  }
  return Object.entries(errors)
    .map(([name, messages]) => ({
      name,
      message: Array.isArray(messages) ? (messages[0] ?? '') : String(messages ?? '')
    }))
    .filter(item => item.message.length > 0)
}
