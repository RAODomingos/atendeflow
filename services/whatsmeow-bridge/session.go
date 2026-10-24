package main

import (
	"context"
	"encoding/base64"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	qrcode "github.com/skip2/go-qrcode"
	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/store/sqlstore"
	"go.mau.fi/whatsmeow/types"
	"go.mau.fi/whatsmeow/types/events"

	// Driver SQLite em Go puro: mantem CGO_ENABLED=0 na imagem Docker.
	_ "modernc.org/sqlite"
)

// Session é uma sessão do WhatsApp (= um canal do AtendeFlow).
type Session struct {
	Name      string
	Container *sqlstore.Container
	Client    *whatsmeow.Client

	mu            sync.RWMutex
	status        string
	qrDataURL     string
	phoneNumber   string
	webhookURL    string
	webhookSecret string
	lastMessages  map[string]lastMessage

	mediaDir string
}

// lastMessage identifica a ultima mensagem recebida em um chat. O recibo de
// leitura do WhatsApp aponta para uma mensagem concreta, entao a sessao
// precisa se lembrar da ultima de cada chat.
type lastMessage struct {
	id     types.MessageID
	sender types.JID
}

// SessionManager guarda as sessões em memória; cada uma tem o seu próprio
// arquivo SQLite (um por canal), o que isola as credenciais e evita locking
// entre sessões.
type SessionManager struct {
	cfg      config
	mu       sync.RWMutex
	sessions map[string]*Session
}

func NewSessionManager(cfg config) (*SessionManager, error) {
	if err := os.MkdirAll(cfg.dataDir, 0o750); err != nil {
		return nil, fmt.Errorf("nao foi possivel criar %s: %w", cfg.dataDir, err)
	}
	mgr := &SessionManager{cfg: cfg, sessions: map[string]*Session{}}
	mgr.loadExisting()
	return mgr, nil
}

// loadExisting sobe as sessoes que ja existem em disco (so uma instancia da
// bridge por vez, mas o canal continua funcionando depois de reiniciar).
func (m *SessionManager) loadExisting() {
	entries, err := os.ReadDir(m.cfg.dataDir)
	if err != nil {
		log.Printf("nao foi possivel listar as sessoes existentes: %v", err)
		return
	}
	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}
		name := entry.Name()
		if _, err := m.Ensure(name); err != nil {
			log.Printf("falha ao retomar a sessao %s: %v", name, err)
		}
	}
}

func sessionFileName(name string) string {
	safe := strings.Map(func(r rune) rune {
		switch {
		case r >= 'a' && r <= 'z', r >= 'A' && r <= 'Z', r >= '0' && r <= '9', r == '-', r == '_':
			return r
		default:
			return '_'
		}
	}, name)
	return safe + ".db"
}

// openSessionStore abre (e migra) o SQLite da sessao.
//
// O driver e o modernc.org/sqlite, em Go puro: assim a imagem Docker roda com
// CGO_ENABLED=0, sem precisar de gcc/build-base. O dialeto "sqlite" e
// interpretado pelo dbutil do whatsmeow como SQLite; as foreign keys sao
// obrigatorias (o proprio sqlstore recusa o upgrade sem elas).
func openSessionStore(dir, name string) (*sqlstore.Container, error) {
	path := filepath.Join(dir, sessionFileName(name))
	address := path + "?_pragma=foreign_keys(1)&_pragma=busy_timeout(5000)"

	container, err := sqlstore.New(context.Background(), "sqlite", address, nil)
	if err != nil {
		return nil, fmt.Errorf("nao foi possivel abrir o banco da sessao: %w", err)
	}
	return container, nil
}

// Ensure devolve a sessao, criando-a se ainda nao existir.
func (m *SessionManager) Ensure(name string) (*Session, error) {
	name = strings.TrimSpace(name)
	if name == "" {
		return nil, fmt.Errorf("nome da sessao obrigatorio")
	}

	m.mu.RLock()
	session, ok := m.sessions[name]
	m.mu.RUnlock()
	if ok {
		return session, nil
	}

	m.mu.Lock()
	defer m.mu.Unlock()
	if session, ok := m.sessions[name]; ok {
		return session, nil
	}

	dir := filepath.Join(m.cfg.dataDir, name)
	if err := os.MkdirAll(filepath.Join(dir, "media"), 0o750); err != nil {
		return nil, fmt.Errorf("nao foi possivel criar o diretorio da sessao: %w", err)
	}

	container, err := openSessionStore(dir, name)
	if err != nil {
		return nil, err
	}

	device, err := container.GetFirstDevice(context.Background())
	if err != nil {
		_ = container.Close()
		return nil, fmt.Errorf("nao foi possivel carregar o device da sessao: %w", err)
	}

	// O mapa LID/telefone vive no container (nao no device), entao pode ser
	// usado mesmo antes do pareamento. O resto dos stores do device so e
	// inicializado depois que o pareamento acontece.
	if device.LIDs == nil {
		device.LIDs = container.LIDMap
	}

	session = &Session{
		Name:         name,
		Container:    container,
		Client:       whatsmeow.NewClient(device, nil),
		status:       "disconnected",
		mediaDir:     filepath.Join(dir, "media"),
		lastMessages: map[string]lastMessage{},
	}
	m.sessions[name] = session

	m.attachEvents(session)
	go m.startSession(session)
	return session, nil
}

func (m *SessionManager) Get(name string) (*Session, bool) {
	m.mu.RLock()
	defer m.mu.RUnlock()
	session, ok := m.sessions[name]
	return session, ok
}

func (m *SessionManager) Remove(name string) error {
	m.mu.Lock()
	session, ok := m.sessions[name]
	delete(m.sessions, name)
	m.mu.Unlock()
	if !ok {
		return fmt.Errorf("sessao %s nao encontrada", name)
	}

	if session.Client != nil && session.Client.IsLoggedIn() {
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		_ = session.Client.Logout(ctx)
		cancel()
	}
	if session.Client != nil {
		session.Client.Disconnect()
	}
	if session.Container != nil {
		_ = session.Container.Close()
	}
	return os.RemoveAll(filepath.Join(m.cfg.dataDir, name))
}

func (m *SessionManager) Close() {
	m.mu.Lock()
	defer m.mu.Unlock()
	for _, session := range m.sessions {
		if session.Client != nil {
			session.Client.Disconnect()
		}
		if session.Container != nil {
			_ = session.Container.Close()
		}
	}
}

// startSession conecta o client e alimenta o QR.
func (m *SessionManager) startSession(session *Session) {
	ctx := context.Background()

	if session.Client.Store.ID != nil {
		// Sessao ja pareada: so conecta.
		session.setStatus("connecting")
		if err := session.Client.Connect(); err != nil {
			log.Printf("[%s] falha ao conectar: %v", session.Name, err)
			session.setStatus("error")
			return
		}
		return
	}

	qrChan, err := session.Client.GetQRChannel(ctx)
	if err != nil {
		log.Printf("[%s] falha ao abrir o canal de QR: %v", session.Name, err)
		session.setStatus("error")
		return
	}

	go func() {
		for item := range qrChan {
			switch item.Event {
			case whatsmeow.QRChannelEventCode:
				session.setQR(qqrDataURL(item.Code))
				session.setStatus("waiting_qr")
			case whatsmeow.QRChannelSuccess.Event:
				session.setQR("")
				session.setStatus("connected")
				session.setPhoneNumber(session.Client.Store.GetJID().ToNonAD().User)
			default:
				if item.Error != nil {
					log.Printf("[%s] QR encerrado: %v", session.Name, item.Error)
					session.setStatus("error")
				}
			}
		}
	}()

	session.setStatus("connecting")
	if err := session.Client.Connect(); err != nil {
		log.Printf("[%s] falha ao conectar: %v", session.Name, err)
		session.setStatus("error")
	}
}

// qqrDataURL transforma o texto do QR em PNG (data URL) para o app exibir.
func qqrDataURL(code string) string {
	if code == "" {
		return ""
	}
	png, err := qrcode.Encode(code, qrcode.Medium, 256)
	if err != nil {
		log.Printf("falha ao gerar o PNG do QR: %v", err)
		return ""
	}
	return "data:image/png;base64," + base64.StdEncoding.EncodeToString(png)
}

func (s *Session) setStatus(status string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.status = status
}

func (s *Session) setQR(qr string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.qrDataURL = qr
}

func (s *Session) setPhoneNumber(phone string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.phoneNumber = phone
}

func (s *Session) setWebhook(url, secret string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.webhookURL = url
	s.webhookSecret = secret
}

func (s *Session) webhook() (string, string) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.webhookURL, s.webhookSecret
}

func (s *Session) snapshot() (status, phone, qr string) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.status, s.phoneNumber, s.qrDataURL
}

// rememberMessage guarda a ultima mensagem recebida em um chat.
func (s *Session) rememberMessage(chat types.JID, id types.MessageID, sender types.JID) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.lastMessages == nil {
		s.lastMessages = map[string]lastMessage{}
	}
	s.lastMessages[chat.String()] = lastMessage{id: id, sender: sender}
}

// lastMessage devolve a ultima mensagem recebida em um chat.
func (s *Session) lastMessage(chat types.JID) (lastMessage, bool) {
	s.mu.RLock()
	defer s.mu.RUnlock()
	last, ok := s.lastMessages[chat.String()]
	return last, ok
}

// attachEvents registra o handler de eventos da sessao.
func (m *SessionManager) attachEvents(session *Session) {
	session.Client.AddEventHandler(func(evt any) {
		switch v := evt.(type) {
		case *events.Connected:
			session.setStatus("connected")
			session.setPhoneNumber(session.Client.Store.GetJID().ToNonAD().User)
		case *events.Disconnected:
			session.setStatus("disconnected")
		case *events.LoggedOut:
			session.setStatus("disconnected")
			session.setQR("")
		case *events.PairSuccess:
			session.setStatus("connected")
			session.setPhoneNumber(v.ID.ToNonAD().User)
		case *events.TemporaryBan:
			session.setStatus("error")
		case *events.Message:
			m.handleMessage(session, v)
		}
	})
}

// resolveJID transforma "5511", "5511@s.whatsapp.net" ou "2259@lid" em JID.
func resolveJID(value string) (types.JID, error) {
	value = strings.TrimSpace(value)
	if value == "" {
		return types.EmptyJID, fmt.Errorf("destinatario obrigatorio")
	}
	if strings.Contains(value, "@") {
		return types.ParseJID(value)
	}
	digits := onlyDigits(value)
	if digits == "" {
		return types.EmptyJID, fmt.Errorf("destinatario invalido: %s", value)
	}
	return types.NewJID(digits, types.DefaultUserServer), nil
}

func onlyDigits(value string) string {
	var out strings.Builder
	for _, r := range value {
		if r >= '0' && r <= '9' {
			out.WriteRune(r)
		}
	}
	return out.String()
}
