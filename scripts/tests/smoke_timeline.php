<?php
require __DIR__ . '/../bootstrap.php';
use App\Core\Database;
use App\Core\Request;
use App\Controllers\ReportsController;

$db = Database::getInstance();
$ctrl = new ReportsController();
$ref = new ReflectionClass($ctrl);

$ok = true;

// extractIds
$m = $ref->getMethod("extractIds"); $m->setAccessible(true);
$_GET = ["ids" => "1,2,3,0,7"];
$ids = $m->invoke($ctrl, Request::capture());
if ($ids !== [1, 2, 3, 7]) { echo "FAIL: extractIds\n"; $ok = false; }
echo "extractIds => [" . implode(',', $ids) . "]\n";

// resolveTimelineParams
$_GET = ["from" => "2026-07-01", "to" => "2026-08-31"];
$r = $ref->getMethod("resolveTimelineParams"); $r->setAccessible(true);
$tl = $r->invoke($ctrl, Request::capture());
if (($tl['granularity'] ?? '') !== 'day') { echo "FAIL: granularity\n"; $ok = false; }

// a query de onde o CSV/PDF consumem retorna linhas
$rows = $db->fetchAll(
    "SELECT c.id, c.created_at, c.status, c.subject, c.priority,
            ct.name AS contact_name, ct.email AS contact_email, ct.phone AS contact_phone,
            d.name AS department_name, u.name AS assigned_user_name,
            ch.name AS channel_name, ch.type AS channel_type
     FROM conversations c
     LEFT JOIN channels ch ON ch.id = c.channel_id
     LEFT JOIN contacts ct ON ct.id = c.contact_id
     LEFT JOIN departments d ON d.id = c.department_id
     LEFT JOIN users u ON u.id = c.assigned_user_id
     {$tl['where']}
     ORDER BY c.created_at DESC LIMIT 5",
    $tl['params']
);
echo "linhas do filtro: " . count($rows) . "\n";
if (empty($rows)) { echo "AVISO: sem conversas no período, pulando agrupamento\n"; }

if (!empty($rows)) {
    $gm = $ref->getMethod("groupByTimeline"); $gm->setAccessible(true);
    $groups = $gm->invoke($ctrl, $rows, "day");
    if (count($groups) < 1) { echo "FAIL: groupByTimeline vazio\n"; $ok = false; }
    echo "grupos (dia): " . count($groups) . "\n";
}

echo $ok ? "PASS: timeline (CSV/PDF estrutura) OK\n" : "SOME FAILURES\n";