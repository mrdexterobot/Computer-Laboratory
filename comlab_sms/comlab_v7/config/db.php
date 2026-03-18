<?php
// ============================================
// COMLAB - Database Configuration
// Supabase/PostgreSQL ready.
// The runtime SQL in this branch is Postgres-focused.
//
// Preferred environment variables for Supabase:
//   DATABASE_URL or SUPABASE_DB_URL
//   SUPABASE_DB_HOST
//   SUPABASE_DB_PORT
//   SUPABASE_DB_NAME
//   SUPABASE_DB_USER
//   SUPABASE_DB_PASS
//   SUPABASE_DB_SSLMODE
//
// Set DB_CONNECTION=pgsql to force Supabase/Postgres.
// ============================================

function comlabEnv(string $key, $default = null) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
    return $value === '' ? $default : $value;
}

function comlabDbDriver(): string {
    $explicit = strtolower((string) comlabEnv('DB_CONNECTION', ''));
    if ($explicit !== '') {
        return $explicit;
    }
    return 'pgsql';
}

function comlabDbConfig(): array {
    $driver = comlabDbDriver();

    if ($driver === 'pgsql') {
        $databaseUrl = (string) (comlabEnv('DATABASE_URL', '') ?: comlabEnv('SUPABASE_DB_URL', ''));
        if ($databaseUrl !== '') {
            $parts = parse_url($databaseUrl);
            if ($parts === false || empty($parts['host']) || empty($parts['path'])) {
                throw new RuntimeException('Invalid DATABASE_URL / SUPABASE_DB_URL value.');
            }

            return [
                'driver'   => 'pgsql',
                'host'     => $parts['host'],
                'port'     => (string) ($parts['port'] ?? 5432),
                'database' => ltrim($parts['path'], '/'),
                'username' => rawurldecode((string) ($parts['user'] ?? '')),
                'password' => rawurldecode((string) ($parts['pass'] ?? '')),
                'sslmode'  => (string) (comlabEnv('SUPABASE_DB_SSLMODE', 'require') ?: 'require'),
            ];
        }

        return [
            'driver'   => 'pgsql',
            'host'     => (string) (comlabEnv('SUPABASE_DB_HOST', comlabEnv('DB_HOST', 'localhost'))),
            'port'     => (string) (comlabEnv('SUPABASE_DB_PORT', comlabEnv('DB_PORT', '5432'))),
            'database' => (string) (comlabEnv('SUPABASE_DB_NAME', comlabEnv('DB_NAME', 'postgres'))),
            'username' => (string) (comlabEnv('SUPABASE_DB_USER', comlabEnv('DB_USER', 'postgres'))),
            'password' => (string) (comlabEnv('SUPABASE_DB_PASS', comlabEnv('DB_PASS', ''))),
            'sslmode'  => (string) (comlabEnv('SUPABASE_DB_SSLMODE', 'require') ?: 'require'),
        ];
    }

    return [
        'driver'   => 'mysql',
        'host'     => (string) comlabEnv('DB_HOST', 'localhost'),
        'port'     => (string) comlabEnv('DB_PORT', '3306'),
        'database' => (string) comlabEnv('DB_NAME', 'comlab_system'),
        'username' => (string) comlabEnv('DB_USER', 'root'),
        'password' => (string) comlabEnv('DB_PASS', ''),
        'charset'  => (string) comlabEnv('DB_CHARSET', 'utf8mb4'),
    ];
}

/**
 * Returns a singleton PDO connection.
 * Throws RuntimeException on failure (caught by callers).
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = comlabDbConfig();

    try {
        if ($config['driver'] === 'pgsql') {
            $dsn = sprintf(
                'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['sslmode']
            );
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );
        }

        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // Don't expose raw DB errors to client
        error_log('[COMLAB DB] Connection failed: ' . $e->getMessage());
        $hint = $config['driver'] === 'pgsql'
            ? 'Check your Supabase/Postgres settings in the environment.'
            : 'Check config/db.php settings.';
        throw new RuntimeException('Database connection failed. ' . $hint);
    }

    return $pdo;
}

/**
 * Postgres-friendly expression for matching a day name stored in a CSV field.
 * Example: Sunday stored in "Monday,Wednesday,Sunday".
 */
function sqlCsvContainsDay(string $column, string $placeholder = '?'): string {
    return sprintf(
        "%s = ANY(string_to_array(replace(%s, ' ', ''), ','))",
        $placeholder,
        $column
    );
}

/**
 * Create a CASE expression for custom sort order.
 * Example: ORDER BY "CASE status WHEN ... END".
 */
function sqlOrderCase(string $column, array $orderedValues): string {
    $cases = [];
    foreach (array_values($orderedValues) as $index => $value) {
        $escaped = str_replace("'", "''", (string) $value);
        $cases[] = "WHEN '{$escaped}' THEN " . ($index + 1);
    }

    return sprintf(
        'CASE %s %s ELSE %d END',
        $column,
        implode(' ', $cases),
        count($orderedValues) + 1
    );
}

/**
 * Insert a row and return the generated primary key.
 * Works best with PostgreSQL/Supabase.
 */
function insertReturningId(PDO $db, string $sql, array $params, string $returnColumn) {
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $stmt = $db->prepare($sql . ' RETURNING ' . $returnColumn);
        $stmt->execute($params);
        $id = $stmt->fetchColumn();
    } else {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $id = $db->lastInsertId();
    }

    if ($id === false) {
        throw new RuntimeException('Unable to fetch inserted record ID.');
    }

    return is_numeric($id) ? (int) $id : (string) $id;
}
