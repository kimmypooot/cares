<?php
/**
 * Database connection (PDO) — config/database.php
 * Centralized, single connection point. Never expose credentials to output.
 */

declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    // ---- Update these for your environment (or use environment variables) ----
    private const DB_HOST = 'localhost';
    private const DB_NAME = 'applicant_system';
    private const DB_USER = 'root';
    private const DB_PASS = '';
    private const DB_CHARSET = 'utf8mb4';

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                self::DB_HOST,
                self::DB_NAME,
                self::DB_CHARSET
            );

            try {
                self::$instance = new PDO($dsn, self::DB_USER, self::DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
            } catch (PDOException $e) {
                // Never leak connection details to the browser.
                error_log('Database connection failed: ' . $e->getMessage());
                http_response_code(500);
                die('A system error occurred. Please try again later or contact the administrator.');
            }
        }

        return self::$instance;
    }
}
