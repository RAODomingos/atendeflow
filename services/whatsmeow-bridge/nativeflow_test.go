package main

import (
	"encoding/json"
	"strings"
	"testing"

	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
)

// TestNativeFlowButtonsValidaFormatoDoMenu confere que o menu vai no formato
// native flow (InteractiveMessage + NativeFlowMessage) e nao no ButtonsMessage
// legado — que o WhatsApp recusa com 405.
func TestNativeFlowButtonsValidaFormatoDoMenu(t *testing.T) {
	msg, err := buildNativeFlowButtons("Qual loja deseja atendimento?", "", []Option{
		{ID: "loja-1", Label: "Loja Centro"},
		{ID: "loja-2", Label: "Loja Norte"},
	})
	if err != nil {
		t.Fatalf("buildNativeFlowButtons falhou: %v", err)
	}

	if msg.ButtonsMessage != nil {
		t.Fatal("o menu nao pode usar ButtonsMessage: o servidor responde 405")
	}
	if msg.ListMessage != nil {
		t.Fatal("o menu nao pode usar ListMessage: o servidor responde 405")
	}

	interactive := msg.GetInteractiveMessage()
	if interactive == nil {
		t.Fatal("a mensagem precisa ser uma InteractiveMessage")
	}
	if got := interactive.GetBody().GetText(); got != "Qual loja deseja atendimento?" {
		t.Fatalf("corpo errado: %q", got)
	}

	flow := interactive.GetNativeFlowMessage()
	if flow == nil {
		t.Fatal("faltou o NativeFlowMessage")
	}
	if flow.GetMessageVersion() != nativeFlowMessageVersion {
		t.Fatalf("messageVersion errado: %d", flow.GetMessageVersion())
	}
	if len(flow.GetButtons()) != 2 {
		t.Fatalf("esperava 2 botoes, veio %d", len(flow.GetButtons()))
	}

	first := flow.GetButtons()[0]
	if first.GetName() != nativeFlowQuickReply {
		t.Fatalf("nome do botao errado: %q", first.GetName())
	}

	params := map[string]string{}
	if err := json.Unmarshal([]byte(first.GetButtonParamsJSON()), &params); err != nil {
		t.Fatalf("params do botao nao e json: %v", err)
	}
	if params["id"] != "loja-1" {
		t.Fatalf("id do botao errado: %q", params["id"])
	}
	if params["display_text"] != "Loja Centro" {
		t.Fatalf("rotulo do botao errado: %q", params["display_text"])
	}
}

// TestNativeFlowListValidaFormatoDaLista confere que a lista vira um botao
// single_select com as linhas dentro dos params.
func TestNativeFlowListValidaFormatoDaLista(t *testing.T) {
	msg, err := buildNativeFlowList("Escolha uma loja", "Nossas lojas", []Option{
		{ID: "loja-1", Label: "Loja Centro"},
		{ID: "loja-2", Label: "Loja Norte"},
	})
	if err != nil {
		t.Fatalf("buildNativeFlowList falhou: %v", err)
	}
	if msg.ListMessage != nil {
		t.Fatal("a lista nao pode usar ListMessage: o servidor responde 405")
	}

	flow := msg.GetInteractiveMessage().GetNativeFlowMessage()
	if flow == nil {
		t.Fatal("faltou o NativeFlowMessage")
	}
	buttons := flow.GetButtons()
	if len(buttons) != 1 {
		t.Fatalf("esperava 1 botao (single_select), veio %d", len(buttons))
	}
	if buttons[0].GetName() != nativeFlowSingleSelect {
		t.Fatalf("nome do botao errado: %q", buttons[0].GetName())
	}

	var params struct {
		Title    string `json:"title"`
		Sections []struct {
			Title string `json:"title"`
			Rows  []struct {
				ID    string `json:"id"`
				Title string `json:"title"`
			} `json:"rows"`
		} `json:"sections"`
	}
	if err := json.Unmarshal([]byte(buttons[0].GetButtonParamsJSON()), &params); err != nil {
		t.Fatalf("params da lista nao e json: %v", err)
	}
	if params.Title != "Nossas lojas" {
		t.Fatalf("titulo do botao errado: %q", params.Title)
	}
	if len(params.Sections) != 1 || len(params.Sections[0].Rows) != 2 {
		t.Fatalf("secoes/linhas erradas: %+v", params.Sections)
	}
	if params.Sections[0].Rows[1].ID != "loja-2" {
		t.Fatalf("id da linha errado: %q", params.Sections[0].Rows[1].ID)
	}
	if params.Sections[0].Rows[1].Title != "Loja Norte" {
		t.Fatalf("rotulo da linha errado: %q", params.Sections[0].Rows[1].Title)
	}
}

// TestNativeFlowBizNodeConfereONoExigido confere o no <biz> que o servidor
// exige: sem ele a mensagem interativa volta com 405.
func TestNativeFlowBizNodeConfereONoExigido(t *testing.T) {
	node := nativeFlowBizNode()

	if node.Tag != "biz" {
		t.Fatalf("tag errada: %q", node.Tag)
	}
	for _, attr := range []string{"actual_actors", "host_storage", "privacy_mode_ts"} {
		if _, ok := node.Attrs[attr]; !ok {
			t.Fatalf("faltou o atributo %q no no <biz>", attr)
		}
	}

	children := node.GetChildren()
	if len(children) != 2 {
		t.Fatalf("esperava 2 filhos no <biz>, veio %d", len(children))
	}

	interactive := children[0]
	if interactive.Tag != "interactive" {
		t.Fatalf("filho Interactive errado: %q", interactive.Tag)
	}
	if interactive.Attrs["type"] != "native_flow" {
		t.Fatalf("tipo do <interactive> errado: %v", interactive.Attrs["type"])
	}
	if interactive.Attrs["v"] != "1" {
		t.Fatalf("versao do <interactive> errada: %v", interactive.Attrs["v"])
	}

	flow := interactive.GetChildByTag("native_flow")
	if flow.Tag != "native_flow" {
		t.Fatalf("faltou o <native_flow> dentro do <interactive>")
	}
	if flow.Attrs["v"] != "9" {
		t.Fatalf("versao do <native_flow> errada: %v", flow.Attrs["v"])
	}
	if flow.Attrs["name"] != "mixed" {
		t.Fatalf("nome do <native_flow> errado: %v", flow.Attrs["name"])
	}

	if children[1].Tag != "quality_control" {
		t.Fatalf("faltou o <quality_control> dentro do <biz>")
	}
}

// TestNativeFlowAdditionalNodesPorTipoDeChat confere que a conversa privada
// leva o no <bot> e o grupo nao.
func TestNativeFlowAdditionalNodesPorTipoDeChat(t *testing.T) {
	private := nativeFlowAdditionalNodes(types.NewJID("5511999998888", types.DefaultUserServer))
	if len(private) != 2 {
		t.Fatalf("conversa privada deveria ter 2 nos, veio %d", len(private))
	}
	if private[0].Tag != "bot" {
		t.Fatalf("primeiro no da conversa privada errado: %q", private[0].Tag)
	}
	if private[1].Tag != "biz" {
		t.Fatalf("segundo no da conversa privada errado: %q", private[1].Tag)
	}

	group := nativeFlowAdditionalNodes(types.NewJID("120363", types.GroupServer))
	if len(group) != 1 {
		t.Fatalf("grupo deveria ter 1 no, veio %d", len(group))
	}
	if group[0].Tag != "biz" {
		t.Fatalf("no do grupo errado: %q", group[0].Tag)
	}
}

// TestParseNativeFlowResponse confere a leitura da resposta a um toque.
func TestParseNativeFlowResponse(t *testing.T) {
	parsed, _ := parseNativeFlowResponse(`{"id":"loja-1","display_text":"Loja Centro"}`)
	if parsed.ID != "loja-1" {
		t.Fatalf("id errado: %q", parsed.ID)
	}
	if parsed.Label != "Loja Centro" {
		t.Fatalf("rotulo errado: %q", parsed.Label)
	}

	// A lista pode devolver a linha escolhida em outro nivel.
	parsed, _ = parseNativeFlowResponse(`{"selected_row":{"id":"loja-2","title":"Loja Norte"}}`)
	if parsed.ID != "loja-2" {
		t.Fatalf("id da linha errado: %q", parsed.ID)
	}
	if parsed.Label != "Loja Norte" {
		t.Fatalf("rotulo da linha errado: %q", parsed.Label)
	}

	// Sem id, o rotulo cai para o proprio id.
	parsed, _ = parseNativeFlowResponse(`{"id":"x"}`)
	if parsed.Label != "x" {
		t.Fatalf("rotulo de reserva errado: %q", parsed.Label)
	}

	// Json invalido nao pode derrubar o webhook.
	parsed, raw := parseNativeFlowResponse("nao-e-json")
	if parsed.ID != "" || parsed.Label != "" {
		t.Fatalf("json invalido gerou opcao: %+v", parsed)
	}
	if raw != "nao-e-json" {
		t.Fatalf("o bruto deveria ser preservado: %q", raw)
	}
}

// TestMensagemInterativaNaoUsaFormatoLegado garante que nenhuma mensagem
// interativa da bridge volte a usar os formatos recusados pelo servidor.
func TestMensagemInterativaNaoUsaFormatoLegado(t *testing.T) {
	buttons, err := buildNativeFlowButtons("texto", "", []Option{{ID: "a", Label: "A"}})
	if err != nil {
		t.Fatalf("falhou: %v", err)
	}
	list, err := buildNativeFlowList("texto", "titulo", []Option{{ID: "a", Label: "A"}})
	if err != nil {
		t.Fatalf("falhou: %v", err)
	}

	for name, msg := range map[string]*waE2E.Message{"botoes": buttons, "lista": list} {
		if msg.GetInteractiveMessage() == nil {
			t.Fatalf("%s: faltou a InteractiveMessage", name)
		}
		if msg.ButtonsMessage != nil || msg.ListMessage != nil {
			t.Fatalf("%s: ainda usa o formato legado", name)
		}
		if strings.TrimSpace(msg.GetInteractiveMessage().GetNativeFlowMessage().GetMessageParamsJSON()) == "" {
			t.Fatalf("%s: messageParamsJSON vazio", name)
		}
	}
}
