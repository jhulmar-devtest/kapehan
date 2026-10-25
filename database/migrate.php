<?php
// CLI-only migration runner. It never loads the web/session bootstrap.
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

require_once __DIR__ . '/../config/secrets.php';
require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance();
$command = $argv[1] ?? 'status';
$migrationDir = __DIR__ . '/migrations';
$files = glob($migrationDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$trackingTableExists = (bool) $db->query("SHOW TABLES LIKE 'schema_migrations'")->fetchColumn();
if (!$trackingTableExists && $command === 'status') {
  echo "Migration history is not initialized. Existing databases should be checked, then baselined before applying new migrations.\n";
  foreach ($files as $file) echo 'untracked  ' . basename($file) . "\n";
  exit(0);
}
if (!$trackingTableExists) {
  $db->exec("CREATE TABLE schema_migrations (
    version VARCHAR(190) NOT NULL PRIMARY KEY,
    checksum CHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function migrationRows(PDO $db): array
{
  $rows = $db->query('SELECT version, checksum, applied_at FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_ASSOC);
  $map = [];
  foreach ($rows as $row) $map[$row['version']] = $row;
  return $map;
}

function migrationSqlStatements(string $sql): array
{
  $statements = [];
  $buffer = '';
  $quote = null;
  $lineComment = false;
  $blockComment = false;
  $length = strlen($sql);
  for ($i = 0; $i < $length; $i++) {
    $char = $sql[$i];
    $next = $sql[$i + 1] ?? '';
    if ($lineComment) {
      if ($char === "\n") {
        $lineComment = false;
        $buffer .= $char;
      }
      continue;
    }
    if ($blockComment) {
      if ($char === '*' && $next === '/') {
        $blockComment = false;
        $i++;
      }
      continue;
    }
    if ($quote !== null) {
      $buffer .= $char;
      if ($char === '\\' && $next !== '') {
        $buffer .= $next;
        $i++;
        continue;
      }
      if ($char === $quote) {
        if (($sql[$i + 1] ?? '') === $quote) {
          $buffer .= $sql[++$i];
          continue;
        }
        $quote = null;
      }
      continue;
    }
    if (($char === '-' && $next === '-' && (($sql[$i + 2] ?? ' ') <= ' ')) || $char === '#') {
      $lineComment = true;
      $i += ($char === '-' ? 1 : 0);
      continue;
    }
    if ($char === '/' && $next === '*') {
      $blockComment = true;
      $i++;
      continue;
    }
    if ($char === "'" || $char === '"' || $char === '`') {
      $quote = $char;
      $buffer .= $char;
      continue;
    }
    if ($char === ';') {
      if (trim($buffer) !== '') $statements[] = trim($buffer);
      $buffer = '';
      continue;
    }
    $buffer .= $char;
  }
  if (trim($buffer) !== '') $statements[] = trim($buffer);
  return $statements;
}

function preflightExistingSchema(PDO $db): void
{
  $tables = array_flip($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
  $required = ['orders', 'order_details', 'cash_drawer_days', 'inventory_order_reservations', 'loyalty_points_ledger', 'addon_ingredients'];
  $missing = array_values(array_diff($required, array_keys($tables)));
  if ($missing) {
    throw new RuntimeException('Existing database is missing expected tables: ' . implode(', ', $missing) . '. Do not baseline it; apply/repair its older migrations first.');
  }
  $columns = $db->query('SHOW COLUMNS FROM order_details')->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('customization_note', $columns, true)) {
    throw new RuntimeException('Existing database schema is not at the expected baseline. Do not baseline it.');
  }
}

try {
  if ($command === 'baseline-existing') {
    preflightExistingSchema($db);
    $applied = migrationRows($db);
    $stmt = $db->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)');
    $baselineCount = 0;
    foreach ($files as $file) {
      $version = basename($file);
      if (str_starts_with($version, '2026_10_20_') || in_array($version, [
        '2026_10_23_high_priority_improvements.sql',
        '2026_10_23_manage_inventory_categories.sql',
        '2026_10_25_add_registration_conflict_recovery.sql',
      ], true)) continue;
      if (isset($applied[$version])) continue;
      $stmt->execute([$version, hash_file('sha256', $file)]);
      $baselineCount++;
    }
    echo "Baseline recorded for {$baselineCount} pre-existing migration(s). Now run: php database/migrate.php apply\n";
    exit(0);
  }

  $applied = migrationRows($db);
  if ($command === 'status') {
    foreach ($files as $file) {
      $version = basename($file);
      $state = isset($applied[$version]) ? 'applied' : 'pending';
      echo $state . "  " . $version . "\n";
    }
    exit(0);
  }
  if ($command !== 'apply') throw new InvalidArgumentException('Usage: php database/migrate.php [status|apply|baseline-existing]');
  if (!$applied && $files) {
    throw new RuntimeException('Migration history is empty. For an existing, already-up-to-date database, run baseline-existing once. Fresh installs should import database/kapehan_amang.sql, which includes migration history.');
  }

  foreach ($files as $file) {
    $version = basename($file);
    $checksum = hash_file('sha256', $file);
    if (isset($applied[$version])) {
      if (!hash_equals($applied[$version]['checksum'], $checksum)) {
        throw new RuntimeException("Applied migration {$version} has changed. Restore the original file and create a new migration instead.");
      }
      continue;
    }
    foreach (migrationSqlStatements((string) file_get_contents($file)) as $sql) $db->exec($sql);
    $db->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)')->execute([$version, $checksum]);
    echo "Applied {$version}\n";
  }
  echo "Migrations are up to date.\n";
} catch (Throwable $e) {
  fwrite(STDERR, 'Migration error: ' . $e->getMessage() . "\n");
  exit(1);
}
