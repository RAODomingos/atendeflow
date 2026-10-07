<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Avalie seu atendimento - OminiDesk</title>
    <link rel="icon" type="image/png" href="<?= asset('assets/img/favicon.png') ?>">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
               background: #f5f5f5; color: #201f1e; min-height: 100vh;
               display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card { background: #fff; border: 1px solid #edebe9; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,.08);
                max-width: 440px; width: 100%; padding: 28px; text-align: center; }
        .card h1 { font-size: 20px; margin: 0 0 6px; font-weight: 600; }
        .sub { color: #605e5c; font-size: 14px; margin: 0 0 18px; }
        .stars { font-size: 38px; color: #8a8886; cursor: pointer; user-select: none; }
        .stars .star { transition: color .12s; padding: 0 3px; }
        .stars .star.on { color: #ffb900; }
        .stars.disabled { cursor: default; }
        textarea { width: 100%; margin-top: 14px; border: 1px solid #8a8886; border-radius: 4px;
                   padding: 10px; font: inherit; resize: vertical; min-height: 70px; color: #201f1e; }
        textarea:focus { outline: none; border-color: #0078d4; box-shadow: 0 0 0 1px #0078d4; }
        button.send { margin-top: 14px; width: 100%; background: #0078d4; color: #fff; border: 0;
                      border-radius: 4px; padding: 12px; font-size: 15px; font-weight: 600; cursor: pointer;
                      transition: background .12s; }
        button.send:hover { background: #106ebe; }
        button.send:disabled { opacity: .5; cursor: not-allowed; }
        .thanks { color: #107c10; font-weight: 600; font-size: 16px; margin-top: 10px; }
        .existing { color: #605e5c; font-size: 14px; margin-top: 8px; }
        .csat-logo { margin: 0 0 12px; }
        .csat-logo img { height: 54px; width: auto; max-width: 230px; object-fit: contain; }
    </style>
</head>
<body>
    <div class="card">
        <div class="csat-logo">
            <img src="<?= asset('assets/img/ominidesk-logo.jpg') ?>" alt="Ominidesk">
        </div>
        <h1>Avalie seu atendimento</h1>
        <p class="sub">
            <?php if (!empty($contact['name'])): ?>
                Obrigado, <?= e($contact['name']) ?>!
            <?php else: ?>
                Sua opinião é importante para nós.
            <?php endif; ?>
        </p>

        <?php if (!empty($existing)): ?>
            <div class="stars disabled" id="stars">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <span class="star <?= $i <= $existing['rating'] ? 'on' : '' ?>">★</span>
                <?php endfor; ?>
            </div>
            <p class="existing">Você já avaliou este atendimento com <?= (int) $existing['rating'] ?>/5.</p>
            <?php if (!empty($existing['comment'])): ?>
                <p class="existing"><?= e($existing['comment']) ?></p>
            <?php endif; ?>
        <?php elseif (!empty($thank)): ?>
            <div class="thanks">Obrigado pela sua avaliação! ✅</div>
        <?php else: ?>
            <form method="POST" action="<?= base_url('csat/' . e($token)) ?>">
                <div class="stars" id="stars">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span class="star" data-v="<?= $i ?>">★</span>
                    <?php endfor; ?>
                </div>
                <input type="hidden" name="rating" id="rating" value="0">
                <textarea name="comment" placeholder="Comentário (opcional)"></textarea>
                <button type="submit" class="send" id="sendBtn" disabled>Enviar avaliação</button>
            </form>
        <?php endif; ?>
    </div>

    <script>
        (function () {
            var stars = document.querySelectorAll('#stars .star');
            var ratingInput = document.getElementById('rating');
            var sendBtn = document.getElementById('sendBtn');
            if (!stars.length || !ratingInput) return;
            var sel = 0;
            stars.forEach(function (s) {
                s.addEventListener('click', function () {
                    sel = parseInt(s.getAttribute('data-v'), 10);
                    ratingInput.value = sel;
                    stars.forEach(function (x) {
                        x.classList.toggle('on', parseInt(x.getAttribute('data-v'), 10) <= sel);
                    });
                    if (sendBtn) sendBtn.disabled = false;
                });
            });
        })();
    </script>
</body>
</html>
