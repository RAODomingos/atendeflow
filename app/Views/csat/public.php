<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Avalie seu atendimento - AtendeFlow</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
               background: #f1f5f9; color: #1f2937; min-height: 100vh;
               display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card { background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,.08);
                max-width: 440px; width: 100%; padding: 28px; text-align: center; }
        .card h1 { font-size: 20px; margin: 0 0 6px; }
        .sub { color: #6b7280; font-size: 14px; margin: 0 0 18px; }
        .stars { font-size: 38px; color: #d1d5db; cursor: pointer; user-select: none; }
        .stars .star { transition: color .12s; padding: 0 3px; }
        .stars .star.on { color: #fbbf24; }
        .stars.disabled { cursor: default; }
        textarea { width: 100%; margin-top: 14px; border: 1px solid #d1d5db; border-radius: 10px;
                   padding: 10px; font: inherit; resize: vertical; min-height: 70px; }
        button.send { margin-top: 14px; width: 100%; background: #2f6fed; color: #fff; border: 0;
                      border-radius: 10px; padding: 12px; font-size: 15px; cursor: pointer; }
        button.send:disabled { opacity: .5; cursor: not-allowed; }
        .thanks { color: #16a34a; font-weight: 600; font-size: 16px; margin-top: 10px; }
        .existing { color: #6b7280; font-size: 14px; margin-top: 8px; }
    </style>
</head>
<body>
    <div class="card">
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
