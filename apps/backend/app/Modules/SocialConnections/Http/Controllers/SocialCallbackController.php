<?php

declare(strict_types=1);

namespace App\Modules\SocialConnections\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Exceptions\PlanLimitExceededException;
use App\Modules\SocialConnections\Exceptions\InvalidOAuthStateException;
use App\Modules\SocialConnections\Services\SocialConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Callback OAuth público del proveedor. Valida el state (single-use) dentro del
 * servicio, crea la conexión y redirige al SPA con el resultado.
 */
class SocialCallbackController extends Controller
{
    public function __construct(private readonly SocialConnectionService $service)
    {
    }

    public function handle(Request $request, string $provider): RedirectResponse
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        // Redes sociales muestra el aviso de cada resultado (el dashboard no).
        $social = $frontend . '/app/social?social=';

        if ($request->query('error')) {
            return redirect()->away($social . 'denied');
        }

        try {
            $connection = $this->service->complete(
                $provider,
                (string) $request->query('code', ''),
                (string) $request->query('state', ''),
            );

            return redirect()->away($frontend . '/app/brands/' . $connection->brand->public_id . '?social=connected');
        } catch (InvalidOAuthStateException) {
            return redirect()->away($social . 'invalid');
        } catch (PlanLimitExceededException) {
            return redirect()->away($social . 'limit');
        } catch (Throwable $e) {
            report($e);

            return redirect()->away($social . 'error');
        }
    }
}
