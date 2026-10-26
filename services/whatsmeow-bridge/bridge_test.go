package main

import (
	"bytes"
	"context"
	"encoding/json"
	"testing"

	"go.mau.fi/whatsmeow"
	"go.mau.fi/whatsmeow/proto/waAdv"
	"go.mau.fi/whatsmeow/types"
)

func TestOnlyDigits(t *testing.T) {
	cases := map[string]string{
		"5511 99999-8888":     "5511999998888",
		"+55 (11) 99999-8888": "5511999998888",
		"":                    "",
		"abc":                 "",
	}
	for input, expected := range cases {
		if got := onlyDigits(input); got != expected {
			t.Fatalf("onlyDigits(%q) = %q, esperava %q", input, got, expected)
		}
	}
}

func TestResolveJID(t *testing.T) {
	jid, err := resolveJID("5511999998888")
	if err != nil {
		t.Fatalf("resolveJID falhou: %v", err)
	}
	if jid.User != "5511999998888" {
		t.Fatalf("usuario errado: %s", jid.User)
	}

	jid, err = resolveJID("120363@g.us")
	if err != nil {
		t.Fatalf("resolveJID falhou: %v", err)
	}
	if jid.Server != "g.us" {
		t.Fatalf("servidor errado: %s", jid.Server)
	}

	if _, err := resolveJID(""); err == nil {
		t.Fatal("resolveJID deveria falhar com entrada vazia")
	}
}

func TestSanitizeFileName(t *testing.T) {
	if got := sanitizeFileName("../../etc/passwd"); got != "passwd" {
		t.Fatalf("sanitizeFileName nao neutralizou o caminho: %s", got)
	}
	if got := sanitizeFileName(""); got != "arquivo" {
		t.Fatalf("sanitizeFileName deveria devolver 'arquivo': %s", got)
	}
}

func TestExtractVCardPhone(t *testing.T) {
	vcard := "BEGIN:VCARD\nVERSION:3.0\nFN:Ana\nTEL;TYPE=CELL;waid=5511999998888:5511999998888\nEND:VCARD"
	if got := extractVCardPhone(vcard); got != "5511999998888" {
		t.Fatalf("extractVCardPhone = %q", got)
	}
	if got := extractVCardPhone("BEGIN:VCARD\nEND:VCARD"); got != "" {
		t.Fatalf("extractVCardPhone deveria devolver vazio, devolveu %q", got)
	}
}

func TestBuildVCard(t *testing.T) {
	vcard := buildVCard("Ana", "+55 11 99999-8888", "Acme")
	if vcard == "" {
		t.Fatal("vcard vazio")
	}
	if got := extractVCardPhone(vcard); got != "5511999998888" {
		t.Fatalf("telefone no vcard incorreto: %q", got)
	}
}

func TestSessionFileName(t *testing.T) {
	if got := sessionFileName("canal vendas/1"); got != "canal_vendas_1.db" {
		t.Fatalf("sessionFileName = %q", got)
	}
}

// TestOpenSessionStore prova que o store do whatsmeow sobe com o driver
// SQLite em Go puro (sem CGO), que o schema e aplicado e que as foreign keys
// ficam ligadas — sem elas o proprio sqlstore recusa o upgrade.
func TestOpenSessionStore(t *testing.T) {
	dir := t.TempDir()

	container, err := openSessionStore(dir, "canal")
	if err != nil {
		t.Fatalf("openSessionStore falhou: %v", err)
	}
	defer container.Close()

	ctx := context.Background()

	device, err := container.GetFirstDevice(ctx)
	if err != nil {
		t.Fatalf("GetFirstDevice falhou: %v", err)
	}
	if device == nil {
		t.Fatal("GetFirstDevice devolveu nil")
	}
	if device.ID != nil {
		t.Fatalf("device novo nao deveria ter JID: %v", device.ID)
	}

	devices, err := container.GetAllDevices(ctx)
	if err != nil {
		t.Fatalf("GetAllDevices falhou: %v", err)
	}
	if len(devices) != 0 {
		t.Fatalf("esperava 0 devices gravados, veio %d", len(devices))
	}

	novo := container.NewDevice()
	novo.ID = ptr(types.NewJID("5511999998888", types.DefaultUserServer))
	novo.LID = types.NewJID("11111111111", types.HiddenUserServer)
	// O pareamento preenche a conta antes de salvar; sem isso o PutDevice
	// panica ao ler device.Account. As colunas de assinatura tem CHECK de
	// tamanho no schema do whatsmeow.
	novo.Account = &waAdv.ADVSignedDeviceIdentity{
		Details:             bytes.Repeat([]byte{0x01}, 16),
		AccountSignature:    bytes.Repeat([]byte{0x02}, 64),
		AccountSignatureKey: bytes.Repeat([]byte{0x03}, 32),
		DeviceSignature:     bytes.Repeat([]byte{0x04}, 64),
	}
	if err := container.PutDevice(ctx, novo); err != nil {
		t.Fatalf("PutDevice falhou: %v", err)
	}

	device, err = container.GetFirstDevice(ctx)
	if err != nil {
		t.Fatalf("GetFirstDevice (apos gravar) falhou: %v", err)
	}
	if device == nil {
		t.Fatal("device nao foi persistido")
	}
	if device.ID == nil || device.ID.User != "5511999998888" {
		t.Fatalf("device persistido com id inesperado: %v", device.ID)
	}
	if device.LID.User != "11111111111" {
		t.Fatalf("device persistido com LID inesperado: %v", device.LID)
	}
}

// TestResolveContactSemPareamento cobre a sessao recem-criada: o device ainda
// nao tem os stores internos (LIDs/Contacts) porque o pareamento nao
// aconteceu. A API nao pode estourar nesse estado.
func TestResolveContactSemPareamento(t *testing.T) {
	dir := t.TempDir()

	container, err := openSessionStore(dir, "canal")
	if err != nil {
		t.Fatalf("openSessionStore falhou: %v", err)
	}
	defer container.Close()

	ctx := context.Background()
	device, err := container.GetFirstDevice(ctx)
	if err != nil {
		t.Fatalf("GetFirstDevice falhou: %v", err)
	}

	session := &Session{
		Name:      "canal",
		Container: container,
		Client:    whatsmeow.NewClient(device, nil),
	}
	if session.hasContactStore() {
		t.Fatal("device novo nao deveria ter store de contatos pronto")
	}

	phone, lid, err := session.ResolveContact(ctx, "5511999998888")
	if err != nil {
		t.Fatalf("ResolveContact falhou: %v", err)
	}
	if phone != "5511999998888" {
		t.Fatalf("telefone inesperado: %q", phone)
	}
	if lid != "" {
		t.Fatalf("LID inesperado em sessao sem pareamento: %q", lid)
	}

	phone, lid, err = session.ResolveContact(ctx, "11111111111@lid")
	if err != nil {
		t.Fatalf("ResolveContact (lid) falhou: %v", err)
	}
	if phone != "" {
		t.Fatalf("telefone inesperado para lid: %q", phone)
	}
	if lid != "11111111111" {
		t.Fatalf("lid inesperado: %q", lid)
	}
}

// TestSendRequestOptionsAceitaArrayOuObjeto protege o envio.
//
// O `options` da bridge e uma struct. Um cliente que monte as opcoes num
// array associativo vazio serializa como [] e o Go recusaria com 400 — o que
// derrubaria TODO o envio sem mensagem util.
func TestSendRequestOptionsAceitaArrayOuObjeto(t *testing.T) {
	casos := []struct {
		raw       string
		esperaNil bool
		quotedID  string
		asVoice   bool
		descricao string
	}{
		{`{"to":"5511999998888","type":"text","text":"oi","options":{}}`, false, "", false, "objeto vazio"},
		{`{"to":"5511999998888","type":"text","text":"oi","options":[]}`, false, "", false, "array vazio"},
		{`{"to":"5511999998888","type":"text","text":"oi"}`, true, "", false, "sem options"},
		{`{"to":"5511999998888","type":"text","text":"oi","options":null}`, true, "", false, "options nulo"},
		{`{"to":"5511999998888","type":"text","text":"oi","options":{"quoted_id":"Q1","as_voice":true}}`, false, "Q1", true, "objeto preenchido"},
	}

	for _, c := range casos {
		var req sendRequest
		if err := json.Unmarshal([]byte(c.raw), &req); err != nil {
			t.Fatalf("%s: json.Unmarshal falhou: %v", c.descricao, err)
		}
		if c.esperaNil {
			if req.Options != nil {
				t.Fatalf("%s: options deveria ficar nil", c.descricao)
			}
			continue
		}
		if req.Options == nil {
			t.Fatalf("%s: options deveria vir preenchido", c.descricao)
		}
		if req.Options.QuotedID != c.quotedID {
			t.Fatalf("%s: quoted_id = %q, esperava %q", c.descricao, req.Options.QuotedID, c.quotedID)
		}
		if req.Options.AsVoice != c.asVoice {
			t.Fatalf("%s: as_voice = %v, esperava %v", c.descricao, req.Options.AsVoice, c.asVoice)
		}
	}
}

// TestSendRequestOptionsRejeitaArrayComConteudo: um array com itens nao tem
// equivalente no contrato, entao precisa ser erro explicito.
func TestSendRequestOptionsRejeitaArrayComConteudo(t *testing.T) {
	var req sendRequest
	err := json.Unmarshal([]byte(`{"to":"1","type":"text","options":["x"]}`), &req)
	if err == nil {
		t.Fatal("esperava erro para options com array preenchido")
	}
}

// TestDetectMediaKind confere a classificacao da midia por mime/extensao.
func TestDetectMediaKind(t *testing.T) {
	cases := []struct {
		filename string
		mime     string
		expected mediaKind
	}{
		{"foto.jpg", "image/jpeg", mediaImage},
		{"sem-mime.png", "", mediaImage},
		{"video.mp4", "video/mp4", mediaVideo},
		{"audio.ogg", "audio/ogg", mediaAudio},
		{"planilha.xlsx", "application/vnd.ms-excel", mediaDocument},
		{"desconhecido.bin", "application/octet-stream", mediaDocument},
	}

	for _, c := range cases {
		if got := detectMediaKind(c.filename, c.mime); got != c.expected {
			t.Fatalf("detectMediaKind(%q, %q) = %v, esperava %v", c.filename, c.mime, got, c.expected)
		}
	}
}
