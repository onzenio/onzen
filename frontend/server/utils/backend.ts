import type { H3Event } from 'h3'
import { createCipheriv, createDecipheriv, randomBytes, scryptSync } from 'node:crypto'

declare const process: { env: Record<string, string | undefined> }

export const SESSION_COOKIE = 'onefisc_session'

const XSRF_COOKIE = 'XSRF-TOKEN'

export type BackendMethod = 'GET' | 'HEAD' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' | 'OPTIONS'

export interface SessionPayload {
  cookies: string[]
  xsrf: string
}

export interface BackendFetchOptions {
  method?: BackendMethod
  body?: Record<string, unknown>
  query?: Record<string, unknown>
  headers?: Record<string, string>
}

function sessionKey(): Uint8Array {
  const secret = process.env.SESSION_SECRET
  if (!secret || secret.length < 32) {
    throw createError({
      statusCode: 500,
      statusMessage: 'BFF session is not configured',
      message: 'SESSION_SECRET ausente ou curto (mínimo 32 caracteres).'
    })
  }
  return scryptSync(secret, 'onefisc-bff-session', 32)
}

function concatBytes(parts: Uint8Array[]): Uint8Array {
  const out = new Uint8Array(parts.reduce((total, part) => total + part.length, 0))
  let offset = 0
  for (const part of parts) {
    out.set(part, offset)
    offset += part.length
  }
  return out
}

function toBase64Url(bytes: Uint8Array): string {
  let binary = ''
  const chunk = 0x8000
  for (let i = 0; i < bytes.length; i += chunk) {
    binary += String.fromCharCode(...bytes.subarray(i, i + chunk))
  }
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

function fromBase64Url(value: string): Uint8Array {
  const binary = atob(value.replace(/-/g, '+').replace(/_/g, '/'))
  const out = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) {
    out[i] = binary.charCodeAt(i)
  }
  return out
}

export function seal(payload: SessionPayload): string {
  const iv = randomBytes(12)
  const cipher = createCipheriv('aes-256-gcm', sessionKey(), iv)
  const ciphertext = concatBytes([cipher.update(JSON.stringify(payload), 'utf8'), cipher.final()])
  return `${toBase64Url(iv)}.${toBase64Url(cipher.getAuthTag())}.${toBase64Url(ciphertext)}`
}

export function unseal(sealed: string): SessionPayload {
  const [ivPart, tagPart, dataPart] = sealed.split('.')
  if (!ivPart || !tagPart || !dataPart) {
    throw new Error('sealed session malformada')
  }
  const decipher = createDecipheriv('aes-256-gcm', sessionKey(), fromBase64Url(ivPart))
  decipher.setAuthTag(fromBase64Url(tagPart))
  const json = new TextDecoder().decode(concatBytes([decipher.update(fromBase64Url(dataPart)), decipher.final()]))
  const payload = JSON.parse(json) as Partial<SessionPayload>
  if (!Array.isArray(payload.cookies) || typeof payload.xsrf !== 'string') {
    throw new Error('sealed session com payload inválido')
  }
  return { cookies: payload.cookies.filter((c): c is string => typeof c === 'string'), xsrf: payload.xsrf }
}

export function readSession(event: H3Event): SessionPayload | null {
  const sealed = getCookie(event, SESSION_COOKIE)
  if (!sealed) {
    return null
  }
  try {
    return unseal(sealed)
  } catch {
    return null
  }
}

function storeSession(event: H3Event, payload: SessionPayload): void {
  setCookie(event, SESSION_COOKIE, seal(payload), {
    httpOnly: true,
    path: '/',
    sameSite: 'lax',
    secure: process.env.NODE_ENV === 'production'
  })
}

function backendBaseUrl(): string {
  const url = process.env.BACKEND_URL
  if (!url) {
    throw createError({
      statusCode: 500,
      statusMessage: 'Backend is not configured',
      message: 'BACKEND_URL não configurado.'
    })
  }
  return url.replace(/\/$/, '')
}

function frontendOrigin(event: H3Event): string {
  try {
    return getRequestURL(event).origin
  } catch {
    return 'http://localhost:3000'
  }
}

function splitSetCookie(header: string): { name: string, value: string } | null {
  const pair = header.split(';', 1)[0]?.trim()
  if (!pair) {
    return null
  }
  const eq = pair.indexOf('=')
  if (eq <= 0) {
    return null
  }
  return { name: pair.slice(0, eq).trim(), value: pair.slice(eq + 1).trim() }
}

function decodeCookieValue(value: string): string {
  try {
    return decodeURIComponent(value)
  } catch {
    return value
  }
}

function getSetCookieHeaders(headers: Headers): string[] {
  const getSetCookie = (headers as Headers & { getSetCookie?: () => string[] }).getSetCookie
  if (typeof getSetCookie === 'function') {
    return getSetCookie.call(headers)
  }
  const single = headers.get('set-cookie')
  return single ? [single] : []
}

function relaySetCookies(event: H3Event, setCookieHeaders: string[], session: SessionPayload | null): void {
  const jar = new Map<string, string>()
  for (const cookie of session?.cookies ?? []) {
    const eq = cookie.indexOf('=')
    if (eq > 0) {
      jar.set(cookie.slice(0, eq), cookie.slice(eq + 1))
    }
  }
  let xsrf = session?.xsrf ?? ''
  for (const header of setCookieHeaders) {
    const parsed = splitSetCookie(header)
    if (!parsed) {
      continue
    }
    jar.set(parsed.name, parsed.value)
    if (parsed.name === XSRF_COOKIE) {
      xsrf = decodeCookieValue(parsed.value)
    }
  }
  if (jar.size === 0) {
    return
  }
  storeSession(event, {
    cookies: [...jar].map(([name, value]) => `${name}=${value}`),
    xsrf
  })
}

async function bootstrapCsrf(base: string, origin: string): Promise<SessionPayload | null> {
  try {
    const response = await $fetch.raw(`${base}/sanctum/csrf-cookie`, {
      method: 'GET',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'Origin': origin
      }
    })
    const jar: string[] = []
    let xsrf = ''
    for (const header of getSetCookieHeaders(response.headers)) {
      const parsed = splitSetCookie(header)
      if (!parsed) {
        continue
      }
      jar.push(`${parsed.name}=${parsed.value}`)
      if (parsed.name === XSRF_COOKIE) {
        xsrf = decodeCookieValue(parsed.value)
      }
    }
    return jar.length > 0 ? { cookies: jar, xsrf } : null
  } catch (error) {
    const status = (error as { response?: { status?: number } }).response?.status
    if (!status) {
      throw createError({ statusCode: 502, statusMessage: 'Bad Gateway', message: 'Falha ao alcançar o backend.' })
    }
    return null
  }
}

export async function backendFetch<T = unknown>(event: H3Event, path: string, init: BackendFetchOptions = {}): Promise<T> {
  const base = backendBaseUrl()
  const method = init.method ?? 'GET'
  const origin = frontendOrigin(event)
  let session = readSession(event)
  if (!session && method !== 'GET' && method !== 'HEAD') {
    session = await bootstrapCsrf(base, origin)
  }
  const headers: Record<string, string> = {
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'Origin': origin,
    ...init.headers
  }
  if (session && session.cookies.length > 0) {
    headers.Cookie = session.cookies.join('; ')
  }
  if (session?.xsrf) {
    headers['X-XSRF-TOKEN'] = session.xsrf
  }
  let response
  try {
    response = await $fetch.raw<T>(`${base}${path}`, {
      method,
      body: init.body,
      query: init.query,
      headers
    })
  } catch (error) {
    const fetchError = error as { response?: { status?: number, headers?: Headers, _data?: unknown } }
    if (fetchError.response) {
      relaySetCookies(event, getSetCookieHeaders(fetchError.response.headers as Headers), session)
      throw createError({ statusCode: fetchError.response.status ?? 500, data: fetchError.response._data })
    }
    throw createError({ statusCode: 502, statusMessage: 'Bad Gateway', message: 'Falha ao alcançar o backend.' })
  }
  relaySetCookies(event, getSetCookieHeaders(response.headers), session)
  setResponseStatus(event, response.status)
  return response._data as T
}

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
