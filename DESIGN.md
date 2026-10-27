---
name: Atendeflow
description: Self-hosted omnichannel service desk with separate green wiki portal
colors:
  primary: "#4f46e5"
  primary-deep: "#4338ca"
  primary-ink: "#3730a3"
  primary-soft: "#eef0ff"
  app-bg: "#f6f7f9"
  panel: "#ffffff"
  panel-alt: "#f8f9fb"
  border-soft: "#e7e9ef"
  border-strong: "#d2d6df"
  border-input: "#c3c9d6"
  ink-primary: "#0f172a"
  ink-secondary: "#475569"
  ink-muted: "#5b6472"
  success: "#15803d"
  success-soft: "#e8f8ee"
  success-text: "#166534"
  warning: "#ea580c"
  warning-soft: "#fff4e5"
  warning-text: "#9a3412"
  danger: "#dc2626"
  danger-soft: "#fee9e9"
  danger-text: "#b91c1c"
  info: "#4f46e5"
  info-soft: "#eef0ff"
  info-text: "#4338ca"
  brand-text: "#4338ca"
  wiki-green: "#006400"
  wiki-green-mid: "#008000"
  wiki-green-soft: "#e6f2e6"
  wiki-ink: "#111814"
typography:
  display:
    fontFamily: "'Inter', 'Inter Variable', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif"
    fontSize: "36px"
    fontWeight: 700
    lineHeight: 1.15
    letterSpacing: "-0.025em"
  headline:
    fontFamily: "'Inter', 'Inter Variable', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif"
    fontSize: "22px"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "-0.02em"
  title:
    fontFamily: "'Inter', 'Inter Variable', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif"
    fontSize: "16px"
    fontWeight: 600
    lineHeight: 1.4
  body:
    fontFamily: "'Inter', 'Inter Variable', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "'Inter', 'Inter Variable', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif"
    fontSize: "11px"
    fontWeight: 700
    lineHeight: 1.4
    letterSpacing: "0.05em"
rounded:
  sm: "8px"
  md: "10px"
  lg: "12px"
  xl: "14px"
  pill: "999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "32px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.panel}"
    rounded: "{rounded.sm}"
    padding: "8px 14px"
  button-primary-hover:
    backgroundColor: "{colors.primary-deep}"
    textColor: "{colors.panel}"
    rounded: "{rounded.sm}"
    padding: "8px 14px"
  button-outline:
    backgroundColor: "transparent"
    textColor: "{colors.ink-primary}"
    rounded: "{rounded.sm}"
    padding: "8px 14px"
  input-default:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink-primary}"
    rounded: "{rounded.md}"
    padding: "10px 14px"
  card-default:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink-primary}"
    rounded: "{rounded.lg}"
    padding: "20px"
  chip-neutral:
    backgroundColor: "{colors.panel-alt}"
    textColor: "{colors.ink-secondary}"
    rounded: "{rounded.pill}"
    padding: "4px 10px"
---

# Design System: Atendeflow

## Overview

**Creative North Star: "The Calm Omnichannel Desk"**

Atendeflow is a service desk that should feel like a well-run workspace, not a dashboard: dense three-pane inbox, flat white surfaces on a cool light-gray canvas, 1px borders, generous 8–14px corners, Inter throughout. Indigo marks ownership and action — active nav, selected conversation, outgoing bubble, primary button — and nothing else.

> **Identity note (2026-10).** The previous identity was a Microsoft 365 / Fluent clone (Fluent Channel Blue `#0078d4`, Segoe UI, a "Microsoft sign-in" style login). With the Office 365 subscription expired, that lineage no longer applies. The system now carries its own identity: **indigo `#4f46e5`**, **Inter**, and a cooler neutral ramp. Do not reintroduce Fluent blue, Segoe UI as a deliberate choice, or Microsoft product naming.

The public wiki portal is a deliberate second world: forest-green editorial knowledge base with Plus Jakarta Sans + DM Serif Display, 12px radius, airy 1140px reading layout. The embeddable WebChat widget bridges the two, reusing the indigo tokens in a floating 384px panel.

**Key Characteristics:**
- Calm operational density: 14px base, tabular counts, 256px sidebar + 400px list + fluid chat
- Flat-by-default with ambient lift only on overlay, hover, focus
- Airy spacing: 60px topbar, 20px page gutters, 12–14px card corners
- Two worlds, no mixing: indigo app + widget, green serif wiki portal

## Colors

Indigo plus cool neutrals; semantic softs for status; forest green confined to the wiki portal.

### Primary
- **Indigo 600** (#4f46e5): Active nav indicator, selected conversation, outgoing bubble, primary buttons, launcher, links. Used sparingly against white/gray.
- **Indigo 700** (#4338ca): Hover state for primary buttons and links.
- **Indigo 800** (#3730a3): Active/pressed state, dark text on light-indigo soft backgrounds.
- **Indigo 50** (#eef0ff): Selected backgrounds — `nav-item.active`, `conv-item.active`, unread notification, hover washes.
- **Indigo 100** (#e0e3ff): Secondary wash (flow chips, secondary chips).

### Secondary
- **Knowledge Forest Green** (#006400): Wiki portal only — hero gradient base, category icons, primary wiki buttons, links.
- **Knowledge Leaf** (#008000): Wiki hover state.
- **Knowledge Wash** (#e6f2e6): Wiki icon tiles, tag pills, hover washes.
- **Knowledge Paper** (#f2f8f2): Wiki search field background.

### Neutral
- **App Canvas** (#f6f7f9): App and chat-body background.
- **Panel White** (#ffffff): Sidebar, panels, cards, modals, incoming bubbles.
- **Panel Alt** (#f8f9fb): Search boxes, meta wells, card alt backgrounds, table row hover.
- **Soft Border** (#e7e9ef): Default 1px borders, dividers.
- **Strong Border** (#d2d6df): Hover borders, scrollbar thumbs, empty-state icons.
- **Input Border** (#c3c9d6): Form-control and composer borders — one step darker than a divider so fields stay visible without a heavy outline.
- **Ink Primary** (#0f172a): Headings, body text, chat names.
- **Ink Secondary** (#475569): Subtitles, previews, labels.
- **Ink Muted** (#5b6472): Timestamps, placeholders, helper text — 5,9:1 on white and 5,2:1 on the selected row `#eef0ff`, so it survives WCAG AA on both.
- **Wiki Ink** (#111814): Wiki body text on `#f7fbf7` background.

Semantic pairs (background + text always used together): Success `#15803d` on `#e8f8ee`; Warning `#ea580c` on `#fff4e5`; Danger `#dc2626` on `#fee9e9`; Info `#4f46e5` on `#eef0ff`. SLA list colors are runtime-driven via `--sla-color-*` but reuse the same softs.

### Named Rules
**The One Voice Rule.** Indigo marks ownership or action only. If it is not clickable, selected, or sent-by-agent, it stays gray.

## Typography

**Display Font:** Inter (with `-apple-system`, `BlinkMacSystemFont`, `Segoe UI`, `Roboto`, `Helvetica Neue`, `Arial` as fallback) — app, widget, login, CSAT
**Body Font:** Inter — app; Plus Jakarta Sans — wiki portal only
**Label/Mono Font:** `ui-monospace`, Consolas for hex codes, API keys, variable chips; DM Serif Display for wiki headlines only

**Character:** Operational grotesque clarity — semibold 600–700 headings with tight −1% to −2.5% tracking; generous editorial serif contrast confined to wiki reading surfaces.

### Hierarchy
- **Display** (700, 36px/1.15, −0.025em): Login hero headline; wiki hero uses DM Serif `clamp(2rem, 5vw, 3.2rem)` instead.
- **Headline** (700, 22px/1.2): Page titles, department header name, stat values, report titles.
- **Title** (600, 16px/1.4): List title, chat title, modal h3, card names.
- **Body** (400, 14px/1.5, max ~65ch in wiki article at 0.97rem/1.75): Messages, previews, table cells.
- **Label** (700, 11px, 0.05em uppercase): Nav labels, stat labels, table headers, badges.

### Named Rules
**The Two Voices Rule.** Inter speaks in the desk; DM Serif + Jakarta speak only in the wiki portal. Never set the wiki serif inside inbox, reports, or flows.

## Layout

App shell is a CSS grid: `256px sidebar + fluid content`, `60px topbar + 1fr body`, full viewport height, no page radius. Inbox mode adds a middle column: `256px | 400px | 1fr`. Wiki reading shell is centered `max-width 1140–1200px` with `24px` gutters, `56–80px` section padding.

Density: list rows `13px 12px`, chat body `24px 28px` with `14px` message gaps, cards `20px` padding, `16px` grid gaps for stat/user/contact grids (`repeat(auto-fit, minmax(180–280px, 1fr))`).

Responsive: 1200px collapses inbox to two columns; 992px fixes chat as full overlay; 860px hides wiki nav links behind hamburger; 768px hides sidebar behind drawer and strips the login hero, stacking contact/dept grids to one column; 480px turns widget into full bottom sheet with 0 radius. Spacing rhythm is 4 / 8 / 16 / 24 / 32px.

## Elevation & Depth

Flat-by-default with ambient lift. Depth comes first from tonal layering (`#f6f7f9` canvas → `#ffffff` panel → `#f8f9fb` alt) and 1px `#e7e9ef` borders; shadows appear only as response to state — hover, popover, modal, or new-message pill.

### Shadow Vocabulary
- **Hairline** (`0 1px 2px rgba(15,23,42,.05)`): Bubbles, toasts, reaction pills at rest.
- **Low** (`0 1px 3px rgba(15,23,42,.06), 0 1px 2px rgba(15,23,42,.04)`): Stat/user cards, date separators.
- **Lift** (`0 6px 16px rgba(15,23,42,.08), 0 2px 5px rgba(15,23,42,.05)`): Hover lift on cards, widget panel, media bubbles.
- **Overlay** (`0 24px 48px rgba(15,23,42,.16), 0 8px 20px rgba(15,23,42,.07)`): Notification dropdown, emoji picker, modals with `blur(4px)` scrim `rgba(0,0,0,.5)`.
- **Wiki Green Ambient** (`0 1px 4px rgba(0,100,0,.07)` / `0 4px 24px rgba(0,100,0,.10)` / `0 12px 48px rgba(0,100,0,.14)`): Same lift grammar tinted green, portal only.

### Named Rules
**The Flat-By-Default Rule.** Surfaces are flat at rest. If a shadow is visible without hover, focus, drag, or overlay, remove it.

## Shapes

Crisp but generous workbench in the app; softer editorial cards in the wiki. App radii: 8px buttons and inputs, 10px inline panels, 12px cards/dropdowns, 14px large cards; pill 20–999px for chips, badges, status pills, date separators. Avatars, launcher, send button, and step numbers are full circles. Incoming bubbles keep a sharp corner on the tail side; outgoing mirrors it. Wiki radius is uniformly 12px for cards, search, category tiles, with 8–10px for inner icon tiles and inputs.

Borders are 1px `#e7e9ef` by default, thickening only semantically: 3px left accent on login alerts and article callouts, dashed 1px amber on internal notes, 2px solid on selected timeline cards.

## Components

Precise and restrained: flat fills, 1px borders, instant feedback, indigo focus rings. No gradients except the login hero, dept banner, and wiki hero.

### Buttons
- **Shape:** Gently square (8px radius).
- **Primary:** Indigo 600 fill (#4f46e5) + white text, `8px 14px`, 13.5px/600; send button 40px square with 12px radius.
- **Hover / Focus:** Indigo 700 (#4338ca) on hover; focus is a 1px indigo border shift, search/input focus adds a 3px `#eef0ff` ring.
- **Secondary / Ghost:** Outline is transparent + `#c3c9d6` border; icon-btn 32–38px transparent turning `#eef0ff` bg + indigo icon on hover.

### Chips
- **Style:** Pill 20–999px, 11–12px/700; variants map to semantic softs (success/info/warning/danger/flow `#e0e3ff` + `#4338ca`).
- **State:** Hover is `brightness(0.95–0.97)` only; filter pills invert to indigo fill + white text when active; tag grid items fill with their own `--tag-color` when applied.
- **Contrast (regra dura):** nenhuma etiqueta pode reprovar em WCAG AA (4,5:1). Cores escolhidas pelo usuário passam por `readable_pair()` (PHP) / `readablePair()` (JS), que devolve o par *(fundo, texto)* já com contraste garantido — quando nem texto branco nem texto escuro passam de 4,5:1 sobre a cor, o **fundo é escurecido** até o branco passar. Texto sobre fundo *tintado* (a cor + alfa) passa por escurecimento da cor do texto. Nunca confiar em `contrast_color()` (escolha binária preto/branco) para texto pequeno sobre cor saturada.
- **Texto sobre *soft*:** usar as variantes escuras `--success-text` (#166534), `--info-text` / `--brand-text` (#4338ca) e `--danger-text` (#b91c1c) — o tom puro (#4f46e5 sobre #eef0ff = 5,6:1) já passa, mas a variante escura garante folga em Fundos tinted. No **tema escuro** essas três variantes invertem para o tom claro (`#6ee7a0` / `#a5b4fc`) — sobre os *softs* escuros o texto escuro reprovaria.

### Cards / Containers
- **Corner Style:** Rounded 12px (app), 12px (wiki).
- **Background:** Panel white on app canvas; alt `#f8f9fb` for meta wells.
- **Shadow Strategy:** Hairline/Low at rest, Lift on hover with `translateY(-2px)`.
- **Border:** 1px soft border always; hover upgrades to strong border or indigo.
- **Internal Padding:** 16–24px; headers have `16–20px` + bottom border.

### Inputs / Fields
- **Style:** Panel white or alt fill, 1px `#c3c9d6` (`--border-input`), 8–12px radius, 11–13.5px in list search and forms.
- **Focus:** Indigo border + `#eef0ff` glow ring (3px); composer and login use a 3–4px ring.
- **Error / Disabled:** Error is danger-soft bg + danger text + 3px danger left border; disabled is `opacity .4–.6` + `not-allowed`.

### Navigation
Sidebar 256px white with right border, 13.5px/500 items, `9px 12px` padding, 8px radius. Hover is `#f1f3f7`; active is `#eef0ff` bg + `#3730a3` text + 3px indigo bar with rounded right corners + 600 weight. Sub-items indent to 38px so the icon aligns under the parent's label. Topbar 60px white with bottom border.

### Chat Bubbles
Incoming: white, 1px soft border, `13px 17px`, 14px/1.5, tail sharp at the bottom-left, 32px avatar alongside. Outgoing: indigo, white text, no border, tail sharp bottom-right, aligned right. Notes are amber dashed with gradient wash; system events are centered muted pills.

## Login

The access screen is its own surface, not a resized app page.

- **Split:** brand panel (indigo gradient `158deg` + radial glow + masked 44px grid) on the left; access card on the right.
- **Card:** white, 18px radius, `--shadow-md`, `40px` padding (`32px` under 560px).
- **Fields:** 50px tall, 12px radius, `--border-input`; focus = indigo border + 4px `#eef0ff` ring.
- **Primary button:** full width, 50px, 12px radius, indigo with a soft indigo shadow; hover indigo 700.
- **Extras:** show/hide password (`aria-pressed`), Caps Lock hint, discreet default-credentials strip, and a theme toggle that respects the saved `omini-theme` preference.

## Do's and Don'ts

Concrete guardrails grounded in the shipped code. No invented prohibitions.

### Do:
- **Do** keep indigo for action and ownership — active, selected, sent, primary — on ≤10% of any inbox screen.
- **Do** use soft + strong text pairs together (e.g. `#15803d` on `#e8f8ee`), never soft as text on white.
- **Do** keep app corners at 8–14px and wiki cards at 12px; circles only for avatars, launcher, send, status dots.
- **Do** show focus with an indigo border + soft ring on every input, search, and composer.
- **Do** confine DM Serif Display and Jakarta to the wiki portal; Inter everywhere else.

### Don't:
- **Don't** reintroduce Microsoft 365 / Fluent blue (`#0078d4`) or Segoe UI as a deliberate pick — the Office 365 lineage is retired.
- **Don't** introduce a new accent hue in the desk — greens, violets, or teals belong only to wiki, SLA/semantic states, or dept color dots.
- **Don't** use gradients outside the login hero, dept banners, wiki hero, and note washes.
- **Don't** add shadows to resting list rows, sidebar items, or table cells — lift on hover/overlay only.
- **Don't** mix the two radius worlds — no 16px SaaS cards inside inbox triage.

## Accessibility floor (non-negotiable)

Measured with a DOM audit on every screen at 1440×900 and 390×844.

- **Contrast:** body text ≥ 4,5:1 (WCAG 2.1 AA); large text ≥ 3:1. Muted ink is `#5b6472` (5,9:1 on white), not `#8a8886` (3,5:1 — rejected). Colored chips go through `readable_pair()` / `readablePair()` so any user-chosen color stays readable.
- **Type floor:** 11px minimum for any rendered text (labels, counters, badges, chart axes, timestamps). 10px is reserved for icon glyphs only.
- **Target floor:** 24×24 CSS px minimum for every click target. Inline text links inside a sentence or table cell are exempt; icon-only buttons are not — give them a 28–38px box.
- **Truncation:** use `text-overflow: ellipsis` on a non-flex text node (or a nested `.conv-preview-text` span). `text-overflow` does not work on a flex container — the text is cut with no ellipsis.
- **Scroll containment:** a wide table lives inside a `.table-wrap` with `overflow-x: auto`, `tabindex="0"` and `role="region"`. The page itself must never scroll horizontally.
- **Touch feedback:** every state (hover, focus, disabled, loading, error, empty) is explicit — no state may be conveyed by color alone.
