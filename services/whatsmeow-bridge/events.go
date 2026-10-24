package main

import (
	"context"
	"log"
	"time"

	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types/events"
)

// Envelope Ã© o corpo que a bridge envia para o webhook do AtendeFlow.
type Envelope struct {
	Provider string           `json:"provider"`
	Event    string           `json:"event"`
	Session  string           `json:"session"`
	Message  *MessagePayload  `json:"message,omitempty"`
	Error    string           `json:"error,omitempty"`
}

// MessagePayload Ã© a mensagem normalizada (mesmo formato do UAZAPI, para o
// provider PHP nÃ£o precisar de duas traduÃ§Ãµes).
type MessagePayload struct {
	ID              string          `json:"id"`
	ChatID          string          `json:"chat_id"`
	From            string          `json:"from"`
	FromPhone       string          `json:"from_phone"`
	FromLID         string          `json:"from_lid"`
	Participant     string          `json:"participant,omitempty"`
	ParticipantPhone string         `json:"participant_phone,omitempty"`
	ParticipantLID  string          `json:"participant_lid,omitempty"`
	IsGroup         bool            `json:"is_group"`
	FromMe          bool            `json:"from_me"`
	Type            string          `json:"type"`
	Body            string          `json:"body,omitempty"`
	Caption         string          `json:"caption,omitempty"`
	Timestamp       int64           `json:"timestamp,omitempty"`
	SenderName      string          `json:"sender_name,omitempty"`
	Media           *MediaPayload   `json:"media,omitempty"`
	QuotedID        string          `json:"quoted_id,omitempty"`
	Reaction        *ReactionPayload `json:"reaction,omitempty"`
	Contact         *ContactPayload `json:"contact,omitempty"`
	Interactive     *InteractivePayload `json:"interactive,omitempty"`
}

type MediaPayload struct {
	URL      string `json:"url"`
	Mime     string `json:"mime"`
	Filename string `json:"filename"`
	Size     int64  `json:"size,omitempty"`
}

type ReactionPayload struct {
	Emoji           string `json:"emoji"`
	ParentMessageID string `json:"parent_message_id,omitempty"`
}

type ContactPayload struct {
	Name         string `json:"name"`
	Phone        string `json:"phone"`
	Organization string `json:"organization,omitempty"`
}

type InteractivePayload struct {
	ID    string `json:"id"`
	Label string `json:"label"`
}

// handleMessage transforma um evento do whatsmeow no envelope do AtendeFlow.
func (m *SessionManager) handleMessage(session *Session, evt *events.Message) {
	if evt == nil || evt.Info.Chat.IsEmpty() {
		return
	}

	ctx := context.Background()
	info := evt.Info
	msg := evt.Message
	if msg == nil {
		return
	}

	payload := &MessagePayload{
		ID:        string(info.ID),
		ChatID:    info.Chat.String(),
		FromMe:    info.IsFromMe,
		IsGroup:   info.IsGroup,
		Timestamp: info.Timestamp.Unix(),
		SenderName: info.PushName,
	}

	sender := info.Sender
	if sender.IsEmpty() {
		sender = info.Chat
	}
	payload.From = sender.String()

	phone, lid, err := session.ResolveContact(ctx, sender.String())
	if err != nil {
		log.Printf("[%s] nao foi possivel resolver o contato %s: %v", session.Name, sender, err)
	}
	payload.FromPhone = phone
	payload.FromLID = lid

	if info.IsGroup {
		payload.Participant = sender.String()
		payload.ParticipantPhone = phone
		payload.ParticipantLID = lid
	}

	// CitaÃ§Ã£o
	if ctxInfo := extractContextInfo(msg); ctxInfo != nil && ctxInfo.GetMessageID() != "" {
		payload.QuotedID = ctxInfo.GetMessageID()
	}

	switch {
	case msg.GetReactionMessage() != nil:
		reaction := msg.GetReactionMessage()
		payload.Type = "reaction"
		payload.Reaction = &ReactionPayload{
			Emoji:           reaction.GetText(),
			ParentMessageID: reaction.GetKey().GetID(),
		}
	case msg.GetButtonsResponseMessage() != nil:
		response := msg.GetButtonsResponseMessage()
		payload.Type = "button"
		payload.Interactive = &InteractivePayload{
			ID:    response.GetSelectedButtonId(),
			Label: response.GetDisplayText(),
		}
	case msg.GetListResponseMessage() != nil:
		response := msg.GetListResponseMessage()
		payload.Type = "list"
		payload.Interactive = &InteractivePayload{
			ID:    response.GetListItemId(),
			Label: response.GetTitle(),
		}
	case msg.GetContactMessage() != nil:
		contact := msg.GetContactMessage()
		payload.Type = "contact"
		payload.Contact = &ContactPayload{
			Name:  contact.GetDisplayName(),
			Phone: extractVCardPhone(contact.GetVcard()),
		}
	case msg.GetImageMessage() != nil:
		payload.Type = "image"
		payload.Body = msg.GetImageMessage().GetCaption()
		m.storeIncomingMedia(ctx, session, payload, msg)
	case msg.GetVideoMessage() != nil:
		payload.Type = "video"
		payload.Body = msg.GetVideoMessage().GetCaption()
		m.storeIncomingMedia(ctx, session, payload, msg)
	case msg.GetAudioMessage() != nil:
		payload.Type = "audio"
		payload.Body = msg.GetAudioMessage().GetCaption()
		m.storeIncomingMedia(ctx, session, payload, msg)
	case msg.GetDocumentMessage() != nil:
		payload.Type = "document"
		payload.Body = msg.GetDocumentMessage().GetCaption()
		m.storeIncomingMedia(ctx, session, payload, msg)
	case msg.GetStickerMessage() != nil:
		payload.Type = "sticker"
		m.storeIncomingMedia(ctx, session, payload, msg)
	case msg.GetExtendedTextMessage() != nil:
		payload.Type = "text"
		payload.Body = msg.GetExtendedTextMessage().GetText()
	case msg.GetConversation() != "":
		payload.Type = "text"
		payload.Body = msg.GetConversation()
	default:
		payload.Type = "text"
		payload.Body = ""
	}

	m.emit(session, Envelope{
		Provider: "whatsmeow",
		Event:    "message",
		Session:  session.Name,
		Message:  payload,
	})
}

// storeIncomingMedia baixa a midia e deixa o arquivo pronto para o app.
func (m *SessionManager) storeIncomingMedia(ctx context.Context, session *Session, payload *MessagePayload, msg *waE2E.Message) {
	data, filename, err := session.downloadMedia(ctx, msg)
	if err != nil {
		log.Printf("[%s] nao foi possivel baixar a midia %s: %v", session.Name, payload.ID, err)
		return
	}
	if _, err := session.StoreMedia(payload.ID, data, mediaMime(msg), filename); err != nil {
		log.Printf("[%s] nao foi possivel gravar a midia %s: %v", session.Name, payload.ID, err)
		return
	}
	payload.Media = &MediaPayload{
		URL:      m.mediaURL(session, payload.ID),
		Mime:     mediaMime(msg),
		Filename: filename,
		Size:     int64(len(data)),
	}
}

// extractContextInfo devolve o ContextInfo de qualquer tipo de mensagem.
func extractContextInfo(msg *waE2E.Message) *waE2E.ContextInfo {
	switch {
	case msg.GetExtendedTextMessage() != nil:
		return msg.GetExtendedTextMessage().GetContextInfo()
	case msg.GetImageMessage() != nil:
		return msg.GetImageMessage().GetContextInfo()
	case msg.GetVideoMessage() != nil:
		return msg.GetVideoMessage().GetContextInfo()
	case msg.GetAudioMessage() != nil:
		return msg.GetAudioMessage().GetContextInfo()
	case msg.GetDocumentMessage() != nil:
		return msg.GetDocumentMessage().GetContextInfo()
	default:
		return nil
	}
}

// extractVCardPhone pega o telefone do vCard.
func extractVCardPhone(vcard string) string {
	for _, line := range splitLines(vcard) {
		if len(line) > 4 && (line[:4] == "TEL:" || line[:4] == "TEL;") {
			parts := splitLines(line)
			_ = parts
			if idx := indexByte(line, ':'); idx >= 0 {
				return onlyDigits(line[idx+1:])
			}
		}
	}
	return ""
}

func splitLines(value string) []string {
	var out []string
	start := 0
	for i := 0; i < len(value); i++ {
		if value[i] == '\n' {
			out = append(out, value[start:i])
			start = i + 1
		}
	}
	if start < len(value) {
		out = append(out, value[start:])
	}
	return out
}

func indexByte(value string, target byte) int {
	for i := 0; i < len(value); i++ {
		if value[i] == target {
			return i
		}
	}
	return -1
}

// emit entrega o envelope no webhook configurado da sessao.
func (m *SessionManager) emit(session *Session, envelope Envelope) {
	url, secret := session.webhook()
	if url == "" {
		return
	}
	go postWebhook(url, secret, envelope)
}

var _ = time.Second
