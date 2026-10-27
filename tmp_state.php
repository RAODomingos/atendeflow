<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/autoload.php';
require_once __DIR__ . '/app/Core/Helper.php';
\App\Config\Environment::load();
$cfg = require __DIR__ . '/config/app.php';
date_default_timezone_set($cfg['timezone'] ?? 'America/Sao_Paulo');
$db = \App\Core\Database::getInstance();

$rows = $db->fetchAll("SELECT id, subject FROM conversations WHERE subject LIKE 'Teste SLA dashboard %'");
echo 'conversas de fixture restantes: ' . count($rows) . PHP_EOL;

$sla = $db->fetch("SELECT `value` FROM settings WHERE `key` = 'sla_enabled'");
echo 'sla_enabled: ' . var_export($sla['value'] ?? null, true) . PHP_EOL;

$ib = $db->fetchAll("SELECT id, name, sla_business_hours, sla_attention_minutes, sla_alert_minutes FROM inboxes WHERE type='department' ORDER BY id");
echo 'caixas de departamento: ' . json_encode($ib) . PHP_EOL;

$t = $db->fetch("SHOW TABLES LIKE 'conversation_slas'");
echo 'tabela conversation_slas presente: ' . var_export((bool) $t, true) . PHP_EOL;

$c = $db->fetch("SELECT COUNT(*) c FROM conversations");
echo 'total de conversas: ' . $c['c'] . PHP_EOL;

$ct = $db->fetch("SELECT last_activity_at FROM contacts WHERE is_group = 0 ORDER BY id LIMIT 1");
echo 'contato last_activity_at: ' . var_export($ct['last_activity_at'] ?? null, true) . PHP_EOL;
