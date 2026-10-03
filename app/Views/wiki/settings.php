<?php
/** @var string $apiBaseUrl */
/** @var string $apiKey */
/** @var int $totalArticles */
/** @var int $totalCategories */
/** @var string $portalUrl */
/** @var array $chat */
/** @var array $chatWidgets */
$embedPath = 'wiki-frontend';
?>
<div class="library-page" style="max-width:900px">
    <div class="page-actions">
        <a href="<?= url('wiki') ?>" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Wiki</a>
    </div>

    <div class="card" style="margin-bottom:12px"><div class="card-header"><h3>1 · Dados de acesso</h3></div>
        <div class="card-body" style="display:grid;grid-template-columns:1fr;gap:12px">
            <div>
                <label style="font-size:12px;font-weight:600">WIKI_API_URL (base do OminiDesk — sem barra no final)</label>
                <div style="display:flex;gap:8px;margin-top:4px">
                    <input id="cfgUrl" class="form-control" readonly onclick="this.select()" value="<?= e($apiBaseUrl) ?>" style="font-family:monospace;font-size:12px">
                    <button class="btn btn-outline" onclick="navigator.clipboard.writeText(document.getElementById('cfgUrl').value)"><i class="fas fa-copy"></i></button>
                </div>
            </div>
            <div>
                <label style="font-size:12px;font-weight:600">WIKI_API_KEY (header X-API-Key)</label>
                <div style="display:flex;gap:8px;margin-top:4px">
                    <input id="cfgKey" type="password" class="form-control" readonly onclick="this.select()" value="<?= e($apiKey) ?>" style="font-family:monospace;font-size:12px">
                    <button class="btn btn-outline" onclick="var i=document.getElementById('cfgKey');i.type=i.type==='password'?'text':'password'"><i class="fas fa-eye"></i></button>
                    <button class="btn btn-outline" onclick="navigator.clipboard.writeText(document.getElementById('cfgKey').value)"><i class="fas fa-copy"></i></button>
                </div>
                <form method="POST" action="<?= url('wiki/settings/regenerate-key') ?>" style="margin-top:8px" data-confirm="Gerar nova chave? O portal externo para de funcionar até atualizar a Key nele.">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline" style="color:var(--danger)"><i class="fas fa-sync"></i> Regenerar chave</button>
                </form>
            </div>
            <div style="font-size:13px;color:var(--text-muted)"><?= (int)$totalCategories ?> categorias · <?= (int)$totalArticles ?> artigos publicados</div>
        </div>
    </div>

    <div class="card" style="margin-bottom:12px"><div class="card-header"><h3>2 · Endpoints consumidos pelo portal (todos exigem a Key)</h3></div>
        <div class="card-body" style="font-size:13px">
            <ul style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px">
                <li><code>GET <?= e($apiBaseUrl) ?>/api/wiki/categories</code> — lista categorias (+ <code>article_count</code>)</li>
                <li><code>GET <?= e($apiBaseUrl) ?>/api/wiki/articles</code> — todos · filtros: <code>?category=slug</code>, <code>?featured=1</code>, <code>?search=texto</code>, <code>?slug=slug-do-artigo</code></li>
                <li><code>GET <?= e($apiBaseUrl) ?>/api/wiki/articles/{slug}</code> — 1 artigo (soma 1 visualização)</li>
                <li><code>GET <?= e($apiBaseUrl) ?>/api/wiki/config</code> — contadores + base de uploads</li>
            </ul>
            <p style="margin:10px 0 0">Autenticação: header <code>X-API-Key: &lt;key&gt;</code> <strong>ou</strong> query <code>?api_key=&lt;key&gt;</code>. Imagens vêm em URL absoluta (<code>cover_image</code> e <code>content_absolute</code>), então funcionam em outro domínio sem ajuste.</p>
        </div>
    </div>

    <div class="card"><div class="card-header"><h3>3 · Como hospedar o portal (pasta <code><?= e($embedPath) ?></code>)</h3></div>
        <div class="card-body" style="font-size:13px;display:flex;flex-direction:column;gap:10px">
            <form method="POST" action="<?= url('wiki/settings/portal') ?>" style="background:var(--bg-soft,#f6f8fb);border:1px solid var(--border-color);border-radius:8px;padding:12px">
                <?= csrf_field() ?>
                <label style="font-size:12px;font-weight:600">URL pública do portal — link que o cliente abre (usado nas sugestões do chat)</label>
                <div style="display:flex;gap:8px;margin-top:6px">
                    <input name="portal_url" class="form-control" value="<?= e($portalUrl) ?>" placeholder="https://suaempresa.com/wiki/" style="font-family:monospace;font-size:12px">
                    <button class="btn btn-primary btn-sm" style="white-space:nowrap"><i class="fas fa-check"></i> Salvar URL</button>
                </div>
                <small style="color:var(--text-muted)">Ex.: <code><?= e($apiBaseUrl) ?>/wiki-frontend/index.html</code> (prévia local) ou o endereço da hospedagem externa. O link do artigo sai como <code>{URL}#article/{slug}</code>.</small>
            </form>
            <ol style="margin:0;padding-left:18px;display:flex;flex-direction:column;gap:6px">
                <li>Copie a pasta <code>public/<?= e($embedPath) ?>/</code> deste projeto para <strong>qualquer hospedagem estática</strong> (pode ser outro domínio, Vercel, Netlify, S3, cPanel…).</li>
                <li>Abra o arquivo <code>config.js</code> e preencha:
<pre style="background:#0f172a;color:#e2e8f0;padding:12px;border-radius:8px;overflow-x:auto;font-size:12px">window.WIKI_CONFIG = {
  API_URL: "<?= e($apiBaseUrl) ?>", // ← sem barra no final
  API_KEY: "<?= e(substr($apiKey, 0, 8)) ?>…",   // ← cole a Key completa
};</pre></li>
                <li>Acesse o <code>index.html</code> hospedado. Pronto: categorias, artigos, busca e imagens vêm da API do OminiDesk.</li>
            </ol>
            <div>
                <a class="btn btn-outline" href="<?= url('wiki-frontend/config.js') ?>" target="_blank"><i class="fas fa-file-code"></i> Ver config.js servido por aqui</a>
                <a class="btn btn-outline" href="<?= url('wiki-frontend/') ?>" target="_blank"><i class="fas fa-external-link-alt"></i> Prévia do portal local</a>
            </div>
            <p style="margin:0;color:var(--text-muted)">Detalhe completo com exemplos cURL/JS e troubleshooting em <code>WIKI_MODULE.md</code> na raiz do projeto.</p>
        </div>
    </div>

    <div class="card" style="margin-top:12px"><div class="card-header"><h3>4 · ChatWeb no portal (balão de atendimento)</h3>
        <span class="badge" style="font-size:11px;padding:3px 10px;border-radius:20px;background:<?= !empty($chat['enabled']) ? '#dcfce7;color:#166534' : '#fee2e2;color:#991b1b' ?>"><?= !empty($chat['enabled']) ? 'Ativo' : 'Inativo' ?></span></div>
        <div class="card-body">
            <form method="POST" action="<?= url('wiki/settings/chat') ?>" style="display:flex;flex-direction:column;gap:12px">
                <?= csrf_field() ?>
                <label style="font-size:13px;display:flex;gap:8px;align-items:center;cursor:pointer">
                    <input type="checkbox" name="enabled" value="1" <?= !empty($chat['enabled']) ? 'checked' : '' ?> style="width:16px;height:16px">
                    <strong>Exibir o ChatWeb no portal público</strong>
                </label>
                <div>
                    <label style="font-size:12px;font-weight:600">Widget (de Configurações → Canais → ChatWeb)</label>
                    <select id="chatWidgetSelect" class="form-control" style="margin-top:4px" onchange="fillWikiChatWidget(this)">
                        <option value="">— Escolher widget —</option>
                        <?php foreach ($chatWidgets as $w): ?>
                            <option value="<?= e($w['widget_key']) ?>"
                                data-title="<?= e($w['title']) ?>"
                                data-color="<?= e($w['color_primary'] ?: '#0078d4') ?>"
                                data-position="<?= e($w['position'] ?: 'right') ?>"
                                <?= ($chat['widget_key'] ?? '') === $w['widget_key'] ? 'selected' : '' ?>>
                                <?= e($w['title']) ?> · <?= e(substr($w['widget_key'], 0, 8)) ?>… <?= empty($w['is_active']) ? '(inativo)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600">Chave do widget (widgetId)</label>
                    <input id="chatWidgetKey" name="widget_key" class="form-control" value="<?= e($chat['widget_key'] ?? '') ?>" placeholder="Cole a chave ou escolha o widget acima" style="font-family:monospace;font-size:12px;margin-top:4px">
                </div>
                <div style="display:grid;grid-template-columns:1fr 130px 130px;gap:12px">
                    <div>
                        <label style="font-size:12px;font-weight:600">Título no portal</label>
                        <input id="chatTitle" name="title" class="form-control" value="<?= e($chat['title'] ?? 'Atendimento') ?>" style="margin-top:4px">
                    </div>
                    <div>
                        <label style="font-size:12px;font-weight:600">Cor</label>
                        <input type="color" id="chatColor" name="color" value="<?= e($chat['color'] ?? '#0078d4') ?>" style="margin-top:4px;width:100%;height:38px;padding:2px;border:1px solid var(--border-color);border-radius:8px;cursor:pointer">
                    </div>
                    <div>
                        <label style="font-size:12px;font-weight:600">Posição</label>
                        <select name="position" class="form-control" style="margin-top:4px">
                            <option value="right" <?= ($chat['position'] ?? 'right') === 'right' ? 'selected' : '' ?>>Direita</option>
                            <option value="left" <?= ($chat['position'] ?? 'right') === 'left' ? 'selected' : '' ?>>Esquerda</option>
                        </select>
                    </div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <button class="btn btn-primary"><i class="fas fa-check"></i> Salvar ChatWeb</button>
                    <?php if (!empty($chat['widget_key'])): ?>
                        <a class="btn btn-outline" href="<?= url('widget/demo/' . $chat['widget_key']) ?>" target="_blank"><i class="fas fa-eye"></i> Testar widget</a>
                    <?php endif; ?>
                    <small style="color:var(--text-muted)">O portal lê essa config da API — vale na hora, sem republicar arquivos.</small>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function fillWikiChatWidget(sel) {
    var opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;
    document.getElementById('chatWidgetKey').value = opt.value;
    document.getElementById('chatTitle').value = opt.getAttribute('data-title') || 'Atendimento';
    document.getElementById('chatColor').value = opt.getAttribute('data-color') || '#0078d4';
    var pos = opt.getAttribute('data-position') || 'right';
    document.querySelector('select[name="position"]').value = pos;
}
</script>
