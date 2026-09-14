import type { H3Event } from 'h3'

/**
 * BFF mínimo do monitoramento: repassa método, query, corpo e cookies de
 * sessão para o Laravel e devolve o corpo do backend sem alterações, de modo
 * que o contrato consumido pelas páginas seja exatamente o da API.
 *
 * Sem backend alcançável, responde 503 factual em vez de estourar.
 */
export async function proxyToBackend<T>(event: H3Event, path: string): Promise<T> {
  const base = (useRuntimeConfig(event).backendUrl as string | undefined)?.replace(/\/$/, '')

  if (!base) {
    setResponseStatus(event, 503)
    return { message: 'Backend não configurado (NUXT_BACKEND_URL).', code: 'BACKEND_UNAVAILABLE' } as T
  }

  const cookie = getRequestHeader(event, 'cookie')
  const method = getMethod(event)

  let body: unknown

  if (method !== 'GET' && method !== 'HEAD') {
    const contentType = getRequestHeader(event, 'content-type') ?? ''

    body = contentType.includes('multipart/form-data')
      ? await readRawBody(event, false)
      : await readBody(event).catch(() => undefined)
  }

  try {
    const data: unknown = await $fetch(`${base}/api${path}`, {
      method,
      query: getQuery(event),
      body: body as BodyInit | Record<string, unknown> | undefined,
      headers: {
        ...(cookie ? { cookie } : {}),
        ...(typeof body !== 'undefined' && getRequestHeader(event, 'content-type')
          ? { 'content-type': getRequestHeader(event, 'content-type') as string }
          : {})
      }
    })
    return data as T
  } catch (error: unknown) {
    const fetchError = error as { response?: { status?: number, _data?: unknown } }
    const status = fetchError?.response?.status

    if (status) {
      setResponseStatus(event, status)
      return (fetchError.response?._data ?? { message: 'Falha no backend.' }) as T
    }

    setResponseStatus(event, 503)
    return { message: 'Não foi possível falar com o backend. Tente novamente.', code: 'BACKEND_UNAVAILABLE' } as T
  }
}
