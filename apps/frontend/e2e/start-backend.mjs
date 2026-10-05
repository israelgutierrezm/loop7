/**
 * Backend aislado para las pruebas E2E: escribe `apps/backend/.env.e2e`
 * (ignorado por git), recrea una base SQLite propia con las migraciones y los
 * datos base, y sirve Laravel en su propio puerto. Nunca toca la base de datos
 * ni el puerto de desarrollo.
 */
import { execFileSync, spawn } from 'node:child_process'
import { existsSync, rmSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const backend = resolve(here, '../../backend')
const database = resolve(backend, 'database/e2e.sqlite').replaceAll('\\', '/')
const port = process.env.E2E_BACKEND_PORT ?? '8002'
const frontend = process.env.E2E_FRONTEND_URL ?? 'http://localhost:5174'
const php = process.env.PHP_BINARY ?? 'php'
const env = { ...process.env, XDEBUG_MODE: 'off' }

writeFileSync(
  resolve(backend, '.env.e2e'),
  [
    'APP_NAME=Loop7',
    'APP_ENV=e2e',
    'APP_KEY=',
    'APP_DEBUG=true',
    `APP_URL=http://127.0.0.1:${port}`,
    `FRONTEND_URL=${frontend}`,
    'APP_LOCALE=es',
    'LOG_CHANNEL=single',
    'LOG_LEVEL=warning',
    'DB_CONNECTION=sqlite',
    `DB_DATABASE="${database}"`,
    // Sin esto, cada escritura espera al disco (en Windows, minutos para migrar).
    'DB_JOURNAL_MODE=WAL',
    'DB_SYNCHRONOUS=OFF',
    'DB_BUSY_TIMEOUT=10000',
    'SESSION_DRIVER=file',
    'SESSION_DOMAIN=',
    `SANCTUM_STATEFUL_DOMAINS=${new URL(frontend).host}`,
    `CORS_ALLOWED_ORIGINS=${frontend}`,
    'CACHE_STORE=file',
    'QUEUE_CONNECTION=sync',
    'MAIL_MAILER=log',
    'FILESYSTEM_DISK=local',
    'BROADCAST_CONNECTION=log',
    '',
  ].join('\n'),
)

rmSync(database, { force: true })
writeFileSync(database, '')

// En Windows, OpenSSL necesita su openssl.cnf para crear claves EC (avisos push):
// se usa el que trae PHP si no hay uno configurado.
if (process.platform === 'win32' && !env.OPENSSL_CONF) {
  const phpDir = execFileSync(php, ['-r', 'echo dirname(PHP_BINARY);'], { env }).toString().trim()
  const cnf = resolve(phpDir, 'extras/ssl/openssl.cnf')
  if (existsSync(cnf)) env.OPENSSL_CONF = cnf
}

const artisan = (...args) => execFileSync(php, ['artisan', ...args, '--env=e2e'], { cwd: backend, env, stdio: 'inherit' })
artisan('key:generate', '--force')
artisan('migrate:fresh', '--seed', '--force')

const server = spawn(php, ['artisan', 'serve', '--env=e2e', '--host=127.0.0.1', `--port=${port}`], {
  cwd: backend,
  env,
  stdio: 'inherit',
})

const stop = () => {
  server.kill()
  process.exit(0)
}
process.on('SIGINT', stop)
process.on('SIGTERM', stop)
server.on('exit', (code) => process.exit(code ?? 0))
