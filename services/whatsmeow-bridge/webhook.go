package main

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"time"
)

// postWebhook entrega o envelope no webhook do AtendeFlow.
//
// O app aceita tres formas de autenticacao; aqui usamos o header
// X-Webhook-Secret (nao polui o log de acesso) e, para quem preferir, a
// assinatura HMAC-SHA256 do corpo.
func postWebhook(url, secret string, envelope Envelope) {
	body, err := json.Marshal(envelope)
	if err != nil {
		log.Printf("falha ao serializar o envelope: %v", err)
		return
	}

	client := &http.Client{Timeout: 15 * time.Second}
	var lastErr error

	for attempt := 0; attempt < 3; attempt++ {
		if attempt > 0 {
			time.Sleep(time.Duration(attempt) * 2 * time.Second)
		}

		req, err := http.NewRequest(http.MethodPost, url, bytes.NewReader(body))
		if err != nil {
			log.Printf("falha ao montar o webhook: %v", err)
			return
		}
		req.Header.Set("Content-Type", "application/json; charset=utf-8")
		if secret != "" {
			req.Header.Set("X-Webhook-Secret", secret)
			req.Header.Set("X-Webhook-Signature", "sha256="+signBody(secret, body))
		}

		resp, err := client.Do(req)
		if err != nil {
			lastErr = err
			continue
		}
		_, _ = io.Copy(io.Discard, resp.Body)
		_ = resp.Body.Close()

		if resp.StatusCode >= 200 && resp.StatusCode < 300 {
			return
		}
		lastErr = fmt.Errorf("webhook respondeu %d", resp.StatusCode)
	}

	log.Printf("falha ao entregar o webhook %s: %v", url, lastErr)
}

func signBody(secret string, body []byte) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write(body)
	return hex.EncodeToString(mac.Sum(nil))
}
