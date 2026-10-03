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
                <h3><i class="fas fa-store" style="color:var(--primary)"></i> Painel Guild (Lojas)</h3>
            </div>
            <div class="card-body">
                <div class="form-group">
                    <label>URL base do painel</label>
                    <input type="url" name="guild_api_base" class="form-control" placeholder="https://painel.guild.com.br" value="<?= e($settings['guild_api_base'] ?? 'https://painel.guild.com.br') ?>">
                </div>
                <div class="form-group">
                    <label>Token da API</label>
                    <input type="password" name="guild_api_token" class="form-control" autocomplete="new-password" placeholder="Token do painel Guild" value="<?= e($settings['guild_api_token'] ?? '') ?>">
                    <small class="text-muted" style="display:block;margin-top:4px;font-size:12px">
                        Usado pelo servidor para buscar lojas/unidades (<code>/api/mac/customer/{id}/stores</code>). Nunca é exposto no navegador.
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
