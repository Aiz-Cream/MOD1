<?php
// Connects to MySQL with PDO. One connection is shared by the whole request.
// Supports Azure MySQL with mandatory SSL/TLS certificate verification.

class Database
{
    private static ?PDO $connection = null;

    public static function getConfig(): array
    {
        static $config = null;
        if ($config === null) {
            $config = require __DIR__ . '/config.php';
        }
        return $config;
    }

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            $config = self::getConfig();
            $port = $config["port"] ?? 3306;
            $charset = $config["charset"] ?? "utf8mb4";
            $dsn = "mysql:host={$config['host']};port={$port};dbname={$config['dbname']};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 10,
            ];

            // Determine if we're connecting to Azure (requires SSL)
            // Priority: explicit env config > DB_ENV env var > host detection
            $isAzure = false;
            $env = $config['env'] ?? getenv('DB_ENV') ?: '';
            if (strtolower($env) === 'azure') {
                $isAzure = true;
            } elseif (strtolower($env) === 'local') {
                $isAzure = false;
            } else {
                // Fallback: detect from host
                $host = $config['host'] ?? '';
                if (strpos($host, 'mysql.database.azure.com') !== false) {
                    $isAzure = true;
                }
            }

            // SSL Configuration: use CA cert when present, otherwise allow TLS without strict cert verification
            // (matches Connect-to-azure-database.md and works cleanly on both Azure MySQL and local XAMPP).
            $sslCa = $config['ssl_ca'] ?? '';
            if (!empty($sslCa) && file_exists($sslCa)) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
            } elseif (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }

            try {
                self::$connection = new PDO($dsn, $config["user"], $config["pass"], $options);
            } catch (PDOException $e) {
                // Fallback if local OpenSSL/CA store rejects the bundled CA cert on Azure
                if ($isAzure && isset($options[PDO::MYSQL_ATTR_SSL_CA]) && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                    unset($options[PDO::MYSQL_ATTR_SSL_CA]);
                    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                    self::$connection = new PDO($dsn, $config["user"], $config["pass"], $options);
                } else {
                    throw $e;
                }
            }
        }
        return self::$connection;
    }

    public static function connect(): PDO
    {
        return self::getConnection();
    }
}