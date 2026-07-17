<?php

namespace App\Services\WhatsApp;

/**
 * Lançada quando o provedor rejeita a autenticação (HTTP 401 / 403).
 * Geralmente indica que a sessão foi resetada/expirada e a credencial
 * salva no banco não é mais válida.
 */
class WhatsAppAuthException extends \RuntimeException
{
}
