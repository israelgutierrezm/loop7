<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notifications\Http\Requests\StartWhatsAppVerificationRequest;
use App\Modules\Notifications\Services\NotificationChannels;
use App\Modules\Notifications\Services\WhatsAppVerification;
use App\Modules\Notifications\WhatsApp\WhatsAppException;
use App\Modules\Notifications\WhatsApp\WhatsAppNumber;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Número de WhatsApp del usuario para recibir avisos: se registra con un código
 * de verificación enviado por WhatsApp.
 */
class WhatsAppNumberController extends Controller
{
    public function __construct(
        private readonly WhatsAppVerification $verification,
        private readonly NotificationChannels $channels,
    ) {
    }

    /**
     * Envía el código de verificación al número.
     */
    public function store(StartWhatsAppVerificationRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->channels->whatsAppAvailableFor($user)) {
            throw ValidationException::withMessages(['phone' => 'Los avisos por WhatsApp no están incluidos en el plan de tus organizaciones.']);
        }

        try {
            $this->verification->start($user, $request->phone());
        } catch (WhatsAppException $e) {
            // El detalle técnico, para quien opera la plataforma; nunca el número.
            Log::warning('WhatsApp: no se pudo enviar el código de verificación.', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            throw ValidationException::withMessages(['phone' => 'No pudimos enviar el código a ese número. Revisa que tenga WhatsApp e inténtalo de nuevo.']);
        }

        return ApiResponse::success(['phone' => WhatsAppNumber::mask($request->phone())], 'Te enviamos un código por WhatsApp.');
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'digits:6']]);
        $phone = $this->verification->confirm($request->user(), $data['code']);

        return ApiResponse::success(['phone' => WhatsAppNumber::mask($phone)], 'Número verificado: recibirás avisos por WhatsApp.');
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->verification->remove($request->user());

        return ApiResponse::success(['phone' => null], 'Número eliminado: ya no recibirás avisos por WhatsApp.');
    }
}
