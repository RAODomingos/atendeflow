package main

import (
	"context"
	"fmt"

	"go.mau.fi/whatsmeow/proto/waE2E"
)

// SendButtons envia um menu de botões interativo (nativo).
//
// O AtendeFlow sempre manda o menu com opção de degradação: se a bridge
// responder native=false, o provider PHP envia a apresentação em texto
// numerado (que o motor de fluxos já responde por posição).
func (s *Session) SendButtons(ctx context.Context, to, text string, buttons []Option) (string, bool, error) {
	jid, err := resolveJID(to)
	if err != nil {
		return "", false, err
	}
	if len(buttons) == 0 {
		return "", false, fmt.Errorf("menu sem opcoes")
	}

	protoButtons := make([]*waE2E.ButtonsMessage_Button, 0, len(buttons))
	for _, button := range buttons {
		protoButtons = append(protoButtons, &waE2E.ButtonsMessage_Button{
			ButtonID: ptr(button.ID),
			ButtonText: &waE2E.ButtonsMessage_Button_ButtonText{
				DisplayText: ptr(button.Label),
			},
			Type: waE2E.ButtonsMessage_Button_RESPONSE.Enum(),
		})
	}

	msg := &waE2E.Message{
		ButtonsMessage: &waE2E.ButtonsMessage{
			ContentText: ptr(text),
			Buttons:     protoButtons,
		},
	}

	resp, err := s.Client.SendMessage(ctx, jid, msg)
	if err != nil {
		return "", false, err
	}
	return string(resp.ID), true, nil
}

// SendList envia uma lista interativa (nativa).
func (s *Session) SendList(ctx context.Context, to, text, title string, items []Option) (string, bool, error) {
	jid, err := resolveJID(to)
	if err != nil {
		return "", false, err
	}
	if len(items) == 0 {
		return "", false, fmt.Errorf("lista sem opcoes")
	}

	rows := make([]*waE2E.ListMessage_Row, 0, len(items))
	for _, item := range items {
		rows = append(rows, &waE2E.ListMessage_Row{
			Title: ptr(item.Label),
			RowID: ptr(item.ID),
		})
	}

	sectionTitle := title
	if sectionTitle == "" {
		sectionTitle = text
	}

	msg := &waE2E.Message{
		ListMessage: &waE2E.ListMessage{
			Title:       ptr(title),
			Description: ptr(text),
			ButtonText:  ptr("Ver opções"),
			ListType:    waE2E.ListMessage_SINGLE_SELECT.Enum(),
			Sections: []*waE2E.ListMessage_Section{
				{
					Title: ptr(sectionTitle),
					Rows:  rows,
				},
			},
		},
	}

	resp, err := s.Client.SendMessage(ctx, jid, msg)
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
