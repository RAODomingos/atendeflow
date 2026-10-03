<?php
// ============================================================
//  admin/_header.php — Layout base do painel admin
//  Inclua no topo de cada página: require '_header.php';
//  Variáveis esperadas: $pageTitle (string)
// ============================================================
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> — Guild Control</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="css/admin.css"/>
  <?php if (!empty($extraHead)) echo $extraHead; ?>
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar">
  <a class="sidebar-logo" href="/admin/">
    <div class="sidebar-logo-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 2L2 7l10 5 10-5-10-5z"/>
        <path d="M2 17l10 5 10-5"/>
        <path d="M2 12l10 5 10-5"/>
      </svg>
    </div>
    <div>
      <div class="sidebar-brand">Guild Control</div>
      <div class="sidebar-brand-sub">Admin</div>
    </div>
  </a>

  <nav class="sidebar-nav">
    <div class="sidebar-section-title">Conteúdo</div>
    <a class="sidebar-link <?= str_contains($_SERVER['PHP_SELF'], 'index.php') && !str_contains($_SERVER['PHP_SELF'], 'login') ? 'active' : '' ?>"
       href="/admin/">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
        <rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>
      </svg>
      Dashboard
    </a>
    <a class="sidebar-link <?= str_contains($_SERVER['PHP_SELF'], 'categories') ? 'active' : '' ?>"
       href="/admin/categories.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
      </svg>
      Categorias
    </a>
    <a class="sidebar-link <?= str_contains($_SERVER['PHP_SELF'], 'article') ? 'active' : '' ?>"
       href="/admin/articles.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
        <polyline points="14 2 14 8 20 8"/>
        <line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>
      </svg>
      Artigos
    </a>
    <a class="sidebar-link <?= str_contains($_SERVER['PHP_SELF'], 'users') ? 'active' : '' ?>"
       href="/admin/users.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
        <circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
      </svg>
      Usuários
    </a>

    <div class="sidebar-section-title" style="margin-top:16px">Configurações</div>
    <a class="sidebar-link" href="/" target="_blank">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>
        <polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>
      </svg>
      Ver site
    </a>
    <a class="sidebar-link" href="/admin/profile.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>
      Meu Perfil
    </a>
    <a class="sidebar-link" href="/admin/change-password.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
      </svg>
      Alterar senha
    </a>
    <a class="sidebar-link" href="/admin/logout.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
      Sair
    </a>
  </nav>

  <div class="sidebar-user">
    <div class="sidebar-user-avatar"><?= strtoupper(substr($_SESSION['admin_name'] ?? 'A', 0, 1)) ?></div>
    <div>
      <div class="sidebar-user-name"><?= htmlspecialchars($_SESSION['admin_name'] ?? '') ?></div>
      <div class="sidebar-user-role">Administrador</div>
    </div>
  </div>
</aside>

<!-- Main -->
<main class="admin-main">
  <header class="admin-topbar">
    <h1 class="admin-page-title"><?= htmlspecialchars($pageTitle ?? '') ?></h1>
    <div class="admin-topbar-actions">
      <button class="theme-toggle" id="themeToggle" aria-label="Alternar tema claro/escuro">
        <svg class="theme-icon-light" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="5"/>
          <line x1="12" y1="1" x2="12" y2="3"/>
          <line x1="12" y1="21" x2="12" y2="23"/>
          <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
          <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
          <line x1="1" y1="12" x2="3" y2="12"/>
          <line x1="21" y1="12" x2="23" y2="12"/>
          <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
          <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
        </svg>
        <svg class="theme-icon-dark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
        </svg>
      </button>
      <?php if (!empty($topbarActions)) echo $topbarActions; ?>
    </div>
  </header>
  <div class="admin-content">
