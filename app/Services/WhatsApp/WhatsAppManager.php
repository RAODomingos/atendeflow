<?php

namespace App\Services\WhatsApp;

/**
 * Fábrica de provedores de WhatsApp.
 *
 * Permite trocar o provedor (WAHA, Evolution, Twilio, etc.) sem tocar no restante
 * do sistema: basta registrar uma nova implementação de WhatsAppProviderInterface e
 * apontar a configuração para ela.
 */
class WhatsAppManager
{
    /** @var array<string, WhatsAppProviderInterface> */
    private static array $instances = [];

    /**
     * Mapa provedor => classe. Para adicionar um novo provedor, registre aqui.
     *
     * 'waha' continua registrado por compatibilidade: conexões já existentes
     * seguem funcionando. Ele é ocultado da lista de seleção de novos canais
     * (ver config/integrations.php -> whatsapp.hidden_providers).
     */
    private static array $registry = [
        'uazapi' => UazapiProvider::class,
        'waha' => WahaProvider::class,
    ];

    public static function register(string $name, string $class): void
    {
        self::$registry[$name] = $class;
    }

    public static function defaultProviderName(): string
    {
        $integrations = require dirname(__DIR__, 3) . '/config/integrations.php';
        return $integrations['whatsapp']['provider'] ?? 'uazapi';
    }

    /**
     * Provedores que podem ser escolhidos na UI (canal novo).
     *
     * Provedores listados em 'hidden_providers' permanecem registrados e
     * funcionais para conexões existentes, mas não são oferecidos.
     */
    public static function availableProviders(): array
    {
        $integrations = require dirname(__DIR__, 3) . '/config/integrations.php';
        $configured = $integrations['whatsapp']['providers'] ?? [];
        $hidden = $integrations['whatsapp']['hidden_providers'] ?? [];
        $names = array_unique(array_merge(array_keys(self::$registry), array_keys($configured)));
        return array_values(array_diff($names, $hidden));
    }

    public static function provider(string $name): WhatsAppProviderInterface
    {
        if (!isset(self::$instances[$name])) {
            if (!isset(self::$registry[$name])) {
                throw new \RuntimeException("Provedor de WhatsApp não registrado: {$name}");
            }
            $class = self::$registry[$name];
            self::$instances[$name] = new $class();
        }
        return self::$instances[$name];
    }

    /**
     * Resolve o provedor a partir de uma linha de conexão (whatsapp_connections).
     */
    public static function forConnection(array $connection): WhatsAppProviderInterface
    {
        $name = $connection['provider'] ?? self::defaultProviderName();
        return self::provider($name);
    }
}
