<?php

namespace App\Services\WhatsApp;

class UazapiProvider implements WhatsAppProviderInterface
{
    private array $config;
    private WhatsAppHttpClient $client;

    public function __construct()
    {
        $this->config = $this->loadConfig();
        $this->client = new WhatsAppHttpClient(
            $this->config['base_url'] ?? 'https://free.uazapi.com',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json']
        );
    }

    public function getName(): string
    {
        return 'uazapi';
    }

    public function initConnection(array $config): array
    {
        $instanceName = $config['instance_name'] ?? ('atendeflow_' . bin2hex(random_bytes(4)));
        $headers = $this->adminAuthHeaders();
        error_log('Uazapi initConnection: admintoken=' . ($headers['admintoken'] ?? ($headers['token'] ?? 'NONE')));

        try {
            $resp = $this->client->post('/instance/create', [
                'Name' => $instanceName,
            ], $headers);
            $body = $resp['body'] ?? [];
            error_log('Uazapi initConnection response: ' . json_encode($body, JSON_UNESCAPED_UNICODE));

            $instance = $body['instance'] ?? $body;
            $returnedName = $instance['name'] ?? $instance['instanceName'] ?? $instanceName;
            $returnedToken = $instance['token'] ?? $body['token'] ?? null;

            // Se o create não retornou token, busca na lista de instâncias
            if (!$returnedToken) {
                error_log('Uazapi initConnection: token not in create response, trying fetchInstances');
                $returnedToken = $this->fetchInstanceToken($returnedName);
                error_log('Uazapi initConnection: fetchInstances returned token=' . ($returnedToken ? substr($returnedToken, 0, 10) . '...' : 'NULL'));
            }

            return [
                'provider_id' => $returnedName,
                'instance_id' => $returnedName,
                'token' => $returnedToken,
                'secret' => null,
                'extra' => ['response' => $body],
            ];
        } catch (\Throwable $e) {
            error_log('Uazapi initConnection exception: ' . $e->getMessage());
            throw new \RuntimeException('Uazapi initConnection falhou: ' . $e->getMessage());
        }
    }

    private function fetchInstanceToken(string $instanceName): ?string
    {
        try {
            $headers = $this->adminAuthHeaders();
            $resp = $this->client->get('/instance/fetchInstances', $headers);
            $body = $resp['body'] ?? [];
            error_log('Uazapi fetchInstances response: ' . json_encode($body, JSON_UNESCAPED_UNICODE));
            if (!is_array($body)) {
                return null;
            }
            foreach ($body as $inst) {
                if (($inst['name'] ?? '') === $instanceName) {
                    return $inst['token'] ?? null;
                }
            }
        } catch (\Throwable $e) {
            error_log('Uazapi fetchInstances exception: ' . $e->getMessage());
        }
        return null;
    }



    public function connect(array $connection): array
    {
        $headers = $this->instanceAuthHeaders($connection);
        error_log('Uazapi connect: token=' . ($headers['token'] ?? ($headers['admintoken'] ?? 'NONE')) . ' instance_name=' . ($connection['instance_name'] ?? 'UNKNOWN'));

        try {
            $resp = $this->client->post('/instance/connect', null, $headers);
            $body = $resp['body'] ?? [];
            error_log('Uazapi connect response: ' . json_encode($body, JSON_UNESCAPED_UNICODE));
            $this->guard($body);

            $qr = $this->extractQr($body);

            if (!$qr) {
                error_log('Uazapi connect: QR code not found in response: ' . json_encode($body, JSON_UNESCAPED_UNICODE));
            }

            return [
                'qr_code' => $qr,
                'status' => 'waiting_qr',
            ];
        } catch (WhatsAppAuthException $e) {
            throw $e;
        } catch (\Throwable $e) {
            error_log('Uazapi connect exception: ' . $e->getMessage());
            return [
                'qr_code' => null,
                'status' => 'error',
            ];
        }
    }

    public function getStatus(array $connection): array
    {
        $headers = $this->instanceAuthHeaders($connection);

        try {
            $resp = $this->client->get('/instance/status', $headers);
            $body = $resp['body'] ?? [];
            error_log('Uazapi status response: ' . json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->guard($body);

            $status = $this->normalizeStatus($body);

            // Owner da API tem prioridade: a instância pode ter sido pareada
            // com outro número e o valor salvo estaria desatualizado.
            $phone = null;
            $instanceInfo = $body['instance'] ?? $body;
            $owner = $instanceInfo['owner'] ?? $body['owner'] ?? null;
            if ($owner) {
                $phone = preg_replace('/\D/', '', (string) $owner);
            }
            if (!$phone && !empty($connection['phone_number'])) {
                $phone = preg_replace('/\D/', '', (string) $connection['phone_number']);
            }

            $qr = null;
            if ($this->needsQr($status)) {
                $qr = $this->extractQr($body);
            }

            $instanceId = $instanceInfo['name'] ?? $instanceInfo['instanceName'] ?? null;

            return [
                'status' => $status,
                'phone_number' => $phone,
                'instance_id' => $instanceId,
                'qr_code' => $qr,
            ];
        } catch (WhatsAppAuthException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return [
                'status' => 'disconnected',
                'phone_number' => null,
                'instance_id' => null,
                'qr_code' => null,
            ];
        }
    }

    public function disconnect(array $connection): void
    {
        $headers = $this->instanceAuthHeaders($connection);
        try {
            $this->client->post('/instance/disconnect', null, $headers);
        } catch (\Throwable $e) {
        }
    }

    public function deleteConnection(array $connection): void
    {
        $name = $connection['instance_name'] ?? '';
        if (!$name) {
            return;
        }
        $headers = $this->adminAuthHeaders();
        try {
            $this->client->delete('/instance/delete/' . urlencode($name), null, $headers);
        } catch (\Throwable $e) {
        }
    }

    public function setWebhook(array $connection, string $url): void
    {
        $headers = $this->instanceAuthHeaders($connection);
        try {
            $resp = $this->client->post('/webhook', [
                'url' => $url,
                'enabled' => true,
                'addUrlEvents' => true,
                'addUrlTypesMessages' => true,
                'events' => ['messages', 'connection', 'qrcode'],
            ], $headers);
            $body = $resp['body'] ?? [];
            error_log('Uazapi setWebhook response: ' . json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable $e) {
            error_log('Uazapi setWebhook error: ' . $e->getMessage());
        }
    }

    public function send(array $connection, string $to, string $type, string $content, array $options = []): array
    {
        $phone = preg_replace('/\D/', '', $to);
        if (!$phone) {
            return ['provider_message_id' => null, 'raw' => []];
        }

        $headers = $this->instanceAuthHeaders($connection);

        if ($type === 'text') {
            $body = [
                'number' => $phone,
                'text' => $content,
                'delay' => 0,
            ];
            // Citação nativa (responder): ID da mensagem original no provedor.
            $quoted = $options['quoted_id'] ?? null;
            if (is_string($quoted) && $quoted !== '') {
                $body['replyid'] = $quoted;
            }
            $resp = $this->client->post('/send/text', $body, $headers);
            $this->guard($resp['body'] ?? []);
            return [
                'provider_message_id' => $this->extractMessageId($resp['body'] ?? []),
                'raw' => $resp['body'],
            ];
        }

        $meta = json_decode($content, true) ?: [];
        $mediaRef = $meta['url'] ?? $content;
        // 'path' (caminho relativo no disco) tem preferência sobre a URL para envio
        // em base64, já que a URL pública normalmente não é acessível pelo servidor
        // do provedor (ngrok, IP dinâmico, etc).
        $localPath = isset($meta['path']) && is_string($meta['path']) ? $meta['path'] : null;
        $caption = $options['caption'] ?? null;
        if (!is_string($caption) || trim($caption) === '') {
            $caption = null;
        }
        $name = $meta['name'] ?? ($options['file_name'] ?? '');
        $mime = $options['mimetype'] ?? ($meta['mime'] ?? null);

        $uazapiType = $this->uazapiMediaType($type, $localPath ?: $mediaRef, $mime);

        // Estratégia: envia o arquivo como está (formato original) e só converte se
        // a Uazapi rejeitar. Assim PNG fica PNG, WebP fica WebP (como sticker),
        // OGG/WebM/Opus são PTT, MP3/M4A/WAV são áudio. Nada vira "document"
        // automaticamente.
        $filePayload = $this->buildMediaFile($mediaRef, $localPath, $uazapiType, $mime);

        $body = [
            'number' => $phone,
            'type' => $uazapiType,
            'file' => $filePayload,
            'delay' => 0,
        ];
        if ($caption !== null) {
            $body['text'] = $caption;
        }
        if ($uazapiType === 'document') {
            $docName = $this->resolveDocumentName($name, $mediaRef);
            if ($docName !== '') {
                $body['docName'] = $docName;
            }
        }
        if ($mime && is_string($mime) && $mime !== '') {
            $body['mimetype'] = $mime;
        }
        // Citação nativa em mídia (responder com contexto).
        $quotedMedia = $options['quoted_id'] ?? null;
        if (is_string($quotedMedia) && $quotedMedia !== '') {
            $body['replyid'] = $quotedMedia;
        }

        $resp = $this->client->post('/send/media', $body, $headers);

        // Se a Uazapi rejeitar o formato (ex.: PNG/WebP não suportado em image),
        // tenta de novo JPEG. NÃO rebaixa para document (a Uazapi aceita document
        // para qualquer coisa, mas descaracteriza imagem/áudio na conversa).
        if (($resp['status'] ?? 0) >= 400 && $uazapiType === 'image' && $this->isImageFormatUnacceptable($mime, $localPath ?: $mediaRef)) {
            $jpegPath = $this->convertImageToJpeg($localPath, $mediaRef);
            if ($jpegPath !== null) {
                $filePayload = $this->readAsBase64DataUri($jpegPath, 'image/jpeg');
                $body['file'] = $filePayload;
                $body['mimetype'] = 'image/jpeg';
                @unlink($jpegPath);
                $resp = $this->client->post('/send/media', $body, $headers);
            }
        }

        if (($resp['status'] ?? 0) >= 400) {
            $errBody = $resp['body'] ?? [];
            $errMsg = is_array($errBody) ? (string)($errBody['message'] ?? $errBody['error'] ?? json_encode($errBody, JSON_UNESCAPED_UNICODE)) : (string) $errBody;
            error_log("Uazapi send error (type={$uazapiType}, status={$resp['status']}): {$errMsg}");
            // Propaga o erro para a UI em vez de devolver provider_message_id silenciosamente
            return [
                'provider_message_id' => null,
                'raw' => $resp['body'],
                'error' => $errMsg,
                'http_status' => $resp['status'] ?? 0,
            ];
        }

        return [
            'provider_message_id' => $this->extractMessageId($resp['body'] ?? []),
            'raw' => $resp['body'],
        ];
    }

    /**
     * Detecta se o formato da imagem provavelmente será rejeitado pela Uazapi
     * (plano free, em particular). WebP/BMP/GIF são convertidos preventivamente;
     * PNG é tentado como está primeiro (com mimetype) e só convertido se a
     * Uazapi devolver erro.
     */
    private function isImageFormatUnacceptable(?string $mime, string $mediaRef): bool
    {
        $m = strtolower((string) $mime);
        if (str_contains($m, 'webp') || str_contains($m, 'bmp') || str_contains($m, 'gif') || $m === 'image/x-icon') {
            return true;
        }
        $ext = strtolower(pathinfo(parse_url($mediaRef, PHP_URL_PATH) ?: $mediaRef, PATHINFO_EXTENSION));
        return in_array($ext, ['webp', 'bmp', 'gif', 'ico'], true);
    }

    /**
     * Converte uma imagem (PNG, WebP, GIF, BMP) para JPEG e devolve o caminho
     * absoluto do arquivo temporário criado. Retorna null se a conversão falhar.
     */
    private function convertImageToJpeg(?string $localPath, string $mediaRef): ?string
    {
        if (!function_exists('imagecreatefromwebp')) {
            return null;
        }
        $absPath = $localPath !== null && $localPath !== ''
            ? $this->resolveLocalFile($localPath)
            : $this->resolveLocalFile($mediaRef);
        if ($absPath === null || !is_file($absPath)) {
            return null;
        }
        $mime = (string) mime_content_type($absPath);
        if (in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
            return $absPath;
        }
        $im = match ($mime) {
            'image/webp' => $this->isAnimatedWebp($absPath) ? false : @imagecreatefromwebp($absPath),
            'image/png'  => @imagecreatefrompng($absPath),
            'image/gif'  => @imagecreatefromgif($absPath),
            'image/bmp'  => @imagecreatefrombmp($absPath),
            default      => false,
        };
        if (!$im) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'uazimg_') . '.jpg';
        if (!imagejpeg($im, $tmp, 92)) {
            imagedestroy($im);
            return null;
        }
        imagedestroy($im);
        return $tmp;
    }

    /**
     * Detecta WebP animado (chunk VP8X/ANIM presente). GD não decodifica.
     */
    private function isAnimatedWebp(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return false;
        }
        $header = fread($fh, 34);
        fclose($fh);
        if (strlen($header) < 34 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WEBP') {
            return false;
        }
        $chunk = substr($header, 12, 4);
        return $chunk === 'VP8X';
    }

    /**
     * Monta o campo `file` aceito pelo /send/media.
     * Estratégia:
     *  1) Se houver `path` local (enviado pelo app), lê o arquivo e envia em
     *     base64 (data:<mime>;base64,...). É o caminho confiável: o servidor do
     *     provedor (Uazapi, em particular o plano gratuito) não consegue acessar
     *     URLs dinâmicas (ngrok, IP não-fixo, domínios internos, autenticação).
     *  2) Se `mediaRef` já for http(s) externa (URL pública), usa ela (mais leve).
     *  3) Se `mediaRef` for caminho local mas sem `path` salvo, tenta resolver
     *     para um caminho absoluto e enviar em base64.
     *  4) Fallback: retorna a URL original (o provedor tentará buscar).
     */
    private function buildMediaFile(string $mediaRef, ?string $localPath, string $uazapiType, ?string $mime): string
    {
        // (1) Tenta o caminho local primeiro (mais confiável)
        if ($localPath !== null && $localPath !== '') {
            $absPath = $this->resolveLocalFile($localPath);
            if ($absPath !== null && is_file($absPath)) {
                return $this->readAsBase64DataUri($absPath, $mime);
            }
            try { error_log("Uazapi: path local informado mas não encontrado: {$localPath} (absPath=" . var_export($absPath, true) . ")"); } catch (\Throwable $e) {}
        }

        $isHttp = (bool) preg_match('#^https?://#i', $mediaRef);
        if ($isHttp) {
            // (2) URL pública — devolve como está
            return $this->absoluteUrl($mediaRef);
        }

        // (3) Caminho local sem path explícito
        $absPath = $this->resolveLocalFile($mediaRef);
        if ($absPath !== null && is_file($absPath)) {
            return $this->readAsBase64DataUri($absPath, $mime);
        }

        // (4) Fallback
        return $this->absoluteUrl($mediaRef);
    }

    /**
     * Lê o arquivo do disco e devolve um data URI (data:<mime>;base64,...)
     * pronto para enviar como `file` no /send/media.
     */
    private function readAsBase64DataUri(string $absPath, ?string $mime): string
    {
        $raw = @file_get_contents($absPath);
        if ($raw === false || $raw === '') {
            try { error_log("Uazapi: falha ao ler arquivo local: {$absPath}"); } catch (\Throwable $e) {}
            return '';
        }
        $detectedMime = $mime ?: (@mime_content_type($absPath) ?: 'application/octet-stream');
        return "data:{$detectedMime};base64," . base64_encode($raw);
    }

    /**
     * Resolve o caminho absoluto de um arquivo de mídia. Aceita:
     *  - caminho absoluto (C:\... ou /var/...)
     *  - caminho relativo já a partir do docroot (public/uploads/...)
     *  - URL relativa que começa com a pasta de uploads
     */
    private function resolveLocalFile(string $mediaRef): ?string
    {
        if ($mediaRef === '') {
            return null;
        }

        if (preg_match('#^[a-z]:[\\\\/]#i', $mediaRef) || str_starts_with($mediaRef, '/')) {
            return $mediaRef;
        }

        $clean = ltrim($mediaRef, '/');
        if (str_starts_with($clean, 'uploads/')) {
            $clean = substr($clean, strlen('uploads/'));
        }

        $publicDir = dirname(__DIR__, 3) . '/public';
        $candidates = [
            $publicDir . '/' . $clean,
            $publicDir . '/uploads/' . $clean,
            dirname(__DIR__, 3) . '/' . $clean,
        ];
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function resolveDocumentName(string $name, string $mediaRef): string
    {
        $candidate = trim($name);
        if ($candidate === '') {
            $candidate = basename(parse_url($mediaRef, PHP_URL_PATH) ?: $mediaRef);
        }
        if ($candidate === '') {
            return '';
        }
        // Garante que o nome do documento preserve a extensão
        if (!pathinfo($candidate, PATHINFO_EXTENSION) && $mediaRef !== '') {
            $ext = pathinfo(parse_url($mediaRef, PHP_URL_PATH) ?: $mediaRef, PATHINFO_EXTENSION);
            if ($ext !== '') {
                $candidate .= '.' . $ext;
            }
        }
        return $candidate;
    }

    /**
     * Mapeia o tipo interno para o tipo aceito pelo /send/media do Uazapi.
     *
     *  - audio/ogg | audio/opus | audio/webm | .ogg | .opus | .webm → ptt
     *    (mensagem de voz com player; Uazapi faz a conversão server-side)
     *  - audio/mp3 | audio/mpeg | audio/wav | audio/m4a | audio/aac | .mp3 ... → audio
     *    (música com player)
     *  - image/jpeg | image/png | .jpg | .png → image (Uazapi aceita os dois)
     *  - image/webp | .webp → image (pode precisar de conversão se Uazapi rejeitar)
     *  - video/mp4 | .mp4 → video
     *  - sticker → sticker
     *  - file/document → document (NUNCA usado como fallback para áudio/imagem)
     */
    private function uazapiMediaType(string $type, string $mediaRef, ?string $mime): string
    {
        if ($type === 'audio') {
            return $this->looksLikePtt($mediaRef, $mime) ? 'ptt' : 'audio';
        }
        if ($type === 'sticker') {
            return 'sticker';
        }
        if ($type === 'image') {
            return 'image';
        }
        return match ($type) {
            'video' => 'video',
            'file', 'document' => 'document',
            default => 'document',
        };
    }

    private function looksLikePtt(string $mediaRef, ?string $mime): bool
    {
        $mime = strtolower((string) $mime);
        // OGG, Opus e WebM (codec Opus do MediaRecorder) são tratados como PTT
        // pelo Uazapi, que faz a conversão server-side quando necessário.
        if (str_contains($mime, 'ogg')
            || str_contains($mime, 'opus')
            || str_contains($mime, 'webm')) {
            return true;
        }
        $path = parse_url($mediaRef, PHP_URL_PATH) ?: $mediaRef;
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return $ext === 'ogg' || $ext === 'opus' || $ext === 'webm';
    }

    public function sendButton(array $connection, string $to, string $text, array $buttons): array
    {
        $phone = preg_replace('/\D/', '', $to);
        if (!$phone) {
            return ['provider_message_id' => null, 'raw' => []];
        }

        $headers = $this->instanceAuthHeaders($connection);

        $choices = [];
        foreach ($buttons as $b) {
            $id = $b['id'] ?? $b['label'] ?? '';
            $label = $b['label'] ?? '';
            $choices[] = ($label ?: 'Opção') . '|' . $id;
        }
        if (trim($text) === '') {
            $text = 'Escolha uma opção:';
        }

        try {
            $resp = $this->client->post('/send/menu', [
                'number' => $phone,
                'type' => 'button',
                'text' => $text,
                'choices' => $choices,
                'delay' => 1000,
            ], $headers);

            if (($resp['status'] ?? 0) >= 400) {
                error_log("Uazapi sendButton error (status={$resp['status']}): " . json_encode($resp['body'], JSON_UNESCAPED_UNICODE));
            }

            return [
                'provider_message_id' => $this->extractMessageId($resp['body'] ?? []),
                'raw' => $resp['body'],
            ];
        } catch (\Throwable $e) {
            error_log('Uazapi sendButton exception: ' . $e->getMessage());
            return ['provider_message_id' => null, 'raw' => []];
        }
    }

    public function sendList(array $connection, string $to, string $text, string $title, array $items): array
    {
        $phone = preg_replace('/\D/', '', $to);
        if (!$phone) {
            return ['provider_message_id' => null, 'raw' => []];
        }

        $headers = $this->instanceAuthHeaders($connection);

        $choices = [];
        foreach ($items as $i => $item) {
            if (!empty($item['section_name'])) {
                $choices[] = '[' . $item['section_name'] . ']';
                $hasSection = true;
                if (!empty($item['rows'])) {
                    foreach ($item['rows'] as $row) {
                        $desc = $row['description'] ?? '';
                        $id = $row['id'] ?? '';
                        $label = $row['title'] ?? '';
                        $choices[] = $label . '|' . $id . ($desc ? '|' . $desc : '');
                    }
                }
            } else {
                $id = $item['id'] ?? $i;
                $label = $item['label'] ?? $item['title'] ?? '';
                $desc = $item['description'] ?? '';
                $choices[] = $label . '|' . $id . ($desc ? '|' . $desc : '');
            }
        }
        if (trim($text) === '') {
            $text = $title ?: 'Escolha uma opção:';
        }

        try {
            $payload = [
                'number' => $phone,
                'type' => 'list',
                'text' => $text,
                'listButton' => 'Ver opções',
                'choices' => $choices,
                'delay' => 1000,
            ];
            if (trim((string) $title) !== '') {
                $payload['footerText'] = $title;
            }
            $resp = $this->client->post('/send/menu', $payload, $headers);

            if (($resp['status'] ?? 0) >= 400) {
                error_log("Uazapi sendList error (status={$resp['status']}): " . json_encode($resp['body'], JSON_UNESCAPED_UNICODE));
            }

            return [
                'provider_message_id' => $this->extractMessageId($resp['body'] ?? []),
                'raw' => $resp['body'],
            ];
        } catch (\Throwable $e) {
            error_log('Uazapi sendList exception: ' . $e->getMessage());
            return ['provider_message_id' => null, 'raw' => []];
        }
    }

    public function downloadMedia(array $connection, string $messageId): ?array
    {
        if ($messageId === '') {
            return null;
        }

        $headers = $this->instanceAuthHeaders($connection);

        try {
            $resp = $this->client->post('/message/download', [
                'id' => $messageId,
                'return_base64' => true,
                'return_link' => false,
            ], $headers);

            $body = $resp['body'] ?? [];

            if (is_array($body)) {
                $base64 = $body['base64Data'] ?? $body['base64'] ?? null;
                if (is_string($base64) && $base64 !== '') {
                    return [
                        'base64' => $base64,
                        'mime' => $body['mimetype'] ?? $body['mime'] ?? null,
                        'name' => $body['filename'] ?? $body['name'] ?? null,
                    ];
                }

                $url = $body['fileURL'] ?? $body['url'] ?? $body['URL'] ?? null;
                if ($url) {
                    return [
                        'fileURL' => $url,
                        'mime' => $body['mimetype'] ?? $body['mime'] ?? null,
                        'name' => $body['filename'] ?? $body['name'] ?? null,
                    ];
                }
            }

            if (is_string($body) && $body !== '') {
                return [
                    'base64' => base64_encode($body),
                    'mime' => null,
                    'name' => null,
                ];
            }
        } catch (\Throwable $e) {
            error_log('Uazapi downloadMedia error: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Extrai contexto de mensagem em grupo: JID do grupo, participante que
     * enviou e lista de mencionados (dígitos). Busca defensiva em vários
     * caminhos do payload, pois o formato varia por versão/evento.
     *
     * @return array{is_group:bool, group_jid:string, group_name:?string, participant_phone:?string, participant_raw:string, mentioned:string[], participant_count:?int}
     */
    private function extractGroupContext(string $remoteJid, array $msg, array $data, array $payload): array
    {
        $participantRaw = (string) ($msg['sender'] ?? $data['sender'] ?? $msg['participant'] ?? $data['participant'] ?? '');
        // remoteJid pode ser o participante em alguns eventos; o grupo é o @g.us
        $groupJid = str_contains($remoteJid, '@g.us') ? $remoteJid : '';
        if ($groupJid === '') {
            foreach ([$msg['chatid'] ?? '', $data['remoteJid'] ?? ''] as $cand) {
                if (is_string($cand) && str_contains($cand, '@g.us')) {
                    $groupJid = $cand;
                    break;
                }
            }
        }
        $participantPhone = $this->normalizePhone($participantRaw) ?: null;

        // Menções: varredura case-insensitive em msg+data (cobre mentionedJID,
        // mentionedJidList, contextInfo aninhado, etc.)
        $mentioned = $this->collectMentionedDigits(['msg' => $msg, 'data' => $data]);

        $chat = $payload['chat'] ?? [];
        $groupName = $chat['name'] ?? $msg['groupName'] ?? $data['groupName'] ?? $msg['groupSubject'] ?? null;
        if (!is_string($groupName) || $groupName === '') {
            $groupName = null;
        }
        $participantCount = $chat['participantsCount'] ?? $msg['participantsCount'] ?? null;
        $participantCount = is_numeric($participantCount) ? (int) $participantCount : null;

        return [
            'is_group' => true,
            'group_jid' => $groupJid,
            'group_name' => $groupName,
            'participant_phone' => $participantPhone,
            'participant_raw' => $participantRaw,
            'mentioned' => $mentioned,
            'participant_count' => $participantCount,
        ];
    }

    public function parseWebhook(array $payload): ?IncomingMessage
    {
        $event = $payload['EventType'] ?? $payload['event'] ?? '';
        $isMessageEvent = $event === 'messages' || $event === 'messages.upsert';
        if (!$isMessageEvent) {
            return null;
        }

        // Uazapi coloca os dados em data.message
        $data = $payload['data'] ?? [];
        $msg = $data['message'] ?? $payload['message'] ?? [];

        if (empty($msg)) {
            return null;
        }

        $isFromMe = !empty($msg['fromMe']) || !empty($data['key']['fromMe']);
        $wasSentByApi = !empty($msg['wasSentByApi']);
        $remoteJid = (string) ($data['key']['remoteJid'] ?? '');
        $isGroup = !empty($msg['isGroup']) || ($remoteJid !== '' && str_contains($remoteJid, '@g.us'));
        // Grupos: não descartar — monta contexto de grupo (jid, participante,
        // menções) e segue o parse normal. O WhatsAppService decide o que fazer.
        $groupExtra = [];
        if ($isGroup) {
            $groupExtra = $this->extractGroupContext($remoteJid, $msg, $data, $payload);
        } else {
            if ($isFromMe || $wasSentByApi) {
                return null;
            }
        }

        $instanceName = $payload['instanceName'] ?? ($payload['instance']['name'] ?? '');
        $providerId = $instanceName ?: '';

        $rawFrom = $data['key']['remoteJid'] ?? $msg['chatid'] ?? $msg['sender'] ?? '';
        $originalFrom = $rawFrom;
        if ($isGroup && !empty($groupExtra['participant_phone'])) {
            // Em grupo, o remetente é o participante (não o JID do grupo).
            $fromPhone = $groupExtra['participant_phone'];
        } else {
            $fromPhone = $this->normalizePhone($rawFrom);
        }
        if (!$fromPhone) {
            return null;
        }

        $messageId = $data['key']['id'] ?? $msg['messageid'] ?? $msg['id'] ?? '';
        $timestamp = isset($data['messageTimestamp']) ? (int) ($data['messageTimestamp']) : (isset($msg['messageTimestamp']) ? (int) ($msg['messageTimestamp'] / 1000) : null);
        $senderName = $data['pushName'] ?? $msg['senderName'] ?? '';
        $messageType = $data['messageType'] ?? $msg['messageType'] ?? '';
        $extra = array_merge(['original_from' => $originalFrom], $groupExtra);
        // Citação recebida: ID da mensagem original no provedor.
        $quotedId = self::extractQuotedId($msg, $data);
        if ($quotedId !== null) {
            $extra['quoted_id'] = $quotedId;
        }

        // Avatar URL do chat webhook (imagePreview = preview, image = full)
        $chat = $payload['chat'] ?? [];
        $avatarUrl = $chat['imagePreview'] ?? $chat['image'] ?? null;
        if ($avatarUrl === '') $avatarUrl = null;

        // Uazapi: message.content pode ser string (texto) ou array (mídia/interativo)
        $content = $msg['content'] ?? null;

        // Mídia: content é array com URL, mimetype, caption
        $mediaTypes = ['ImageMessage', 'AudioMessage', 'VideoMessage', 'DocumentMessage', 'StickerMessage', 'PttMessage'];
        if (in_array($messageType, $mediaTypes, true) && is_array($content)) {
            $mediaUrl = $content['URL'] ?? $content['url'] ?? '';
            $mediaMime = $content['mimetype'] ?? $content['mimeType'] ?? '';
            $caption = is_string($content['caption'] ?? null) ? $content['caption'] : '';
            $mediaName = $content['name'] ?? $content['filename'] ?? $msg['mediaType'] ?? '';
            $mediaSize = isset($content['fileLength']) ? (int) $content['fileLength'] : 0;
            $extra = array_merge($extra, array_filter([
                'media_name' => $mediaName,
                'media_size' => $mediaSize,
            ]));

            $type = match ($messageType) {
                'ImageMessage' => 'image',
                'AudioMessage', 'PttMessage' => 'audio',
                'VideoMessage' => 'video',
                'DocumentMessage' => 'file',
                'StickerMessage' => 'sticker',
                default => 'file',
            };

            return IncomingMessage::media(
                $providerId, $messageId, $fromPhone, $type, $mediaUrl, $mediaMime,
                $caption, $timestamp, false, $senderName, $avatarUrl, $extra
            );
        }

        // Reação (ReactionMessage): content é array; converter para o formato JSON
        // que o WhatsAppService espera ({"reaction":..., "parent_message_id":...})
        if ($messageType === 'ReactionMessage') {
            $emoji = is_array($content) ? ($content['text'] ?? '') : (is_string($content) ? $content : '');
            if ($emoji === '') $emoji = $msg['text'] ?? '';
            $parentMsgId = $msg['reaction'] ?? '';
            if ($parentMsgId === '' && is_array($content)) {
                $parentMsgId = $content['key']['ID'] ?? $content['key']['id'] ?? '';
            }
            return IncomingMessage::text(
                $providerId, $messageId, $fromPhone,
                json_encode([
                    'reaction' => $emoji,
                    'parent_message_id' => $parentMsgId,
                ], JSON_UNESCAPED_UNICODE),
                $timestamp, false, $senderName, $avatarUrl, $extra
            );
        }

        // Resposta de botão / lista interativa (menu do fluxo):
        // content é array com {text: "label", id: "option-id"}; buttonOrListid guarda o id
        $interactiveTypes = ['ButtonsResponseMessage', 'ListResponseMessage', 'ButtonMessage', 'ListMessage', 'InteractiveMessage', 'InteractiveResponseMessage'];
        if (in_array($messageType, $interactiveTypes, true) || !empty($msg['buttonOrListid'])) {
            $replyText = is_array($content) ? ($content['text'] ?? '') : (is_string($content) ? $content : '');
            if ($replyText === '') $replyText = $msg['text'] ?? '';
            if ($replyText === '') $replyText = $msg['buttonOrListid'] ?? '';
            return IncomingMessage::text(
                $providerId, $messageId, $fromPhone, $replyText,
                $timestamp, false, $senderName, $avatarUrl, $extra
            );
        }

        // Text message: content pode ser string ou array {text: "..."}
        $text = '';
        if (is_array($content)) {
            $text = $content['text'] ?? $content['title'] ?? $msg['text'] ?? '';
        } elseif (is_string($content)) {
            $text = $content;
        } else {
            $text = $msg['text'] ?? '';
        }

        return IncomingMessage::text(
            $providerId, $messageId, $fromPhone, $text,
            $timestamp, false, $senderName, $avatarUrl, $extra
        );
    }

    /**
     * Extrai o ID da mensagem citada (resposta) do payload: campo `quoted`
     * no nível da mensagem ou stanzaId aninhado em contextInfo.
     */
    private static function extractQuotedId(array $msg, array $data): ?string
    {
        foreach ([$msg['quoted'] ?? null, $data['quoted'] ?? null] as $cand) {
            if (is_string($cand) && $cand !== '') {
                return $cand;
            }
        }
        $bags = [];
        if (isset($msg['content']) && is_array($msg['content'])) {
            $bags[] = $msg['content'];
        }
        if (isset($msg['contextInfo']) && is_array($msg['contextInfo'])) {
            $bags[] = $msg['contextInfo'];
        }
        foreach ($bags as $bag) {
            foreach (['stanzaId', 'quotedId', 'quoted_id'] as $k) {
                if (!empty($bag[$k]) && is_string($bag[$k])) {
                    return $bag[$k];
                }
            }
            if (!empty($bag['contextInfo']) && is_array($bag['contextInfo'])) {
                foreach (['stanzaId', 'quotedId', 'quoted_id'] as $k) {
                    if (!empty($bag['contextInfo'][$k]) && is_string($bag['contextInfo'][$k])) {
                        return $bag['contextInfo'][$k];
                    }
                }
            }
        }
        return null;
    }

    public function editMessage(array $connection, string $messageId, string $text): bool
    {
        if ($messageId === '') {
            return false;
        }

        $headers = $this->instanceAuthHeaders($connection);
        try {
            $resp = $this->client->post('/message/edit', [
                'id' => $messageId,
                'text' => $text,
            ], $headers);
            $this->guard($resp['body'] ?? []);
            return ($resp['status'] ?? 0) === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function deleteMessage(array $connection, string $messageId): bool
    {
        if ($messageId === '') {
            return false;
        }

        $headers = $this->instanceAuthHeaders($connection);
        try {
            $resp = $this->client->post('/message/delete', [
                'id' => $messageId,
            ], $headers);
            $this->guard($resp['body'] ?? []);
            return ($resp['status'] ?? 0) === 200;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function resolvePhone(array $connection, string $contactId): ?string
    {
        // Uazapi não possui endpoint específico para resolução LID -> telefone.
        // O telefone real já é resolvido via sender/chatid no webhook.
        return null;
    }

    public function sendReaction(array $connection, string $messageId, string $reaction, ?string $to = null): bool
    {
        if ($messageId === '') {
            return false;
        }
        $number = $to !== null && $to !== '' ? $to : null;
        if ($number === null) {
            return false;
        }
        if (!str_contains($number, '@')) {
            $digits = preg_replace('/\D/', '', $number);
            if (!$digits) {
                return false;
            }
            $number = $digits . '@s.whatsapp.net';
        }

        $headers = $this->instanceAuthHeaders($connection);
        try {
            $resp = $this->client->post('/message/react', [
                'number' => $number,
                'id' => $messageId,
                'text' => $reaction,
            ], $headers);
            $this->guard($resp['body'] ?? []);
            return ($resp['status'] ?? 0) === 200;
        } catch (\Throwable $e) {
            error_log('Uazapi sendReaction error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Envia texto para um grupo (JID @g.us preservado — sem normalizar dígitos).
     */
    public function sendGroupText(array $connection, string $groupJid, string $text): array
    {
        $headers = $this->instanceAuthHeaders($connection);
        $resp = $this->client->post('/send/text', [
            'number' => $groupJid,
            'text' => $text,
            'delay' => 0,
        ], $headers);
        $this->guard($resp['body'] ?? []);
        return [
            'provider_message_id' => $this->extractMessageId($resp['body'] ?? []),
            'raw' => $resp['body'],
        ];
    }

    public function getProfilePicture(array $connection, string $contactId): ?string
    {
        $phone = preg_replace('/\D/', '', $contactId);
        if (!$phone) {
            return null;
        }

        $headers = $this->instanceAuthHeaders($connection);
        try {
            $resp = $this->client->post('/chat/details', [
                'number' => $phone,
                'preview' => true,
            ], $headers);

            $body = $resp['body'] ?? [];
            $url = $body['imagePreview'] ?? $body['image'] ?? null;
            if ($url && is_string($url) && $url !== '') {
                return $url;
            }
        } catch (\Throwable $e) {
            error_log('Uazapi getProfilePicture error: ' . $e->getMessage());
        }

        return null;
    }

    public function sendSeen(array $connection, string $chatId): void
    {
        $phone = preg_replace('/\D/', '', $chatId);
        if (!$phone) {
            return;
        }
        $headers = $this->instanceAuthHeaders($connection);
        try {
            $this->client->post('/message/markread', ['number' => $phone], $headers);
        } catch (\Throwable $e) {
        }
    }

    public function startTyping(array $connection, string $chatId): void
    {
        $phone = preg_replace('/\D/', '', $chatId);
        if (!$phone) {
            return;
        }
        $headers = $this->instanceAuthHeaders($connection);
        try {
            $this->client->post('/instance/presence', [
                'number' => $phone,
                'type' => 'typing',
            ], $headers);
        } catch (\Throwable $e) {
        }
    }

    public function stopTyping(array $connection, string $chatId): void
    {
        $phone = preg_replace('/\D/', '', $chatId);
        if (!$phone) {
            return;
        }
        $headers = $this->instanceAuthHeaders($connection);
        try {
            $this->client->post('/instance/presence', [
                'number' => $phone,
                'type' => 'stop',
            ], $headers);
        } catch (\Throwable $e) {
        }
    }

    // ----------------------------------------------------------------

    private function adminAuthHeaders(): array
    {
        $adminToken = $this->config['admin_token'] ?? '';
        return $adminToken ? ['admintoken' => $adminToken] : [];
    }

    private function instanceAuthHeaders(array $connection): array
    {
        $token = $connection['instance_token'] ?? '';
        if ($token) {
            return ['token' => $token];
        }
        // Fallback: usa admintoken como token de instância se não tiver token específico
        $adminToken = $this->config['admin_token'] ?? '';
        return $adminToken ? ['token' => $adminToken] : [];
    }

    private function guard(mixed $body): void
    {
        if (!is_array($body)) {
            return;
        }
        // Uazapi usa {code: 401, message: "..."} em vez de {status: 401}
        $errCode = $body['code'] ?? $body['status'] ?? null;
        if ($errCode !== null) {
            if ((int) $errCode === 401 || (int) $errCode === 403) {
                throw new WhatsAppAuthException('Uazapi token inválido ou não autorizado.');
            }
        }
        $errStatus = $body['status'] ?? '';
        if (is_string($errStatus) && strtolower($errStatus) === 'unauthorized') {
            throw new WhatsAppAuthException('Uazapi token inválido ou não autorizado.');
        }
    }

    private function normalizeStatus(array $body): string
    {
        // Se o body tem código de erro (ex.: 401 Missing token), retorna 'error'
        if (isset($body['code']) && (int) $body['code'] >= 400) {
            return 'error';
        }
        if (isset($body['error'])) {
            return 'error';
        }

        // Uazapi returns {instance: {status: "connected", ...}, status: {connected: bool}}
        $instance = $body['instance'] ?? $body;
        $raw = $instance['status'] ?? $instance['state'] ?? $instance['connectionStatus'] ?? null;
        if (!is_string($raw)) {
            $raw = $body['status'] ?? null;
        }
        if (!is_string($raw)) {
            $raw = '';
        }
        $status = strtolower($raw);
        return match (true) {
            $status === 'connected' || $status === 'open' || $status === 'authenticated' => 'connected',
            $status === 'waiting_qr' || $status === 'starting' || $status === 'connecting' || $status === 'disconnected' => 'waiting_qr',
            $status === 'failed' || $status === 'error' => 'error',
            default => 'error',
        };
    }

    private function needsQr(string $status): bool
    {
        return in_array($status, ['waiting_qr', 'disconnected'], true);
    }

    private function extractQr(mixed $body): ?string
    {
        if (!$body) {
            return null;
        }

        if (is_string($body)) {
            if (str_starts_with($body, "\x89PNG")) {
                return 'data:image/png;base64,' . base64_encode($body);
            }
            if (str_starts_with($body, 'data:')) {
                return $body;
            }
            return 'data:image/png;base64,' . $body;
        }

        if (!is_array($body)) {
            return null;
        }

        // Uazapi /instance/connect returns {qrcode: "data:image/png;base64,...", pairingCode: "..."}
        // Also check inside data wrapper: {status: "success", data: {qrcode: "..."}}
        $raw = $body['qrcode'] ?? $body['qrCode'] ?? null;
        if (!$raw) {
            $data = $body['data'] ?? null;
            if (is_array($data)) {
                $raw = $data['qrcode'] ?? $data['qrCode'] ?? null;
            }
        }

        // Check instance wrapper used by status endpoint
        if (!$raw) {
            $instance = $body['instance'] ?? null;
            if (is_array($instance)) {
                $raw = $instance['qrcode'] ?? $instance['qrCode'] ?? null;
            }
        }

        if (!$raw) {
            return null;
        }

        if (is_string($raw)) {
            if (str_starts_with($raw, 'data:')) {
                return $raw;
            }
            return 'data:image/png;base64,' . $raw;
        }
        if (is_array($raw) && !empty($raw['base64'])) {
            return 'data:image/png;base64,' . $raw['base64'];
        }
        return null;
    }

    private function extractMessageId(mixed $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }
        $data = $body['data'] ?? $body;

        // Vários formatos conhecidos do Uazapi dependendo da versão e do tipo:
        //  - {messageid: "ABC"} ou {data: {messageid: "ABC"}}
        //  - {id: "ABC"} ou {data: {id: "ABC"}}
        //  - {key: {id: "ABC", fromMe: true, remoteJid: "..."}}
        //  - {data: {key: {id: "ABC"}}}
        $candidates = [
            $data['messageid'] ?? null,
            $data['id'] ?? null,
            $body['messageid'] ?? null,
            $body['id'] ?? null,
        ];
        if (is_array($data['key'] ?? null)) {
            $candidates[] = $data['key']['id'] ?? null;
        }
        if (is_array($body['key'] ?? null)) {
            $candidates[] = $body['key']['id'] ?? null;
        }
        if (is_array($data['data']['key'] ?? null)) {
            $candidates[] = $data['data']['key']['id'] ?? null;
        }

        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') {
                return $c;
            }
        }
        return null;
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }
        $phone = preg_replace('/@s\.whatsapp\.net$|@c\.us$|@lid$|@g\.us$/', '', $phone);
        // Sufixo de dispositivo do LID (ex.: 225962742546599:93@lid -> base)
        $phone = preg_replace('/:\d+$/', '', $phone);
        $digits = preg_replace('/\D/', '', $phone);
        return $digits === '' ? null : $digits;
    }

    /**
     * Coleta JIDs mencionados varrendo o payload de forma case-insensitive
     * (mentionedJid x mentionedJID x mentionedIds, em qualquer profundidade).
     *
     * @return string[] dígitos únicos
     */
    private function collectMentionedDigits(array $node): array
    {
        $found = [];
        $leaves = function ($value) use (&$leaves, &$found) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $leaves($v);
                }
                return;
            }
            if (is_string($value) && $value !== '') {
                $digits = preg_replace('/\D/', '', $value);
                if ($digits !== '' && strlen($digits) >= 8) {
                    $found[] = $digits;
                }
            }
        };
        $walk = function ($value) use (&$walk, $leaves) {
            if (!is_array($value)) {
                return;
            }
            foreach ($value as $k => $v) {
                if (is_string($k) && preg_match('/^mentionedjid(list|ids)?$/i', $k)) {
                    $leaves($v);
                } else {
                    $walk($v);
                }
            }
        };
        $walk($node);
        return array_values(array_unique($found));
    }

    private function absoluteUrl(string $url): string
    {
        if (preg_match('#^https?://#', $url)) {
            return $url;
        }
        return base_url(ltrim($url, '/'));
    }

    private function loadConfig(): array
    {
        $integrations = require dirname(__DIR__, 3) . '/config/integrations.php';
        return $integrations['whatsapp']['providers']['uazapi'] ?? [];
    }
}
