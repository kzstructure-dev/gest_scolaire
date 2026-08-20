<?php
declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . 'config.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = database();

if ($method === 'GET') {
    $search = trim((string) ($_GET['search'] ?? ''));
    if ($search !== '') {
        $query = $pdo->prepare('SELECT * FROM students WHERE first_name || " " || last_name LIKE :search OR class_name LIKE :search OR phone LIKE :search ORDER BY id DESC');
        $query->execute(['search' => '%' . $search . '%']);
    } else {
        $query = $pdo->query('SELECT * FROM students ORDER BY id DESC');
    }

    $students = array_map(static function (array $student): array {
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
    }, $query->fetchAll());

    jsonResponse(['success' => true, 'students' => $students]);
}

if ($method === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $firstName = trim((string) ($payload['firstName'] ?? ''));
    $lastName = trim((string) ($payload['lastName'] ?? ''));
    $className = trim((string) ($payload['className'] ?? ''));
    $phone = trim((string) ($payload['phone'] ?? ''));

    if ($firstName === '' || $lastName === '' || $className === '') {
        jsonResponse(['success' => false, 'message' => 'Les prenoms, le nom et la classe sont obligatoires.'], 422);
    }

    $cycle = preg_match('/^(CP|CE|CM)/i', $className) === 1 ? 'Primaire' : 'Secondaire';
    $insert = $pdo->prepare('INSERT INTO students (first_name, last_name, class_name, cycle, phone) VALUES (?, ?, ?, ?, ?)');
    $insert->execute([$firstName, $lastName, $className, $cycle, $phone]);
    $id = (int) $pdo->lastInsertId();

    jsonResponse([
        'success' => true,
        'message' => 'Dossier eleve cree avec succes.',
        'student' => [
            'id' => $id,
            'initials' => initials($firstName, $lastName),
            'name' => $firstName . ' ' . $lastName,
            'className' => $className,
            'cycle' => $cycle,
            'phone' => $phone,
            'status' => 'Inscrit',
            'statusClass' => 'good',
        ],
    ], 201);
}

jsonResponse(['success' => false, 'message' => 'Methode non autorisee.'], 405);
