<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

$databasePath = dirname(__DIR__).'/var/gest_scolaire_test.sqlite';

if (!is_dir(dirname($databasePath))) {
    mkdir(dirname($databasePath), 0775, true);
}

if (is_file($databasePath)) {
    unlink($databasePath);
}

putenv('GEST_SCOLAIRE_DB='.$databasePath);

// config.php cree le schema SQLite et les donnees de reference (dont les roles metier).
require dirname(__DIR__).'/config.php';

$connection = database();
$statement = $connection->prepare(
    'INSERT INTO users (establishment_id, role_id, first_name, last_name, email, password_hash, active)
     VALUES (1, (SELECT id FROM roles WHERE name = ?), ?, ?, ?, ?, 1)'
);

$password = password_hash(App\Tests\TestUsers::PASSWORD, PASSWORD_BCRYPT);

foreach (App\Tests\TestUsers::ACCOUNTS as $email => [$roleName, $firstName, $lastName]) {
    $statement->execute([$roleName, $firstName, $lastName, $email, $password]);
}
