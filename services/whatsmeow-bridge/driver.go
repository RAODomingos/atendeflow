package main

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
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
				Text:        ptr(text),
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

	var mentioned []string
	for _, mention := range mentions {
		if strings.EqualFold(mention, "all") {
			mentioned = append(mentioned, "all")
			continue
		}
		mjid, err := resolveJID(mention)
		if err != nil {
			continue
		}
		mentioned = append(mentioned, mjid.String())
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

// SendMedia envia imagem/audio/video/documento.
// O upload e feito pela propria biblioteca (Client.Upload) e o mime do
// arquivo decide o tipo da mensagem montada a partir do UploadResponse.
func (s *Session) SendMedia(ctx context.Context, to string, data []byte, filename, mime, caption, quotedID string, asVoice bool) (string, error) {
	jid, err := resolveJID(to)
	if err != nil {
		return "", err
	}
	if filename == "" {
		filename = "arquivo"
	}
	if mime == "" {
		mime = detectMime(filename)
	}

	kind := detectMediaKind(filename, mime)
	uploaded, err := s.Client.Upload(ctx, data, mediaTypeFor(kind))
	if err != nil {
		return "", err
	}

	msg := s.buildMediaMessage(kind, uploaded, filename, mime, caption, quotedID, asVoice)

	resp, err := s.Client.SendMessage(ctx, jid, msg)
	if err != nil {
		return "", err
	}
	return string(resp.ID), nil
}

// mediaKind classifica a midia que vai ser enviada.
type mediaKind int

const (
	mediaImage mediaKind = iota
	mediaVideo
	mediaAudio
	mediaDocument
)

// detectMediaKind decide o tipo a partir do mime e da extensao do arquivo.
func detectMediaKind(filename, mime string) mediaKind {
	mime = strings.ToLower(strings.TrimSpace(mime))
	ext := strings.ToLower(filepath.Ext(filename))

	switch {
	case strings.HasPrefix(mime, "image/"),
		ext == ".jpg", ext == ".jpeg", ext == ".png", ext == ".webp":
		return mediaImage
	case strings.HasPrefix(mime, "video/"),
		ext == ".mp4", ext == ".3gp", ext == ".mkv", ext == ".mov":
		return mediaVideo
	case strings.HasPrefix(mime, "audio/"),
		ext == ".ogg", ext == ".opus", ext == ".mp3", ext == ".m4a", ext == ".aac":
		return mediaAudio
	default:
		return mediaDocument
	}
}

// mediaTypeFor devolve a chave de criptografia usada no upload.
func mediaTypeFor(kind mediaKind) whatsmeow.MediaType {
	switch kind {
	case mediaImage:
		return whatsmeow.MediaImage
	case mediaVideo:
		return whatsmeow.MediaVideo
	case mediaAudio:
		return whatsmeow.MediaAudio
	default:
		return whatsmeow.MediaDocument
	}
}

// buildMediaMessage monta a mensagem de midia a partir do UploadResponse.
func (s *Session) buildMediaMessage(kind mediaKind, up whatsmeow.UploadResponse, filename, mime, caption, quotedID string, asVoice bool) *waE2E.Message {
	var contextInfo *waE2E.ContextInfo
	if quotedID != "" {
		contextInfo = s.contextInfo(quotedID, nil)
	}

	switch kind {
	case mediaImage:
		msg := &waE2E.ImageMessage{
			URL:           ptr(up.URL),
			DirectPath:    ptr(up.DirectPath),
			MediaKey:      up.MediaKey,
			FileEncSHA256: up.FileEncSHA256,
			FileSHA256:    up.FileSHA256,
			FileLength:    ptr(up.FileLength),
			Mimetype:      ptr(mime),
			ContextInfo:   contextInfo,
		}
		if caption != "" {
			msg.Caption = ptr(caption)
		}
		return &waE2E.Message{ImageMessage: msg}

	case mediaVideo:
		msg := &waE2E.VideoMessage{
			URL:           ptr(up.URL),
			DirectPath:    ptr(up.DirectPath),
			MediaKey:      up.MediaKey,
			FileEncSHA256: up.FileEncSHA256,
			FileSHA256:    up.FileSHA256,
			FileLength:    ptr(up.FileLength),
			Mimetype:      ptr(mime),
			ContextInfo:   contextInfo,
		}
		if caption != "" {
			msg.Caption = ptr(caption)
		}
		return &waE2E.Message{VideoMessage: msg}

	case mediaAudio:
		msg := &waE2E.AudioMessage{
			URL:           ptr(up.URL),
			DirectPath:    ptr(up.DirectPath),
			MediaKey:      up.MediaKey,
			FileEncSHA256: up.FileEncSHA256,
			FileSHA256:    up.FileSHA256,
			FileLength:    ptr(up.FileLength),
			Mimetype:      ptr(mime),
			ContextInfo:   contextInfo,
		}
		if asVoice {
			msg.PTT = ptr(true)
		}
		return &waE2E.Message{AudioMessage: msg}

	default:
		msg := &waE2E.DocumentMessage{
			URL:           ptr(up.URL),
			DirectPath:    ptr(up.DirectPath),
			MediaKey:      up.MediaKey,
			FileEncSHA256: up.FileEncSHA256,
			FileSHA256:    up.FileSHA256,
			FileLength:    ptr(up.FileLength),
			Mimetype:      ptr(mime),
			FileName:      ptr(filename),
			Title:         ptr(filename),
			ContextInfo:   contextInfo,
		}
		if caption != "" {
			msg.Caption = ptr(caption)
		}
		return &waE2E.Message{DocumentMessage: msg}
	}
}

// detectMime adivinha o mime pelo extensao do arquivo.
func detectMime(filename string) string {
	switch strings.ToLower(filepath.Ext(filename)) {
	case ".jpg", ".jpeg":
		return "image/jpeg"
	case ".png":
		return "image/png"
	case ".webp":
		return "image/webp"
	case ".mp4":
		return "video/mp4"
	case ".ogg", ".opus":
		return "audio/ogg"
	case ".mp3":
		return "audio/mpeg"
	case ".pdf":
		return "application/pdf"
	default:
		return "application/octet-stream"
	}
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
//
// O recibo de leitura do WhatsApp aponta para uma mensagem concreta, entao a
// sessao guarda a ultima mensagem recebida em cada chat. Sem ela nao ha o que
// marcar — e nao e erro: o app chama isso como melhor esforco.
func (s *Session) MarkSeen(ctx context.Context, chatJID string) error {
	jid, err := resolveJID(chatJID)
	if err != nil {
		return err
	}

	last, ok := s.lastMessage(jid)
	if !ok {
		return nil
	}

	return s.Client.MarkRead(
		ctx,
		[]types.MessageID{last.id},
		time.Now(),
		jid,
		last.sender,
		types.ReceiptTypeRead,
	)
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
func (s *Session) SetPresence(ctx context.Context, available bool) {
	if available {
		_ = s.Client.SendPresence(ctx, types.PresenceAvailable)
		return
	}
	_ = s.Client.SendPresence(ctx, types.PresenceUnavailable)
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
		if s.hasContactStore() {
			if contact, err := s.Client.Store.Contacts.GetContact(ctx, p.JID); err == nil {
				name = contact.FullName
			}
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
//
// Uma sessao que ainda nao foi pareada nao tem mapa LID/telefone: nesse caso
// devolvemos o que da para saber (o proprio id) em vez de estourar.
func (s *Session) ResolveContact(ctx context.Context, contact string) (phone string, lid string, err error) {
	jid, err := resolveJID(contact)
	if err != nil {
		return "", "", err
	}

	if jid.Server == types.HiddenUserServer {
		lid = jid.User
		if !s.hasLIDStore() {
			return "", lid, nil
		}
		pn, err := s.Client.Store.LIDs.GetPNForLID(ctx, jid)
		if err == nil && !pn.IsEmpty() {
			phone = pn.User
		}
		return phone, lid, nil
	}

	phone = jid.User
	// Descobre o LID correspondente (quando o WhatsApp ja conhece o par).
	if s.hasLIDStore() {
		if lidJID, err := s.Client.Store.LIDs.GetLIDForPN(ctx, jid); err == nil && !lidJID.IsEmpty() {
			lid = lidJID.User
		}
	}
	return phone, lid, nil
}

// isPaired indica se a sessao ja foi pareada com o WhatsApp. Sem pareamento
// as chamadas que vao ate o WhatsApp ficam esperando conexao ate estourar o
// timeout, entao a API usa isso para responder na hora.
func (s *Session) isPaired() bool {
	return s.Client != nil && s.Client.Store != nil && s.Client.Store.ID != nil
}

// hasLIDStore diz se o store de LID/telefone ja pode ser consultado.
func (s *Session) hasLIDStore() bool {
	return s.Client != nil && s.Client.Store != nil && s.Client.Store.LIDs != nil
}

// hasContactStore diz se o store de contatos ja pode ser consultado.
func (s *Session) hasContactStore() bool {
	return s.Client != nil && s.Client.Store != nil && s.Client.Store.Contacts != nil
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
func (s *Session) contextInfo(quotedID string, mentioned []string) *waE2E.ContextInfo {
	info := &waE2E.ContextInfo{MentionedJID: mentioned}
	if quotedID != "" {
		info.StanzaID = ptr(quotedID)
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
	if strings.TrimSpace(name) == "" {
		return "arquivo"
	}
	name = filepath.Base(name)
	if name == "." || name == string(filepath.Separator) {
		return "arquivo"
	}
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
	data, err := s.Client.DownloadAny(ctx, msg)
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
		return msg.ImageMessage.GetMimetype()
	case msg.VideoMessage != nil:
		return msg.VideoMessage.GetMimetype()
	case msg.AudioMessage != nil:
		return msg.AudioMessage.GetMimetype()
	case msg.DocumentMessage != nil:
		return msg.DocumentMessage.GetMimetype()
	default:
		return "application/octet-stream"
	}
}

// mediaMessageSize devolve o tamanho declarado da midia (0 quando ausente).
func mediaMessageSize(msg *waE2E.Message) uint64 {
	switch {
	case msg.ImageMessage != nil:
		return msg.ImageMessage.GetFileLength()
	case msg.VideoMessage != nil:
		return msg.VideoMessage.GetFileLength()
	case msg.AudioMessage != nil:
		return msg.AudioMessage.GetFileLength()
	case msg.DocumentMessage != nil:
		return msg.DocumentMessage.GetFileLength()
	default:
		return 0
	}
}
