<?php
/**
 * One-off data load: creates Partner Agency records + already-Active
 * accounts (one per agency) and imports their Vacant Positions, sourced
 * from resources/for-update.xlsx (Sheet1: agency list, Sheet2: vacant
 * positions). See docs/superpowers/ for the approved plan.
 *
 * Run once from the command line:
 *   php scripts/import-partner-agencies-2026-09.php
 *
 * Safe to re-run: agencies/vacancies already present (by exact name/
 * position/level/salary match) are skipped rather than duplicated.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    die('This script must be run from the command line.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = Database::getConnection();

// ---------------------------------------------------------------------
// Data extracted from resources/for-update.xlsx (Sheet1 + Sheet2),
// normalized (trimmed, whitespace-collapsed, Excel control-char escapes
// resolved) and grouped: identical (agency, position, level, salary)
// spreadsheet lines are collapsed into one row with 'count' = vacant_count.
// ---------------------------------------------------------------------
$agencyNames = [
    'City Government of Tacloban',
    'Civil Service Regional Office VIII',
    'Department of Agrarian Reform Region VIII',
    'Department of Agriculture Regional Field Office VIII',
    'Department of Environment and Natural Resources',
    'DepEd Schools Division of Tacloban City',
    'DOH - TREATMENT AND REHABILITATION CENTER, DULAG',
    'Department of Labor and Employment',
    'DSWD FIELD OFFICE VIII',
    'Eastern Visayas Medical Center',
    'Eastern Visayas State University',
    'LAND TRANSPORTATION OFFICE, ROVIII',
    'MGO-Burauen, Leyte',
    'PALOMPON INSTITUTE OF TECHNOLOGY',
    'Philippine Statistics Authority Regional Statistical Services Office VIII',
    'Technical Education and Skills Development Authority Region VIII',
];

// Confirmed by the Administrator: these spreadsheet names are the same
// real agencies already registered (with active accounts) under a
// different name — reuse their existing agency_id, don't create new
// agency/account rows for them.
$existingAgencyIdByName = [
    'Civil Service Regional Office VIII' => 1,
    'Philippine Statistics Authority Regional Statistical Services Office VIII' => 3,
];

$vacancies = [
    ['agency' => 'City Government of Tacloban', 'position' => 'Laboratory Technician I', 'level' => 'Plantilla Level 1', 'salary' => '6', 'count' => 1],
    ['agency' => 'City Government of Tacloban', 'position' => 'Administrative Assistant II (Public Relations Assistant)', 'level' => 'Plantilla Level 1', 'salary' => '8', 'count' => 2],
    ['agency' => 'City Government of Tacloban', 'position' => 'Social Welfare Officer I', 'level' => 'Plantilla Level 2', 'salary' => '11', 'count' => 6],
    ['agency' => 'City Government of Tacloban', 'position' => 'Driver', 'level' => 'Job Order', 'salary' => NULL, 'count' => 4],
    ['agency' => 'City Government of Tacloban', 'position' => 'Utility', 'level' => 'Job Order', 'salary' => NULL, 'count' => 1],
    ['agency' => 'City Government of Tacloban', 'position' => 'Administrative Aide', 'level' => 'Job Order', 'salary' => NULL, 'count' => 1],
    ['agency' => 'Civil Service Regional Office VIII', 'position' => 'Administrative Assistant III', 'level' => 'Plantilla Level 1', 'salary' => '11', 'count' => 1],
    ['agency' => 'Department of Agrarian Reform Region VIII', 'position' => 'Agrarian Reform Program Officer II', 'level' => 'Plantilla Level 2', 'salary' => '15', 'count' => 3],
    ['agency' => 'Department of Agrarian Reform Region VIII', 'position' => 'Legal Assistant II', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'Department of Agrarian Reform Region VIII', 'position' => 'Senior Agrarian Reform Program Technologist', 'level' => 'Plantilla Level 2', 'salary' => '14', 'count' => 7],
    ['agency' => 'Department of Agrarian Reform Region VIII', 'position' => 'Agrarian Reform Program Officer I', 'level' => 'Plantilla Level 2', 'salary' => '11', 'count' => 1],
    ['agency' => 'Department of Agrarian Reform Region VIII', 'position' => 'Agrarian Reform Program Technologist', 'level' => 'Plantilla Level 2', 'salary' => '10', 'count' => 3],
    ['agency' => 'Department of Agrarian Reform Region VIII', 'position' => 'Cartographer II', 'level' => 'Plantilla Level 1', 'salary' => '8', 'count' => 1],
    ['agency' => 'Department of Agriculture Regional Field Office VIII', 'position' => 'Senior Administrative Assistant I', 'level' => 'COS', 'salary' => '13', 'count' => 1],
    ['agency' => 'Department of Agriculture Regional Field Office VIII', 'position' => 'Project Assistant IV', 'level' => 'Job Order', 'salary' => NULL, 'count' => 3],
    ['agency' => 'Department of Agriculture Regional Field Office VIII', 'position' => 'Utility Worker II (Plumber)', 'level' => 'COS', 'salary' => '3', 'count' => 1],
    ['agency' => 'Department of Agriculture Regional Field Office VIII', 'position' => 'Project Assistant I', 'level' => 'COS', 'salary' => '8', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Administrative Assistant II (Artist Illustrator II)', 'level' => 'Plantilla Level 1', 'salary' => '8', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Information Systems Analyst III', 'level' => 'Plantilla Level 2', 'salary' => '19', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Statistician II', 'level' => 'Plantilla Level 2', 'salary' => '15', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Administrative Officer IV(Management and Audit Analyst II)', 'level' => 'Plantilla Level 2', 'salary' => '15', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Administrative Assistant I', 'level' => 'Plantilla Level 1', 'salary' => '7', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Administrative Officer V (Budget Officer III)', 'level' => 'Plantilla Level 2', 'salary' => '18', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Administrative Assistant III (Senior Bookkeeper)', 'level' => 'Plantilla Level 1', 'salary' => '9', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Administrative Assistant II (Accounting Clerk III)', 'level' => 'Plantilla Level 1', 'salary' => '8', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Administrative Assistant II (Budgeting Assistant)', 'level' => 'Plantilla Level 1', 'salary' => '8', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Administrative Officer IV (Human Resource Management Officer II)', 'level' => 'Plantilla Level 2', 'salary' => '15', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Administrative Officer II (Administrative Officer I)', 'level' => 'Plantilla Level 2', 'salary' => '11', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Administrative Assistant II (Clerk IV)', 'level' => 'Plantilla Level 1', 'salary' => '8', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Administrative Assistant II (Property Custodian)', 'level' => 'Plantilla Level 1', 'salary' => '8', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Administrative Assistant I (Computer Operator I)', 'level' => 'Plantilla Level 1', 'salary' => '7', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Legal Assistant II', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Farm Supervisor', 'level' => 'Plantilla Level 1', 'salary' => '8', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Engineer III', 'level' => 'Plantilla Level 2', 'salary' => '19', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Forest Management Specialist II', 'level' => 'Plantilla Level 2', 'salary' => '15', 'count' => 4],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Cartographer II', 'level' => 'Plantilla Level 1', 'salary' => '8', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Mathematician Aide II', 'level' => 'Plantilla Level 1', 'salary' => '8', 'count' => 2],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Administrative Aide VI (Clerk III)', 'level' => 'Plantilla Level 1', 'salary' => '6', 'count' => 3],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Cartographer I', 'level' => 'Plantilla Level 1', 'salary' => '6', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Mathematician Aide I', 'level' => 'Plantilla Level 1', 'salary' => '6', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Engineering Aide', 'level' => 'Plantilla Level 1', 'salary' => '4', 'count' => 2],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Development Management Officer IV', 'level' => 'Plantilla Level 2', 'salary' => '22', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Economist I', 'level' => 'Plantilla Level 2', 'salary' => '11', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Administrative Officer I (Records Officer I)', 'level' => 'Plantilla Level 2', 'salary' => '10', 'count' => 1],
    ['agency' => 'Department of Environment and Natural Resources', 'position' => 'Forest Ranger', 'level' => 'Plantilla Level 1', 'salary' => '4', 'count' => 1],
    ['agency' => 'DepEd Schools Division of Tacloban City', 'position' => 'Administrative Assistant III', 'level' => 'Plantilla Level 1', 'salary' => '9', 'count' => 1],
    ['agency' => 'DOH - TREATMENT AND REHABILITATION CENTER, DULAG', 'position' => 'ADMINISTRATIVE OFFICER V', 'level' => 'Plantilla Level 2', 'salary' => '18', 'count' => 1],
    ['agency' => 'Department of Labor and Employment', 'position' => 'Administrative Officer V/ Budget Officer III', 'level' => 'Plantilla Level 2', 'salary' => '18', 'count' => 1],
    ['agency' => 'Department of Labor and Employment', 'position' => 'Administrative Officer V/ Cashier III', 'level' => 'Plantilla Level 2', 'salary' => '18', 'count' => 1],
    ['agency' => 'Department of Labor and Employment', 'position' => 'GIP', 'level' => 'GIP', 'salary' => NULL, 'count' => 5],
    ['agency' => 'DSWD FIELD OFFICE VIII', 'position' => 'SOCIAL WELFARE OFFICER III', 'level' => 'COS', 'salary' => '18', 'count' => 2],
    ['agency' => 'DSWD FIELD OFFICE VIII', 'position' => 'SOCIAL WELFARE OFFICER II', 'level' => 'COS', 'salary' => '15', 'count' => 4],
    ['agency' => 'DSWD FIELD OFFICE VIII', 'position' => 'PROJECT DEVELOPMENT OFFICER III (Area Coordinator)', 'level' => 'COS', 'salary' => '18', 'count' => 1],
    ['agency' => 'DSWD FIELD OFFICE VIII', 'position' => 'PROJECT DEVELOPMENT OFFICER II (Community Empowerment Facilitator)', 'level' => 'COS', 'salary' => '15', 'count' => 1],
    ['agency' => 'DSWD FIELD OFFICE VIII', 'position' => 'TECHNICAL FACILITATOR', 'level' => 'COS', 'salary' => '17', 'count' => 1],
    ['agency' => 'Eastern Visayas Medical Center', 'position' => 'Medical Officer IV', 'level' => 'Plantilla Level 2', 'salary' => '23', 'count' => 4],
    ['agency' => 'Eastern Visayas Medical Center', 'position' => 'Medical Officer III', 'level' => 'Plantilla Level 2', 'salary' => '21', 'count' => 3],
    ['agency' => 'Eastern Visayas Medical Center', 'position' => 'Psychologist II', 'level' => 'Plantilla Level 2', 'salary' => '18', 'count' => 1],
    ['agency' => 'Eastern Visayas Medical Center', 'position' => 'Occupational Therapist II', 'level' => 'Plantilla Level 2', 'salary' => '15', 'count' => 1],
    ['agency' => 'Eastern Visayas Medical Center', 'position' => 'Midwife III', 'level' => 'Plantilla Level 2', 'salary' => '13', 'count' => 1],
    ['agency' => 'Eastern Visayas Medical Center', 'position' => 'Medical Equipment Technician IV', 'level' => 'Plantilla Level 2', 'salary' => '13', 'count' => 1],
    ['agency' => 'Eastern Visayas Medical Center', 'position' => 'Pharmacist I', 'level' => 'Plantilla Level 2', 'salary' => '11', 'count' => 1],
    ['agency' => 'Eastern Visayas Medical Center', 'position' => 'Occupational Therapist I', 'level' => 'Plantilla Level 2', 'salary' => '11', 'count' => 5],
    ['agency' => 'Eastern Visayas Medical Center', 'position' => 'Occupational Therapist I', 'level' => 'Plantilla Level 2', 'salary' => '10', 'count' => 1],
    ['agency' => 'Eastern Visayas Medical Center', 'position' => 'Health Education And Promotion Officer I', 'level' => 'Plantilla Level 2', 'salary' => '10', 'count' => 1],
    ['agency' => 'Eastern Visayas Medical Center', 'position' => 'Dental Hygienist', 'level' => 'Plantilla Level 2', 'salary' => '10', 'count' => 1],
    ['agency' => 'Eastern Visayas Medical Center', 'position' => 'Speech Therapist I (Part-Time)', 'level' => 'Plantilla Level 2', 'salary' => '10', 'count' => 1],
    ['agency' => 'Eastern Visayas Medical Center', 'position' => 'Speech Therapist I (Part-Time)', 'level' => 'Plantilla Level 2', 'salary' => '7', 'count' => 1],
    ['agency' => 'Eastern Visayas Medical Center', 'position' => 'Ward Assistant', 'level' => 'Plantilla Level 1', 'salary' => '7', 'count' => 2],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Associate Professor V (Guidance and Counselling)', 'level' => 'Plantilla Level 2', 'salary' => '22', 'count' => 1],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Instructor III (Guidance and Counselling)', 'level' => 'Plantilla Level 2', 'salary' => '15', 'count' => 1],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Instructor III (Electronics Engineering)', 'level' => 'Plantilla Level 2', 'salary' => '15', 'count' => 1],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Instructor I (Industrial Engineering)', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Instructor I (Geodetic Engineering)', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Instructor I (Natural Science)', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Instructor I (Economics)', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Instructor I (Culture and Arts)', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Instructor I (Science)', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 2],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Instructor I (Accounting)', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 2],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Instructor I (Entreprenuership)', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Instructor I (Nutrition and Dietitics)', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Instructor I (Elem. Education)', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Guidance Counselor II', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Guidance Counselor I', 'level' => 'Plantilla Level 2', 'salary' => '11', 'count' => 1],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Dental Aide', 'level' => 'COS', 'salary' => NULL, 'count' => 1],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Medical Aide', 'level' => 'COS', 'salary' => NULL, 'count' => 1],
    ['agency' => 'Eastern Visayas State University', 'position' => 'Admin. Aide I', 'level' => 'COS', 'salary' => NULL, 'count' => 5],
    ['agency' => 'LAND TRANSPORTATION OFFICE, ROVIII', 'position' => 'Administrative Aide IV (Clerk II)', 'level' => 'Plantilla Level 1', 'salary' => '17506', 'count' => 1],
    ['agency' => 'LAND TRANSPORTATION OFFICE, ROVIII', 'position' => 'Administrative Aide VI (Clerk III)', 'level' => 'Plantilla Level 1', 'salary' => '19716', 'count' => 1],
    ['agency' => 'MGO-Burauen, Leyte', 'position' => 'Administrative Officer III (Supply Officer II)', 'level' => 'Plantilla Level 2', 'salary' => '14', 'count' => 1],
    ['agency' => 'PALOMPON INSTITUTE OF TECHNOLOGY', 'position' => 'ADMINISTRATIVE OFFICER V (Administrative Officer III)', 'level' => 'Plantilla Level 2', 'salary' => '18', 'count' => 1],
    ['agency' => 'PALOMPON INSTITUTE OF TECHNOLOGY', 'position' => 'DORMITORY MANAGER III', 'level' => 'Plantilla Level 2', 'salary' => '15', 'count' => 1],
    ['agency' => 'PALOMPON INSTITUTE OF TECHNOLOGY', 'position' => 'GUIDANCE COUNSELOR II', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'PALOMPON INSTITUTE OF TECHNOLOGY', 'position' => 'GUIDANCE COUNSELOR I', 'level' => 'Plantilla Level 2', 'salary' => '11', 'count' => 1],
    ['agency' => 'PALOMPON INSTITUTE OF TECHNOLOGY', 'position' => 'INSTRUCTOR I (BA Communication)', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'PALOMPON INSTITUTE OF TECHNOLOGY', 'position' => 'INSTRUCTOR I (Home Economics)', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'PALOMPON INSTITUTE OF TECHNOLOGY', 'position' => 'INSTRUCTOR I (Librarian)', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'PALOMPON INSTITUTE OF TECHNOLOGY', 'position' => 'TEACHING PERSONNEL UNDER CONTRACT OF SERVICE', 'level' => 'COS', 'salary' => 'N/A', 'count' => 1],
    ['agency' => 'Philippine Statistics Authority Regional Statistical Services Office VIII', 'position' => 'Accountant I', 'level' => 'Plantilla Level 2', 'salary' => '13', 'count' => 1],
    ['agency' => 'Philippine Statistics Authority Regional Statistical Services Office VIII', 'position' => 'Registration Officer II', 'level' => 'Plantilla Level 2', 'salary' => '14', 'count' => 1],
    ['agency' => 'Technical Education and Skills Development Authority Region VIII', 'position' => 'Instructor I (TESDAB-INST1-2-2020)', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'Technical Education and Skills Development Authority Region VIII', 'position' => 'Instructor I (TESDAB-INST1-540004-2022)', 'level' => 'Plantilla Level 2', 'salary' => '12', 'count' => 1],
    ['agency' => 'Technical Education and Skills Development Authority Region VIII', 'position' => 'Guidance Counselor III', 'level' => 'Plantilla Level 2', 'salary' => '13', 'count' => 1],
    ['agency' => 'Technical Education and Skills Development Authority Region VIII', 'position' => 'Administrative Officer V (Human Resource Management Officer III)', 'level' => 'Plantilla Level 2', 'salary' => '18', 'count' => 2],
    ['agency' => 'Technical Education and Skills Development Authority Region VIII', 'position' => 'Technical Education and Skills Development Specialist II', 'level' => 'Plantilla Level 2', 'salary' => '16', 'count' => 2],
    ['agency' => 'Technical Education and Skills Development Authority Region VIII', 'position' => 'Administrative Officer II (Financial Analyst I)', 'level' => 'Plantilla Level 2', 'salary' => '11', 'count' => 1],
    ['agency' => 'Technical Education and Skills Development Authority Region VIII', 'position' => 'Guidance Counselor I', 'level' => 'Plantilla Level 2', 'salary' => '11', 'count' => 1],
    ['agency' => 'Technical Education and Skills Development Authority Region VIII', 'position' => 'Technical Education and Skills Development Specialist I', 'level' => 'Plantilla Level 2', 'salary' => '13', 'count' => 2],
    ['agency' => 'Technical Education and Skills Development Authority Region VIII', 'position' => 'Administrative Officer IV (Human Resource Management Officer II)', 'level' => 'Plantilla Level 2', 'salary' => '15', 'count' => 1],
    ['agency' => 'Technical Education and Skills Development Authority Region VIII', 'position' => 'Assistant Professor I (TESDAB-AP1-182-2017)', 'level' => 'Plantilla Level 2', 'salary' => '15', 'count' => 1],
];

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function slugify_username(string $agencyName, array $taken): string
{
    $slug = strtolower($agencyName);
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
    $slug = trim($slug, '_');
    $slug = substr($slug, 0, 30);
    if ($slug === '') $slug = 'agency';

    $candidate = $slug;
    $suffix = 2;
    while (in_array($candidate, $taken, true)) {
        $candidate = substr($slug, 0, 30 - strlen((string)$suffix) - 1) . '_' . $suffix;
        $suffix++;
    }
    return $candidate;
}

function random_temp_password(): string
{
    return bin2hex(random_bytes(6)); // 12 hex chars, satisfies the >=8 char minimum
}

// ---------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------
$actorId = (int)($pdo->query("SELECT id FROM care_jf_users WHERE role = 'Administrator' ORDER BY id LIMIT 1")->fetchColumn() ?: 0) ?: null;

$takenUsernames = $pdo->query("SELECT username FROM care_jf_users")->fetchAll(PDO::FETCH_COLUMN);

$createdCredentials = []; // agency_name => [username, password, employer_id]
$agencyIdByName = $existingAgencyIdByName;

$pdo->beginTransaction();
try {
    foreach ($agencyNames as $agencyName) {
        if (isset($agencyIdByName[$agencyName])) {
            continue; // reusing an existing agency — no new agency/account
        }

        // Skip re-creating if a prior run already inserted this agency
        // (idempotent re-run support).
        $dup = $pdo->prepare("SELECT id FROM care_jf_partner_agencies WHERE agency_name = :n");
        $dup->execute([':n' => mb_strtoupper($agencyName, 'UTF-8')]);
        $existingId = $dup->fetchColumn();
        if ($existingId) {
            $agencyIdByName[$agencyName] = (int)$existingId;
            continue;
        }

        $upperName = mb_strtoupper($agencyName, 'UTF-8');
        $pdo->prepare("INSERT INTO care_jf_partner_agencies (agency_name, address, contact_person, contact_no) VALUES (:n, '', '', '')")
            ->execute([':n' => $upperName]);
        $newAgencyId = (int)$pdo->lastInsertId();
        $agencyIdByName[$agencyName] = $newAgencyId;
        audit_log($pdo, $actorId, 'CREATE', 'care_jf_partner_agencies', $newAgencyId, "Created Partner Agency: {$upperName} (bulk import)");

        $username = slugify_username($agencyName, $takenUsernames);
        $takenUsernames[] = $username;
        $password = random_temp_password();

        $pdo->prepare(
            "INSERT INTO care_jf_users (username, password, full_name, role, status, is_active, agency_id, is_primary)
             VALUES (:u, :p, :f, 'Partner Agency', 'Active', 1, :aid, 1)"
        )->execute([
            ':u' => $username, ':p' => password_hash($password, PASSWORD_DEFAULT),
            ':f' => $upperName, ':aid' => $newAgencyId,
        ]);
        $newUserId = (int)$pdo->lastInsertId();
        audit_log($pdo, $actorId, 'CREATE', 'care_jf_users', $newUserId, "Created and activated Partner Agency account {$username} for {$upperName} (bulk import)");

        $employerId = generate_employer_id($pdo);
        $pdo->prepare("UPDATE care_jf_partner_agencies SET employer_id = :eid WHERE id = :id")
            ->execute([':eid' => $employerId, ':id' => $newAgencyId]);
        audit_log($pdo, $actorId, 'EMPLOYER_ID_GENERATED', 'care_jf_partner_agencies', $newAgencyId, "Employer ID {$employerId} generated (bulk import)");

        $createdCredentials[$agencyName] = ['username' => $username, 'password' => $password, 'employer_id' => $employerId];
    }

    $vacancyInsertCount = 0;
    $vacancySkipCount = 0;
    foreach ($vacancies as $v) {
        if (!isset($agencyIdByName[$v['agency']])) {
            throw new RuntimeException("No agency_id resolved for '{$v['agency']}' — aborting.");
        }
        $agencyId = $agencyIdByName[$v['agency']];

        // Idempotent re-run support: skip an exact (agency, position, level, salary) match.
        $dupCheck = $pdo->prepare(
            "SELECT id FROM care_jf_job_vacancies WHERE agency_id = :aid AND position = :pos AND job_level = :lvl
             AND (salary_grade <=> :sal)"
        );
        $dupCheck->execute([':aid' => $agencyId, ':pos' => $v['position'], ':lvl' => $v['level'], ':sal' => $v['salary']]);
        if ($dupCheck->fetchColumn()) {
            $vacancySkipCount++;
            continue;
        }

        $pdo->prepare(
            "INSERT INTO care_jf_job_vacancies (agency_id, title, position, job_level, salary_grade, vacant_count, status)
             VALUES (:aid, 'Job Available', :pos, :lvl, :sal, :vac, 'Active')"
        )->execute([
            ':aid' => $agencyId, ':pos' => $v['position'], ':lvl' => $v['level'],
            ':sal' => $v['salary'], ':vac' => $v['count'],
        ]);
        $newVacancyId = (int)$pdo->lastInsertId();
        audit_log($pdo, $actorId, 'VACANCY_CREATE', 'care_jf_job_vacancies', $newVacancyId, "Created vacancy: {$v['position']} (bulk import)");
        $vacancyInsertCount++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Import failed, rolled back: " . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------
echo "Agencies created: " . count($createdCredentials) . "\n";
echo "Agencies reused (existing): " . count($existingAgencyIdByName) . "\n";
echo "Vacancies inserted: {$vacancyInsertCount}, skipped as already present: {$vacancySkipCount}\n\n";

if ($createdCredentials) {
    echo "New Partner Agency credentials (distribute securely, then advise each agency to change their password):\n";
    echo str_pad('Agency', 55) . str_pad('Username', 22) . str_pad('Password', 16) . "Employer ID\n";
    foreach ($createdCredentials as $agencyName => $c) {
        echo str_pad(substr($agencyName, 0, 54), 55) . str_pad($c['username'], 22) . str_pad($c['password'], 16) . $c['employer_id'] . "\n";
    }

    $csvPath = __DIR__ . '/partner-agency-credentials-2026-09.csv';
    $fh = fopen($csvPath, 'w');
    fputcsv($fh, ['Agency Name', 'Username', 'Temporary Password', 'Employer ID']);
    foreach ($createdCredentials as $agencyName => $c) {
        fputcsv($fh, [$agencyName, $c['username'], $c['password'], $c['employer_id']]);
    }
    fclose($fh);
    echo "\nAlso written to: {$csvPath}\n";
    echo "This CSV contains plaintext passwords — do not commit it. Delete it once credentials are distributed.\n";
}
