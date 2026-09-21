<?php
/**
 * includes/db_admin.php
 *
 * Full-database .sql backup + full-data reset, backing Account Settings'
 * Administrator-only "Database Management" card. No shell-out to the
 * mysqldump binary (often disabled on shared hosting, and not guaranteed
 * present even on a XAMPP box) — a minimal, dependency-free dump in pure
 * PHP/PDO, mirroring includes/xlsx_writer.php's same "no third-party
 * library" approach. The output is a plain CREATE TABLE + INSERT .sql
 * file, importable via phpMyAdmin/mysql CLI on any XAMPP install.
 */

declare(strict_types=1);

/**
 * Stream a full backup of the current database as a downloadable .sql
 * file. Never writes the dump to disk on the server — it's generated
 * and streamed straight to the browser, so no dump file is ever left
 * sitting in a web-accessible (or any) directory.
 */
function stream_sql_backup(PDO $pdo): void
{
    $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $filename = 'care_job_fair_db_backup_' . date('Ymd_His') . '.sql';

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    echo "-- CARE (Candidate Application & Registration for Employment) database backup\n";
    echo "-- Database: {$dbName}\n";
    echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Compatible with XAMPP / phpMyAdmin / mysql CLI import.\n\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n";
    echo "SET NAMES utf8mb4;\n\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        echo "-- --------------------------------------------------------\n";
        echo "-- Table: {$table}\n";
        echo "-- --------------------------------------------------------\n";
        echo "DROP TABLE IF EXISTS `{$table}`;\n";

        $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
        echo $createRow['Create Table'] . ";\n\n";

        $rowCount = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        if ($rowCount === 0) {
            continue;
        }

        $columns = array_column($pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(), 'Field');
        $columnList = '`' . implode('`, `', $columns) . '`';

        $chunkSize = 500;
        for ($offset = 0; $offset < $rowCount; $offset += $chunkSize) {
            $stmt = $pdo->query("SELECT * FROM `{$table}` LIMIT {$chunkSize} OFFSET {$offset}");
            $valueGroups = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $values = array_map(
                    fn($value) => $value === null ? 'NULL' : $pdo->quote((string)$value),
                    $row
                );
                $valueGroups[] = '(' . implode(', ', $values) . ')';
            }
            if ($valueGroups) {
                echo "INSERT INTO `{$table}` ({$columnList}) VALUES\n" . implode(",\n", $valueGroups) . ";\n";
            }
        }
        echo "\n";
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

/**
 * Wipes all Applicant / Employment / Job-Vacancy / Service-Availment /
 * Audit-Log data for a fresh cycle, while deliberately PRESERVING every
 * row in care_jf_users, care_jf_partner_agencies, and
 * care_jf_agency_services — accounts and agency configuration survive a
 * reset. This scope was a deliberate choice (confirmed with the
 * requester), not an oversight: wiping user accounts would lock every
 * admin out with no way back in short of restoring an earlier backup.
 * TRUNCATE (not DELETE) is used so AUTO_INCREMENT ids and the
 * per-month applicant-code counters in care_jf_id_sequences also start
 * fresh — matching the "start a new cycle" intent — and so FK ordering
 * doesn't matter (FOREIGN_KEY_CHECKS is disabled for the duration).
 * Returns a human-readable summary for the caller's flash message.
 */
function reset_application_records(PDO $pdo): string
{
    $tables = [
        'care_jf_service_availments',
        'care_jf_employment_records',
        'care_jf_job_vacancies',
        'care_jf_applicants',
        'care_jf_audit_logs',
        'care_jf_id_sequences',
        'care_jf_login_throttle',
    ];

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($tables as $table) {
            $pdo->exec("TRUNCATE TABLE `{$table}`");
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    return 'Applicants, employment records, job vacancies, service availments, and audit logs were cleared. '
         . 'User accounts and Partner Agency accounts were preserved.';
}
