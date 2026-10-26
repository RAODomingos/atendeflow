package main

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// Routes monta a API da bridge. O contrato é consumido por
// app/Services/WhatsApp/WhatsmeowProvider.php — os dois lados andam juntos.
func (m *SessionManager) Routes() http.Handler {
	mux := http.NewServeMux()

	mux.HandleFunc("GET /health", m.handleHealth)
	mux.HandleFunc("POST /sessions", m.handleCreateSession)
	mux.HandleFunc("GET /sessions/{id}/qr", m.handleQR)
	mux.HandleFunc("GET /sessions/{id}/status", m.handleStatus)
	mux.HandleFunc("POST /sessions/{id}/disconnect", m.handleDisconnect)
	mux.HandleFunc("DELETE /sessions/{id}", m.handleDeleteSession)
	mux.HandleFunc("POST /sessions/{id}/webhook", m.handleSetWebhook)
	mux.HandleFunc("POST /sessions/{id}/send", m.handleSend)
	mux.HandleFunc("POST /sessions/{id}/interactive/buttons", m.handleInteractiveButtons)
	mux.HandleFunc("POST /sessions/{id}/interactive/list", m.handleInteractiveList)
	mux.HandleFunc("POST /sessions/{id}/messages/{mid}/edit", m.handleEditMessage)
	mux.HandleFunc("POST /sessions/{id}/messages/{mid}/delete", m.handleDeleteMessage)
	mux.HandleFunc("POST /sessions/{id}/messages/{mid}/react", m.handleReactMessage)
	mux.HandleFunc("POST /sessions/{id}/messages/seen", m.handleSeen)
	mux.HandleFunc("POST /sessions/{id}/typing", m.handleTyping)
	mux.HandleFunc("POST /sessions/{id}/groups/{jid}/text", m.handleGroupText)
	mux.HandleFunc("GET /sessions/{id}/groups/{jid}/participants", m.handleParticipants)
	mux.HandleFunc("POST /sessions/{id}/contact", m.handleContact)
	mux.HandleFunc("GET /sessions/{id}/contacts/{contact}/avatar", m.handleAvatar)
	mux.HandleFunc("GET /sessions/{id}/resolve/{contact}", m.handleResolve)
	mux.HandleFunc("GET /sessions/{id}/media/{mid}", m.handleMedia)

	return mux
}

// ---------------------------------------------------------------- payloads

type createSessionRequest struct {
	Name string `json:"name"`
}

type sendRequest struct {
	To      string        `json:"to"`
	Type    string        `json:"type"`
	Text    string        `json:"text"`
	Media   *mediaRequest `json:"media"`
	Options *sendOptions  `json:"options"`
}

type sendOptions struct {
	Caption  string `json:"caption"`
	QuotedID string `json:"quoted_id"`
	FileName string `json:"file_name"`
	MimeType string `json:"mimetype"`
	AsVoice  bool   `json:"as_voice"`
}

// UnmarshalJSON aceita objeto (o contrato) e array vazio.
//
// Um cliente PHP que monta as opcoes num array associativo vazio serializa
// como [] e o Go recusaria com 400 — o que derrubaria TODO o envio. Como a
// falha silenciosa custa caro, a bridge prefere aceitar as duas formas.
func (o *sendOptions) UnmarshalJSON(data []byte) error {
	trimmed := bytes.TrimSpace(data)
	if len(trimmed) == 0 || string(trimmed) == "null" {
		return nil
	}

	if trimmed[0] == '[' {
		var list []json.RawMessage
		if err := json.Unmarshal(trimmed, &list); err != nil {
			return err
		}
		if len(list) > 0 {
			return fmt.Errorf("options deve ser um objeto JSON")
		}
		return nil
	}

	type plain sendOptions
	var decoded plain
	if err := json.Unmarshal(trimmed, &decoded); err != nil {
		return err
	}
	*o = sendOptions(decoded)
	return nil
}

type mediaRequest struct {
	Filename string `json:"filename"`
	MimeType string `json:"mimetype"`
	Data     string `json:"data"`
	Encoding string `json:"encoding"`
	URL      string `json:"url"`
	Type     string `json:"type"`
}

type interactiveRequest struct {
	To      string   `json:"to"`
	Text    string   `json:"text"`
	Title   string   `json:"title"`
	Buttons []Option `json:"buttons"`
	Items   []Option `json:"items"`
}

type editRequest struct {
	Text string `json:"text"`
}

type reactionRequest struct {
	Reaction string `json:"reaction"`
	To       string `json:"to"`
}

type targetRequest struct {
	To string `json:"to"`
}

type typingRequest struct {
	To    string `json:"to"`
	State string `json:"state"`
}

type groupTextRequest struct {
	Text     string   `json:"text"`
	Mentions []string `json:"mentions"`
}

type contactRequest struct {
	To           string `json:"to"`
	Name         string `json:"name"`
	Phone        string `json:"phone"`
	Organization string `json:"organization"`
}

type webhookRequest struct {
	URL    string `json:"url"`
	Secret string `json:"secret"`
}

// ---------------------------------------------------------------- handlers

func writeJSONBody(w http.ResponseWriter, payload any) error {
	return json.NewEncoder(w).Encode(payload)
}

func (m *SessionManager) handleHealth(w http.ResponseWriter, r *http.Request) {
	m.mu.RLock()
	sessions := make([]map[string]string, 0, len(m.sessions))
	for name, session := range m.sessions {
		status, phone, _ := session.snapshot()
		sessions = append(sessions, map[string]string{"name": name, "status": status, "phone_number": phone})
	}
	m.mu.RUnlock()

	writeJSON(w, http.StatusOK, map[string]any{
		"ok":       true,
		"engine":   "whatsmeow",
		"sessions": sessions,
	})
}

func (m *SessionManager) handleCreateSession(w http.ResponseWriter, r *http.Request) {
	var body createSessionRequest
	if err := decodeJSON(r, &body); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": err.Error()})
		return
	}
	if strings.TrimSpace(body.Name) == "" {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": "name obrigatorio"})
		return
	}

	session, err := m.Ensure(body.Name)
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, map[string]string{"error": err.Error()})
		return
	}
	status, phone, qr := session.snapshot()
	writeJSON(w, http.StatusOK, map[string]any{
		"id":           session.Name,
		"status":       status,
		"phone_number": phone,
		"qr_code":      qr,
	})
}

func (m *SessionManager) handleQR(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	status, phone, qr := session.snapshot()
	writeJSON(w, http.StatusOK, map[string]any{
		"qr_code":      qr,
		"status":       status,
		"phone_number": phone,
	})
}

func (m *SessionManager) handleStatus(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	status, phone, qr := session.snapshot()
	writeJSON(w, http.StatusOK, map[string]any{
		"status":       status,
		"phone_number": phone,
		"qr_code":      qr,
	})
}

func (m *SessionManager) handleDisconnect(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	if session.Client != nil {
		session.Client.Disconnect()
	}
	session.setStatus("disconnected")
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (m *SessionManager) handleDeleteSession(w http.ResponseWriter, r *http.Request) {
	name := r.PathValue("id")
	if err := m.Remove(name); err != nil {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (m *SessionManager) handleSetWebhook(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	var body webhookRequest
	if err := decodeJSON(r, &body); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": err.Error()})
		return
	}
	session.setWebhook(body.URL, body.Secret)
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (m *SessionManager) handleSend(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	if !m.exigePareada(w, session) {
		return
	}
	var body sendRequest
	if err := decodeJSON(r, &body); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": err.Error()})
		return
	}

	ctx, cancel := context.WithTimeout(r.Context(), 90*time.Second)
	defer cancel()

	options := sendOptions{}
	if body.Options != nil {
		options = *body.Options
	}

	if body.Type == "text" || body.Type == "" {
		id, err := session.SendText(ctx, body.To, body.Text, options.QuotedID)
		if err != nil {
			writeJSON(w, http.StatusBadGateway, map[string]string{"error": err.Error()})
			return
		}
		writeJSON(w, http.StatusOK, map[string]any{"message_id": id, "native": true})
		return
	}

	data, filename, mime, err := m.mediaBytes(ctx, body.Media)
	if err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": err.Error()})
		return
	}
	if filename == "" {
		filename = options.FileName
	}
	if mime == "" {
		mime = options.MimeType
	}

	id, err := session.SendMedia(ctx, body.To, data, filename, mime, options.Caption, options.QuotedID, options.AsVoice)
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"message_id": id, "native": true})
}

func (m *SessionManager) handleInteractiveButtons(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	if !m.exigePareada(w, session) {
		return
	}
	var body interactiveRequest
	if err := decodeJSON(r, &body); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": err.Error()})
		return
	}

	ctx, cancel := context.WithTimeout(r.Context(), 60*time.Second)
	defer cancel()

	id, native, err := session.SendButtons(ctx, body.To, body.Text, body.Buttons)
	if err != nil {
		// O app degrada para texto numerado quando native=false.
		writeJSON(w, http.StatusOK, map[string]any{"native": false, "error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"message_id": id, "native": native})
}

func (m *SessionManager) handleInteractiveList(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	if !m.exigePareada(w, session) {
		return
	}
	var body interactiveRequest
	if err := decodeJSON(r, &body); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": err.Error()})
		return
	}

	ctx, cancel := context.WithTimeout(r.Context(), 60*time.Second)
	defer cancel()

	id, native, err := session.SendList(ctx, body.To, body.Text, body.Title, body.Items)
	if err != nil {
		writeJSON(w, http.StatusOK, map[string]any{"native": false, "error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"message_id": id, "native": native})
}

func (m *SessionManager) handleEditMessage(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	if !m.exigePareada(w, session) {
		return
	}
	var body editRequest
	if err := decodeJSON(r, &body); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": err.Error()})
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 30*time.Second)
	defer cancel()

	if err := session.EditMessage(ctx, r.PathValue("id"), r.PathValue("mid"), body.Text); err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (m *SessionManager) handleDeleteMessage(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	if !m.exigePareada(w, session) {
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 30*time.Second)
	defer cancel()

	if err := session.DeleteMessage(ctx, r.PathValue("id"), r.PathValue("mid")); err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (m *SessionManager) handleReactMessage(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	if !m.exigePareada(w, session) {
		return
	}
	var body reactionRequest
	if err := decodeJSON(r, &body); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": err.Error()})
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 30*time.Second)
	defer cancel()

	chat := r.PathValue("id")
	if body.To != "" {
		chat = body.To
	}
	if err := session.ReactMessage(ctx, chat, r.PathValue("mid"), body.Reaction); err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (m *SessionManager) handleSeen(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	if !m.exigePareada(w, session) {
		return
	}
	var body targetRequest
	if err := decodeJSON(r, &body); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": err.Error()})
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 15*time.Second)
	defer cancel()

	if err := session.MarkSeen(ctx, body.To); err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (m *SessionManager) handleTyping(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	if !m.exigePareada(w, session) {
		return
	}
	var body typingRequest
	if err := decodeJSON(r, &body); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": err.Error()})
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 15*time.Second)
	defer cancel()

	if err := session.SetTyping(ctx, body.To, body.State == "composing"); err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"ok": true})
}

func (m *SessionManager) handleGroupText(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	if !m.exigePareada(w, session) {
		return
	}
	var body groupTextRequest
	if err := decodeJSON(r, &body); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": err.Error()})
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 90*time.Second)
	defer cancel()

	id, err := session.SendGroupText(ctx, r.PathValue("jid"), body.Text, body.Mentions)
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"message_id": id})
}

func (m *SessionManager) handleParticipants(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	if !m.exigePareada(w, session) {
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 30*time.Second)
	defer cancel()

	participants, err := session.GroupParticipants(ctx, r.PathValue("jid"))
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, participants)
}

func (m *SessionManager) handleContact(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	if !m.exigePareada(w, session) {
		return
	}
	var body contactRequest
	if err := decodeJSON(r, &body); err != nil {
		writeJSON(w, http.StatusBadRequest, map[string]string{"error": err.Error()})
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 30*time.Second)
	defer cancel()

	id, err := session.SendContact(ctx, body.To, body.Name, body.Phone, body.Organization)
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]any{"message_id": id})
}

func (m *SessionManager) handleAvatar(w http.ResponseWriter, r *http.Request) {
	// A foto de perfil depende de um endpoint do whatsmeow que muda entre
	// versoes; enquanto isso, o app usa o avatar que ja tem no contato.
	writeJSON(w, http.StatusNotFound, map[string]string{"error": "avatar indisponivel"})
}

func (m *SessionManager) handleResolve(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 15*time.Second)
	defer cancel()

	phone, lid, err := session.ResolveContact(ctx, r.PathValue("contact"))
	if err != nil {
		writeJSON(w, http.StatusBadGateway, map[string]string{"error": err.Error()})
		return
	}
	writeJSON(w, http.StatusOK, map[string]string{"phone": phone, "lid": lid})
}

func (m *SessionManager) handleMedia(w http.ResponseWriter, r *http.Request) {
	session, ok := m.session(w, r)
	if !ok {
		return
	}
	messageID := r.PathValue("mid")
	path, found := session.MediaFile(messageID)
	if !found {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "midia nao encontrada"})
		return
	}

	info, err := os.Stat(path)
	if err != nil {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "midia nao encontrada"})
		return
	}

	writeJSON(w, http.StatusOK, map[string]string{
		"url":  m.mediaURLFromRequest(r, session, messageID),
		"mime": mimeFromPath(path),
		"name": filepath.Base(path),
	})
	_ = info
}

// ---------------------------------------------------------------- helpers

// exigePareada devolve false (e responde 409) quando a sessao ainda nao foi
// pareada com o WhatsApp. Sem isso a chamada fica esperando conexao ate
// estourar o timeout.
func (m *SessionManager) exigePareada(w http.ResponseWriter, session *Session) bool {
	if session.isPaired() {
		return true
	}
	writeJSON(w, http.StatusConflict, map[string]string{
		"error": "sessao ainda nao pareada com o WhatsApp",
	})
	return false
}

func (m *SessionManager) session(w http.ResponseWriter, r *http.Request) (*Session, bool) {
	name := r.PathValue("id")
	session, ok := m.Get(name)
	if !ok {
		writeJSON(w, http.StatusNotFound, map[string]string{"error": "sessao nao encontrada: " + name})
		return nil, false
	}
	return session, true
}

func decodeJSON(r *http.Request, target any) error {
	defer r.Body.Close()
	decoder := json.NewDecoder(r.Body)
	return decoder.Decode(target)
}

// mediaBytes devolve os bytes da midia (base64 no corpo ou url).
func (m *SessionManager) mediaBytes(ctx context.Context, media *mediaRequest) ([]byte, string, string, error) {
	if media == nil {
		return nil, "", "", fmt.Errorf("midia obrigatoria")
	}
	if media.Data != "" {
		if strings.EqualFold(media.Encoding, "base64") || media.Encoding == "" {
			data, err := base64.StdEncoding.DecodeString(strings.TrimSpace(media.Data))
			if err != nil {
				return nil, "", "", fmt.Errorf("base64 invalido: %w", err)
			}
			return data, media.Filename, media.MimeType, nil
		}
	}
	if media.URL != "" {
		req, err := http.NewRequestWithContext(ctx, http.MethodGet, media.URL, nil)
		if err != nil {
			return nil, "", "", err
		}
		resp, err := (&http.Client{Timeout: 60 * time.Second}).Do(req)
		if err != nil {
			return nil, "", "", err
		}
		defer resp.Body.Close()
		if resp.StatusCode >= 400 {
			return nil, "", "", fmt.Errorf("download da midia falhou (%d)", resp.StatusCode)
		}
		data, err := readAll(resp.Body)
		if err != nil {
			return nil, "", "", err
		}
		return data, media.Filename, media.MimeType, nil
	}
	return nil, "", "", fmt.Errorf("midia sem conteudo")
}

func readAll(r io.Reader) ([]byte, error) {
	return io.ReadAll(r)
}

// mediaURLFromRequest monta a URL publica da midia.
func (m *SessionManager) mediaURLFromRequest(r *http.Request, session *Session, messageID string) string {
	if m.cfg.publicURL != "" {
		return strings.TrimRight(m.cfg.publicURL, "/") + "/sessions/" + session.Name + "/media/" + messageID
	}
	scheme := "http"
	if proto := r.Header.Get("X-Forwarded-Proto"); proto != "" {
		scheme = proto
	}
	return scheme + "://" + r.Host + "/sessions/" + session.Name + "/media/" + messageID
}

// mediaURL monta a URL da midia sem request (usada no envelope do webhook).
func (m *SessionManager) mediaURL(session *Session, messageID string) string {
	if m.cfg.publicURL != "" {
		return strings.TrimRight(m.cfg.publicURL, "/") + "/sessions/" + session.Name + "/media/" + messageID
	}
	return "/sessions/" + session.Name + "/media/" + messageID
}

func mimeFromPath(path string) string {
	switch strings.ToLower(filepath.Ext(path)) {
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
	case ".pdf":
		return "application/pdf"
	default:
		return "application/octet-stream"
	}
}

var _ = log.Printf
