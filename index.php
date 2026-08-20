<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
$studentRows = database()->query('SELECT * FROM students ORDER BY id DESC')->fetchAll();
$initialStudents = array_map(static function (array $student): array {
    return [
        'id' => (int) $student['id'],
        'initials' => initials($student['first_name'], $student['last_name']),
        'name' => $student['first_name'] . ' ' . $student['last_name'],
        'className' => $student['class_name'],
        'cycle' => $student['cycle'],
        'phone' => $student['phone'],
        'status' => $student['status'],
        'statusClass' => $student['status'] === 'A verifier' ? 'pending' : ($student['status'] === 'Retard paiement' ? 'late' : 'good'),
    ];
}, $studentRows);
$classRows = database()->query('SELECT c.name, c.capacity, l.name AS level_name, cy.name AS cycle, COUNT(r.id) AS student_count FROM classes c JOIN levels l ON l.id = c.level_id JOIN cycles cy ON cy.id = l.cycle_id LEFT JOIN registrations r ON r.class_id = c.id AND r.status = "Validee" GROUP BY c.id ORDER BY l.sort_order, c.name')->fetchAll();
$entityCounts = [];
foreach (['students', 'classes', 'teachers', 'registrations', 'attendance', 'evaluations', 'grades', 'report_cards', 'invoices', 'payments', 'notifications'] as $table) {
  $entityCounts[$table] = (int) database()->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#12343b">
  <title>Ecole Horizon | Gestion scolaire</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <div class="app-shell">
    <aside class="sidebar" aria-label="Navigation principale">
      <div class="brand"><div class="brand-mark">EH</div><div><strong>Ecole Horizon</strong><span>Gestion scolaire</span></div></div>
      <div class="school-year"><span>Annee scolaire</span><strong>2025 - 2026</strong><button id="yearButton" type="button" title="Changer d'annee">⌄</button></div>
      <nav class="main-nav">
        <button class="nav-item active" data-view="dashboard"><span class="nav-icon">⌂</span>Tableau de bord</button>
        <button class="nav-item" data-view="students"><span class="nav-icon">◉</span>Eleves</button>
        <button class="nav-item" data-view="attendance"><span class="nav-icon">✓</span>Presences</button>
        <button class="nav-item" data-view="grades"><span class="nav-icon">▤</span>Notes & bulletins</button>
        <button class="nav-item" data-view="finance"><span class="nav-icon">₣</span>Finances</button>
        <button class="nav-item" data-view="classes"><span class="nav-icon">▦</span>Classes</button>
        <button class="nav-item" data-view="documents"><span class="nav-icon">▥</span>Documents</button>
      </nav>
      <div class="sidebar-footer"><div class="help-card"><span class="help-badge">?</span><div><strong>Besoin d'aide ?</strong><small>Consulter le guide de gestion</small></div></div><button class="logout" type="button">↪ Deconnexion</button></div>
    </aside>
    <main class="main-content">
      <header class="topbar"><button class="mobile-menu" id="mobileMenu" type="button" aria-label="Ouvrir le menu">☰</button><div class="breadcrumb"><span>Administration</span><b>/</b><strong id="breadcrumbCurrent">Tableau de bord</strong></div><div class="top-actions"><button class="icon-button" type="button" title="Notifications" id="notificationButton">♢<i></i></button><div class="profile"><div class="avatar">AK</div><div><strong>Adama Kone</strong><span>Directeur</span></div><button type="button" title="Menu du profil">⌄</button></div></div></header>
      <section class="page" id="appView"></section>
    </main>
  </div>
  <div class="toast" id="toast" role="status"></div>
  <div class="modal-backdrop" id="modalBackdrop" hidden><section class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle"><button class="modal-close" id="modalClose" type="button" aria-label="Fermer">×</button><div id="modalContent"></div></section></div>
  <script>window.initialStudents = <?= json_encode($initialStudents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>; window.initialData = <?= json_encode(['classes' => $classRows, 'entityCounts' => $entityCounts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
  <script src="app.js"></script>
</body>
</html>
