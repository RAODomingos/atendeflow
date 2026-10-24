package main

import "testing"

func TestOnlyDigits(t *testing.T) {
	cases := map[string]string{
		"5511 (11) 99999-8888": "5511999998888",
		"+55 11 99999 8888":    "5511999998888",
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
