# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primary: support agents and managers at small-to-mid businesses doing daily omnichannel customer service. Agents triage and reply in shared/personal inboxes; managers configure departments, business hours, flows, macros/tags, SLA and close reasons; admins manage users, channels, inboxes, WhatsApp connections and wiki API keys.

Secondary (reachable only via public surfaces): end-customers contacting via embeddable WebChat widget, WhatsApp, public CSAT link, and hosted wiki portal.

## Product Purpose

Atendeflow (repo also calls it OminiDesk) is a self-hosted omnichannel service desk. It centralizes WebChat and WhatsApp conversations, chatbot flows, contacts, knowledge base and service measurement in one PHP app. Success means every conversation has clear ownership and timely resolution with auditable history and CSAT/report coverage.

## Positioning

Self-hosted PHP 8.1+ / MySQL alternative to SaaS helpdesks: one deploy owns data, inbox, visual flow engine with versioning plus async delays/retries, and an API-key wiki portal that can be hosted anywhere.

## Operating Context

Workflows: inbox triage (mine/unassigned/personal), assign/transfer, snooze, merge, bulk actions, internal notes, tags, macros/canned responses, message edit/delete/reaction/retry, subject/unit/priority/status changes, CSAT collection, agent/manager reporting.

Environments: Laragon/XAMPP-style LAMP locally, served from `public/` via `php -S localhost:8080 -t public`; flow background work via `workers/flow_worker.php` cron each minute; WhatsApp via external provider (Uazapi/WAHA/Evolution API); wiki frontend as static files in `public/wiki-frontend/` hosted externally with `config.js` URL + key.

## Capabilities and Constraints

Confirmed capabilities: WebChat widget (`/widget/*`, `/api/webchat/*`), WhatsApp connections/groups + webhook (`/webhooks/whatsapp`), flow builder with 16-condition operators/templates/timeouts/versioning, departments + business hours, contacts with merge/PDF, macros/tags/canned/subjects/close-reasons, CSAT links, reports (conversations/agents/csat/timeline), wiki categories/articles with public read API, realtime via SSE + polling + unread/inbox-count APIs, SLA/notification sounds/preferences.

Technical constraints: PHP >= 8.1 with pdo/mbstring/json/openssl, MySQL 8, custom procedural-MVC router, server-rendered views + vanilla JS (no JS framework), role model agent/manager/admin enforced in `routes/web.php`, PT-BR UI.

Terminology: inboxes, conversations, flows/nodes, macros vs canned responses, tags, departments, close reasons, CSAT, SLA, wiki portal.

Explicitly undecided: canonical product name (README says OminiDesk, `composer.json` says atendeflow/atendeflow); whether AUDIT.md security/performance roadmap changes scope of next surface work.

## Brand Commitments

Name on record is dual: Atendeflow and OminiDesk. Voice is PT-BR operational support language. No logo, palette, typography or other visual identity confirmed as binding; no visual constraints volunteered in init.

## Evidence on Hand

Code and docs: `routes/web.php`, `app/Controllers/` (Inbox, Flow, WhatsApp, Wiki, Reports, Csat), `app/Views/`, `public/assets/`, `public/widget/`, `public/wiki-frontend/`, `database/schema.sql` + `database/migrations/`, `README.md`, `AUDIT.md`, `FLOW_IMPROVEMENTS.md`, `WIKI_MODULE.md`.

Absences that must not be fabricated: no testimonials, customer lists, benchmarks, pricing, licensing or deployment claims on record.

## Product Principles

1. One inbox owns every conversation — no channel lives outside triage, assignment and history.
2. Automate with a human fallback — flows, business-hours routing and wiki suggestions accelerate agents, never strand customers.
3. Self-hosted simplicity over SaaS breadth — keep deploy, data and customization in one PHP/MySQL tree.
4. Reuse knowledge — wiki articles, macros and canned responses are the shared memory of support.
5. Service is measurable — SLA, CSAT and reports decide whether a workflow stays.
