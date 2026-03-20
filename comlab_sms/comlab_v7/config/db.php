<?php
// ============================================
// COMLAB - Database Configuration
// Supabase only.
// Loads credentials from the project .env file
// so Apache/PHP does not need OS-level env setup.
// ============================================

function comlabLoadEnv(): void {
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $envFiles = [
        dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env',
        dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env',
    ];

    foreach ($envFiles as $envFile) {
        if (!is_file($envFile) || !is_readable($envFile)) {
            continue;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            continue;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            $value = trim($value);
            $length = strlen($value);
            if (
                $length >= 2 &&
                (($value[0] === '"' && $value[$length - 1] === '"') ||
                 ($value[0] === "'" && $value[$length - 1] === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if (getenv($key) === false) {
                putenv($key . '=' . $value);
            }
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        return;
    }
}

function comlabEnv(string $key, $default = null) {
    comlabLoadEnv();

    $value = getenv($key);
    if ($value === false || $value === '') {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }

    return $value === '' ? $default : $value;
}

function comlabDbDriver(): string {
    return 'pgsql';
}

function comlabDbSchema(): string {
    $schema = (string) comlabEnv('SUPABASE_DB_SCHEMA', 'comlab');
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema)) {
        throw new RuntimeException('Invalid SUPABASE_DB_SCHEMA value.');
    }

    return $schema;
}

function comlabAuditLogTable(): string {
    return 'audit_logs';
}

function comlabQualifySql(string $sql): string {
    static $objects = [
        'audit_logs',
        'cashier_integration_events',
        'cashier_payment_links',
        'clinic_cashier_audit_logs',
        'clinic_cashier_sync_logs',
        'dashboard_summary',
        'department_clearance_records',
        'department_flow_profiles',
        'departments',
        'device_maintenance_logs',
        'devices',
        'faculty_presence_summary',
        'faculty_schedules',
        'integration_documents',
        'integration_message_feed',
        'integration_record_types',
        'integration_route_summary',
        'integration_routes',
        'lab_usage_logs',
        'locations',
        'login_attempts',
        'requests',
        'schedule_attendance',
        'user_sessions',
        'users',
    ];

    $schema = comlabDbSchema();
    $pattern = '/(\b(?:FROM|JOIN|UPDATE|INTO|DELETE\s+FROM|TRUNCATE(?:\s+TABLE)?)\s+)([A-Za-z_][A-Za-z0-9_]*)(?=(?:\s|\(|$))/i';

    return preg_replace_callback($pattern, static function (array $matches) use ($objects, $schema): string {
        if (!in_array(strtolower($matches[2]), $objects, true)) {
            return $matches[0];
        }

        return $matches[1] . $schema . '.' . $matches[2];
    }, $sql) ?? $sql;
}

class ComlabPdo extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(comlabQualifySql($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $query = comlabQualifySql($query);
        if ($fetchMode === null) {
            return parent::query($query);
        }

        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false {
        return parent::exec(comlabQualifySql($statement));
    }
}

function comlabDbConfig(): array {
    $databaseUrl = (string) (comlabEnv('DATABASE_URL', '') ?: comlabEnv('SUPABASE_DB_URL', ''));
    if ($databaseUrl !== '') {
        $parts = parse_url($databaseUrl);
        if (
            $parts === false ||
            empty($parts['scheme']) ||
            empty($parts['host']) ||
            empty($parts['path']) ||
            empty($parts['user'])
        ) {
            throw new RuntimeException('Invalid Supabase DATABASE_URL / SUPABASE_DB_URL value.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['postgres', 'postgresql'], true)) {
            throw new RuntimeException('Supabase DATABASE_URL must use the postgres:// or postgresql:// scheme.');
        }

        return [
            'driver'   => 'pgsql',
            'host'     => (string) $parts['host'],
            'port'     => (string) ($parts['port'] ?? 5432),
            'database' => ltrim((string) $parts['path'], '/'),
            'username' => rawurldecode((string) $parts['user']),
            'password' => rawurldecode((string) ($parts['pass'] ?? '')),
            'sslmode'  => (string) (comlabEnv('SUPABASE_DB_SSLMODE', 'require') ?: 'require'),
        ];
    }

    $host = (string) comlabEnv('SUPABASE_DB_HOST', '');
    $port = (string) comlabEnv('SUPABASE_DB_PORT', '5432');
    $name = (string) comlabEnv('SUPABASE_DB_NAME', '');
    $user = (string) comlabEnv('SUPABASE_DB_USER', '');
    $pass = (string) comlabEnv('SUPABASE_DB_PASS', '');

    if ($host === '' || $name === '' || $user === '') {
        throw new RuntimeException(
            'Supabase database settings are missing. Set DATABASE_URL or the SUPABASE_DB_HOST, ' .
            'SUPABASE_DB_NAME, SUPABASE_DB_USER, and SUPABASE_DB_PASS values in Computer-Laboratory/comlab_sms/.env.'
        );
    }

    return [
        'driver'   => 'pgsql',
        'host'     => $host,
        'port'     => $port,
        'database' => $name,
        'username' => $user,
        'password' => $pass,
        'sslmode'  => (string) (comlabEnv('SUPABASE_DB_SSLMODE', 'require') ?: 'require'),
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

    if (!extension_loaded('pdo_pgsql')) {
        throw new RuntimeException(
            'Supabase requires the PDO PostgreSQL driver. Enable the pdo_pgsql and pgsql extensions in your PHP runtime.'
        );
    }

    $config = comlabDbConfig();

    try {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['sslmode']
        );

        $pdo = new ComlabPdo($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Supabase's pooler works more reliably with client-side prepares.
            PDO::ATTR_EMULATE_PREPARES   => true,
        ]);

        $schema = str_replace('"', '""', comlabDbSchema());
        $pdo->exec(sprintf('SET search_path TO "%s", public', $schema));
    } catch (PDOException $e) {
        // Don't expose raw DB errors to client
        error_log('[COMLAB DB] Connection failed: ' . $e->getMessage());
        throw new RuntimeException('Database connection failed. Check your Supabase/Postgres settings in the environment.');
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
    $stmt = $db->prepare($sql . ' RETURNING ' . $returnColumn);
    $stmt->execute($params);
    $id = $stmt->fetchColumn();

    if ($id === false) {
        throw new RuntimeException('Unable to fetch inserted record ID.');
    }

    return is_numeric($id) ? (int) $id : (string) $id;
}
