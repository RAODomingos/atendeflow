-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Tempo de geração: 07/05/2026 às 13:43
-- Versão do servidor: 10.4.32-MariaDB
-- Versão do PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `wiki`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `articles`
--

CREATE TABLE `articles` (
  `id` int(11) NOT NULL,
  `slug` varchar(150) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `cover_image` varchar(500) DEFAULT NULL,
  `featured` tinyint(1) DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `published_at` date DEFAULT curdate(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `view_count` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `articles`
--

INSERT INTO `articles` (`id`, `slug`, `category_id`, `title`, `description`, `content`, `cover_image`, `featured`, `sort_order`, `published_at`, `created_at`, `updated_at`, `view_count`) VALUES
(1, 'kit-guild-control', 1, 'Kit Guild Control', 'Tudo o que você vai receber no seu kit Guild Control', '<h1 style=\"text-align: center;\"><span style=\"font-size: 48px;\" class=\"ql-font-serif\">Seu Guild Control Chegou!</span></h1><p><br></p><p style=\"text-align: center;\"><img src=\"/uploads/20260410_223213_00be7f11aa4b.png\"></p><p><br></p><p>Baixe o guia de instalação do seu equipamento </p><p><br></p><p><br></p>', '', 1, 0, '2026-04-10', '2026-04-10 20:32:46', '2026-04-21 02:57:38', 0),
(2, 'teste-do-segundo-artigo', 2, 'teste do segundo artigo', 'TEste de artigo', '<p>\r\n      </p><h2 style=\"text-align: center;\">Introdução</h2><p>\r\n      </p><p>Escreva uma introdução envolvente que captará a atenção do leitor...</p><p>\r\n      \r\n      </p><h2>Desenvolvimento</h2><p>\r\n      </p><p>Desenvolva seus principais pontos aqui...</p><p>\r\n      \r\n      </p><h2>Conclusão</h2><p>\r\n      </p><p>Resuma os pontos principais e finalize com uma reflexão...</p><p>\r\n    </p>', '', 0, 0, '2026-04-21', '2026-04-21 16:57:50', '2026-04-21 17:00:25', 0),
(3, 'teste-22', 4, 'teste 2.2', 'Artigo de teste 2.2', '<p>TEste 2.2 G1.com.br</p>', '', 0, 0, '2026-05-07', '2026-05-07 11:32:40', '2026-05-07 11:32:40', 0);

-- --------------------------------------------------------

--
-- Estrutura para tabela `article_views`
--

CREATE TABLE `article_views` (
  `id` int(11) NOT NULL,
  `article_id` int(11) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `referrer` varchar(500) DEFAULT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `backup_logs`
--

CREATE TABLE `backup_logs` (
  `id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `backup_type` enum('manual','automatic') DEFAULT 'manual',
  `status` enum('success','failed') DEFAULT 'success',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `slug` varchar(100) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `icon_svg` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `categories`
--

INSERT INTO `categories` (`id`, `slug`, `title`, `description`, `icon_svg`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'comece-aqui', 'Comece aqui', 'Tudo que você precisa saber para dar os primeiros passos com o Guild Control.', '<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"white\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6\"/></svg>', 1, '2026-04-10 20:28:13', '2026-04-10 20:28:13'),
(2, 'conhecendo-painel', 'Conhecendo o painel', 'Explore todas as funcionalidades e recursos do seu painel de controle.', '<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"white\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><rect x=\"3\" y=\"3\" width=\"7\" height=\"7\"/><rect x=\"14\" y=\"3\" width=\"7\" height=\"7\"/><rect x=\"14\" y=\"14\" width=\"7\" height=\"7\"/><rect x=\"3\" y=\"14\" width=\"7\" height=\"7\"/></svg>', 2, '2026-04-10 20:28:13', '2026-04-10 20:28:13'),
(3, 'ativar-guild', 'Como ativar seu Guild Control', 'Guias passo a passo para ativar e configurar sua conta Guild Control.', '<svg viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"white\" stroke-width=\"2\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z\"/></svg>', 3, '2026-04-10 20:28:13', '2026-04-10 20:28:13'),
(4, 'teste4', 'teste4', 'fwdfsfsfsdfsdfs', '', 4, '2026-04-21 02:58:23', '2026-04-21 02:58:30');

-- --------------------------------------------------------

--
-- Estrutura para tabela `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('info','success','warning','error') DEFAULT 'info',
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `session_logs`
--

CREATE TABLE `session_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `role` enum('admin','editor','viewer') DEFAULT 'editor',
  `last_login` timestamp NULL DEFAULT NULL,
  `last_activity` timestamp NULL DEFAULT NULL,
  `login_attempts` int(11) DEFAULT 0,
  `locked_until` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Despejando dados para a tabela `users`
--

INSERT INTO `users` (`id`, `name`, `username`, `password`, `created_at`, `role`, `last_login`, `last_activity`, `login_attempts`, `locked_until`) VALUES
(1, 'Administrador', 'admin', '$2a$12$VNbbLOXrPfV5tPcqRlEZGuQ.efcFu98czab7ACDRE8wtDOJlX.AF2', '2026-04-10 20:28:13', 'editor', NULL, NULL, 0, NULL);

-- --------------------------------------------------------

--
-- Estrutura stand-in para view `v_article_stats`
-- (Veja abaixo para a visão atual)
--
CREATE TABLE `v_article_stats` (
`id` int(11)
,`title` varchar(255)
,`slug` varchar(150)
,`category_title` varchar(255)
,`featured` tinyint(1)
,`view_count` int(11)
,`total_views` bigint(21)
,`last_viewed` timestamp
,`created_at` timestamp
);

-- --------------------------------------------------------

--
-- Estrutura stand-in para view `v_user_activity`
-- (Veja abaixo para a visão atual)
--
CREATE TABLE `v_user_activity` (
`id` int(11)
,`name` varchar(100)
,`username` varchar(100)
,`last_login` timestamp
,`last_activity` timestamp
,`session_count` bigint(21)
,`last_activity_log` timestamp
);

-- --------------------------------------------------------

--
-- Estrutura para view `v_article_stats`
--
DROP TABLE IF EXISTS `v_article_stats`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_article_stats`  AS SELECT `a`.`id` AS `id`, `a`.`title` AS `title`, `a`.`slug` AS `slug`, `c`.`title` AS `category_title`, `a`.`featured` AS `featured`, `a`.`view_count` AS `view_count`, count(`av`.`id`) AS `total_views`, max(`av`.`viewed_at`) AS `last_viewed`, `a`.`created_at` AS `created_at` FROM ((`articles` `a` left join `categories` `c` on(`c`.`id` = `a`.`category_id`)) left join `article_views` `av` on(`av`.`article_id` = `a`.`id`)) GROUP BY `a`.`id`, `a`.`title`, `a`.`slug`, `c`.`title`, `a`.`featured`, `a`.`view_count`, `a`.`created_at` ;

-- --------------------------------------------------------

--
-- Estrutura para view `v_user_activity`
--
DROP TABLE IF EXISTS `v_user_activity`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_user_activity`  AS SELECT `u`.`id` AS `id`, `u`.`name` AS `name`, `u`.`username` AS `username`, `u`.`last_login` AS `last_login`, `u`.`last_activity` AS `last_activity`, count(`sl`.`id`) AS `session_count`, max(`sl`.`created_at`) AS `last_activity_log` FROM (`users` `u` left join `session_logs` `sl` on(`sl`.`user_id` = `u`.`id`)) GROUP BY `u`.`id`, `u`.`name`, `u`.`username`, `u`.`last_login`, `u`.`last_activity` ;

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `articles`
--
ALTER TABLE `articles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD KEY `idx_articles_category_id` (`category_id`),
  ADD KEY `idx_articles_featured` (`featured`),
  ADD KEY `idx_articles_sort_order` (`sort_order`),
  ADD KEY `idx_articles_created_at` (`created_at`),
  ADD KEY `idx_articles_view_count` (`view_count`);

--
-- Índices de tabela `article_views`
--
ALTER TABLE `article_views`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_article_id` (`article_id`),
  ADD KEY `idx_viewed_at` (`viewed_at`),
  ADD KEY `idx_ip_address` (`ip_address`);

--
-- Índices de tabela `backup_logs`
--
ALTER TABLE `backup_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_status` (`status`);

--
-- Índices de tabela `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`),
  ADD UNIQUE KEY `idx_categories_slug` (`slug`),
  ADD KEY `idx_categories_sort_order` (`sort_order`);

--
-- Índices de tabela `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_read` (`read`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Índices de tabela `session_logs`
--
ALTER TABLE `session_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Índices de tabela `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `articles`
--
ALTER TABLE `articles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `article_views`
--
ALTER TABLE `article_views`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `backup_logs`
--
ALTER TABLE `backup_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de tabela `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `session_logs`
--
ALTER TABLE `session_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `articles`
--
ALTER TABLE `articles`
  ADD CONSTRAINT `articles_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_articles_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Restrições para tabelas `article_views`
--
ALTER TABLE `article_views`
  ADD CONSTRAINT `fk_views_article` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE;

--
-- Restrições para tabelas `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
