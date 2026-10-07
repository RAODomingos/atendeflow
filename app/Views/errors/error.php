<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title ?? 'Erro') ?> - OminiDesk</title>
    <link rel="icon" type="image/png" href="<?= asset('assets/img/favicon.png') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?= asset('assets/css/app.css') ?>">
</head>
<body class="error-page">
    <div class="error-container">
        <div class="error-code"><?= http_response_code() ?></div>
        <h1><?= e($title) ?></h1>
        <p><?= e($message) ?></p>
        <a href="<?= url('/') ?>" class="btn btn-primary">
            <i class="fas fa-home"></i>
            Voltar ao início
        </a>
    </div>
</body>
</html>
