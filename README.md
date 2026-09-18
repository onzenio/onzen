# OneFisc — Ambiente de desenvolvimento (Docker)

Perfil dev com **bind mounts live**: editar arquivos em `./backend` ou
`./frontend` reflete automaticamente nos containers, **sem rebuild**.

- Backend: `./backend:/app` (com `vendor` em volume nomeado `backend-vendor`)
- Frontend: `./frontend:/app` (com `node_modules` em `frontend-node-modules`)

## Por que dois arquivos?

O `docker-compose.yml` base publica as portas padrão (5432, 6379, 4222…),
que **colidem com a stack wzap** (5432, 4222/8222, 8081). O override
`docker-compose.dev.yml` remapeia só as portas do host — dentro da rede
`app` os serviços seguem nas portas padrão, então nenhum env interno muda.

| Serviço  | Host (dev) | Container | Obs                    |
|----------|------------|-----------|------------------------|
| backend  | 8000       | 8000      | `GET /up` (health)     |
| frontend | 3000       | 3000      | Nuxt dev               |
| postgres | 5433       | 5432      | wzap usa 5432          |
| redis    | 6380       | 6379      |                        |
| nats     | 4223       | 4222      | wzap usa 4222          |
| nats mgmt| 8223       | 8222      | wzap usa 8222          |

Pré-requisito: `SESSION_SECRET` definido no `.env` da raiz
(`openssl rand -hex 32`).

## Subir / descer

```bash
# Sobe tudo (infra + backend + queue workers + frontend)
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build

# Acompanhar subida (primeira vez instala deps: composer + pnpm, demora)
docker compose -f docker-compose.yml -f docker-compose.dev.yml logs -f backend frontend

# Status / saúde
docker compose -f docker-compose.yml -f docker-compose.dev.yml ps

# Derrubar (mantém volumes de dados e caches)
docker compose -f docker-compose.yml -f docker-compose.dev.yml down
```

> Atalho: exporte `COMPOSE_FILE=docker-compose.yml:docker-compose.dev.yml`
> e use `docker compose …` normalmente.

## Verificar

```bash
curl http://127.0.0.1:8000/up                                   # backend → "OK"
curl -o /dev/null -w '%{http_code}\n' http://127.0.0.1:3000/    # frontend → 200
```

## Comandos do dia a dia

```bash
F="-f docker-compose.yml -f docker-compose.dev.yml"

# Logs
docker compose $F logs -f backend        # Laravel (artisan serve)
docker compose $F logs -f queue          # worker default
docker compose $F logs -f serpro-queue   # worker serpro
docker compose $F logs -f frontend       # Nuxt dev
docker compose $F logs -f postgres redis nats

# Artisan (backend)
docker compose $F exec backend php artisan migrate
docker compose $F exec backend php artisan tinker
docker compose $F exec backend composer test   # PHPUnit

# Frontend
docker compose $F exec frontend pnpm run lint
docker compose $F exec frontend pnpm run typecheck
docker compose $F exec frontend pnpm add <pkg>   # atualiza pnpm-lock.yaml local via bind mount

# Infra (do host)
psql 'postgres://onefisc:secret@127.0.0.1:5433/onefisc'
redis-cli -p 6380 ping
curl http://127.0.0.1:8223/healthz   # NATS monitoring
```

## Notas

- O entrypoint do backend roda `composer install` + `migrate --force`
  (`RUN_MIGRATIONS=1`); o do frontend roda `pnpm install --frozen-lockfile`.
- `queue` e `serpro-queue` sobem após o backend ficar saudável.
- Rebuild só é necessário ao mudar `Dockerfile` ou entrypoints:
  `docker compose $F up -d --build <serviço>`.
- Nunca commite `.env` (cada app tem seu `.env.example`).
