<?php
/**
 * Connexion PDO et helpers BDD
 */

if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../config.php';
}

class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            try {
                self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                if (DEBUG_MODE) {
                    die('DB error: ' . $e->getMessage());
                }
                http_response_code(500);
                die('Database connection error');
            }
        }
        return self::$pdo;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function lastInsertId(): string
    {
        return self::pdo()->lastInsertId();
    }
}

// Helpers de configuration
function config_get(string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (DB::fetchAll('SELECT config_key, config_value FROM asso_blog_admin_config') as $row) {
            $cache[$row['config_key']] = $row['config_value'];
        }
    }
    return $cache[$key] ?? $default;
}

function config_set(string $key, string $value): void
{
    DB::execute(
        'INSERT INTO asso_blog_admin_config (config_key, config_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)',
        [$key, $value]
    );
}

// Logger
function admin_log(string $action, string $details = '', string $status = 'info'): void
{
    try {
        DB::execute(
            'INSERT INTO asso_blog_admin_logs (action, details, status, ip) VALUES (?, ?, ?, ?)',
            [
                substr($action, 0, 100),
                $details,
                $status,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    } catch (Throwable $e) {
        // Ne jamais bloquer l'app si le log échoue
    }
}
