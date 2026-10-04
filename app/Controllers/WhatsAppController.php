<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\View;
use App\Models\WhatsAppConnection;
use App\Services\WhatsAppService;

class WhatsAppController
{
    private WhatsAppService $service;

    public function __construct()
    {
        $this->service = new WhatsAppService();
    }

    /**
     * Inicia o pareamento e retorna o QR Code (JSON).
     */
    public function connect(Request $request, int $id): void
    {
        try {
            $instanceName = $request->input('instance_name');
            $result = $this->service->connectChannel($id, $instanceName ?: null);
            View::json($result);
        } catch (\Throwable $e) {
            error_log('WhatsApp connect error: ' . $e->getMessage());
            View::json(['error' => env('APP_DEBUG', false) ? $e->getMessage() : 'Falha ao conectar.'], 500);
        }
    }

    /**
     * Consulta o estado da conexão (polling da UI).
     */
    public function status(Request $request, int $id): void
    {
        try {
            $result = $this->service->status($id);
            View::json($result);
        } catch (\Throwable $e) {
            error_log('WhatsApp status error: ' . $e->getMessage());
            View::json(['error' => env('APP_DEBUG', false) ? $e->getMessage() : 'Falha ao consultar status.'], 500);
        }
    }

    public function disconnect(Request $request, int $id): void
    {
        try {
            $this->service->disconnectChannel($id);
            $conn = WhatsAppConnection::findByChannel($id);
            View::json([
                'status' => $conn['status'] ?? 'disconnected',
                'phone_number' => $conn['phone_number'] ?? null,
                'qr_code' => null,
            ]);
        } catch (\Throwable $e) {
            error_log('WhatsApp disconnect error: ' . $e->getMessage());
            View::json(['error' => env('APP_DEBUG', false) ? $e->getMessage() : 'Falha ao desconectar.'], 500);
        }
    }

    /**
     * Recebe os webhooks do provedor (rota pública, sem auth).
     */
    public function webhook(Request $request): void
    {
        $raw = file_get_contents('php://input');
        // Log mínimo sem PII/segredos: nunca gravar query (?secret=) nem corpo.
        @file_put_contents(dirname(__DIR__, 2) . '/storage/logs/webhook.log',
            '[' . date('Y-m-d H:i:s') . '] webhook method=' . ($_SERVER['REQUEST_METHOD'] ?? '?')
            . ' bytes=' . strlen((string) $raw) . PHP_EOL, FILE_APPEND);

        $payload = json_decode($raw, true) ?: [];
        if (empty($payload) && !empty($_POST)) {
            $payload = $_POST;
        }

        try {
            $this->service->handleWebhook($payload, (string) $raw);
        } catch (\Throwable $e) {
            // Nunca quebramos o webhook do provedor; registramos no log.
            error_log('WhatsApp webhook error: ' . $e->getMessage());
        }

        View::json(['ok' => true]);
    }
}
