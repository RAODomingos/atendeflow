<style>
@page { margin: 18mm 14mm; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #201f1e; line-height: 1.45; }
h1, h2, h3 { margin: 0; }

.doc-header { width: 100%; border-collapse: collapse; }
.doc-header td { vertical-align: middle; padding: 0 0 8px 0; border-bottom: 2px solid #0078d4; }
.doc-header td.r { text-align: right; }
.doc-logo { display: inline-block; width: 26px; height: 26px; background: #0078d4; color: #ffffff; font-size: 13pt; font-weight: 700; text-align: center; line-height: 26px; border-radius: 5px; margin-right: 8px; vertical-align: middle; }
.doc-brand { display: inline-block; vertical-align: middle; }
.doc-brand-name { font-size: 12pt; font-weight: 700; color: #201f1e; }
.doc-brand-title { font-size: 7.5pt; color: #8a8886; text-transform: uppercase; letter-spacing: .08em; margin-top: 1px; }
.doc-meta { font-size: 8pt; color: #605e5c; }

.proto-label { font-size: 6.5pt; color: #8a8886; text-transform: uppercase; letter-spacing: .1em; margin-bottom: 2px; }
.proto { display: inline-block; border: 1px solid #0078d4; background: #eff6fc; color: #005a9e; font-family: 'DejaVu Sans Mono', monospace; font-size: 10.5pt; font-weight: 700; padding: 3px 9px; border-radius: 4px; }
.proto-inline { font-family: 'DejaVu Sans Mono', monospace; font-weight: 700; color: #005a9e; }

.section { margin-bottom: 12px; }
.section-title { font-size: 8.5pt; font-weight: 700; color: #005a9e; text-transform: uppercase; letter-spacing: .07em; border-left: 3px solid #0078d4; padding: 1px 0 1px 8px; margin: 14px 0 8px; }

table.kv { width: 100%; border-collapse: collapse; }
table.kv td { padding: 4px 9px; vertical-align: top; font-size: 9.5pt; border-bottom: 1px solid #edebe9; }
table.kv td.k { font-weight: 700; color: #605e5c; width: 130px; background: #faf9f8; }
table.kv td.v { color: #201f1e; }
table.kv tr.proto-row td.k { background: #eff6fc; color: #005a9e; }
table.kv tr.proto-row td.v { background: #eff6fc; }

.card { background: #faf9f8; border: 1px solid #edebe9; border-radius: 6px; padding: 10px 12px; margin-bottom: 10px; }

.badge { display: inline-block; padding: 1px 7px; border-radius: 9px; font-size: 7.5pt; font-weight: 700; }
.badge-success { background: #dff6dd; color: #0b6a0b; }
.badge-info { background: #eff6fc; color: #005a9e; }
.badge-warning { background: #fff4ce; color: #b45309; }
.badge-neutral { background: #f3f2f1; color: #605e5c; }
.badge-danger { background: #fde7e9; color: #a4262c; }

.tag { display: inline-block; padding: 1px 7px; border-radius: 9px; font-size: 7.5pt; margin: 1px 2px 1px 0; }

.msg { padding: 6px 10px; margin-bottom: 5px; border-radius: 6px; border: 1px solid #edebe9; page-break-inside: avoid; }
.msg-out { background: #eff6fc; border-left: 3px solid #0078d4; }
.msg-in { background: #faf9f8; border-left: 3px solid #d2d0ce; }
.msg-note { background: #fff4ce; border-left: 3px solid #b45309; }
.msg-system { background: #f5f5f5; border-left: 3px solid #8a8886; text-align: center; font-size: 8.5pt; color: #605e5c; }
.msg-header { font-size: 7.5pt; color: #8a8886; margin-bottom: 2px; }
.msg-header strong { color: #201f1e; font-size: 8pt; }
.msg-content { font-size: 9.5pt; word-wrap: break-word; }
.msg-time { font-size: 7pt; color: #8a8886; text-align: right; margin-top: 2px; }
.msg-file { color: #0078d4; text-decoration: none; }
.msg-caption { font-size: 8.5pt; color: #605e5c; margin-top: 3px; }
.msg-date-sep { text-align: center; font-size: 8pt; color: #605e5c; margin: 10px 0 6px; border-bottom: 1px dashed #d2d0ce; padding-bottom: 3px; }

.event { padding: 3px 8px; margin-bottom: 2px; font-size: 8.5pt; color: #605e5c; border-left: 2px solid #edebe9; }
.event strong { color: #201f1e; }
.event-time { font-size: 7pt; color: #8a8886; }

.csat-box { background: #fff4ce; border: 1px solid #f2d887; border-radius: 6px; padding: 8px; text-align: center; font-size: 9.5pt; color: #201f1e; }
.csat-stars { color: #ffb900; font-size: 14pt; }

table.data { width: 100%; border-collapse: collapse; }
table.data th, table.data td { padding: 4px 7px; vertical-align: top; border-bottom: 1px solid #edebe9; font-size: 8.5pt; }
table.data th { background: #f5f5f5; color: #605e5c; text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: .05em; border-bottom: 1px solid #d2d0ce; }
table.data td.num { text-align: center; color: #8a8886; }

table.stat-grid { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
table.stat-grid td { padding: 8px 6px; border: 1px solid #edebe9; text-align: center; background: #faf9f8; }
table.stat-grid .val { font-size: 15pt; font-weight: 700; color: #0078d4; display: block; }
table.stat-grid .lbl { font-size: 7pt; color: #8a8886; text-transform: uppercase; letter-spacing: .06em; }

.conv { page-break-before: always; }
.head-table { width: 100%; border-collapse: collapse; }
.head-table td { vertical-align: middle; padding: 0 0 8px 0; border-bottom: 1px solid #edebe9; }
.head-table td.r { text-align: right; width: 1%; }
.head-title { font-size: 14pt; font-weight: 700; color: #201f1e; }
.head-sub { font-size: 8.5pt; color: #605e5c; margin-top: 3px; }

.footer { text-align: center; font-size: 7.5pt; color: #8a8886; border-top: 1px solid #edebe9; padding-top: 6px; margin-top: 14px; }
.muted { color: #8a8886; }
</style>
