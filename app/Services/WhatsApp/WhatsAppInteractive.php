<?php

namespace App\Services\WhatsApp;

/**
 * Menus interativos (botões e listas) — renderização em texto numerado.
 *
 * Por que existe: nem toda API de WhatsApp consegue enviar botão/lista nativo.
 * O `whatsapp-web.js`, por exemplo, depreciou o envio de interativos; o zapo
 * só expõe o protobuf cru. Mas o AtendeFlow já responde menu pelo **número**
 * da opção (`FlowEngineService::handleMenuResponse` aceita label, value ou
 * posição), então apresentar as opções como texto numerado mantém o fluxo
 * inteiro funcionando — sem mudar nenhum fluxo do painel.
 *
 * Esta classe é a implementação única dessa apresentação: o `WhatsAppService`
 * usa como fallback e os provedores usam quando o motor não manda interativo.
 */
final class WhatsAppInteractive
{
    /**
     * Normaliza botões/itens crus para `[{id, label}]`.
     * Aceita `label`, `title`, `text` e `name` como rótulo.
     *
     * @return array<int, array{id:string, label:string}>
     */
    public static function normalizeOptions(array $raw): array
    {
        $out = [];
        $i = 0;
        foreach ($raw as $key => $item) {
            $i++;
            if (is_string($item)) {
                $label = trim($item);
                $id = (string) ($key !== '' && !is_int($key) ? $key : $i);
            } elseif (is_array($item)) {
                $label = '';
                foreach (['label', 'title', 'text', 'name'] as $field) {
                    if (!empty($item[$field]) && is_string($item[$field])) {
                        $label = trim($item[$field]);
                        break;
                    }
                }
                $id = isset($item['id']) && is_scalar($item['id']) ? (string) $item['id'] : (string) $i;
            } else {
                continue;
            }
            if ($label === '') {
                continue;
            }
            $out[] = ['id' => $id !== '' ? $id : (string) $i, 'label' => $label];
        }
        return $out;
    }

    /**
     * Apresenta um menu de botões como texto numerado.
     * Formato idêntico ao fallback já usado pelo WhatsAppService.
     */
    public static function renderButtonsAsText(string $text, array $buttons): string
    {
        $options = self::normalizeOptions($buttons);
        $lines = [];
        $i = 0;
        foreach ($options as $option) {
            $i++;
            $lines[] = $i . ' - ' . $option['label'];
        }
        if ($lines === []) {
            return $text;
        }
        return trim($text) . "\n\n" . implode("\n", $lines);
    }

    /**
     * Apresenta uma lista interativa como texto numerado.
     * Formato idêntico ao fallback já usado pelo WhatsAppService.
     */
    public static function renderListAsText(string $text, string $title, array $items): string
    {
        $options = self::normalizeOptions($items);
        $lines = [];
        $i = 0;
        foreach ($options as $option) {
            $i++;
            $lines[] = $i . ' - ' . $option['label'];
        }
        if ($lines === []) {
            return $text;
        }
        return trim($text) . "\n\n" . ($title !== '' ? $title : 'Opções') . ":\n" . implode("\n", $lines);
    }
}
