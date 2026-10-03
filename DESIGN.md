---
name: Atendeflow
description: Self-hosted Fluent-style omnichannel service desk with separate green wiki portal
colors:
  primary: "#0078d4"
  primary-deep: "#106ebe"
  primary-ink: "#005a9e"
  primary-soft: "#deecf9"
  app-bg: "#f5f5f5"
  panel: "#ffffff"
  panel-alt: "#faf9f8"
  border-soft: "#edebe9"
  border-strong: "#d2d0ce"
  ink-primary: "#201f1e"
  ink-secondary: "#605e5c"
  ink-muted: "#8a8886"
  success: "#107c10"
  success-soft: "#dff6dd"
  warning: "#f7630c"
  warning-soft: "#fff4ce"
  danger: "#d13438"
  danger-soft: "#fde7e9"
  wiki-green: "#006400"
  wiki-green-mid: "#008000"
  wiki-green-soft: "#e6f2e6"
  wiki-ink: "#111814"
typography:
  display:
    fontFamily: "'Segoe UI', 'Segoe UI Variable Text', Inter, -apple-system, Arial, sans-serif"
    fontSize: "34px"
    fontWeight: 600
    lineHeight: 1.2
    letterSpacing: "-0.02em"
  headline:
    fontFamily: "'Segoe UI', 'Segoe UI Variable Text', Inter, -apple-system, Arial, sans-serif"
    fontSize: "24px"
    fontWeight: 800
    lineHeight: 1.2
    letterSpacing: "-0.01em"
  title:
    fontFamily: "'Segoe UI', 'Segoe UI Variable Text', Inter, -apple-system, Arial, sans-serif"
    fontSize: "17px"
    fontWeight: 600
    lineHeight: 1.4
  body:
    fontFamily: "'Segoe UI', 'Segoe UI Variable Text', Inter, -apple-system, Arial, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "'Segoe UI', 'Segoe UI Variable Text', Inter, -apple-system, Arial, sans-serif"
    fontSize: "12px"
    fontWeight: 700
    lineHeight: 1.4
    letterSpacing: "0.05em"
rounded:
  sm: "4px"
  md: "6px"
  lg: "8px"
  xl: "12px"
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
    padding: "7px 14px"
  button-primary-hover:
    backgroundColor: "{colors.primary-deep}"
    textColor: "{colors.panel}"
    rounded: "{rounded.sm}"
    padding: "7px 14px"
  button-outline:
    backgroundColor: "transparent"
    textColor: "{colors.ink-primary}"
    rounded: "{rounded.sm}"
    padding: "7px 14px"
  input-default:
    backgroundColor: "{colors.panel-alt}"
    textColor: "{colors.ink-primary}"
    rounded: "{rounded.lg}"
    padding: "10px 14px"
  card-default:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink-primary}"
    rounded: "{rounded.lg}"
    padding: "24px"
  chip-neutral:
    backgroundColor: "{colors.panel-alt}"
    textColor: "{colors.ink-secondary}"
    rounded: "{rounded.pill}"
    padding: "4px 10px"
---

# Design System: Atendeflow

## Overview

**Creative North Star: "The Fluent Service Desk"**

Atendeflow looks and behaves like a calm Microsoft 365 workbench for triage and reply. Dense three-pane inbox, flat white surfaces on warm gray app background, 1px borders, 4–8px corners, Segoe UI throughout. Blue is reserved for ownership and action — active nav, selected conversation, outgoing bubble, primary button — never as decoration.

The public wiki portal is a deliberate second world: forest-green editorial knowledge base with Plus Jakarta Sans + DM Serif Display, 12px radius, airy 1140px reading layout. The embeddable WebChat widget bridges the two, reusing Fluent blue tokens in a floating 384px panel.

**Key Characteristics:**
- Calm operational density: 14px base, tabular counts, 240px sidebar + 380px list + fluid chat
- Flat-by-default with ambient lift only on overlay, hover, focus
- Precise and restrained components: 4px buttons, 1px borders, 0.12–0.15s feedback
- Two worlds, no mixing: Fluent blue app + widget, green serif wiki portal

## Colors

Operational Fluent blue plus warm-gray neutrals; semantic softs for status; forest green confined to the wiki portal.

### Primary
- **Fluent Channel Blue** (#0078d4): Active nav indicator, selected conversation, outgoing bubble, primary buttons, launcher, links. Used sparingly against white/gray.
- **Fluent Deep Blue** (#106ebe): Hover state for primary buttons and links.
- **Fluent Ink Blue** (#005a9e): Active/pressed state, dark text on light-blue soft backgrounds.
- **Channel Mist** (#deecf9): Selected backgrounds — `nav-item.active`, `conv-item.active`, unread notification, hover washes.

### Secondary
- **Knowledge Forest Green** (#006400): Wiki portal only — hero gradient base, category icons, primary wiki buttons, links.
- **Knowledge Leaf** (#008000): Wiki hover state.
- **Knowledge Wash** (#e6f2e6): Wiki icon tiles, tag pills, hover washes.
- **Knowledge Paper** (#f2f8f2): Wiki search field background.

### Neutral
- **App Canvas** (#f5f5f5): App and chat-body background.
- **Panel White** (#ffffff): Sidebar, panels, cards, modals, incoming bubbles.
- **Panel Alt** (#faf9f8): Search boxes, meta wells, card alt backgrounds, table row hover.
- **Soft Border** (#edebe9): Default 1px borders, dividers.
- **Strong Border** (#d2d0ce): Hover borders, scrollbar thumbs, empty-state icons.
- **Ink Primary** (#201f1e): Headings, body text, chat names.
- **Ink Secondary** (#605e5c): Subtitles, previews, labels.
- **Ink Muted** (#8a8886): Timestamps, placeholders, helper text.
- **Wiki Ink** (#111814): Wiki body text on `#f7fbf7` background.

Semantic pairs (background + text always used together): Success `#107c10` on `#dff6dd`; Warning `#f7630c` on `#fff4ce`; Danger `#d13438` on `#fde7e9`; Info `#0078d4` on `#eff6fc`. SLA list colors are runtime-driven via `--sla-color-*` but reuse the same softs.

### Named Rules
**The One Voice Rule.** Fluent blue marks ownership or action only. If it is not clickable, selected, or sent-by-agent, it stays gray.

## Typography

**Display Font:** Segoe UI Variable / Segoe UI (with Inter, -apple-system, Helvetica Neue, Arial fallback) — app, widget, CSAT
**Body Font:** Segoe UI stack — app; Plus Jakarta Sans — wiki portal only
**Label/Mono Font:** Consolas / ui-monospace for hex codes, API keys, variable chips; DM Serif Display for wiki headlines only

**Character:** Operational grotesque clarity in the app — semibold 600–800 headings with tight -1% to -2% tracking; generous editorial serif contrast confined to wiki reading surfaces.

### Hierarchy
- **Display** (600, 34px/1.2, -0.02em): Login hero headline; wiki hero uses DM Serif `clamp(2rem, 5vw, 3.2rem)` instead.
- **Headline** (800, 24px/1.2): Department header name, stat values 22–24px/800, report titles.
- **Title** (600, 15–17px/1.4): List title 17px, chat title 15.5px/700, modal h3 17px, card names 15px/700.
- **Body** (400, 14px/1.5, max ~65ch in wiki article at 0.97rem/1.75): Messages, previews 12.5–14px, table cells 13.5px.
- **Label** (700, 11–12px, 0.05em uppercase): Nav labels 11px, stat labels 12px uppercase, table th 12px uppercase, badges 11px/700.

### Named Rules
**The Two Voices Rule.** Segoe UI speaks in the desk; DM Serif + Jakarta speak only in the wiki portal. Never set wiki serif inside inbox, reports, or flows.

## Layout

App shell is a CSS grid: `240px sidebar + fluid content`, `49px topbar + 1fr body`, full viewport height, no page radius. Inbox mode adds a middle column: `240px | 380px | 1fr`. Wiki reading shell is centered `max-width 1140–1200px` with `24px` gutters, `56–80px` section padding.

Density: list rows `12px 10px`, chat body `20px 24px` with `12px` message gaps, cards `18–24px` padding, `14–16px` grid gaps for stat/user/contact grids (`repeat(auto-fill, minmax(180–280px, 1fr))`).

Responsive: 1200px collapses inbox to two columns; 992px fixes chat as full overlay; 860px hides wiki nav links behind hamburger; 768px hides sidebar behind drawer + strips login hero, stacks contact/dept grids to one column; 480px turns widget into full bottom sheet with 0 radius. Spacing rhythm is 4 / 8 / 16 / 24 / 32px.

## Elevation & Depth

Flat-by-default with ambient lift. Depth comes first from tonal layering (`#f5f5f5` canvas → `#ffffff` panel → `#faf9f8` alt) and 1px `#edebe9` borders; shadows appear only as response to state — hover, popover, modal, or new-message pill.

### Shadow Vocabulary
- **Hairline** (`box-shadow: 0 1px 2px rgba(0,0,0,.09)`): Bubbles, toasts, reaction pills at rest.
- **Fluent Low** (`box-shadow: 0 1.6px 3.6px rgba(0,0,0,.13), 0 .3px .9px rgba(0,0,0,.11)`): Stat/user cards, date separators, stat tiles.
- **Fluent Lift** (`box-shadow: 0 6.4px 14.4px rgba(0,0,0,.13), 0 1.2px 3.6px rgba(0,0,0,.11)`): Hover lift on cards, widget panel, media bubbles.
- **Overlay** (`box-shadow: 0 32px 64px rgba(0,0,0,.18), 0 16px 40px rgba(0,0,0,.14)`): Notification dropdown, emoji picker, modals with `blur(4px)` scrim `rgba(0,0,0,.5)`.
- **Wiki Green Ambient** (`box-shadow: 0 1px 4px rgba(0,100,0,.07)` / `0 4px 24px rgba(0,100,0,.10)` / `0 12px 48px rgba(0,100,0,.14)`): Same lift grammar tinted green, portal only.

### Named Rules
**The Flat-By-Default Rule.** Surfaces are flat at rest. If a shadow is visible without hover, focus, drag, or overlay, remove it.

## Shapes

Small, crisp, rectangular workbench in the app; softer editorial cards in the wiki. App radii: 4px buttons, inputs, brand marks; 6px large panels/modals; 8px page cards, message bodies, login card; pill 20–999px for chips, badges, status pills, date separators. Avatars, launcher, send button, and step numbers are full circles. Incoming bubbles keep a 2–5px sharp corner on the tail side; outgoing mirrors it. Wiki radius is uniformly 12px for cards, search, category tiles, with 8–10px for inner icon tiles and inputs.

Borders are 1px `#edebe9` by default, thickening only semantically: 3px left accent on login alerts and article callouts, dashed 1px amber on internal notes, 2px solid on selected timeline cards.

## Components

Precise and restrained: flat fills, 1px borders, instant feedback, blue focus rings. No gradients except login hero, dept banner, and wiki hero.

### Buttons
- **Shape:** Gently square (4px radius), 2px radius for block login button.
- **Primary:** Fluent Channel Blue fill (#0078d4) + white text, `7px 14px`, 13.5px/600; send кноп 40px square.
- **Hover / Focus:** Deep blue (#106ebe) on hover, ink blue (#005a9e) on active; focus is `0 0 0 1px #0078d4` border shift, search focus adds `0 0 0 3px #deecf9`.
- **Secondary / Ghost:** Outline is transparent + `#8a8886` border; icon-btn 32–36px transparent turning `#deecf9` bg + `#106ebe` icon on hover.

### Chips
- **Style:** Pill 20–999px, 11–12px/700; variants map to semantic softs (success/info/warning/danger/flow `#c7e6f7` + `#106ebe`).
- **State:** Hover is `brightness(0.95–0.97)` only; filter pills invert to blue fill + white text when active; tag grid items fill with their own `--tag-color` when applied.

### Cards / Containers
- **Corner Style:** Rounded 8px (app), 12px (wiki).
- **Background:** Panel white on app canvas; alt `#faf9f8` for meta wells.
- **Shadow Strategy:** Hairline/Fluent Low at rest, Fluent Lift on hover with `translateY(-2px)`.
- **Border:** 1px soft border always; hover upgrades to strong border or blue.
- **Internal Padding:** 14–24px; headers have `14–18px 16–24px` + bottom border.

### Inputs / Fields
- **Style:** Alt-gray fill `#faf9f8` or white, 1px `#edebe9` (or `#8a8886` in login/composer), 4px in composer/login, 11–13px in list search and forms.
- **Focus:** Blue border + soft glow; login/composer uses 1px ring, list search uses 3px `#deecf9` ring.
- **Error / Disabled:** Error is danger-soft bg + danger text + 3px danger left border; disabled is `opacity .4–.6` + `not-allowed`.

### Navigation
Sidebar 240px white with right border, 13.5px/500 items, `8px 10px` padding, 4px radius. Hover is `#f3f2f1`; active is `#deecf9` bg + `#005a9e` text + 3px left blue bar + 600 weight. Topbar 48px white with bottom border. Wiki nav is 64px sticky blurred white with 8px pill links turning green wash on hover plus 2px underline grow.

### Chat Bubbles
Incoming: white, 1px soft border, `8px 12px`, 14px/1.5, tail sharp at 2px bottom-left, 32px avatar alongside. Outgoing: Fluent blue, white text, no border, tail sharp bottom-right, aligned right. Notes are amber dashed with gradient wash; system events are centered muted pills.

## Do's and Don'ts

Concrete guardrails grounded in the shipped code. No invented prohibitions.

### Do:
- **Do** keep blue for action and ownership — active, selected, sent, primary — on ≤10% of any inbox screen.
- **Do** use soft + strong text pairs together (e.g. `#107c10` on `#dff6dd`), never soft as text on white.
- **Do** keep app corners at 4–8px and wiki cards at 12px; circles only for avatars, launcher, send, status dots.
- **Do** show focus with a blue border + soft ring on every input, search, and composer.
- **Do** confine DM Serif Display and Jakarta to the wiki portal; Segoe UI everywhere else.

### Don't:
- **Don't** introduce a new accent hue in the desk — greens, violets, or teals belong only to wiki, SLA/semantic states, or dept color dots.
- **Don't** use gradients outside login hero, dept banners, wiki hero, and note washes.
- **Don't** add shadows to resting list rows, sidebar items, or table cells — lift on hover/overlay only.
- **Don't** mix the two radius worlds — no 12–16px SaaS cards inside inbox triage.
