// Bridge do AtendeFlow sobre a biblioteca whatsmeow.
//
// O whatsmeow é uma biblioteca Go (não um servidor HTTP): esta service é a
// camada que o AtendeFlow (PHP) consome. Ela hospeda uma sessão do WhatsApp
// por canal do AtendeFlow, expõe uma API REST pequena e encaminha os eventos
// recebidos para o webhook do app.
//
// O contrato desta API é o que o app/Services/WhatsApp/WhatsmeowProvider.php
// consome — os dois lados precisam andar juntos.
package main

import (
	"context"
	"log"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"
)

type config struct {
	addr       string
	dataDir    string
	bridgeToke string
	publicURL  string
	mediaTTL   time.Duration
}

func loadConfig() config {
	cfg := config{
		addr:       env("BRIDGE_ADDR", ":8090"),
		dataDir:    env("BRIDGE_DATA_DIR", "./data"),
		bridgeToke: env("BRIDGE_TOKEN", ""),
		publicURL:  env("BRIDGE_PUBLIC_URL", ""),
		mediaTTL:   24 * time.Hour,
	}
	if v := env("BRIDGE_MEDIA_TTL_HOURS", ""); v != "" {
		if h, err := time.ParseDuration(v + "h"); err == nil {
			cfg.mediaTTL = h
		}
	}
	return cfg
}

func env(key, fallback string) string {
	if v, ok := os.LookupEnv(key); ok && strings.TrimSpace(v) != "" {
		return strings.TrimSpace(v)
	}
	return fallback
}

func main() {
	cfg := loadConfig()

	manager, err := NewSessionManager(cfg)
	if err != nil {
		log.Fatalf("nao foi possivel iniciar o session manager: %v", err)
	}
	defer manager.Close()

	srv := &http.Server{
		Addr:              cfg.addr,
		Handler:           withAuth(cfg.bridgeToke, manager.Routes()),
		ReadHeaderTimeout: 10 * time.Second,
		WriteTimeout:      120 * time.Second,
	}

	go func() {
		log.Printf("bridge do whatsmeow ouvindo em %s (dados em %s)", cfg.addr, cfg.dataDir)
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("servidor caiu: %v", err)
		}
	}()

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, os.Interrupt, syscall.SIGTERM)
	<-stop

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = srv.Shutdown(ctx)
	log.Println("bridge encerrada")
}

// withAuth protege a API com o token compartilhado (header X-Bridge-Token).
// Sem token configurado, a API fica aberta — só para desenvolvimento.
func withAuth(token string, next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if token != "" && r.Header.Get("X-Bridge-Token") != token {
			writeJSON(w, http.StatusUnauthorized, map[string]string{"error": "token invalido"})
			return
		}
		next.ServeHTTP(w, r)
	})
}

func writeJSON(w http.ResponseWriter, status int, payload any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	if payload == nil {
		return
	}
	if err := writeJSONBody(w, payload); err != nil {
		log.Printf("falha ao escrever resposta: %v", err)
	}
}
