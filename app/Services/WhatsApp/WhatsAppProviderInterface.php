<?php

namespace App\Services\WhatsApp;

/**
 * Contrato comum a todos os provedores de WhatsApp (WAHA, Evolution API, etc.).
 *
 * O provedor trabalha sempre com um "array de conexão" (a linha de whatsapp_connections)
 * e nunca conhece a camada de banco de dados do sistema — assim a troca de provedor
 * não exige alteração nos controladores ou no service de orquestração.
 */
interface WhatsAppProviderInterface
{
    /**
     * Nome curto e estável do provedor (ex.: "waha").
     */
    public function getName(): string;

    /**
     * Cria a instância/sessão remota no provedor.
     *
     * @param array $config Dados do canal/tenant (nome sugerido, tokens de admin, etc.)
     * @return array{provider_id:string, token:?string, secret:?string, extra:array}
     *             provider_id = identificador usado para casar webhooks
     *             token       = token da instância (pode ser nulo se o provedor não retornar)
     */
    public function initConnection(array $config): array;

    /**
     * Inicia o pareamento (QR Code / pairing code).
     *
     * @param array $connection Linha de whatsapp_connections
     * @return array{qr_code:?string, status:string} qr_code em formato data-URL quando aplicável
     */
    public function connect(array $connection): array;

    /**
     * Consulta o estado atual da conexão.
     *
     * @return array{status:string, phone_number:?string, qr_code:?string}
     */
    public function getStatus(array $connection): array;

    /**
     * Encerra a sessão no provedor (mantém o registro local).
     */
    public function disconnect(array $connection): void;

    /**
     * Remove definitivamente a instância no provedor.
     */
    public function deleteConnection(array $connection): void;

    /**
     * Registra a URL de webhook no provedor.
     */
    public function setWebhook(array $connection, string $url): void;

    /**
     * Envia uma mensagem pela conversa.
     *
     * @param array  $connection Linha de whatsapp_connections
     * @param string $to         Número do destinatário em formato internacional (somente dígitos)
     * @param string $type       text|image|audio|video|file
     * @param string $content    Texto da mensagem OU JSON com metadados de mídia
     * @param array  $options    Opções extras (caption, mimetype, etc.)
     * @return array{provider_message_id:?string, raw:mixed}
     */
    public function send(array $connection, string $to, string $type, string $content, array $options = []): array;

    /**
     * Normaliza o payload bruto do webhook em uma IncomingMessage.
     * Retorna null quando o evento deve ser ignorado (ex.: mensagem enviada pela própria API).
     */
    public function parseWebhook(array $payload): ?IncomingMessage;

    /**
     * Resolve a mídia de uma mensagem recebida, devolvendo a URL direta de download.
     * Muitos provedores entregam apenas o id da mensagem no webhook e exigem esta
     * chamada adicional para obter o arquivo.
     *
     * @return array{fileURL:string, mime:?string, name:?string}|null
     */
    public function downloadMedia(array $connection, string $messageId): ?array;

    /**
     * Edita o conteúdo de uma mensagem já enviada (funcionalidade nativa do WhatsApp).
     *
     * @param array  $connection Linha de whatsapp_connections
     * @param string $messageId  ID da mensagem no provedor (channel_message_id)
     * @param string $text       Novo conteúdo de texto
     * @return bool true quando a edição foi aceita pelo provedor
     */
    public function editMessage(array $connection, string $messageId, string $text): bool;

    /**
     * Apaga uma mensagem para todos os participantes (delete for everyone).
     *
     * @param array  $connection Linha de whatsapp_connections
     * @param string $messageId  ID da mensagem no provedor (channel_message_id)
     * @return bool true quando a exclusão foi aceita pelo provedor
     */
    public function deleteMessage(array $connection, string $messageId): bool;

    /**
     * Resolve o número de telefone real de um contato a partir do ID do provedor (ex.: LID -> telefone).
     * Retorna null se não for possível resolver.
     */
    public function resolvePhone(array $connection, string $contactId): ?string;

    /**
     * Envia uma reação (emoji) a uma mensagem existente.
     *
     * @param array  $connection Linha de whatsapp_connections
     * @param string $messageId  ID da mensagem a ser reagida (channel_message_id)
     * @param string $reaction   Emoji da reação (string vazia para remover)
     * @return bool true se a reação foi aceita pelo provedor
     */
    public function sendReaction(array $connection, string $messageId, string $reaction): bool;

    /**
     * Obtém a URL da foto de perfil de um contato no WhatsApp.
     *
     * @param array  $connection Linha de whatsapp_connections
     * @param string $contactId  ID do contato no provedor (ex.: 5511999999999@c.us)
     * @return string|null URL da foto (absoluta ou data-URL) ou null se não disponível
     */
    public function getProfilePicture(array $connection, string $contactId): ?string;
}
