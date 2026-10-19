<?php
/**
 * JARK University Enrollment System
 * functions.php — Core Backend Functions (OOP Refactored)
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─── Constants ────────────────────────────────────────────────────────────────
define('DATA_DIR',         __DIR__ . '/data/');
define('USERS_FILE',       DATA_DIR . 'users.json');
define('COURSES_FILE',     DATA_DIR . 'courses.json');
define('ENROLLMENTS_FILE', DATA_DIR . 'enrollments.json');
define('GRADES_FILE',      DATA_DIR . 'grades.json');
define('SEMESTERS_FILE',   DATA_DIR . 'semesters.json');

define('APP_NAME',  'JARK University');
define('APP_SHORT', 'JARKU');
define('APP_TAGLINE', 'Excellence · Innovation · Service');

// Include OOP classes
require_once __DIR__ . '/enrollment-system.php';

// ─── JSON Helpers ─────────────────────────────────────────────────────────────
function readJSON(string $file): array {
    if (!file_exists($file)) return [];
    $raw = file_get_contents($file);
    if ($raw === false) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function isEligibleForCourse(string $studentId, string $courseId): bool {
    $grades = readJSON(GRADES_FILE);
    $allCourses = readJSON(COURSES_FILE);
    
    // 1. Find the target course data
    $targetCourse = null;
    foreach ($allCourses as $c) {
        if ($c['id'] === $courseId) {
            $targetCourse = $c;
            break;
        }
    }
    
    if (!$targetCourse || empty($targetCourse['prerequisites'])) {
        return true; // No prerequisites, always eligible
    }
    
    // 2. Check if the student passed all prerequisites
    foreach ($targetCourse['prerequisites'] as $prereqId) {
        $passed = false;
        foreach ($grades as $g) {
            if ($g['studentId'] === $studentId && $g['courseId'] === $prereqId) {
                // If they passed (grade <= 3.00), they are okay
                if ($g['remarks'] === 'Passed') {
                    $passed = true;
                    break;
                }
            }
        }
        
        // If they failed or never took it, they cannot enroll
        if (!$passed) {
            return false;
        }
    }
    
    return true;
}

function getCourseNameById(string $courseId): string {
    $courses = readJSON(COURSES_FILE);
    foreach ($courses as $c) {
        if ($c['id'] === $courseId) return $c['name'];
    }
    return "Unknown Course";
}

function writeJSON(string $file, array $data): bool {
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $result = file_put_contents(
        $file,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    return $result !== false;
}

// ─── Authentication ───────────────────────────────────────────────────────────
function authenticateStudent(string $studentId, string $password): ?array {
    $studentId = strtoupper(trim($studentId));
    $password  = normalizeStudentPassword($password);

    foreach (readJSON(USERS_FILE) as $user) {
        if (
            strtoupper($user['username']) === $studentId &&
            strtoupper($user['password']) === $password &&
            $user['role'] === 'student'
        ) {
            return $user;
        }
    }
    return null;
}

function authenticateStaff(string $username, string $password): ?array {
    $username = trim($username);
    $password = trim($password);

    foreach (readJSON(USERS_FILE) as $user) {
        if (
            $user['username'] === $username &&
            $user['password'] === $password &&
            in_array($user['role'], ['professor', 'registrar'], true)
        ) {
            return $user;
        }
    }
    return null;
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function requireLogin(string $redirectTo = 'index.php'): void {
    if (!isLoggedIn()) redirect($redirectTo);
}

function requireRole(string|array $roles, string $redirectTo = 'index.php'): void {
    requireLogin($redirectTo);
    if (!in_array($_SESSION['role'], (array)$roles, true)) redirect($redirectTo);
}

function sessionLogin(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['firstName']  = $user['firstName'];
    $_SESSION['lastName']   = $user['lastName'];
    $_SESSION['email']      = $user['email'] ?? '';
    $_SESSION['title']      = $user['title'] ?? '';
    if (isset($user['program']))    $_SESSION['program']    = $user['program'];
    if (isset($user['yearLevel']))  $_SESSION['yearLevel']  = $user['yearLevel'];
    if (isset($user['department'])) $_SESSION['department'] = $user['department'];
    if (isset($user['section']))    $_SESSION['section']    = $user['section'];
}

function sessionLogout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    
    $dbUser = getUserById($_SESSION['user_id']);
    if ($dbUser !== null) {
        return $dbUser;
    }
    
    // Safety Fallback: Build an array using session variables if database reads slip
    return [
        'id'               => $_SESSION['user_id'],
        'username'         => $_SESSION['username'],
        'role'             => $_SESSION['role'],
        'firstName'        => $_SESSION['firstName'],
        'lastName'         => $_SESSION['lastName'],
        'email'            => $_SESSION['email'] ?? '',
        'program'          => $_SESSION['program'] ?? 'BSCS',
        'yearLevel'        => $_SESSION['yearLevel'] ?? 1,
        'section'          => $_SESSION['section'] ?? '',
        'enrollmentStatus' => $_SESSION['enrollmentStatus'] ?? 'not-enrolled'
    ];
}


function sessionName(): string {
    $title = $_SESSION['title'] ?? '';
    $first = $_SESSION['firstName'] ?? '';
    $last  = $_SESSION['lastName'] ?? '';
    return trim("$title $first $last");
}

function sessionFullName(): string {
    return ($_SESSION['firstName'] ?? '') . ' ' . ($_SESSION['lastName'] ?? '');
}

// ─── User CRUD ────────────────────────────────────────────────────────────────
function getUserById(string $id): ?array {
    foreach (readJSON(USERS_FILE) as $user) {
        if ($user['id'] === $id) return $user;
    }
    return null;
}

function getUserByUsername(string $username): ?array {
    foreach (readJSON(USERS_FILE) as $user) {
        if ($user['username'] === $username) return $user;
    }
    return null;
}

function getAllStudents(): array {
    return array_values(array_filter(
        readJSON(USERS_FILE),
        fn($u) => $u['role'] === 'student'
    ));
}

function getAllProfessors(): array {
    return array_values(array_filter(
        readJSON(USERS_FILE),
        fn($u) => $u['role'] === 'professor'
    ));
}

function getAllStaff(): array {
    return array_values(array_filter(
        readJSON(USERS_FILE),
        fn($u) => in_array($u['role'], ['professor', 'registrar'], true)
    ));
}

function createStudentCredential(array $data): array {
    $users = readJSON(USERS_FILE);

    $firstName = sanitize($data['firstName'] ?? '');
    $lastName  = sanitize($data['lastName'] ?? '');
    $email     = sanitize($data['email'] ?? '');
    $program   = sanitize($data['program'] ?? '');
    $phone     = sanitize($data['phone'] ?? '');
    $birthday  = sanitize($data['birthday'] ?? '');
    $address   = sanitize($data['address'] ?? '');
    $section   = sanitize($data['section'] ?? '');
    $yearLevel = max(1, min(5, (int)($data['yearLevel'] ?? 1)));

    if ($firstName === '' || $lastName === '' || $email === '' || $program === '') {
        return ['success' => false, 'message' => 'First name, last name, email, and program are required.'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'Please enter a valid email address.'];
    }

    if (!array_key_exists($program, allPrograms())) {
        return ['success' => false, 'message' => 'Please select a valid program.'];
    }

    foreach ($users as $u) {
        if (strtolower((string)($u['email'] ?? '')) === strtolower($email)) {
            return ['success' => false, 'message' => 'Email address is already registered.'];
        }
    }

    $year = date('Y');
    $students = array_filter($users, fn($u) => $u['role'] === 'student');
    $yearStudents = array_filter($students, fn($u) => str_starts_with($u['id'], $year . '-'));
    $count = count($yearStudents) + 1;
    $newId = $year . '-' . str_pad($count, 4, '0', STR_PAD_LEFT) . '-A';

    $password = normalizeStudentPassword($lastName);

    $newUser = [
        'id'               => $newId,
        'username'         => $newId,
        'password'         => $password,
        'role'             => 'student',
        'firstName'        => $firstName,
        'lastName'         => $lastName,
        'email'            => $email,
        'phone'            => $phone,
        'yearLevel'        => $yearLevel,
        'program'          => $program,
        'section'          => $section,
        'enrollmentStatus' => 'not-enrolled',
        'address'          => $address,
        'birthday'         => $birthday,
        'title'            => '',
        'createdAt'        => date('Y-m-d'),
    ];

    $users[] = $newUser;
    writeJSON(USERS_FILE, $users);

    return [
        'success'  => true,
        'message'  => "Account created! Your Student ID is <strong>$newId</strong>. Your password is your last name in ALL CAPS.",
        'user'     => $newUser,
        'studentId'=> $newId,
        'password' => $password,
    ];
}

function registerStudent(array $data): array {
    return createStudentCredential($data);
}

function updateUser(string $id, array $data): bool {
    $users = readJSON(USERS_FILE);
    foreach ($users as &$user) {
        if ($user['id'] === $id) {
            $allowed = [
                'firstName','lastName','email','phone','address',
                'yearLevel','section','enrollmentStatus','program','title','department'
            ];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $data)) {
                    $user[$field] = $data[$field];
                }
            }
            writeJSON(USERS_FILE, $users);
            return true;
        }
    }
    return false;
}

function deleteStudent(string $id): bool {
    $users    = readJSON(USERS_FILE);
    $filtered = array_values(array_filter(
        $users,
        fn($u) => !($u['id'] === $id && $u['role'] === 'student')
    ));
    if (count($filtered) === count($users)) return false;
    return writeJSON(USERS_FILE, $filtered);
}

// ─── Course Functions (with OOP helpers) ─────────────────────────────────────
function getAllCourses(): array {
    return readJSON(COURSES_FILE);
}

function getCourseById(string $id): ?array {
    foreach (readJSON(COURSES_FILE) as $course) {
        if ($course['id'] === $id) return $course;
    }
    return null;
}

function getCoursesByProgram(string $program, int $yearLevel = 0): array {
    return array_values(array_filter(readJSON(COURSES_FILE), function($c) use ($program, $yearLevel) {
        $matchProgram = ($c['program'] === $program || $c['program'] === 'ALL');
        $matchYear    = ($yearLevel === 0 || (int)$c['yearLevel'] === $yearLevel);
        return $matchProgram && $matchYear;
    }));
}

function getCoursesByProfessor(string $profId): array {
    return array_values(array_filter(
        readJSON(COURSES_FILE),
        fn($c) => $c['professorId'] === $profId
    ));
}

function addCourse(array $data): string {
    $courses = readJSON(COURSES_FILE);
    $newId   = 'CRS-' . str_pad(count($courses) + 1, 3, '0', STR_PAD_LEFT);
    $courses[] = array_merge([
        'id'           => $newId,
        'prerequisites'=> [],
        'slots'        => 40,
        'enrolled'     => 0,
    ], $data);
    writeJSON(COURSES_FILE, $courses);
    return $newId;
}

function updateCourse(string $id, array $data): bool {
    $courses = readJSON(COURSES_FILE);
    foreach ($courses as &$c) {
        if ($c['id'] === $id) {
            $c = array_merge($c, $data);
            return writeJSON(COURSES_FILE, $courses);
        }
    }
    return false;
}

function deleteCourse(string $id): bool {
    $courses  = readJSON(COURSES_FILE);
    $filtered = array_values(array_filter($courses, fn($c) => $c['id'] !== $id));
    if (count($filtered) === count($courses)) return false;
    return writeJSON(COURSES_FILE, $filtered);
}

// ─── Semester Functions ───────────────────────────────────────────────────────
function getActiveSemester(): ?array {
    $all = readJSON(SEMESTERS_FILE);
    if (empty($all)) {
        // Safety Fallback: If semesters.json fails to read or parse on InfinityFree
        return [
            'id' => 'SEM-2025-1',
            'academicYear' => '2025-2026',
            'semester' => 1,
            'enrollmentStart' => '2025-07-01',
            'enrollmentEnd' => '2026-12-31',
            'isActive' => true
        ];
    }
    foreach ($all as $s) {
        if (!empty($s['isActive'])) return $s;
    }
    return $all[0];
}

function getAllSemesters(): array {
    return readJSON(SEMESTERS_FILE);
}

function getSemesterById(string $id): ?array {
    foreach (readJSON(SEMESTERS_FILE) as $s) {
        if ($s['id'] === $id) return $s;
    }
    return null;
}

// ─── Enrollment Functions (OOP wrappers) ─────────────────────────────────────
function getAllEnrollments(): array {
    return readJSON(ENROLLMENTS_FILE);
}

function getStudentEnrollment(string $studentId, string $semesterId = ''): ?array {
    if ($semesterId === '') {
        $sem = getActiveSemester();
        $semesterId = $sem['id'] ?? '';
    }
    foreach (readJSON(ENROLLMENTS_FILE) as $enrollment) {
        if ($enrollment['studentId'] === $studentId && $enrollment['semesterId'] === $semesterId) {
            return $enrollment;
        }
    }
    return null;
}

function submitEnrollment(string $studentId, array $subjectIds): array {
    if (!isEnrollmentOpen()) {
        return ['success' => false, 'message' => 'Enrollment period is closed.'];
    }
    
    $student = getStudentObject($studentId);
    if (!$student) return ['success' => false, 'message' => 'Student record not found.'];
    
    $sem = getActiveSemester();
    $semesterId = $sem['id'] ?? '';
    
    $allCourses = readJSON(COURSES_FILE);
    $validatedSubjects = [];
    
    foreach ($subjectIds as $id) {
        foreach ($allCourses as $c) {
            if ($c['id'] === $id) {
                $courseObj = new Course($c);
                if (!$courseObj->checkPrerequisites($studentId)) {
                    return [
                        'success' => false, 
                        'message' => "Enrollment blocked! You do not meet the minimum passing grade requirements for prerequisites of course: " . $c['code']
                    ];
                }
                $validatedSubjects[] = $id;
            }
        }
    }
    
    $enrollments = readJSON(ENROLLMENTS_FILE);
    $found = false;
    foreach ($enrollments as &$e) {
        if ($e['studentId'] === $studentId && $e['semesterId'] === $semesterId) {
            $e['subjects'] = $validatedSubjects;
            $e['updatedAt'] = date('Y-m-d H:i:s');
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        $enrollments[] = [
            'id' => 'ENR-' . str_pad(count($enrollments) + 1, 3, '0', STR_PAD_LEFT),
            'studentId' => $studentId,
            'semesterId' => $semesterId,
            'subjects' => $validatedSubjects,
            'status' => 'pending',
            'submittedAt' => date('Y-m-d H:i:s'),
            'verifiedAt' => null,
            'verifiedBy' => null,
            'updatedAt' => date('Y-m-d H:i:s')
        ];
    }
    
    writeJSON(ENROLLMENTS_FILE, $enrollments);
    return ['success' => true, 'message' => 'Enrollment selections successfully updated and locked for validation.'];
}

function dropSubject(string $studentId, string $subjectId): array {
    $student = getStudentObject($studentId);
    $course = Course::findById($subjectId);
    $sem = getActiveSemester();
    if (!$student || !$course || !$sem) {
        return ['success' => false, 'message' => 'Invalid request.'];
    }
    return $student->dropCourse($course, $sem['id']);
}

function verifyEnrollment(string $enrollmentId, string $status, string $verifierId): bool {
    $enrollments = readJSON(ENROLLMENTS_FILE);
    foreach ($enrollments as &$e) {
        if ($e['id'] === $enrollmentId) {
            $e['status']     = $status;
            $e['verifiedAt'] = date('Y-m-d H:i:s');
            $e['verifiedBy'] = $verifierId;
            $e['updatedAt']  = date('Y-m-d H:i:s');
            writeJSON(ENROLLMENTS_FILE, $enrollments);
            $stuStatus = $status === 'verified' ? 'enrolled' : 'not-enrolled';
            updateUser($e['studentId'], ['enrollmentStatus' => $stuStatus]);
            return true;
        }
    }
    return false;
}

function getClassList(string $courseId): array {
    $sem  = getActiveSemester();
    $list = [];
    foreach (readJSON(ENROLLMENTS_FILE) as $e) {
        if (
            $e['semesterId'] === ($sem['id'] ?? '') &&
            in_array($courseId, $e['subjects'], true) &&
            $e['status'] === 'verified'
        ) {
            $student = getUserById($e['studentId']);
            if ($student) $list[] = $student;
        }
    }
    return $list;
}

function countEnrollmentsByStatus(string $status): int {
    $sem = getActiveSemester();
    return count(array_filter(
        readJSON(ENROLLMENTS_FILE),
        fn($e) => $e['status'] === $status && $e['semesterId'] === ($sem['id'] ?? '')
    ));
}

function getPendingEnrollments(): array {
    $sem = getActiveSemester();
    $semId = $sem['id'] ?? '';
    $list = [];
    foreach (readJSON(ENROLLMENTS_FILE) as $e) {
        if ($e['semesterId'] === $semId && $e['status'] === 'pending') {
            $student = getUserById($e['studentId']);
            $list[] = array_merge($e, ['student' => $student]);
        }
    }
    return $list;
}

// ─── Grade Functions ──────────────────────────────────────────────────────────
function getStudentGrades(string $studentId, string $semesterId = ''): array {
    return array_values(array_filter(
        readJSON(GRADES_FILE),
        fn($g) => $g['studentId'] === $studentId &&
                  ($semesterId === '' || $g['semesterId'] === $semesterId)
    ));
}

function submitGrade(string $studentId, string $courseId, ?float $grade, string $profId): bool {
    $sem    = getActiveSemester();
    $semId  = $sem['id'] ?? '';
    $grades = readJSON(GRADES_FILE);
    $now    = date('Y-m-d H:i:s');

    foreach ($grades as &$g) {
        if (
            $g['studentId'] === $studentId &&
            $g['courseId']  === $courseId  &&
            $g['semesterId']=== $semId
        ) {
            $g['grade']       = $grade;
            $g['remarks']     = gradeRemarks($grade);
            $g['submittedBy'] = $profId;
            $g['updatedAt']   = $now;
            return writeJSON(GRADES_FILE, $grades);
        }
    }

    $grades[] = [
        'id'          => 'GRD-' . str_pad(count($grades) + 1, 3, '0', STR_PAD_LEFT),
        'studentId'   => $studentId,
        'courseId'    => $courseId,
        'semesterId'  => $semId,
        'grade'       => $grade,
        'remarks'     => gradeRemarks($grade),
        'submittedBy' => $profId,
        'createdAt'   => $now,
        'updatedAt'   => $now,
    ];
    return writeJSON(GRADES_FILE, $grades);
}

function gradeRemarks(?float $grade): string {
    if ($grade === null) return 'INC';
    return $grade <= 3.0 ? 'Passed' : 'Failed';
}

function isPassingGrade(?float $grade): bool {
    return $grade !== null && $grade <= 3.0;
}

function isFailingGrade(?float $grade): bool {
    return $grade !== null && $grade > 3.0;
}

function getFailingGrades(string $studentId): array {
    return array_values(array_filter(
        getStudentGrades($studentId),
        fn($g) => isFailingGrade($g['grade'] ?? null)
    ));
}

function getLetterGrade(?float $grade): string {
    if ($grade === null) return 'INC';
    if ($grade <= 1.0)  return 'A+';
    if ($grade <= 1.25) return 'A';
    if ($grade <= 1.5)  return 'A-';
    if ($grade <= 1.75) return 'B+';
    if ($grade <= 2.0)  return 'B';
    if ($grade <= 2.25) return 'B-';
    if ($grade <= 2.5)  return 'C+';
    if ($grade <= 2.75) return 'C';
    if ($grade <= 3.0)  return 'D';
    return 'F';
}

function getGradeColorClass(?float $grade): string {
    if ($grade === null) return 'badge-gray';
    if ($grade <= 1.5)  return 'badge-green';
    if ($grade <= 2.5)  return 'badge-blue';
    if ($grade <= 3.0)  return 'badge-yellow';
    return 'badge-red';
}

function calculateGWA(array $grades): ?float {
    $valid = array_filter($grades, fn($g) => $g['grade'] !== null);
    if (empty($valid)) return null;
    $sum = array_sum(array_column($valid, 'grade'));
    return round($sum / count($valid), 4);
}

function getTotalUnitsEnrolled(array $subjectIds): int {
    $total = 0;
    foreach ($subjectIds as $id) {
        $c = getCourseById($id);
        if ($c) $total += (int)($c['units'] ?? 0);
    }
    return $total;
}

// ─── Dashboard Stats ──────────────────────────────────────────────────────────
function getDashboardStats(): array {
    $students    = getAllStudents();
    $courses     = getAllCourses();
    $enrollments = getAllEnrollments();
    $sem         = getActiveSemester();
    $semId       = $sem['id'] ?? '';

    $semEnrollments = array_filter($enrollments, fn($e) => $e['semesterId'] === $semId);

    $baseStudentCount = 1347 + count($students); 

    return [
        'totalStudents'       => $baseStudentCount,
        'totalCourses'        => count($courses),
        'pendingEnrollments'  => count(array_filter($semEnrollments, fn($e) => $e['status'] === 'pending')),
        'verifiedEnrollments' => count(array_filter($semEnrollments, fn($e) => $e['status'] === 'verified')),
        'activeSemester'      => $sem,
    ];
}

// ─── Flash Messages ───────────────────────────────────────────────────────────
function flash(string $type, string $message): void {
    $_SESSION['flash'] = compact('type', 'message');
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

function showFlash(): string {
    $f = getFlash();
    if (!$f) return '';
    $icon = match($f['type']) {
        'success' => '✓',
        'error'   => '✕',
        'warning' => '⚠',
        default   => 'ℹ',
    };
    $msg = $f['message'];
    return '<div class="flash flash-' . htmlspecialchars($f['type']) . '">'
         . '<span class="flash-icon">' . $icon . '</span>'
         . '<span>' . $msg . '</span>'
         . '<button class="flash-close" onclick="this.parentElement.remove()">×</button>'
         . '</div>';
}

// ─── Helpers ──────────────────────────────────────────────────────────────────
function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function normalizeStudentPassword(string $value): string {
    $normalized = trim($value);
    $normalized = str_replace(['Ñ', 'ñ'], ['N', 'N'], $normalized);
    return strtoupper($normalized);
}

function formatDate(string $date, string $format = 'F d, Y'): string {
    if (!$date) return '—';
    $ts = strtotime($date);
    return $ts ? date($format, $ts) : '—';
}

function redirect(string $url): never {
    header("Location: $url");
    exit;
}

function activeTab(string $tab, string $current): string {
    return $tab === $current ? ' active' : '';
}

function programLabel(string $code): string {
    return match($code) {
        'BSCS'  => 'BS Computer Science',
        'BSIT'  => 'BS Information Technology',
        'BSIS'  => 'BS Information Systems',
        'BSECE' => 'BS Electronics Engineering',
        'BSME'  => 'BS Mechanical Engineering',
        'BSCPE' => 'BS Computer Engineering',
        'BSED'  => 'BS Education',
        'BSBA'  => 'BS Business Administration',
        'ALL'   => 'All Programs',
        default => $code,
    };
}

function allPrograms(): array {
    return [
        'BSCS'  => 'BS Computer Science',
        'BSIT'  => 'BS Information Technology',
        'BSIS'  => 'BS Information Systems',
        'BSECE' => 'BS Electronics Engineering',
        'BSME'  => 'BS Mechanical Engineering',
        'BSCPE' => 'BS Computer Engineering',
        'BSED'  => 'BS Education',
        'BSBA'  => 'BS Business Administration',
    ];
}

function yearLevelLabel(int $year): string {
    return match($year) {
        1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year',
        4 => '4th Year', 5 => '5th Year', default => $year . 'th Year'
    };
}

function enrollmentStatusBadge(string $status): string {
    $map = [
        'enrolled'     => ['label' => 'Enrolled',     'class' => 'badge-green'],
        'pending'      => ['label' => 'Pending',       'class' => 'badge-yellow'],
        'not-enrolled' => ['label' => 'Not Enrolled',  'class' => 'badge-gray'],
        'verified'     => ['label' => 'Verified',      'class' => 'badge-green'],
        'rejected'     => ['label' => 'Rejected',      'class' => 'badge-red'],
    ];
    $info = $map[$status] ?? ['label' => ucfirst($status), 'class' => 'badge-gray'];
    return '<span class="badge ' . $info['class'] . '">' . $info['label'] . '</span>';
}

function avatarInitials(string $firstName, string $lastName): string {
    return strtoupper(substr($firstName, 0, 1) . substr($lastName, 0, 1));
}

function getStudentObject(string $studentId): ?Student {
    $user = getUserById($studentId);
    if (!$user || $user['role'] !== 'student') return null;
    return new Student($user);
}

// Enrollment period functions (delegated to OOP)
function isEnrollmentOpen(): bool {
    return EnrollmentPeriod::isOpen();
}

function setEnrollmentPeriod(string $start, ?string $end = null): bool {
    return EnrollmentPeriod::setDates($start, $end);
}

function closeEnrollmentPeriod(): bool {
    return EnrollmentPeriod::closeNow();
}

function getEnrollmentPeriod(): array {
    return EnrollmentPeriod::get();
}

// Prerequisite check (delegated)
function checkPrerequisites(string $studentId, string $courseId): bool {
    $course = Course::findById($courseId);
    if (!$course) return true;
    return $course->checkPrerequisites($studentId);
}
?>