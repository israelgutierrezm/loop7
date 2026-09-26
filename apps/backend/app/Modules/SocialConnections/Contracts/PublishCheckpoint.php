<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Contracts;

use Closure;

/**
 * Progreso de una publicación que sobrevive a los reintentos del job (p. ej. el
 * contenedor de Instagram que Meta ya está procesando, o el id del post ya
 * publicado). Cada cambio se guarda en el acto: si el worker muere a mitad, el
 * siguiente intento parte de ahí en vez de volver a subir o duplicar.
 *
 * Sin persistencia (valor por defecto) funciona en memoria.
 */
final class PublishCheckpoint
{
    /**
     * @param  array<string, mixed>  $data
     * @param  (Closure(array<string, mixed>): void)|null  $persist
     */
    public function __construct(
        private array $data = [],
        private readonly ?Closure $persist = null,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->save();
    }

    public function forget(string $key): void
    {
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    private function save(): void
    {
        if ($this->persist !== null) {
            ($this->persist)($this->data);
        }
    }
}
