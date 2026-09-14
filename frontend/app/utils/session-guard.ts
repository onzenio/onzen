import type { Session, UserRole } from '~/types/monitoring'

function backendMessage(error: unknown): string | null {
  const message = (error as { data?: { message?: unknown } })?.data?.message
  return typeof message === 'string' && message.trim() !== '' ? message : null
}

/**
 * Verifica a sessão no BFF e o Role do usuário sem colapsar falhas do backend
 * em 403: 401 continua 401, 403 continua 403, 5xx sobe factual e falha de rede
 * (sem statusCode) vira 503. Role não autorizado resulta 403.
 */
export async function guardSessionRole(allowedRoles: UserRole[], restrictedMessage: string): Promise<void> {
  let session: Session

  try {
    session = await $fetch<Session>('/api/me')
  } catch (error: unknown) {
    const status = (error as { statusCode?: number })?.statusCode

    if (typeof status !== 'number') {
      throw createError({ statusCode: 503, statusMessage: 'Não foi possível falar com o backend. Tente novamente.' })
    }

    if (status === 401) {
      throw createError({ statusCode: 401, statusMessage: 'Autentique-se para acessar esta área.' })
    }

    if (status === 403) {
      throw createError({ statusCode: 403, statusMessage: restrictedMessage })
    }

    throw createError({ statusCode: status, statusMessage: backendMessage(error) ?? 'Falha ao verificar a sessão no backend.' })
  }

  if (!allowedRoles.includes(session.user.role)) {
    throw createError({ statusCode: 403, statusMessage: restrictedMessage })
  }
}
