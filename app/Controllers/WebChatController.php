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
            "SELECT w.*, c.id as channel_id FROM webchat_widgets w
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

        $conversation = $this->conversationService->createFromChannel(
            $sourceData,
            $widget['channel_id'],
            $widget['department_id']
        );

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

            if ($hasFile) {
                $uploaded = save_uploaded_file('file');
                if ($uploaded) {
                    $meta = [
                        'url'  => $uploaded['url'],
                        'name' => $uploaded['name'],
                        'size' => $uploaded['size'],
                    ];
                    Conversation::addMessage($conversation['id'], [
                        'type' => $uploaded['type'],
                        'content' => json_encode($meta),
                        'direction' => 'inbound',
                    ]);
                    Conversation::update($conversation['id'], [
                        'status' => $conversation['assigned_user_id'] ? 'open' : 'new',
                        'last_message_at' => date('Y-m-d H:i:s'),
                    ]);
                    Contact::update($conversation['contact_id'], ['last_contact_at' => date('Y-m-d H:i:s')]);
                }
            }

            if ($hasText) {
                $this->conversationService->receiveMessage($conversation['id'], trim($text));
            }

            $messages = Conversation::getMessages($conversation['id']);

            View::json([
                'success' => true,
                'messages' => $messages,
            ]);
        } else {
            $since = $request->input('since');
            if ($since) {
                $messages = Database::getInstance()->fetchAll(
                    "SELECT m.*, u.name as user_name
                     FROM messages m
                     LEFT JOIN users u ON u.id = m.user_id
                     WHERE m.conversation_id = ? AND m.created_at > ?
                     ORDER BY m.created_at ASC",
                    [$conversation['id'], $since]
                );
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

        $code = file_get_contents(__DIR__ . '/../../public/widget/chat.js');
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

        Conversation::addCsat($conversation['id'], $rating, $comment);
        View::json(['success' => true]);
    }
}
