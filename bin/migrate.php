<?php
declare(strict_types=1);

/**
 * Minimal SQL migration runner.
 *
 * Applies db/migrations/*.sql in filename order, tracking applied files in
 * the comet_migrations table. Statements are split on ";" at end of line,
 * so keep migrations to plain statements (no stored procedures).
 *
 * Run inside the app container:
 *   docker compose exec app php /var/www/bin/migrate.php
 */

if (PHP_SAPI !== 'cli') {
    die("CLI only.\n");
}

require '/var/www/html/db.php'; // defines $_MY_SERV, $_MY_DB, $_MY_USER, $_MY_PASS

$migrationsDir = '/var/www/migrations';

$pdo = new PDO(
    "mysql:host={$_MY_SERV};dbname={$_MY_DB};charset=utf8mb4",
    $_MY_USER,
    $_MY_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS comet_migrations (
        filename VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $pdo->query('SELECT filename FROM comet_migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = glob($migrationsDir . '/*.sql');
sort($files);

$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        continue;
    }

    echo "Applying $name ...\n";
    $sql = file_get_contents($file);

    // Strip line comments, then split on ";" at end of line.
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = preg_split('/;\s*$/m', $sql);

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $pdo->exec($statement);
    }

    $stmt = $pdo->prepare('INSERT INTO comet_migrations (filename, applied_at) VALUES (?, NOW())');
    $stmt->execute([$name]);
    $ran++;
}

echo $ran === 0 ? "Nothing to apply - up to date.\n" : "Applied $ran migration(s).\n";
