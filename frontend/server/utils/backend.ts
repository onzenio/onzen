import type { H3Event } from 'h3'

/**
 * Passthrough binário (guia de parcela): repassa cookies e devolve o corpo
 * com os cabeçalhos de download do backend. Erros JSON do backend são
 * repassados com o status original.
 */
export async function proxyBinaryToBackend(event: H3Event, path: string): Promise<unknown> {
  const base = (useRuntimeConfig(event).backendUrl as string | undefined)?.replace(/\/$/, '')

  if (!base) {
    setResponseStatus(event, 503)
    return { message: 'Backend não configurado (NUXT_BACKEND_URL).', code: 'BACKEND_UNAVAILABLE' }
  }

  const cookie = getRequestHeader(event, 'cookie')

  try {
    const res = await $fetch.raw<ArrayBuffer>(`${base}/api${path}`, {
      method: 'GET',
      responseType: 'arrayBuffer',
      headers: cookie ? { cookie } : {}
    })
    for (const [key, value] of res.headers.entries()) {
      if (['content-type', 'content-disposition', 'cache-control'].includes(key.toLowerCase())) {
        setResponseHeader(event, key, value)
      }
    }
    if (res._data === undefined) {
      setResponseStatus(event, 503)
      return { message: 'Download vazio.', code: 'BACKEND_UNAVAILABLE' }
    }
    return new Uint8Array(res._data)
  } catch (error: unknown) {
    const response = (error as { response?: { status?: number, _data?: unknown } })?.response
    if (response?.status) {
      setResponseStatus(event, response.status)
      if (response._data instanceof ArrayBuffer) {
        try {
          return JSON.parse(new TextDecoder().decode(response._data)) as unknown
        } catch {
          return { message: 'Falha no download.' }
        }
      }
      return response._data ?? { message: 'Falha no download.' }
    }
    setResponseStatus(event, 503)
    return { message: 'Não foi possível falar com o backend. Tente novamente.', code: 'BACKEND_UNAVAILABLE' }
  }
}
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
