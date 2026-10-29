<div class="settings-page">
    <?php $subPage = 'geral'; require __DIR__ . '/_tabs.php'; ?>

    <form method="POST" action="<?= url('settings') ?>">
        <?= csrf_field() ?>

        <div class="card" style="border-radius:12px;overflow:hidden;margin-top:16px">
            <div class="card-header" style="background:var(--bg-card)">
                <h3><i class="fas fa-smile" style="color:var(--warning)"></i> CSAT Pós-Resolução</h3>
            </div>
            <div class="card-body">
                <div class="form-check" style="display:flex;align-items:center;gap:10px;margin-bottom:16px;padding:12px 16px;background:var(--bg-content);border-radius:8px;">
                    <input type="checkbox" name="csat_enabled" value="1" id="csatEnabled" <?= (!empty($settings['csat_enabled']) && $settings['csat_enabled'] === '1') ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--primary)">
                    <label for="csatEnabled" style="font-weight:500;cursor:pointer;margin:0">
                        Enviar solicitação de avaliação ao marcar a conversa como "Resolvida"
                    </label>
                </div>
                <div class="form-group">
                    <label>Mensagem de solicitação de CSAT</label>
                    <textarea name="csat_message" class="form-control" rows="3" placeholder="Ex: Olá {{contact.name}}, como foi seu atendimento? Responda aqui mesmo com uma nota de 1 a 5."><?= e($settings['csat_message'] ?? '') ?></textarea>
                    <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                        Enviada ao <strong>resolver ou fechar</strong> a conversa. No ChatWeb abre um cartão com estrelas e campo de comentário;
                        nos demais canais (ex.: WhatsApp) é enviado o <strong>link da página de avaliação</strong>
                        (use <code>{{csat.link}}</code> na mensagem; se não usar, o link é anexado ao final automaticamente).
                        Variáveis: <code>{{contact.name}}</code>, <code>{{contact.email}}</code>,
                        <code>{{department.name}}</code>, <code>{{agent.name}}</code>, <code>{{csat.link}}</code>
                    </small>
                </div>
            </div>
        </div>

        <div class="card" style="border-radius:12px;overflow:hidden;margin-top:16px">
            <div class="card-header" style="background:var(--bg-card)">
                <h3><i class="fas fa-recycle" style="color:var(--primary)"></i> Reabertura de atendimento</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label>Novo atendimento do mesmo cliente</label>
                    <select name="atendimento_reabertura_modo" class="form-control" id="atendimentoModoGlobal">
                        <option value="unique" <?= (string) ($settings['atendimento_reabertura_modo'] ?? 'unique') === 'unique' ? 'selected' : '' ?>>Sempre novo (único)</option>
                        <option value="always" <?= (string) ($settings['atendimento_reabertura_modo'] ?? 'unique') === 'always' ? 'selected' : '' ?>>Reabrir o atendimento anterior</option>
                        <option value="window" <?= (string) ($settings['atendimento_reabertura_modo'] ?? 'unique') === 'window' ? 'selected' : '' ?>>Reabrir se for em até X minutos</option>
                    </select>
                    <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                        Padrão do sistema: o que acontece quando o mesmo cliente escreve de novo no WhatsApp
                        depois de o atendimento anterior ter sido encerrado. Cada caixa pode ter a sua própria
                        regra (Configurações → Caixas). Vale para as mensagens do WhatsApp.
                    </small>
                </div>
                <div class="form-group" id="atendimentoJanelaGlobalGroup" style="<?= (string) ($settings['atendimento_reabertura_modo'] ?? 'unique') === 'window' ? '' : 'display:none' ?>">
                    <label>Janela de reabertura</label>
                    <div style="display:flex;align-items:center;gap:8px">
                        <input type="number" min="1" max="10080" name="atendimento_reabertura_janela_minutos" class="form-control"
                               id="atendimentoJanelaGlobal" value="<?= e((string) ($settings['atendimento_reabertura_janela_minutos'] ?? '120')) ?>" style="width:120px">
                        <span style="color:var(--text-muted)">minutos</span>
                    </div>
                    <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                        Contada da última mensagem do atendimento anterior (padrão: 120 minutos).
                    </small>
                </div>
            </div>
        </div>

        <div class="card" style="border-radius:12px;overflow:hidden;margin-top:16px">
            <div class="card-header" style="background:var(--bg-card)">
                <h3><i class="fas fa-store" style="color:var(--primary)"></i> Painel Guild (Lojas)</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label>URL base do painel</label>
                    <input type="url" name="guild_api_base" class="form-control" placeholder="https://painel.guild.com.br" value="<?= e($settings['guild_api_base'] ?? 'https://painel.guild.com.br') ?>">
                </div>
                <div class="form-group">
                    <label>Token da API</label>
                    <input type="password" name="guild_api_token" class="form-control" autocomplete="new-password" placeholder="<?= !empty($settings['guild_api_token']) ? '•••••••• (configurado — preencha para trocar)' : 'Token do painel Guild' ?>" value="">
                    <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                        Usado pelo servidor para buscar lojas/unidades (<code>/api/mac/customer/{id}/stores</code>). Nunca é exposto no navegador<?= !empty($settings['guild_api_token']) ? ' — <strong>token configurado</strong>' : '' ?>; em branco mantém o atual.
                    </small>
                </div>
            </div>
        </div>

        <div class="card" style="border-radius:12px;overflow:hidden;margin-top:16px">
            <div class="card-header" style="background:var(--bg-card)">
                <h3><i class="fas fa-microphone" style="color:var(--primary)"></i> Transcrição de Áudios (OpenAI)</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label>Chave da API (Whisper)</label>
                    <input type="password" name="openai_api_key" class="form-control" autocomplete="new-password" placeholder="<?= !empty($settings['openai_api_key']) ? '•••••••• (configurada — preencha para trocar)' : 'sk-...' ?>" value="">
                    <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                        Usada pelo servidor para transcrever áudios sob demanda no inbox. Nunca é exposta no navegador<?= !empty($settings['openai_api_key']) ? ' — <strong>chave configurada</strong>' : '' ?>; em branco mantém a atual. Alternativa: <code>OPENAI_API_KEY</code> no <code>.env</code>.
                    </small>
                </div>
            </div>
        </div>

        <div class="form-actions" style="margin-top:20px">
            <button type="submit" class="btn btn-primary" style="padding:10px 28px;border-radius:10px">
                <i class="fas fa-save"></i> Salvar Configurações
            </button>
        </div>
    </form>
</div>

<script>
(function() {
    // A janela de reabertura só faz sentido no modo "reabrir por janela".
    var modo = document.getElementById('atendimentoModoGlobal');
    var janela = document.getElementById('atendimentoJanelaGlobalGroup');
    if (!modo || !janela) return;
    function apply() {
        janela.style.display = modo.value === 'window' ? '' : 'none';
    }
    modo.addEventListener('change', apply);
    apply();
})();
</script>
