<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\View;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Flow;
use App\Services\ConversationService;
use App\Services\FlowEngineService;

class WebChatController
{
    private ConversationService $conversationService;
    private FlowEngineService $flowEngine;

    public function __construct()
    {
        $this->conversationService = new ConversationService();
        $this->flowEngine = new FlowEngineService();
    }

    public function session(Request $request): void
    {
        $widgetKey = $request->input('widget_key');
        if (!$widgetKey) {
            View::json(['error' => 'widget_key é obrigatório'], 400);
        }

        $widget = Database::getInstance()->fetch(
            "SELECT w.*, c.id as channel_id, c.department_id as channel_department_id
             FROM webchat_widgets w
             JOIN channels c ON c.id = w.channel_id
             WHERE w.widget_key = ? AND w.is_active = 1",
            [$widgetKey]
        );

        if (!$widget) {
            View::json(['error' => 'Widget não encontrado ou inativo'], 404);
        }

        $name = $request->input('name', 'Visitante');
        $email = $request->input('email');
        $phone = $request->input('phone');
        $cnpj = $request->input('cnpj');

        $contact = Contact::findOrCreate($name, $email, $phone, ['cnpj' => $cnpj]);

        $sourceData = [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'source' => 'webchat',
        ];

        // Departamento do widget, com fallback para o do canal: garante que a
        // validação de horário por departamento funcione na conversa criada.
        $deptId = $widget['department_id'] ?? $widget['channel_department_id'] ?? null;

        $conversation = $this->conversationService->createFromChannel(
            $sourceData,
            $widget['channel_id'],
            $deptId ? (int) $deptId : null
        );

        // Nova webchat era silenciosa até o primeiro inbound: avisa a caixa já.
        try {
            \App\Services\NotificationService::notifyNewMessage(
                (int) $conversation['id'], 0, 'Nova conversa do site'
            );
        } catch (\Throwable $e) {
            error_log('webchat session notify error: ' . $e->getMessage());
        }

        // Start flow if configured
        if ($widget['flow_id']) {
            $this->flowEngine->start($conversation['id'], $widget['flow_id']);
        } elseif ($widget['welcome_message']) {
            Conversation::addMessage($conversation['id'], [
                'type' => 'text',
                'content' => $widget['welcome_message'],
                'direction' => 'outbound',
            ]);
        }

        View::json([
            'session_id' => $conversation['public_id'],
            'conversation_id' => $conversation['id'],
            'contact_id' => $contact['id'],
        ]);
    }

    public function resume(Request $request): void
    {
        $widgetKey = $request->input('widget_key');
        $publicId = $request->input('session_id');
        if (!$widgetKey || !$publicId) {
            View::json(['valid' => false], 400);
        }

        $widget = Database::getInstance()->fetch(
            "SELECT w.*, c.id as channel_id FROM webchat_widgets w
             JOIN channels c ON c.id = w.channel_id
             WHERE w.widget_key = ? AND w.is_active = 1",
            [$widgetKey]
        );

        if (!$widget) {
            View::json(['valid' => false], 404);
        }

        $conversation = Conversation::findByPublicId($publicId);
        if (!$conversation || in_array($conversation['status'], ['closed', 'resolved'], true)) {
            View::json(['valid' => false]);
        }

        $messages = Conversation::getMessages($conversation['id']);

        View::json([
            'valid' => true,
            'session_id' => $conversation['public_id'],
            'conversation_id' => $conversation['id'],
            'contact_id' => $conversation['contact_id'],
            'status' => $conversation['status'],
            'messages' => $messages,
        ]);
    }

    public function messages(Request $request): void
    {
        $publicId = $request->input('session_id');
        if (!$publicId) {
            View::json(['error' => 'session_id é obrigatório'], 400);
        }

        $conversation = Conversation::findByPublicId($publicId);
        if (!$conversation) {
            View::json(['error' => 'Sessão não encontrada'], 404);
        }

        if ($request->isPost()) {
            $text = $request->input('message');
            $file = $request->file('file');
            $hasFile = !empty($file['tmp_name']);
            $hasText = $text !== null && trim($text) !== '';

            if (!$hasFile && !$hasText) {
                View::json(['error' => 'Mensagem vazia'], 400);
                return;
            }

            // Citação do cliente (responder): valida que a citada é da conversa.
            $replyTo = (int) ($request->input('reply_to') ?? 0);
            if ($replyTo > 0) {
                $parent = Conversation::getMessage($replyTo);
                if (!$parent
                    || (int) ($parent['conversation_id'] ?? 0) !== (int) $conversation['id']
                    || in_array($parent['type'] ?? '', ['system', 'internal_note'], true)
                ) {
                    $replyTo = 0;
                }
            }

            if ($hasFile) {
                $uploaded = save_uploaded_file('file');
                if ($uploaded) {
                    $meta = [
                        'url'  => $uploaded['url'],
                        'name' => $uploaded['name'],
                        'size' => $uploaded['size'],
                        'path' => $uploaded['path'] ?? null,
                    ];
                    $mediaData = [
                        'type' => $uploaded['type'],
                        'content' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'direction' => 'inbound',
                    ];
                    if ($replyTo > 0) {
                        $mediaData['reply_to'] = $replyTo;
                    }
                    $mediaMsgId = Conversation::addMessage($conversation['id'], $mediaData);
                    Conversation::update($conversation['id'], [
                        'status' => $conversation['assigned_user_id'] ? 'waiting_customer' : 'new',
                        'last_message_at' => date('Y-m-d H:i:s'),
                    ]);
                    Contact::update($conversation['contact_id'], ['last_contact_at' => date('Y-m-d H:i:s')]);

                    try {
                        $label = ['image' => 'Imagem', 'audio' => 'Áudio', 'video' => 'Vídeo', 'file' => 'Arquivo'][$uploaded['type']] ?? 'Mídia';
                        \App\Services\NotificationService::notifyNewMessage(
                            (int) $conversation['id'],
                            (int) $mediaMsgId,
                            $label
                        );
                    } catch (\Throwable $e) {
                        error_log('webchat media notify error: ' . $e->getMessage());
                    }

                    // Mídia também é mensagem do cliente: valida horário do departamento.
                    try {
                        $this->conversationService->maybeSendAbsence((int) $conversation['id']);
                    } catch (\Throwable $e) {
                        error_log('webchat media absence error: ' . $e->getMessage());
                    }
                }
            }

            if ($hasText) {
                $this->conversationService->receiveMessage($conversation['id'], trim($text), 'text', null, $replyTo > 0 ? $replyTo : null);
            }

            $messages = Conversation::getMessages($conversation['id']);

            $convUpdated = Conversation::findByPublicId($publicId);
            View::json([
                'success' => true,
                'messages' => $messages,
                'conversation' => [
                    'id' => $convUpdated['id'],
                    'status' => $convUpdated['status'],
                ],
            ]);
        } else {
            $since = $request->input('since');
            if ($since) {
                // Inclui mensagens EDITADAS (updated_at novo) para o cliente
                // ver edições/reações do atendente sem recarregar a página.
                $messages = Database::getInstance()->fetchAll(
                    "SELECT m.*, u.name as user_name, u.avatar as user_avatar, ct.avatar as contact_avatar
                     FROM messages m
                     LEFT JOIN users u ON u.id = m.user_id
                     JOIN conversations c ON c.id = m.conversation_id
                     LEFT JOIN contacts ct ON ct.id = c.contact_id
                     WHERE m.conversation_id = ? AND (m.created_at > ? OR m.updated_at > ?)
                     ORDER BY m.created_at ASC",
                    [$conversation['id'], $since, $since]
                );
                foreach ($messages as &$m) {
                    $m['avatar_url'] = $m['direction'] === 'inbound'
                        ? ($m['contact_avatar'] ?? null)
                        : ($m['user_avatar'] ?? null);
                }
                unset($m);
                // Anexa a citada (reply_to_data) como em getMessages,
                // para o widget renderizar o bloco de citação no poll.
                $rqIds = array_values(array_filter(array_column($messages, 'reply_to')));
                if ($rqIds) {
                    $ph = implode(',', array_fill(0, count($rqIds), '?'));
                    $rqs = Database::getInstance()->fetchAll(
                        "SELECT id, content, type, direction, user_id FROM messages WHERE id IN ({$ph})",
                        $rqIds
                    );
                    $rqMap = [];
                    foreach ($rqs as $r) { $rqMap[$r['id']] = $r; }
                    foreach ($messages as &$m) {
                        if (!empty($m['reply_to']) && isset($rqMap[$m['reply_to']])) {
                            $m['reply_to_data'] = $rqMap[$m['reply_to']];
                        }
                    }
                    unset($m);
                }
            } else {
                $messages = Conversation::getMessages($conversation['id']);
            }

            View::json([
                'messages' => $messages,
                'conversation' => [
                    'id' => $conversation['id'],
                    'status' => $conversation['status'],
                ],
            ]);
        }
    }

    public function widgetJs(Request $request): void
    {
        header('Content-Type: application/javascript');
        header('Cache-Control: no-cache, private');

        $base = __DIR__ . '/../../public';
        $code = file_get_contents($base . '/widget/chat.js');
        $recorder = $base . '/assets/js/audio_recorder.js';
        if (is_file($recorder)) {
            $code .= "\n\n" . file_get_contents($recorder);
        }
        echo $code;
        exit;
    }

    public function widgetCss(Request $request): void
    {
        header('Content-Type: text/css');
        header('Cache-Control: no-cache, private');

        $code = file_get_contents(__DIR__ . '/../../public/widget/chat.css');
        echo $code;
        exit;
    }

    public function demo(Request $request, string $key): void
    {
        $widget = Database::getInstance()->fetch(
            "SELECT w.*, c.id as channel_id, f.name as flow_name
             FROM webchat_widgets w
             JOIN channels c ON c.id = w.channel_id
             LEFT JOIN flows f ON f.id = w.flow_id
             WHERE w.widget_key = ?",
            [$key]
        );

        if (!$widget) {
            http_response_code(404);
            echo 'Widget não encontrado';
            exit;
        }

        View::render('settings/demo', [
            'widget' => $widget,
        ]);
    }

    public const CLIENT_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏', '🔥', '👏', '😍'];

    /**
     * Localiza a conversa do cliente pela sessão pública do widget.
     * Retorna null quando a sessão é inválida ou a conversa foi encerrada.
     */
    private function resolveClientConversation(?string $publicId): ?array
    {
        if (!$publicId) {
            return null;
        }
        $conversation = Conversation::findByPublicId($publicId);
        if (!$conversation || in_array($conversation['status'], ['closed', 'resolved', 'spam'], true)) {
            return null;
        }
        return $conversation;
    }

    /**
     * POST /api/webchat/messages/{id}/edit — cliente edita a própria msg.
     */
    public function editMessage(Request $request, int $id): void
    {
        $conversation = $this->resolveClientConversation($request->input('session_id'));
        if (!$conversation) {
            View::json(['success' => false, 'error' => 'sessao_invalida'], 404);
            return;
        }
        $content = trim((string) $request->input('content'));
        if ($content === '' || mb_strlen($content) > 4000) {
            View::json(['success' => false, 'error' => 'conteudo_invalido'], 422);
            return;
        }
        $ok = Conversation::clientUpdateMessage((int) $conversation['id'], $id, $content);
        if (!$ok) {
            View::json(['success' => false, 'error' => 'edicao_nao_permitida'], 422);
            return;
        }
        View::json(['success' => true, 'message' => Conversation::getMessage($id)]);
    }

    /**
     * POST /api/webchat/messages/{id}/delete — cliente apaga a própria msg.
     */
    public function deleteMessage(Request $request, int $id): void
    {
        $conversation = $this->resolveClientConversation($request->input('session_id'));
        if (!$conversation) {
            View::json(['success' => false, 'error' => 'sessao_invalida'], 404);
            return;
        }
        $ok = Conversation::clientDeleteMessage((int) $conversation['id'], $id);
        if (!$ok) {
            View::json(['success' => false, 'error' => 'exclusao_nao_permitida'], 422);
            return;
        }
        View::json(['success' => true]);
    }

    /**
     * POST /api/webchat/messages/{id}/reaction — cliente reage (toggle).
     */
    public function reactMessage(Request $request, int $id): void
    {
        $conversation = $this->resolveClientConversation($request->input('session_id'));
        if (!$conversation) {
            View::json(['success' => false, 'error' => 'sessao_invalida'], 404);
            return;
        }
        $emoji = trim((string) $request->input('reaction'));
        if (!in_array($emoji, self::CLIENT_REACTIONS, true)) {
            View::json(['success' => false, 'error' => 'reacao_invalida'], 422);
            return;
        }
        $ok = Conversation::clientToggleReaction(
            (int) $conversation['id'],
            $id,
            $emoji,
            (int) $conversation['contact_id']
        );
        if (!$ok) {
            View::json(['success' => false, 'error' => 'reacao_nao_permitida'], 422);
            return;
        }
        View::json(['success' => true, 'message' => Conversation::getMessage($id)]);
    }

    /**
     * Recebe a avaliação CSAT enviada pelo widget do ChatWeb.
     */
    public function csat(Request $request): void
    {
        $publicId = $request->input('session_id');
        $rating = (int) $request->input('rating');
        $comment = trim((string) $request->input('comment'));

        if ($rating < 1 || $rating > 5) {
            View::json(['success' => false, 'error' => 'rating_invalido'], 400);
            return;
        }

        $conversation = Conversation::findByPublicId($publicId);
        if (!$conversation) {
            View::json(['success' => false, 'error' => 'sessao_invalida'], 404);
            return;
        }

        // Uma avaliação por conversa: não sobrescreve voto existente.
        if (Conversation::getCsat($conversation['id'])) {
            View::json(['success' => false, 'error' => 'ja_avaliado'], 409);
            return;
        }

        Conversation::addCsat($conversation['id'], $rating, $comment);
        View::json(['success' => true]);
    }
}
