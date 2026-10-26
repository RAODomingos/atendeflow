package main

import (
	"encoding/json"
	"fmt"
	"strconv"
	"time"

	waBinary "go.mau.fi/whatsmeow/binary"
	"go.mau.fi/whatsmeow/proto/waE2E"
	"go.mau.fi/whatsmeow/types"
)

// O WhatsApp recusa (405 "not-allowed") as mensagens interativas legadas,
// isto e, ButtonsMessage e ListMessage. O formato aceito hoje e o "native
// flow": uma InteractiveMessage cujo NativeFlowMessage carrega os botoes,
// enviada junto com o no <biz> que o servidor exige.
//
// O no <biz> e o mesmo que o evolution-api (Baileys) injeta via
// additionalNodes, e o formato dos botoes (quick_reply, single_select) e o
// mesmo documentado no Baileys. O whatsmeow so injeta <biz> sozinho para
// ButtonsMessage/ListMessage — justamente os tipos que o servidor recusa —,
// por isso o no e montado aqui e enviado como AdditionalNodes.

const (
	// privacyModeTSOffset e o deslocamento fixo do atributo privacy_mode_ts.
	privacyModeTSOffset = 77980457

	// nativeFlowMessageVersion e a versao do fluxo nativo aceita pelo cliente.
	nativeFlowMessageVersion = 1

	// nativeFlowSingleSelect e o nome do botao que abre o seletor de lista.
	nativeFlowSingleSelect = "single_select"

	// nativeFlowQuickReply e o nome do botao de resposta rapida.
	nativeFlowQuickReply = "quick_reply"
)

// nativeFlowBizNode monta o no <biz> exigido pelo servidor para aceitar
// mensagens interativas em formato native flow.
func nativeFlowBizNode() waBinary.Node {
	return waBinary.Node{
		Tag: "biz",
		Attrs: waBinary.Attrs{
			"actual_actors":   "2",
			"host_storage":    "2",
			"privacy_mode_ts": strconv.FormatInt(time.Now().Unix()-privacyModeTSOffset, 10),
		},
		Content: []waBinary.Node{
			{
				Tag:   "interactive",
				Attrs: waBinary.Attrs{"type": "native_flow", "v": "1"},
				Content: []waBinary.Node{
					{Tag: "native_flow", Attrs: waBinary.Attrs{"v": "9", "name": "mixed"}},
				},
			},
			{
				Tag:   "quality_control",
				Attrs: waBinary.Attrs{"source_type": "third_party"},
			},
		},
	}
}

// nativeFlowAdditionalNodes monta os nos extras do relay.
// Em conversa privada o WhatsApp espera tambem o no <bot biz_bot="1">;
// em grupo ele nao deve ser enviado.
func nativeFlowAdditionalNodes(jid types.JID) []waBinary.Node {
	nodes := make([]waBinary.Node, 0, 2)
	if jid.Server != types.GroupServer {
		nodes = append(nodes, waBinary.Node{
			Tag:   "bot",
			Attrs: waBinary.Attrs{"biz_bot": "1"},
		})
	}
	return append(nodes, nativeFlowBizNode())
}

// nativeFlowMessage monta a InteractiveMessage com o fluxo nativo.
func nativeFlowMessage(header, body, footer string, buttons []*waE2E.InteractiveMessage_NativeFlowMessage_NativeFlowButton) *waE2E.Message {
	interactive := &waE2E.InteractiveMessage{
		Body: &waE2E.InteractiveMessage_Body{Text: ptr(body)},
		InteractiveMessage: &waE2E.InteractiveMessage_NativeFlowMessage_{
			NativeFlowMessage: &waE2E.InteractiveMessage_NativeFlowMessage{
				Buttons:           buttons,
				MessageParamsJSON: ptr("{}"),
				MessageVersion:    ptr(int32(nativeFlowMessageVersion)),
			},
		},
	}
	if header != "" {
		interactive.Header = &waE2E.InteractiveMessage_Header{Title: ptr(header)}
	}
	if footer != "" {
		interactive.Footer = &waE2E.InteractiveMessage_Footer{Text: ptr(footer)}
	}
	return &waE2E.Message{InteractiveMessage: interactive}
}

// buildNativeFlowButtons converte as opcoes em botoes quick_reply.
//
// Cada botao carrega o id da opcao e o rotulo: e isso que volta na resposta
// quando o cliente toca no botao.
func buildNativeFlowButtons(text, title string, buttons []Option) (*waE2E.Message, error) {
	protoButtons := make([]*waE2E.InteractiveMessage_NativeFlowMessage_NativeFlowButton, 0, len(buttons))
	for _, button := range buttons {
		params, err := json.Marshal(map[string]string{
			"display_text": button.Label,
			"id":           button.ID,
		})
		if err != nil {
			return nil, fmt.Errorf("parametros do botao %q: %w", button.Label, err)
		}
		protoButtons = append(protoButtons, &waE2E.InteractiveMessage_NativeFlowMessage_NativeFlowButton{
			Name:             ptr(nativeFlowQuickReply),
			ButtonParamsJSON: ptr(string(params)),
		})
	}
	return nativeFlowMessage(title, text, "", protoButtons), nil
}

// buildNativeFlowList converte os itens em um botao single_select.
//
// O native flow nao tem "lista": o cliente toca no botao e o WhatsApp abre o
// seletor com as secoes e linhas enviadas aqui.
func buildNativeFlowList(text, title string, items []Option) (*waE2E.Message, error) {
	rows := make([]map[string]string, 0, len(items))
	for _, item := range items {
		rows = append(rows, map[string]string{
			"header":      "",
			"title":       item.Label,
			"description": "",
			"id":          item.ID,
		})
	}

	sectionTitle := title
	if sectionTitle == "" {
		sectionTitle = text
	}
	buttonTitle := title
	if buttonTitle == "" {
		buttonTitle = "Ver opções"
	}

	params, err := json.Marshal(map[string]any{
		"title": buttonTitle,
		"sections": []map[string]any{
			{
				"title": sectionTitle,
				"rows":  rows,
			},
		},
	})
	if err != nil {
		return nil, fmt.Errorf("parametros da lista: %w", err)
	}

	buttons := []*waE2E.InteractiveMessage_NativeFlowMessage_NativeFlowButton{
		{
			Name:             ptr(nativeFlowSingleSelect),
			ButtonParamsJSON: ptr(string(params)),
		},
	}
	return nativeFlowMessage(title, text, "", buttons), nil
}

// nativeFlowResponse e a resposta a um toque em um botao do fluxo nativo.
type nativeFlowResponse struct {
	ID    string `json:"id"`
	Label string `json:"display_text"`
}

// parseNativeFlowResponse extrai a opcao escolhida da resposta do fluxo nativo.
//
// O paramsJSON traz o id e o rotulo da opcao. A lista (single_select) devolve
// o mesmo formato; se vier em outro formato, o texto bruto e preservado no
// rotulo para o app ainda conseguir tratar a resposta.
func parseNativeFlowResponse(paramsJSON string) (nativeFlowResponse, string) {
	var out nativeFlowResponse
	raw := map[string]any{}
	if err := json.Unmarshal([]byte(paramsJSON), &raw); err != nil {
		return out, paramsJSON
	}

	if v, ok := raw["id"].(string); ok {
		out.ID = v
	}
	if v, ok := raw["display_text"].(string); ok {
		out.Label = v
	}

	// A lista pode devolver a linha escolhida em outro nivel do JSON.
	for _, key := range []string{"selected_row", "selectedRow", "row"} {
		row, ok := raw[key].(map[string]any)
		if !ok {
			continue
		}
		if out.ID == "" {
			if v, ok := row["id"].(string); ok {
				out.ID = v
			}
		}
		if out.Label == "" {
			if v, ok := row["title"].(string); ok {
				out.Label = v
			}
		}
	}

	if out.Label == "" {
		out.Label = out.ID
	}
	return out, paramsJSON
}
