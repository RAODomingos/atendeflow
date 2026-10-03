<?php
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use App\Core\Database;
use App\Core\View;
use App\Models\Conversation;
use App\Models\Contact;
use App\Models\Tag;

$db = Database::getInstance();

$outDir = sys_get_temp_dir() . '/omini-pdf-smoke';
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

$failures = 0;

$render = static function (string $view, array $data, string $filename) use ($outDir, &$failures): void {
    $html = View::renderBuffer($view, $data);

    $hasProtocolBadge = str_contains($html, 'class="proto"');
    $hasProtocolLabel = str_contains($html, 'Protocolo');

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->setPaper('A4');
    $dompdf->loadHtml($html);
    $dompdf->render();
    $pdf = $dompdf->output();

    $path = $outDir . '/' . $filename;
    file_put_contents($path, $pdf);

    $ok = strlen($pdf) > 2000 && $hasProtocolBadge && $hasProtocolLabel;
    printf(
        "%s %-28s %6d bytes  badge=%s protocolo=%s  -> %s\n",
        $ok ? 'OK  ' : 'FAIL',
        $view,
        strlen($pdf),
        $hasProtocolBadge ? 'sim' : 'NAO',
        $hasProtocolLabel ? 'sim' : 'NAO',
        $path
    );
    if (!$ok) {
        $failures++;
    }
};

$conv = $db->fetch(
    "SELECT c.*, ch.type as channel_type, ch.name as channel_name,
            d.name as department_name, d.color as department_color,
            u.name as assigned_user_name, ct.name as contact_name,
            ct.email as contact_email, ct.phone as contact_phone,
            ct.avatar as contact_avatar, ct.company as contact_company
     FROM conversations c
     LEFT JOIN contacts ct ON ct.id = c.contact_id
     LEFT JOIN departments d ON d.id = c.department_id
     LEFT JOIN users u ON u.id = c.assigned_user_id
     LEFT JOIN channels ch ON ch.id = c.channel_id
     ORDER BY c.protocol IS NULL, c.id DESC
     LIMIT 1"
);

if (!$conv) {
    echo "FAIL: nenhuma conversa no banco para o teste\n";
    exit(1);
}

$allTags = Tag::all();
$contact = Contact::find((int) $conv['contact_id']);
$messages = Conversation::getMessages((int) $conv['id']);
$events = Conversation::getEvents((int) $conv['id']);
$csat = Conversation::getCsat((int) $conv['id']);

echo 'protocolo da conversa de teste: ' . ($conv['protocol'] ?? '(sem protocolo)') . "\n";

$render('inbox/pdf', [
    'conversation' => $conv,
    'messages' => $messages,
    'events' => $events,
    'contact' => $contact,
    'csat' => $csat,
    'allTags' => $allTags,
], 'teste-conversa.pdf');

$contactConvs = $db->fetchAll(
    "SELECT c.*, ch.type as channel_type, ch.name as channel_name,
            d.name as department_name, u.name as assigned_user_name,
            ct.name as contact_name
     FROM conversations c
     LEFT JOIN channels ch ON ch.id = c.channel_id
     LEFT JOIN departments d ON d.id = c.department_id
     LEFT JOIN users u ON u.id = c.assigned_user_id
     LEFT JOIN contacts ct ON ct.id = c.contact_id
     WHERE c.contact_id = ?
     ORDER BY c.created_at DESC
     LIMIT 5",
    [(int) $conv['contact_id']]
);

$contactData = [];
foreach ($contactConvs as $cc) {
    $cid = (int) $cc['id'];
    $contactData[] = [
        'conversation' => $cc,
        'messages' => Conversation::getMessages($cid),
        'events' => Conversation::getEvents($cid),
        'csat' => Conversation::getCsat($cid),
    ];
}

$render('contacts/pdf_full', [
    'contact' => $contact,
    'conversationsData' => $contactData,
    'allTags' => $allTags,
    'pdfScope' => 'Todas as conversas (' . count($contactData) . ')',
], 'teste-contato.pdf');

$rows = $db->fetchAll(
    "SELECT c.*, ch.type as channel_type, ch.name as channel_name,
            ct.name AS contact_name, ct.email AS contact_email, ct.phone AS contact_phone,
            d.name AS department_name, d.color AS department_color,
            u.name AS assigned_user_name
     FROM conversations c
     LEFT JOIN channels ch ON ch.id = c.channel_id
     LEFT JOIN contacts ct ON ct.id = c.contact_id
     LEFT JOIN departments d ON d.id = c.department_id
     LEFT JOIN users u ON u.id = c.assigned_user_id
     ORDER BY c.created_at DESC
     LIMIT 5"
);

$timelineData = [];
foreach ($rows as $cc) {
    $cid = (int) $cc['id'];
    $cc['tags'] = $db->fetchAll(
        "SELECT t.* FROM tags t
         INNER JOIN conversation_tags ct ON ct.tag_id = t.id
         WHERE ct.conversation_id = ?",
        [$cid]
    );
    $timelineData[] = [
        'conversation' => $cc,
        'messages' => Conversation::getMessages($cid),
        'events' => Conversation::getEvents($cid),
        'csat' => Conversation::getCsat($cid),
    ];
}

$render('reports/timeline_pdf', [
    'timeline' => [
        'from' => date('Y-m-d', strtotime('-30 days')),
        'to' => date('Y-m-d'),
        'status' => null,
        'granularity' => 'day',
    ],
    'conversationsData' => $timelineData,
    'allTags' => $allTags,
    'isSelection' => false,
    'selectedCount' => 0,
], 'teste-timeline.pdf');

echo $failures === 0 ? "TODOS OS PDFs RENDERIZARAM\n" : "FALHAS: {$failures}\n";
exit($failures === 0 ? 0 : 1);
