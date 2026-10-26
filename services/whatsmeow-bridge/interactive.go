package main

import (
	"context"
	"fmt"

	"go.mau.fi/whatsmeow"
)

// SendButtons envia um menu de botões interativo (nativo).
//
// O menu vai no formato "native flow" (InteractiveMessage + NativeFlowMessage)
// porque o WhatsApp recusa com 405 os ButtonsMessage legados. O AtendeFlow
// sempre manda o menu com opção de degradação: se a bridge responder
// native=false, o provider PHP envia a apresentação em texto numerado (que o
// motor de fluxos já responde por posição).
func (s *Session) SendButtons(ctx context.Context, to, text string, buttons []Option) (string, bool, error) {
	jid, err := resolveJID(to)
	if err != nil {
		return "", false, err
	}
	if len(buttons) == 0 {
		return "", false, fmt.Errorf("menu sem opcoes")
	}

	msg, err := buildNativeFlowButtons(text, "", buttons)
	if err != nil {
		return "", false, err
	}

	nodes := nativeFlowAdditionalNodes(jid)
	resp, err := s.Client.SendMessage(ctx, jid, msg, whatsmeow.SendRequestExtra{AdditionalNodes: &nodes})
	if err != nil {
		return "", false, err
	}
	return string(resp.ID), true, nil
}

// SendList envia uma lista interativa (nativa).
//
// No native flow a lista é um botão "single_select": o cliente toca e o
// WhatsApp abre o seletor com as seções enviadas aqui.
func (s *Session) SendList(ctx context.Context, to, text, title string, items []Option) (string, bool, error) {
	jid, err := resolveJID(to)
	if err != nil {
		return "", false, err
	}
	if len(items) == 0 {
		return "", false, fmt.Errorf("lista sem opcoes")
	}

	msg, err := buildNativeFlowList(text, title, items)
	if err != nil {
		return "", false, err
	}

	nodes := nativeFlowAdditionalNodes(jid)
	resp, err := s.Client.SendMessage(ctx, jid, msg, whatsmeow.SendRequestExtra{AdditionalNodes: &nodes})
	if err != nil {
		return "", false, err
	}
	return string(resp.ID), true, nil
}

// Option é uma opção de menu/lista.
type Option struct {
	ID    string `json:"id"`
	Label string `json:"label"`
}
