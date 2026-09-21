# Estructura recomendada del repositorio

## Opción recomendada: monorepo
```
/
  apps/
    backend/      # Laravel 12
    frontend/     # Vue 3 + TS
  docs/
  infra/
  CLAUDE.md
  README.md
```

También es válido servir Vue mediante el mismo dominio detrás de Nginx. Mantener separación clara entre backend y frontend.

## Backend sugerido
```
app/
  Modules/
    Identity/
    Organizations/
    Brands/
    Social/
    Content/
    Publishing/
    AI/
    Billing/
    Payments/
    Analytics/
    Audit/
```

## Frontend sugerido
```
src/
  app/
  layouts/
  modules/
  components/
  composables/
  stores/
  router/
  services/
  types/
  utils/
```

Organizar frontend por feature antes que por tipo cuando el módulo crezca.
