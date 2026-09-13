// @types/node não é dependência do projeto (pnpm-lock.yaml intocável):
// superfície mínima de node:crypto usada pelo selo de sessão do BFF.
declare module 'node:crypto' {
  export interface CipherGCM {
    update(data: string, inputEncoding: 'utf8'): Uint8Array
    final(): Uint8Array
    getAuthTag(): Uint8Array
  }
  export interface DecipherGCM {
    setAuthTag(tag: Uint8Array): void
    update(data: Uint8Array): Uint8Array
    final(): Uint8Array
  }
  export function randomBytes(size: number): Uint8Array
  export function scryptSync(password: string, salt: string, keylen: number): Uint8Array
  export function createCipheriv(algorithm: 'aes-256-gcm', key: Uint8Array, iv: Uint8Array): CipherGCM
  export function createDecipheriv(algorithm: 'aes-256-gcm', key: Uint8Array, iv: Uint8Array): DecipherGCM
}
