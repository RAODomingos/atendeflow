package main

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"time"

	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
	"google.golang.org/protobuf/proto"
)

// ptr evita colisão com o helper genérico `new` do whatsmeow.
func ptr[T any](v T) *T { return &v }

// SendText envia texto (com citação opcional).
func (s *Session) SendText(ctx context.Context, to, text, quotedID string) (string, error) {
	jid, err := resolveJID(to)
	if err != nil {
		return "", err
	}

	msg := &waE2E.Message{Conversation: ptr(text)}
	if quotedID != "" {
		msg = &waE2E.Message{
			ExtendedTextMessage: &waE2E.ExtendedTextMessage{
				Text:       ptr(text),
				ContextInfo: s.contextInfo(quotedID, nil),
			},
		}
	}

	resp, err := s.Client.SendMessage(ctx, jid, msg)
	if err != nil {
		return "", err
	}
	return string(resp.ID), nil
}

// SendGroupText envia texto em grupo, com menções.
func (s *Session) SendGroupText(ctx context.Context, groupJID, text string, mentions []string) (string, error) {
	jid, err := resolveJID(groupJID)
	if err != nil {
		return "", err
	}
	if jid.Server != types.GroupServer {
		return "", fmt.Errorf("chat invalido para envio em grupo: %s", jid)
	}

	var mentioned []*waE2E.MentionedJID
	for _, mention := range mentions {
		if strings.EqualFold(mention, "all") {
			mentioned = append(mentioned, &waE2E.MentionedJID{JID: ptr("all")})
			continue
		}
		mjid, err := resolveJID(mention)
		if err != nil {
			continue
		}
		mentioned = append(mentioned, &waE2E.MentionedJID{JID: ptr(mjid.String())})
	}

	msg := &waE2E.Message{
		ExtendedTextMessage: &waE2E.ExtendedTextMessage{
			Text:        ptr(text),
			ContextInfo: &waE2E.ContextInfo{MentionedJID: mentioned},
		},
	}

	resp, err := s.Client.SendMessage(ctx, jid, msg)
	if err != nil {
		return "", err
	}
	return string(resp.ID), nil
}

// SendMedia envia imagem/audio/video/documento/sticker.
// O upload é feito pela propria biblioteca e o mime do arquivo decide o tipo.
func (s *Session) SendMedia(ctx context.Context, to string, data []byte, filename, mime, caption, quotedID string, asVoice bool) (string, error) {
	jid, err := resolveJID(to)
	if err != nil {
		return "", err
	}
	if filename == "" {
		filename = "arquivo"
	}
	if mime == "" {
		mime = "application/octet-stream"
	}

	uploaded, err := s.Client.Upload(ctx, bytes.NewReader(data), filename, mime)
	if err != nil {
		return "", err
	}

	// O Upload devolve a mensagem com o campo de midia preenchido.
	if asVoice && uploaded.AudioMessage != nil {
		uploaded.AudioMessage.PTT = true
	}
	if caption != "" {
		switch {
		case uploaded.ImageMessage != nil:
			uploaded.ImageMessage.Caption = ptr(caption)
		case uploaded.VideoMessage != nil:
			uploaded.VideoMessage.Caption = ptr(caption)
		case uploaded.AudioMessage != nil:
			uploaded.AudioMessage.Caption = ptr(caption)
		case uploaded.DocumentMessage != nil:
			uploaded.DocumentMessage.Caption = ptr(caption)
		}
	}
	if quotedID != "" {
		uploaded.MessageContextInfo = s.contextInfo(quotedID, nil)
	}

	resp, err := s.Client.SendMessage(ctx, jid, uploaded)
	if err != nil {
		return "", err
	}
	return string(resp.ID), nil
}

// SendContact envia o cartao de contato (vCard).
func (s *Session) SendContact(ctx context.Context, to, name, phone, organization string) (string, error) {
	jid, err := resolveJID(to)
	if err != nil {
		return "", err
	}
	if name == "" {
		name = phone
	}
	vcard := buildVCard(name, phone, organization)

	msg := &waE2E.Message{
		ContactMessage: &waE2E.ContactMessage{
			DisplayName: ptr(name),
			Vcard:       ptr(vcard),
		},
	}

	resp, err := s.Client.SendMessage(ctx, jid, msg)
	if err != nil {
		return "", err
	}
	return string(resp.ID), nil
}

func buildVCard(name, phone, organization string) string {
	var lines []string
	lines = append(lines, "BEGIN:VCARD", "VERSION:3.0", "FN:"+strings.TrimSpace(name))
	if organization != "" {
		lines = append(lines, "ORG:"+organization)
	}
	lines = append(lines, "TEL;TYPE=CELL;waid="+onlyDigits(phone)+":"+onlyDigits(phone))
	lines = append(lines, "END:VCARD")
	return strings.Join(lines, "\n")
}

// EditMessage edita uma mensagem enviada (janela de 20 minutos).
func (s *Session) EditMessage(ctx context.Context, chatJID, messageID, text string) error {
	jid, err := resolveJID(chatJID)
	if err != nil {
		return err
	}
	msg := s.Client.BuildEdit(jid, types.MessageID(messageID), &waE2E.Message{Conversation: ptr(text)})
	_, err = s.Client.SendMessage(ctx, jid, msg)
	return err
}

// DeleteMessage apaga a mensagem para todos.
func (s *Session) DeleteMessage(ctx context.Context, chatJID, messageID string) error {
	jid, err := resolveJID(chatJID)
	if err != nil {
		return err
	}
	msg := s.Client.BuildRevoke(jid, types.EmptyJID, types.MessageID(messageID))
	_, err = s.Client.SendMessage(ctx, jid, msg)
	return err
}

// ReactMessage reage a uma mensagem (emoji vazio remove a reacao).
func (s *Session) ReactMessage(ctx context.Context, chatJID, messageID, emoji string) error {
	jid, err := resolveJID(chatJID)
	if err != nil {
		return err
	}
	msg := s.Client.BuildReaction(jid, types.EmptyJID, types.MessageID(messageID), emoji)
	_, err = s.Client.SendMessage(ctx, jid, msg)
	return err
}

// MarkSeen marca as mensagens do chat como lidas.
func (s *Session) MarkSeen(ctx context.Context, chatJID string) error {
	jid, err := resolveJID(chatJID)
	if err != nil {
		return err
	}
	return s.Client.MarkRead(ctx, []types.JID{jid}, nil, types.EmptyJID, types.ReceiptTypeRead)
}

// SetTyping liga/desliga o "digitando...".
func (s *Session) SetTyping(ctx context.Context, chatJID string, composing bool) error {
	jid, err := resolveJID(chatJID)
	if err != nil {
		return err
	}
	state := types.ChatPresencePaused
	if composing {
		state = types.ChatPresenceComposing
	}
	return s.Client.SendChatPresence(ctx, jid, state, types.ChatPresenceMediaText)
}

// SetPresence marca a conta como online/offline.
func (s *Session) SetPresence(available bool) {
	if available {
		s.Client.SendPresence(types.PresenceAvailable)
		return
	}
	s.Client.SendPresence(types.PresenceUnavailable)
}

// GroupParticipants lista os participantes do grupo no shape do AtendeFlow.
func (s *Session) GroupParticipants(ctx context.Context, groupJID string) ([]Participant, error) {
	jid, err := resolveJID(groupJID)
	if err != nil {
		return nil, err
	}
	info, err := s.Client.GetGroupInfo(ctx, jid)
	if err != nil {
		return nil, err
	}

	participants := make([]Participant, 0, len(info.Participants))
	for _, p := range info.Participants {
		phone, lid := splitPhoneAndLID(p.JID)
		name := ""
		if contact, err := s.Client.Store.Contacts.GetContact(ctx, p.JID); err == nil {
			name = contact.FullName
		}
		participants = append(participants, Participant{
			Phone:   phone,
			LID:     lid,
			Name:    name,
			IsAdmin: p.IsAdmin || p.IsSuperAdmin,
		})
	}
	return participants, nil
}

// Participant é o shape de participante esperado pelo AtendeFlow.
type Participant struct {
	Phone   string `json:"phone"`
	LID     string `json:"lid"`
	Name    string `json:"name"`
	IsAdmin bool   `json:"is_admin"`
}

// ResolveContact resolve um identificador (telefone ou LID) no par LID/telefone.
func (s *Session) ResolveContact(ctx context.Context, contact string) (phone string, lid string, err error) {
	jid, err := resolveJID(contact)
	if err != nil {
		return "", "", err
	}

	if jid.Server == types.HiddenUserServer {
		lid = jid.User
		pn, err := s.Client.Store.LIDs.GetPNForLID(ctx, jid)
		if err == nil && !pn.IsEmpty() {
			phone = pn.User
		}
		return phone, lid, nil
	}

	phone = jid.User
	// Descobre o LID correspondente (quando o WhatsApp ja conhece o par).
	if lidJID, err := s.Client.Store.LIDs.GetLIDForPN(ctx, jid); err == nil && !lidJID.IsEmpty() {
		lid = lidJID.User
	}
	return phone, lid, nil
}

// splitPhoneAndLID separa telefone e LID de um JID do WhatsApp.
func splitPhoneAndLID(jid types.JID) (phone string, lid string) {
	switch jid.Server {
	case types.DefaultUserServer:
		phone = jid.User
	case types.HiddenUserServer:
		lid = jid.User
	}
	return phone, lid
}

// contextInfo monta o ContextInfo de citação.
func (s *Session) contextInfo(quotedID string, mentioned []*waE2E.MentionedJID) *waE2E.ContextInfo {
	info := &waE2E.ContextInfo{MentionedJID: mentioned}
	if quotedID != "" {
		info.MessageID = ptr(quotedID)
	}
	return info
}

// StoreMedia grava a midia recebida em disco e devolve o caminho.
func (s *Session) StoreMedia(messageID string, data []byte, mime, filename string) (string, error) {
	if filename == "" {
		filename = messageID
	}
	filename = sanitizeFileName(filename)
	path := filepath.Join(s.mediaDir, messageID+"-"+filename)
	if err := os.WriteFile(path, data, 0o640); err != nil {
		return "", err
	}
	return path, nil
}

// MediaFile devolve o arquivo de midia ja salvo.
func (s *Session) MediaFile(messageID string) (string, bool) {
	entries, err := os.ReadDir(s.mediaDir)
	if err != nil {
		return "", false
	}
	for _, entry := range entries {
		if strings.HasPrefix(entry.Name(), messageID+"-") {
			return filepath.Join(s.mediaDir, entry.Name()), true
		}
	}
	return "", false
}

func sanitizeFileName(name string) string {
	name = filepath.Base(name)
	var out strings.Builder
	for _, r := range name {
		switch {
		case r >= 'a' && r <= 'z', r >= 'A' && r <= 'Z', r >= '0' && r <= '9', r == '.', r == '-', r == '_':
			out.WriteRune(r)
		default:
			out.WriteRune('_')
		}
	}
	res := out.String()
	if res == "" {
		return "arquivo"
	}
	return res
}

// downloadMedia baixa a midia de uma mensagem recebida.
func (s *Session) downloadMedia(ctx context.Context, msg *waE2E.Message) ([]byte, string, error) {
	reader, err := s.Client.DownloadAny(ctx, msg, "")
	if err != nil {
		return nil, "", err
	}
	defer reader.Close()
	data, err := io.ReadAll(reader)
	if err != nil {
		return nil, "", err
	}
	return data, mediaFileName(msg), nil
}

func mediaFileName(msg *waE2E.Message) string {
	switch {
	case msg.ImageMessage != nil:
		return "imagem.jpg"
	case msg.VideoMessage != nil:
		return "video.mp4"
	case msg.AudioMessage != nil:
		return "audio.ogg"
	case msg.DocumentMessage != nil:
		if name := msg.DocumentMessage.GetFileName(); name != "" {
			return name
		}
		return "documento"
	case msg.StickerMessage != nil:
		return "sticker.webp"
	default:
		return "arquivo"
	}
}

func mediaMime(msg *waE2E.Message) string {
	switch {
	case msg.ImageMessage != nil:
		return msg.ImageMessage.GetMIMEType()
	case msg.VideoMessage != nil:
		return msg.VideoMessage.GetMIMEType()
	case msg.AudioMessage != nil:
		return msg.AudioMessage.GetMIMEType()
	case msg.DocumentMessage != nil:
		return msg.DocumentMessage.GetMIMEType()
	default:
		return "application/octet-stream"
	}
}

// protoString evitaildes de marshalling em debug.
func protoString(msg *waE2E.Message) string {
	data, err := proto.Marshal(msg)
	if err != nil {
		return ""
	}
	return fmt.Sprintf("%d bytes", len(data))
}

var _ = protoString
var _ = time.Second
