<?php
/**
 * JARK University Enrollment System
 * staff-dashboard.php — Professor & Registrar Dashboard
 * (Integrated Enrollment Period Control)
 */
require_once 'functions.php';
requireRole(['professor', 'registrar'], 'staff-login.php');

$user  = currentUser();
$role  = $_SESSION['role'];
$sem   = getActiveSemester();
$flash = showFlash();

// ─── POST Actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'registrar_submit_grade' && $role === 'registrar') {
        $studentId = trim($_POST['student_id'] ?? '');
        $courseId  = trim($_POST['course_id'] ?? '');
        $gradeVal  = trim($_POST['grade'] ?? '');
        
        if ($studentId && $courseId && $gradeVal !== '') {
            $gradesData = readJSON(GRADES_FILE);
            $found = false;
            
            // Determine structural remarks based on the standard grade threshold (3.00)
            $numericGrade = (float)$gradeVal;
            $remarks = ($numericGrade <= 3.00 && $numericGrade > 0) ? 'Passed' : 'Failed';
            
            foreach ($gradesData as &$g) {
                if ($g['studentId'] === $studentId && $g['courseId'] === $courseId) {
                    $g['grade'] = $numericGrade;
                    $g['remarks'] = $remarks;
                    $g['updatedAt'] = date('Y-m-d H:i:s');
                    $g['submittedBy'] = $user['id'];
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $gradesData[] = [
                    'id' => 'GRD-' . str_pad(count($gradesData) + 1, 3, '0', STR_PAD_LEFT),
                    'studentId' => $studentId,
                    'courseId' => $courseId,
                    'semesterId' => $_POST['semester_id'] ?? 'SEM-PREVIOUS',
                    'grade' => $numericGrade,
                    'remarks' => $remarks,
                    'submittedBy' => $user['id'],
                    'createdAt' => date('Y-m-d H:i:s'),
                    'updatedAt' => date('Y-m-d H:i:s')
                ];
            }
            
            writeJSON(GRADES_FILE, $gradesData);
            flash('success', "Grade entry updated successfully. Student prerequisite blocks re-evaluated.");
        } else {
            flash('error', "Incomplete payload submitted.");
        }
        redirect('staff-dashboard.php?tab=reports');
        redirect('staff-dashboard.php?tab=registrar_grades');
    }

    // ── Registrar: Verify / Reject Enrollment ──
    if ($action === 'verify_enrollment' && $role === 'registrar') {
        $enrollId = $_POST['enrollment_id'] ?? '';
        $status   = $_POST['status'] ?? 'verified';
        if ($enrollId && in_array($status, ['verified', 'rejected'], true)) {
            $ok = verifyEnrollment($enrollId, $status, $user['id']);
            flash($ok ? 'success' : 'error', $ok
                ? 'Enrollment ' . ($status === 'verified' ? 'verified' : 'rejected') . ' successfully.'
                : 'Failed to update enrollment.');
        }
        redirect('staff-dashboard.php?tab=enrollments');
    }

    // ── Professor / Registrar: Submit Grade ──
    if ($action === 'submit_grade') {
        $studentId = $_POST['student_id'] ?? '';
        $courseId  = $_POST['course_id']  ?? '';
        $gradeVal  = $_POST['grade']      ?? '';
        $grade     = ($gradeVal === '' || $gradeVal === 'INC') ? null : (float)$gradeVal;

        if ($studentId && $courseId) {
            $ok = submitGrade($studentId, $courseId, $grade, $user['id']);
            flash($ok ? 'success' : 'error', $ok
                ? 'Grade submitted successfully.'
                : 'Failed to submit grade.');
        }
        redirect('staff-dashboard.php?tab=grades&course=' . urlencode($courseId));
    }

    // ── Registrar: Add Course ──
    if ($action === 'add_course' && $role === 'registrar') {
        $courseData = [
            'code'        => strtoupper(sanitize($_POST['code']        ?? '')),
            'name'        => sanitize($_POST['name']                   ?? ''),
            'description' => sanitize($_POST['description']            ?? ''),
            'units'       => (int)($_POST['units']                     ?? 3),
            'program'     => sanitize($_POST['program']                ?? ''),
            'yearLevel'   => (int)($_POST['yearLevel']                 ?? 1),
            'semester'    => (int)($_POST['semester']                  ?? 1),
            'schedule'    => sanitize($_POST['schedule']               ?? ''),
            'room'        => sanitize($_POST['room']                   ?? ''),
            'professorId' => sanitize($_POST['professorId']            ?? ''),
            'slots'       => (int)($_POST['slots']                     ?? 40),
            'enrolled'    => 0,
            'prerequisites' => [],
        ];
        if (!$courseData['code'] || !$courseData['name']) {
            flash('error', 'Course code and name are required.');
        } else {
            $newId = addCourse($courseData);
            flash('success', "Course <strong>{$courseData['code']}</strong> added successfully (ID: $newId).");
        }
        redirect('staff-dashboard.php?tab=courses');
    }

    // ── Registrar: Edit Course ──
    if ($action === 'edit_course' && $role === 'registrar') {
        $courseId   = $_POST['course_id'] ?? '';
        $courseData = [
            'code'        => strtoupper(sanitize($_POST['code']        ?? '')),
            'name'        => sanitize($_POST['name']                   ?? ''),
            'description' => sanitize($_POST['description']            ?? ''),
            'units'       => (int)($_POST['units']                     ?? 3),
            'program'     => sanitize($_POST['program']                ?? ''),
            'yearLevel'   => (int)($_POST['yearLevel']                 ?? 1),
            'semester'    => (int)($_POST['semester']                  ?? 1),
            'schedule'    => sanitize($_POST['schedule']               ?? ''),
            'room'        => sanitize($_POST['room']                   ?? ''),
            'professorId' => sanitize($_POST['professorId']            ?? ''),
            'slots'       => (int)($_POST['slots']                     ?? 40),
        ];
        if ($courseId) {
            $ok = updateCourse($courseId, $courseData);
            flash($ok ? 'success' : 'error', $ok ? 'Course updated successfully.' : 'Failed to update course.');
        }
        redirect('staff-dashboard.php?tab=courses');
    }

    // ── Registrar: Delete Course ──
    if ($action === 'delete_course' && $role === 'registrar') {
        $courseId = $_POST['course_id'] ?? '';
        if ($courseId) {
            $ok = deleteCourse($courseId);
            flash($ok ? 'success' : 'error', $ok ? 'Course deleted.' : 'Failed to delete course.');
        }
        redirect('staff-dashboard.php?tab=courses');
    }

    // ── Registrar: Create Student Credential ──
// ── Registrar: Create Student Credential ──
        if ($action === 'create_student_credential' && $role === 'registrar') {
            $studentData = [
                'firstName' => trim($_POST['firstName'] ?? ''),
                'lastName'  => trim($_POST['lastName'] ?? ''),
                'email'     => trim($_POST['email'] ?? ''),
                'phone'     => trim($_POST['phone'] ?? ''),
                'program'   => trim($_POST['program'] ?? ''),
                'yearLevel' => (int)($_POST['yearLevel'] ?? 1),
                'section'   => trim($_POST['section'] ?? ''),
                'birthday'  => trim($_POST['birthday'] ?? ''),
                'address'   => trim($_POST['address'] ?? ''),
            ];

            $result = createStudentCredential($studentData);
            
            // 1. Process Grades ONLY IF the student account was created successfully
            if ($result['success'] && !empty($_POST['prev_course'])) {
                $newStudentId = $result['studentId']; // Grab the actual newly generated ID
                $gradesData = readJSON(GRADES_FILE);
                
                foreach ($_POST['prev_course'] as $index => $courseId) {
                    $gradeVal = $_POST['prev_grade'][$index] ?? '';
                    if ($courseId && $gradeVal !== '') {
                        $gradesData[] = [
                            'studentId' => $newStudentId,
                            'courseId'  => $courseId,
                            'grade'     => (float)$gradeVal,
                            'remarks'   => ((float)$gradeVal <= 3.00) ? 'Passed' : 'Failed',
                            'updatedAt' => date('Y-m-d H:i:s')
                        ];
                    }
                }
                writeJSON(GRADES_FILE, $gradesData);
            }

            // 2. Set the flash message
            flash(
                $result['success'] ? 'success' : 'error',
                $result['success']
                    ? "Student credential created with grades. Student ID: <strong>{$result['studentId']}</strong> | Password: <strong>{$result['password']}</strong>"
                    : $result['message']
            );
            
            // 3. Redirect ONLY AT THE END so all the code above finishes running
            redirect('staff-dashboard.php?tab=students');
        }
        
    // ── Registrar: Edit Student ──
    if ($action === 'edit_student' && $role === 'registrar') {
        $studentId  = $_POST['student_id'] ?? '';
        $updateData = [
            'firstName'        => sanitize($_POST['firstName']        ?? ''),
            'lastName'         => sanitize($_POST['lastName']         ?? ''),
            'email'            => sanitize($_POST['email']            ?? ''),
            'phone'            => sanitize($_POST['phone']            ?? ''),
            'program'          => sanitize($_POST['program']          ?? ''),
            'yearLevel'        => (int)($_POST['yearLevel']           ?? 1),
            'section'          => sanitize($_POST['section']          ?? ''),
            'address'          => sanitize($_POST['address']          ?? ''),
            'enrollmentStatus' => sanitize($_POST['enrollmentStatus'] ?? 'not-enrolled'),
        ];
        if ($studentId) {
            $ok = updateUser($studentId, $updateData);
            flash($ok ? 'success' : 'error', $ok ? 'Student record updated.' : 'Failed to update student.');
        }
        redirect('staff-dashboard.php?tab=students');
    }

    // ── Registrar: Delete Student ──
    if ($action === 'delete_student' && $role === 'registrar') {
        $studentId = $_POST['student_id'] ?? '';
        if ($studentId) {
            $ok = deleteStudent($studentId);
            flash($ok ? 'success' : 'error', $ok ? 'Student record deleted.' : 'Failed to delete student.');
        }
        redirect('staff-dashboard.php?tab=students');
    }

    // ── NEW: Registrar: Update Enrollment Period ── (integrated feature)
    if ($action === 'update_enrollment_period' && $role === 'registrar') {
        $start = $_POST['enrollment_start'] ?? '';
        $end   = $_POST['enrollment_end'] ?? null;
        if ($start) {
            if (setEnrollmentPeriod($start, $end ?: null)) {
                flash('success', 'Enrollment period updated successfully.');
            } else {
                flash('error', 'Failed to update enrollment period.');
            }
        } elseif (isset($_POST['force_close'])) {
            if (closeEnrollmentPeriod()) {
                flash('warning', 'Enrollment period has been forcefully closed.');
            } else {
                flash('error', 'Failed to close enrollment period.');
            }
        }
        redirect('staff-dashboard.php?tab=enrollment_period');
    }
}

// ─── Tab & Data Prep ──────────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'overview';

// Shared data
$allCourses    = getAllCourses();
$allStudents   = getAllStudents();
$allEnrollments= getAllEnrollments();
$allSemesters  = getAllSemesters();
$stats         = getDashboardStats();
$professors    = getAllProfessors();

// Active semester enrollments
$semId = $sem['id'] ?? '';
$semEnrollments = array_values(array_filter($allEnrollments, fn($e) => $e['semesterId'] === $semId));

// Professor: only courses they teach
$myCourses = ($role === 'professor')
    ? getCoursesByProfessor($user['id'])
    : $allCourses;

// Selected course for grade submission
$selectedCourseId = $_GET['course'] ?? ($myCourses[0]['id'] ?? '');
$selectedCourse   = getCourseById($selectedCourseId);

// Class list for selected course
$classList = $selectedCourse ? getClassList($selectedCourseId) : [];

$initials = avatarInitials($user['firstName'], $user['lastName']);
$dept     = $user['department'] ?? ($role === 'professor' ? 'Faculty' : "Registrar's Office");

// Helper: get enrollment for a student in active sem
function getActiveEnrollmentForStudent(string $studentId, array $allEnrollments, string $semId): ?array {
    foreach ($allEnrollments as $e) {
        if ($e['studentId'] === $studentId && $e['semesterId'] === $semId) return $e;
    }
    return null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= $role === 'registrar' ? 'Registrar' : 'Professor' ?> Dashboard — JARK University</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    /* Staff-specific overrides */
    .role-tag-registrar { background: rgba(11,29,87,.1); color: var(--navy); }
    .role-tag-professor { background: rgba(202,160,46,.15); color: #8a6d10; }

    .grade-input {
      width: 80px; padding: 6px 8px;
      border: 1.5px solid var(--gray-200); border-radius: var(--radius-sm);
      font-size: 13px; font-family: var(--font-body); text-align: center;
      transition: var(--transition);
    }
    .grade-input:focus { outline: none; border-color: var(--navy); box-shadow: 0 0 0 3px rgba(11,29,87,.08); }

    .status-dot {
      width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0;
    }
    .dot-green  { background: var(--green-bright); }
    .dot-yellow { background: var(--yellow); }
    .dot-gray   { background: var(--gray-300); }
    .dot-red    { background: var(--red); }

    .enroll-row-pending  { background: rgba(234,179,8,.04); }
    .enroll-row-verified { background: rgba(34,197,94,.04); }
    .enroll-row-rejected { background: rgba(239,68,68,.04); }

    .course-select-bar {
      display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
      padding: 14px 18px; background: var(--gray-50);
      border: 1px solid var(--gray-200); border-radius: var(--radius-sm);
      margin-bottom: 20px;
    }
    .course-select-bar label { font-size: 13px; font-weight: 700; color: var(--gray-700); white-space: nowrap; }
    .course-select-bar select { flex: 1; min-width: 220px; max-width: 380px; }

    .modal-overlay {
      position: fixed; inset: 0; background: rgba(0,0,0,.45);
      display: flex; align-items: center; justify-content: center;
      z-index: 1000; padding: 20px;
      opacity: 0; pointer-events: none; transition: opacity .2s ease;
    }
    .modal-overlay.open { opacity: 1; pointer-events: all; }
    .modal-box {
      background: var(--white); border-radius: var(--radius);
      box-shadow: var(--shadow-xl); width: 100%; max-width: 520px;
      max-height: 90vh; overflow-y: auto;
      transform: translateY(16px); transition: transform .25s ease;
    }
    .modal-overlay.open .modal-box { transform: translateY(0); }
    .modal-header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 20px 24px 16px; border-bottom: 1px solid var(--gray-100);
    }
    .modal-header h3 { font-family: var(--font-display); font-size: 18px; color: var(--navy); }
    .modal-close {
      background: none; border: none; cursor: pointer;
      font-size: 20px; color: var(--gray-400); padding: 4px 8px;
      border-radius: 6px; transition: var(--transition); line-height: 1;
    }
    .modal-close:hover { background: var(--gray-100); color: var(--gray-700); }
    .modal-body { padding: 20px 24px; }
    .modal-footer {
      display: flex; justify-content: flex-end; gap: 10px;
      padding: 14px 24px; border-top: 1px solid var(--gray-100);
    }

    .report-card {
      border: 1px solid var(--gray-200); border-radius: var(--radius);
      overflow: hidden; transition: var(--transition);
    }
    .report-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
    .report-card-head {
      padding: 18px 20px; background: linear-gradient(135deg, var(--navy-dark), var(--navy));
      color: white;
    }
    .report-card-body { padding: 16px 20px; }

    .search-filter-bar {
      display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; align-items: center;
    }
    .search-filter-bar input, .search-filter-bar select {
      font-size: 13px; padding: 8px 12px;
    }
    .search-filter-bar input { flex: 1; min-width: 180px; }

    @media print {
      .sidebar, .topbar, .modal-overlay, .btn-primary, .btn-secondary, .btn-danger,
      .hamburger-btn, .sidebar-overlay, .page-header-actions, .no-print { display: none !important; }
      .main-content { margin-left: 0 !important; }
      .page-content { padding: 0 !important; }
      table { font-size: 11px; }
    }
  </style>
</head>
<body>
<div class="app-layout">

  <!-- ══════════════════════ SIDEBAR ══════════════════════ -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-logo">
        <div class="sidebar-logo-icon"><img src="JARKULOGO.png" alt="JARK University logo"></div>
        <div class="sidebar-logo-text">
          <span class="sidebar-logo-name">JARK University</span>
          <span class="sidebar-logo-sub"><?= $role === 'registrar' ? "Registrar's Office" : 'Faculty Portal' ?></span>
        </div>
      </div>
      <button class="sidebar-close" id="sidebarClose" aria-label="Close menu">✕</button>
    </div>

    <div class="sidebar-user">
      <div class="sidebar-avatar"><?= $initials ?></div>
      <div>
        <div class="sidebar-user-name">
          <?= htmlspecialchars(($user['title'] ? $user['title'] . ' ' : '') . $user['firstName'] . ' ' . $user['lastName']) ?>
        </div>
        <div class="sidebar-user-role">
          <?= $role === 'registrar' ? '📋 Registrar' : '👨‍🏫 Professor' ?> · <?= htmlspecialchars($dept) ?>
        </div>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-label">Main</div>
      <a href="?tab=overview" class="nav-item<?= $tab==='overview'?' active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Overview
      </a>

      <?php if ($role === 'registrar'): ?>
          <button class="nav-btn" onclick="openTab('tab-grades')">
              <i class="icon-grade"></i> Input Grades
          </button>
      <?php endif; ?>

      <?php if ($role === 'registrar'): ?>
      <div class="nav-section-label">Management</div>
      <a href="?tab=enrollments" class="nav-item<?= $tab==='enrollments'?' active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        Enrollments
        <?php $pendingCount = $stats['pendingEnrollments']; if ($pendingCount > 0): ?>
        <span class="nav-badge"><?= $pendingCount ?></span>
        <?php endif; ?>
      </a>
      <a href="?tab=students" class="nav-item<?= $tab==='students'?' active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/></svg>
        Students
      </a>
      <a href="?tab=courses" class="nav-item<?= $tab==='courses'?' active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
        Courses
      </a>
      <a href="?tab=reports" class="nav-item<?= $tab==='reports'?' active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        Reports
      </a>
      <a href="?tab=registrar_grades" class="nav-item<?= $tab==='registrar_grades'?' active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
        Input Grades
      </a>
      <!-- NEW: Enrollment Period menu item -->
      <a href="?tab=enrollment_period" class="nav-item<?= $tab==='enrollment_period'?' active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Enrollment Period
      </a>
      <?php endif; ?>

      <?php if ($role === 'registrar'): ?>
        <div id="tab-grades" class="tab-content" style="display:none;">
            <h3>Student Grade Entry</h3>
            <form method="POST" action="staff-dashboard.php" class="form-container">
                <input type="hidden" name="action" value="registrar_submit_grade">
                
                <div class="form-group">
                    <label>Student ID</label>
                    <input type="text" name="student_id" required placeholder="e.g., 2026-0001">
                </div>

                <div class="form-group">
                    <label>Course ID</label>
                    <input type="text" name="course_id" required placeholder="e.g., CS101">
                </div>

                <div class="form-group">
                    <label>Grade (1.00 - 5.00)</label>
                    <input type="number" step="0.25" name="grade" required>
                </div>

                <button type="submit" class="btn-primary">Submit Grade</button>
            </form>
        </div>
        <?php endif; ?>

      <?php if ($role === 'professor'): ?>
      <div class="nav-section-label">Teaching</div>
      <a href="?tab=classlist" class="nav-item<?= $tab==='classlist'?' active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/></svg>
        Class List
      </a>
      <a href="?tab=grades" class="nav-item<?= $tab==='grades'?' active':'' ?>">
        <svg class="nav-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
        Submit Grades
      </a>
      <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
      <a href="logout.php" class="sidebar-logout">
        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        Sign Out
      </a>
    </div>
  </aside>

  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <!-- ══════════════════════ MAIN CONTENT ══════════════════════ -->
  <div class="main-content">

    <!-- Topbar -->
    <header class="topbar">
      <div class="topbar-left">
        <button class="hamburger-btn" id="hamburger" aria-label="Toggle menu">
          <span></span><span></span><span></span>
        </button>
        <span class="page-title">
          <?= match($tab) {
            'enrollments' => 'Manage Enrollments',
            'students'    => 'Student Records',
            'courses'     => 'Course Management',
            'reports'     => 'Reports',
            'classlist'   => 'Class List',
            'grades'      => 'Submit Grades',
            'enrollment_period' => 'Enrollment Period',
            default       => 'Dashboard'
          } ?>
        </span>
      </div>
      <div class="topbar-right">
        <?php if ($sem): ?>
        <div class="topbar-sem">
          <span class="full"><?= htmlspecialchars($sem['label']) ?></span>
          <span class="short">SEM <?= $sem['semester'] ?></span>
        </div>
        <?php endif; ?>
        <div class="topbar-avatar" title="<?= htmlspecialchars($user['firstName']) ?>"><?= $initials ?></div>
      </div>
    </header>

    <div class="page-content">
      <?= $flash ?>

      <?php
      // ══════════════════════════════════════════════════════════════
      //  OVERVIEW TAB
      // ══════════════════════════════════════════════════════════════
      if ($tab === 'overview'):
      ?>
      <div class="page-header">
        <div class="page-header-left">
          <h1>Welcome, <?= htmlspecialchars(($user['title'] ? $user['title'] . ' ' : '') . $user['lastName']) ?>! 👋</h1>
          <p><?= $role === 'registrar' ? "Registrar's Office" : htmlspecialchars($dept) ?></p>
        </div>
        <div class="page-header-actions">
          <span class="badge <?= $role === 'registrar' ? 'badge-navy' : 'badge-gold' ?>">
            <?= $role === 'registrar' ? '📋 Registrar' : '👨‍🏫 Professor' ?>
          </span>
        </div>
      </div>

      <!-- Stats Grid -->
      <div class="stat-grid">
        <?php if ($role === 'registrar'): ?>
        <div class="stat-card" style="animation-delay:.0s">
          <div class="stat-icon stat-icon-navy">🎓</div>
          <div class="stat-value"><?= $stats['totalStudents'] ?></div>
          <div class="stat-label">Total Students</div>
        </div>
        <div class="stat-card" style="animation-delay:.07s">
          <div class="stat-icon stat-icon-gold">📚</div>
          <div class="stat-value"><?= $stats['totalCourses'] ?></div>
          <div class="stat-label">Total Courses</div>
        </div>
        <div class="stat-card" style="animation-delay:.14s">
          <div class="stat-icon stat-icon-yellow">⏳</div>
          <div class="stat-value"><?= $stats['pendingEnrollments'] ?></div>
          <div class="stat-label">Pending Enrollments</div>
        </div>
        <div class="stat-card" style="animation-delay:.21s">
          <div class="stat-icon stat-icon-green">✅</div>
          <div class="stat-value"><?= $stats['verifiedEnrollments'] ?></div>
          <div class="stat-label">Verified Enrollments</div>
        </div>
        <?php else: ?>
        <div class="stat-card" style="animation-delay:.0s">
          <div class="stat-icon stat-icon-navy">📖</div>
          <div class="stat-value"><?= count($myCourses) ?></div>
          <div class="stat-label">My Courses</div>
        </div>
        <div class="stat-card" style="animation-delay:.07s">
          <div class="stat-icon stat-icon-gold">👥</div>
          <?php $totalStudents = 0; foreach ($myCourses as $mc) $totalStudents += (int)$mc['enrolled']; ?>
          <div class="stat-value"><?= $totalStudents ?></div>
          <div class="stat-label">Total Students</div>
        </div>
        <div class="stat-card" style="animation-delay:.14s">
          <div class="stat-icon stat-icon-green">📝</div>
          <?php $gradedCount = 0;
            foreach ($myCourses as $mc) {
                $cl = getClassList($mc['id']);
                foreach ($cl as $st) {
                    $g = getStudentGrades($st['id'], $semId);
                    foreach ($g as $gr) { if ($gr['courseId'] === $mc['id']) $gradedCount++; }
                }
            }
          ?>
          <div class="stat-value"><?= $gradedCount ?></div>
          <div class="stat-label">Grades Submitted</div>
        </div>
        <div class="stat-card" style="animation-delay:.21s">
          <div class="stat-icon stat-icon-yellow">📅</div>
          <div class="stat-value"><?= $sem ? $sem['semester'] : '—' ?></div>
          <div class="stat-label">Current Semester</div>
        </div>
        <?php endif; ?>
      </div>

        <?php if ($role === 'registrar' && ($_GET['tab'] ?? 'overview') === 'reports'): ?>
            <div class="card" style="margin-top: 20px;">
                <div class="card-header">
                    <div class="card-title">Manage Historical Prerequisites & Backlog Grades</div>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="registrar_submit_grade">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:600;">Select Student</label>
                                <select name="student_id" required style="width:100%; padding:8px; border-radius:4px; border:1px solid var(--gray-300);">
                                    <?php 
                                    $users = readJSON(USERS_FILE);
                                    foreach ($users as $u) {
                                        if (($u['role'] ?? '') === 'student') {
                                            echo "<option value='{$u['id']}'>{$u['id']} - {$u['lastName']}, {$u['firstName']} ({$u['program']})</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:600;">Target Course/Prerequisite</label>
                                <select name="course_id" required style="width:100%; padding:8px; border-radius:4px; border:1px solid var(--gray-300);">
                                    <?php 
                                    $courses = readJSON(COURSES_FILE);
                                    foreach ($courses as $c) {
                                        echo "<option value='{$c['id']}'>{$c['code']} - {$c['name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label style="display:block; margin-bottom:4px; font-weight:600;">Grade (1.00 - 5.00)</label>
                                <input type="number" step="0.25" min="1.00" max="5.00" name="grade" placeholder="e.g., 3.00" required style="width:100%; padding:8px; border-radius:4px; border:1px solid var(--gray-300);">
                            </div>
                            <div style="display:flex; align-items:flex-end;">
                                <button type="submit" class="btn-primary" style="margin-top:0;">Save Prerequisite Grade</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
      <?php if ($role === 'registrar' && $stats['pendingEnrollments'] > 0): ?>
      <div class="enrollment-banner banner-yellow">
        <div class="banner-icon">⏳</div>
        <div class="banner-content">
          <h3><?= $stats['pendingEnrollments'] ?> Enrollment<?= $stats['pendingEnrollments'] > 1 ? 's' : '' ?> Awaiting Verification</h3>
          <p>There are pending enrollment requests that require your review and approval.</p>
        </div>
        <a href="?tab=enrollments" class="btn-primary no-print" style="margin-left:auto;white-space:nowrap;width:auto;padding:10px 18px">
          Review Now →
        </a>
      </div>
      <?php endif; ?>

      <!-- Recent Enrollments (Registrar) / My Courses (Professor) -->
      <?php if ($role === 'registrar'): ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title">Recent Enrollment Activity</div>
          <a href="?tab=enrollments" class="btn-secondary btn-sm no-print">View All</a>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Student</th><th>Program</th><th>Subjects</th><th>Submitted</th><th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $recentEnroll = array_slice(array_reverse($semEnrollments), 0, 8);
              foreach ($recentEnroll as $e):
                $stu = getUserById($e['studentId']);
                if (!$stu) continue;
              ?>
              <tr class="enroll-row-<?= $e['status'] ?>">
                <td>
                  <strong><?= htmlspecialchars($stu['firstName'] . ' ' . $stu['lastName']) ?></strong><br>
                  <span class="text-xs text-gray"><?= htmlspecialchars($stu['id']) ?></span>
                </td>
                <td><span class="badge badge-navy"><?= htmlspecialchars($stu['program']) ?></span></td>
                <td><?= count($e['subjects']) ?> subject<?= count($e['subjects']) !== 1 ? 's' : '' ?></td>
                <td class="text-sm"><?= formatDate($e['submittedAt'], 'M d, Y') ?></td>
                <td><?= enrollmentStatusBadge($e['status']) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($recentEnroll)): ?>
              <tr><td colspan="5" class="text-center text-gray" style="padding:24px">No enrollment activity yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php else: // Professor overview ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title">My Courses This Semester</div>
        </div>
        <?php if (empty($myCourses)): ?>
        <div class="empty-state">
          <div class="empty-icon">📭</div>
          <h3>No Courses Assigned</h3>
          <p>You have no courses assigned for this semester.</p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th>Code</th><th>Course Name</th><th>Schedule</th><th>Room</th><th>Enrolled</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($myCourses as $c): ?>
              <tr>
                <td><span class="badge badge-gold"><?= htmlspecialchars($c['code']) ?></span></td>
                <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                <td class="text-sm"><?= htmlspecialchars($c['schedule']) ?></td>
                <td class="text-sm"><?= htmlspecialchars($c['room']) ?></td>
                <td>
                  <span class="badge badge-navy"><?= (int)$c['enrolled'] ?> / <?= (int)$c['slots'] ?></span>
                </td>
                <td>
                  <a href="?tab=classlist&course=<?= urlencode($c['id']) ?>" class="btn-secondary btn-sm">Class List</a>
                  <a href="?tab=grades&course=<?= urlencode($c['id']) ?>" class="btn-primary btn-sm" style="margin-left:4px">Grades</a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php
      // ══════════════════════════════════════════════════════════════
      //  ENROLLMENTS TAB (Registrar only)
      // ══════════════════════════════════════════════════════════════
      elseif ($tab === 'enrollments' && $role === 'registrar'):
        $filterStatus = $_GET['status'] ?? '';
        $filterProgram= $_GET['program'] ?? '';
        $displayEnrollments = $semEnrollments;
        if ($filterStatus)  $displayEnrollments = array_values(array_filter($displayEnrollments, fn($e) => $e['status'] === $filterStatus));
        if ($filterProgram) {
            $displayEnrollments = array_values(array_filter($displayEnrollments, function($e) use ($filterProgram) {
                $stu = getUserById($e['studentId']);
                return $stu && $stu['program'] === $filterProgram;
            }));
        }
      ?>
      <div class="page-header">
        <div class="page-header-left">
          <h1>Enrollment Management</h1>
          <p><?= htmlspecialchars($sem['label'] ?? 'Current Semester') ?></p>
        </div>
        <div class="page-header-actions no-print">
          <span class="badge badge-yellow"><?= $stats['pendingEnrollments'] ?> Pending</span>
          <span class="badge badge-green"><?= $stats['verifiedEnrollments'] ?> Verified</span>
        </div>
      </div>

      <!-- Filters -->
      <div class="search-filter-bar no-print">
        <form method="GET" action="" style="display:contents">
          <input type="hidden" name="tab" value="enrollments"/>
          <select name="status" onchange="this.form.submit()" style="max-width:160px">
            <option value="" <?= !$filterStatus ? 'selected' : '' ?>>All Statuses</option>
            <option value="pending"  <?= $filterStatus==='pending'  ? 'selected' : '' ?>>Pending</option>
            <option value="verified" <?= $filterStatus==='verified' ? 'selected' : '' ?>>Verified</option>
            <option value="rejected" <?= $filterStatus==='rejected' ? 'selected' : '' ?>>Rejected</option>
          </select>
          <select name="program" onchange="this.form.submit()" style="max-width:200px">
            <option value="">All Programs</option>
            <?php foreach (allPrograms() as $code => $label): ?>
            <option value="<?= $code ?>" <?= $filterProgram===$code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if ($filterStatus || $filterProgram): ?>
          <a href="?tab=enrollments" class="btn-secondary btn-sm">✕ Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="card">
        <div class="table-wrap">
          <table class="custom-table">
          <table>
            <thead>
              <tr>
                <th>ID</th><th>Student</th><th>Program / Year</th>
                <th>Subjects</th><th>Submitted</th><th>Status</th><th class="no-print">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($displayEnrollments as $e):
                $stu = getUserById($e['studentId']);
                if (!$stu) continue;
              ?>
              <tr class="enroll-row-<?= $e['status'] ?>">
                <td class="text-xs text-gray"><?= htmlspecialchars($e['id']) ?></td>
                <td>
                  <div style="display:flex;align-items:center;gap:10px">
                    <div class="avatar" style="width:32px;height:32px;font-size:12px;border-radius:8px;flex-shrink:0"><?= avatarInitials($stu['firstName'], $stu['lastName']) ?></div>
                    <div>
                      <strong><?= htmlspecialchars($stu['firstName'] . ' ' . $stu['lastName']) ?></strong><br>
                      <span class="text-xs text-gray"><?= htmlspecialchars($stu['id']) ?></span>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="badge badge-navy"><?= htmlspecialchars($stu['program']) ?></span>
                  <span class="text-xs text-gray" style="margin-left:4px"><?= yearLevelLabel((int)$stu['yearLevel']) ?></span>
                </td>
                <td>
                  <?php foreach ($e['subjects'] as $sid):
                    $c = getCourseById($sid);
                  ?>
                  <?php if ($c): ?>
                  <span class="badge badge-navy" style="font-size:10px;margin:2px"><?= htmlspecialchars($c['code']) ?></span>
                  <?php endif; ?>
                  <?php endforeach; ?>
                  <span class="text-xs text-gray">(<?= count($e['subjects']) ?>)</span>
                </td>
                <td class="text-sm"><?= formatDate($e['submittedAt'], 'M d, Y g:i A') ?></td>
                <td><?= enrollmentStatusBadge($e['status']) ?></td>
                <td class="no-print">
                  <?php if ($e['status'] === 'pending'): ?>
                  <form method="POST" action="?tab=enrollments" style="display:inline" onsubmit="return confirm('Verify this enrollment?')">
                    <input type="hidden" name="action" value="verify_enrollment"/>
                    <input type="hidden" name="enrollment_id" value="<?= htmlspecialchars($e['id']) ?>"/>
                    <input type="hidden" name="status" value="verified"/>
                    <button class="btn-primary btn-sm">✓ Verify</button>
                  </form>
                  <form method="POST" action="?tab=enrollments" style="display:inline;margin-left:4px" onsubmit="return confirm('Reject this enrollment?')">
                    <input type="hidden" name="action" value="verify_enrollment"/>
                    <input type="hidden" name="enrollment_id" value="<?= htmlspecialchars($e['id']) ?>"/>
                    <input type="hidden" name="status" value="rejected"/>
                    <button class="btn-danger btn-sm">✕ Reject</button>
                  </form>
                  <?php elseif ($e['status'] === 'verified'): ?>
                  <span class="text-xs text-gray">Verified <?= $e['verifiedAt'] ? formatDate($e['verifiedAt'], 'M d') : '' ?></span>
                  <?php else: ?>
                  <span class="text-xs" style="color:var(--red)">Rejected</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($displayEnrollments)): ?>
              <tr><td colspan="7" class="text-center text-gray" style="padding:32px">No enrollment records found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php
      // ══════════════════════════════════════════════════════════════
      //  STUDENTS TAB (Registrar only)
      // ══════════════════════════════════════════════════════════════
      elseif ($tab === 'students' && $role === 'registrar'):
        $searchQ = strtolower(trim($_GET['q'] ?? ''));
        $filterProg = $_GET['program'] ?? '';
        $filterEnroll = $_GET['enroll'] ?? '';
        $displayStudents = $allStudents;
        if ($searchQ) {
            $displayStudents = array_values(array_filter($displayStudents, fn($s) =>
                str_contains(strtolower($s['firstName'] . ' ' . $s['lastName'] . ' ' . $s['id'] . ' ' . $s['email']), $searchQ)
            ));
        }
        if ($filterProg)   $displayStudents = array_values(array_filter($displayStudents, fn($s) => $s['program'] === $filterProg));
        if ($filterEnroll) $displayStudents = array_values(array_filter($displayStudents, fn($s) => $s['enrollmentStatus'] === $filterEnroll));
        $editStudent = isset($_GET['edit']) ? getUserById($_GET['edit']) : null;
      ?>
      <div class="page-header">
        <div class="page-header-left">
          <h1>Student Records</h1>
          <p><?= count($displayStudents) ?> of <?= count($allStudents) ?> students</p>
        </div>
        <div class="page-header-actions no-print">
          <button class="btn-primary" onclick="openModal('createStudentModal')">+ Create Student Credential</button>
        </div>
      </div>

      <!-- Search & Filters -->
      <div class="search-filter-bar no-print">
        <form method="GET" action="" style="display:contents">
          <input type="hidden" name="tab" value="students"/>
          <input type="text" name="q" placeholder="Search name, ID, email…" value="<?= htmlspecialchars($searchQ) ?>" style="min-width:220px"/>
          <select name="program" onchange="this.form.submit()" style="max-width:200px">
            <option value="">All Programs</option>
            <?php foreach (allPrograms() as $code => $label): ?>
            <option value="<?= $code ?>" <?= $filterProg===$code ? 'selected' : '' ?>><?= htmlspecialchars($code) ?> — <?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="enroll" onchange="this.form.submit()" style="max-width:160px">
            <option value="">All Statuses</option>
            <option value="enrolled"     <?= $filterEnroll==='enrolled'     ? 'selected':'' ?>>Enrolled</option>
            <option value="pending"      <?= $filterEnroll==='pending'      ? 'selected':'' ?>>Pending</option>
            <option value="not-enrolled" <?= $filterEnroll==='not-enrolled' ? 'selected':'' ?>>Not Enrolled</option>
          </select>
          <button type="submit" class="btn-primary btn-sm">Search</button>
          <?php if ($searchQ || $filterProg || $filterEnroll): ?>
          <a href="?tab=students" class="btn-secondary btn-sm">✕ Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <div class="card">
        <div class="table-wrap">
          <table class="custom-table">
          <table>
            <thead>
              <tr><th>Student ID</th><th>Name</th><th>Program</th><th>Year</th><th>Section</th><th>Status</th><th class="no-print">Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($displayStudents as $stu): ?>
              <tr>
                <td class="text-sm"><code style="background:var(--gray-100);padding:2px 6px;border-radius:4px"><?= htmlspecialchars($stu['id']) ?></code></td>
                <td>
                  <div style="display:flex;align-items:center;gap:10px">
                    <div class="avatar" style="width:32px;height:32px;font-size:12px;border-radius:8px;flex-shrink:0"><?= avatarInitials($stu['firstName'], $stu['lastName']) ?></div>
                    <div>
                      <strong><?= htmlspecialchars($stu['firstName'] . ' ' . $stu['lastName']) ?></strong><br>
                      <span class="text-xs text-gray"><?= htmlspecialchars($stu['email']) ?></span>
                    </div>
                  </div>
                </td>
                <td><span class="badge badge-navy"><?= htmlspecialchars($stu['program']) ?></span></td>
                <td class="text-sm"><?= yearLevelLabel((int)$stu['yearLevel']) ?></td>
                <td class="text-sm"><?= htmlspecialchars($stu['section'] ?: '—') ?></td>
                <td><?= enrollmentStatusBadge($stu['enrollmentStatus']) ?></td>
                <td class="no-print">
                  <button class="btn-secondary btn-sm"
                    onclick="openEditStudent(<?= htmlspecialchars(json_encode($stu), ENT_QUOTES) ?>)">Edit</button>
                  <form method="POST" action="?tab=students" style="display:inline;margin-left:4px"
                    onsubmit="return confirm('Delete <?= htmlspecialchars($stu['firstName'] . ' ' . $stu['lastName']) ?>? This cannot be undone.')">
                    <input type="hidden" name="action" value="delete_student"/>
                    <input type="hidden" name="student_id" value="<?= htmlspecialchars($stu['id']) ?>"/>
                    <button type="submit" class="btn-danger btn-sm">Delete</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($displayStudents)): ?>
              <tr><td colspan="7" class="text-center text-gray" style="padding:32px">No students found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="modal-overlay" id="createStudentModal">
        <div class="modal-box">
          <div class="modal-header">
            <h3>Create Student Credential</h3>
            <button class="modal-close" onclick="closeModal('createStudentModal')">&times;</button>
          </div>
          <form method="POST" action="?tab=students">
            <input type="hidden" name="action" value="create_student_credential"/>
            <div class="modal-body">
              <div class="grid-2" style="gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin-bottom:0">
                  <label>First Name</label>
                  <div class="input-wrap"><input type="text" name="firstName" required/></div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label>Last Name</label>
                  <div class="input-wrap"><input type="text" name="lastName" required/></div>
                </div>
              </div>
              <div class="form-group">
                <label>Email</label>
                <div class="input-wrap"><input type="email" name="email" required/></div>
              </div>
              <div class="grid-2" style="gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin-bottom:0">
                  <label>Phone</label>
                  <div class="input-wrap"><input type="tel" name="phone"/></div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label>Birthday</label>
                  <div class="input-wrap"><input type="date" name="birthday"/></div>
                </div>
              </div>
              <div class="grid-2" style="gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin-bottom:0">
                  <label>Program</label>
                  <div class="input-wrap">
                    <select name="program" required>
                      <option value="">Select program</option>
                      <?php foreach (allPrograms() as $code => $label): ?>
                      <option value="<?= $code ?>"><?= htmlspecialchars($code) ?> &mdash; <?= htmlspecialchars($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label>Year Level</label>
                  <div class="input-wrap">
                    <select name="yearLevel">
                      <?php for ($y = 1; $y <= 5; $y++): ?>
                      <option value="<?= $y ?>"><?= yearLevelLabel($y) ?></option>
                      <?php endfor; ?>
                    </select>
                  </div>
                </div>
              </div>
              <div class="grid-2" style="gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin-bottom:0">
                  <label>Section</label>
                  <div class="input-wrap"><input type="text" name="section"/></div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label>Default Password</label>
                  <div class="input-wrap"><input type="text" value="Student last name in ALL CAPS" disabled/></div>
                </div>
              </div>

              <div class="grade-entry-section" style="margin-top: 15px; border-top: 1px solid #ddd; padding-top: 10px;">
                <h4>Previous Semester Grades (Optional)</h4>
                <div id="grade-inputs">
                    <div class="input-row" style="display: flex; gap: 5px; margin-bottom: 5px;">
                        <input type="text" name="prev_course[]" placeholder="Course ID (e.g. CS101)">
                        <input type="number" step="0.25" name="prev_grade[]" placeholder="Grade">
                    </div>
                </div>
                <button type="button" onclick="addGradeRow()" style="font-size: 12px; margin-top: 5px;">+ Add another grade</button>
            </div>
              <div class="form-group">
                <label>Address</label>
                <div class="input-wrap"><input type="text" name="address"/></div>
              </div>
              <div class="form-group" style="margin-bottom:0">
                <div class="flash flash-warning" style="margin:0">
                  <span class="flash-icon">⚠</span>
                  <span class="flash-icon">⚠️</span>
                  <span>The system will generate the Student ID automatically. The initial password will be the student's last name in ALL CAPS.</span>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn-secondary" onclick="closeModal('createStudentModal')">Cancel</button>
              <button type="submit" class="btn-primary">Create Credential</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Edit Student Modal -->
      <div class="modal-overlay" id="editStudentModal">
        <div class="modal-box">
          <div class="modal-header">
            <h3>Edit Student Record</h3>
            <button class="modal-close" onclick="closeModal('editStudentModal')">×</button>
          </div>
          <form method="POST" action="?tab=students">
            <input type="hidden" name="action" value="edit_student"/>
            <input type="hidden" name="student_id" id="editStudentId"/>
            <div class="modal-body">
              <div class="grid-2" style="gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin-bottom:0">
                  <label>First Name</label>
                  <div class="input-wrap"><input type="text" name="firstName" id="editFirstName" required/></div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label>Last Name</label>
                  <div class="input-wrap"><input type="text" name="lastName" id="editLastName" required/></div>
                </div>
              </div>
              <div class="form-group">
                <label>Email</label>
                <div class="input-wrap"><input type="email" name="email" id="editEmail" required/></div>
              </div>
              <div class="grid-2" style="gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin-bottom:0">
                  <label>Phone</label>
                  <div class="input-wrap"><input type="tel" name="phone" id="editPhone"/></div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label>Section</label>
                  <div class="input-wrap"><input type="text" name="section" id="editSection"/></div>
                </div>
              </div>
              <div class="grid-2" style="gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin-bottom:0">
                  <label>Program</label>
                  <div class="input-wrap">
                    <select name="program" id="editProgram">
                      <?php foreach (allPrograms() as $code => $label): ?>
                      <option value="<?= $code ?>"><?= htmlspecialchars($code) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label>Year Level</label>
                  <div class="input-wrap">
                    <select name="yearLevel" id="editYearLevel">
                      <?php for ($y = 1; $y <= 5; $y++): ?>
                      <option value="<?= $y ?>"><?= yearLevelLabel($y) ?></option>
                      <?php endfor; ?>
                    </select>
                  </div>
                </div>
              </div>

              <div class="grade-entry-section" style="margin-top: 15px; border-top: 1px solid #ddd; padding-top: 10px;">
                <h4>Previous Semester Grades (Optional)</h4>
                <div id="grade-inputs">
                    <div class="input-row" style="display: flex; gap: 5px; margin-bottom: 5px;">
                        <input type="text" name="prev_course[]" placeholder="Course ID (e.g. CS101)">
                        <input type="number" step="0.25" name="prev_grade[]" placeholder="Grade">
                    </div>
                </div>
                <button type="button" onclick="addGradeRow()" style="font-size: 12px; margin-top: 5px;">+ Add another grade</button>
            </div>
              <div class="form-group">
                <label>Enrollment Status</label>
                <div class="input-wrap">
                  <select name="enrollmentStatus" id="editEnrollStatus">
                    <option value="not-enrolled">Not Enrolled</option>
                    <option value="pending">Pending</option>
                    <option value="enrolled">Enrolled</option>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label>Address</label>
                <div class="input-wrap"><input type="text" name="address" id="editAddress"/></div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn-secondary" onclick="closeModal('editStudentModal')">Cancel</button>
              <button type="submit" class="btn-primary">Save Changes</button>
            </div>
          </form>
        </div>
      </div>

      <?php
      // ══════════════════════════════════════════════════════════════
      //  COURSES TAB (Registrar only)
      // ══════════════════════════════════════════════════════════════
      elseif ($tab === 'courses' && $role === 'registrar'):
        $editCourse = isset($_GET['edit']) ? getCourseById($_GET['edit']) : null;
      ?>
      <div class="page-header">
        <div class="page-header-left">
          <h1>Course Management</h1>
          <p><?= count($allCourses) ?> courses in the system</p>
        </div>
        <div class="page-header-actions no-print">
          <button class="btn-primary" onclick="openModal('addCourseModal')">+ Add Course</button>
        </div>
      </div>

      <div class="card">
        <div class="table-wrap">
          <table class="custom-table">
          <table>
            <thead>
              <tr><th>Code</th><th>Course Name</th><th>Program</th><th>Year</th><th>Units</th><th>Schedule</th><th>Slots</th><th>Professor</th><th class="no-print">Actions</th></tr>
            </thead>
            <tbody>
              <?php foreach ($allCourses as $c):
                $prof = getUserById($c['professorId']);
              ?>
              <tr>
                <td><span class="badge badge-gold"><?= htmlspecialchars($c['code']) ?></span></td>
                <td>
                  <strong><?= htmlspecialchars($c['name']) ?></strong><br>
                  <span class="text-xs text-gray"><?= htmlspecialchars($c['id']) ?></span>
                </td>
                <td><span class="badge badge-navy"><?= htmlspecialchars($c['program']) ?></span></td>
                <td class="text-sm"><?= yearLevelLabel((int)$c['yearLevel']) ?></td>
                <td class="text-sm"><?= (int)$c['units'] ?></td>
                <td class="text-sm"><?= htmlspecialchars($c['schedule']) ?></td>
                <td>
                  <span class="badge <?= (int)$c['enrolled'] >= (int)$c['slots'] ? 'badge-red' : 'badge-green' ?>">
                    <?= (int)$c['enrolled'] ?>/<?= (int)$c['slots'] ?>
                  </span>
                </td>
                <td class="text-sm">
                  <?= $prof ? htmlspecialchars(($prof['title'] ?? '') . ' ' . $prof['lastName']) : '<span class="text-gray">—</span>' ?>
                </td>
                <td class="no-print">
                  <button class="btn-secondary btn-sm" onclick="openEditCourse(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)">Edit</button>
                  <form method="POST" action="?tab=courses" style="display:inline;margin-left:4px"
                    onsubmit="return confirm('Delete course <?= htmlspecialchars($c['code']) ?>?')">
                    <input type="hidden" name="action" value="delete_course"/>
                    <input type="hidden" name="course_id" value="<?= htmlspecialchars($c['id']) ?>"/>
                    <button type="submit" class="btn-danger btn-sm">Delete</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($allCourses)): ?>
              <tr><td colspan="9" class="text-center text-gray" style="padding:32px">No courses found.<?= '' ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Add Course Modal -->
      <div class="modal-overlay" id="addCourseModal">
        <div class="modal-box">
          <div class="modal-header">
            <h3>Add New Course</h3>
            <button class="modal-close" onclick="closeModal('addCourseModal')">×</button>
          </div>
          <form method="POST" action="?tab=courses">
            <input type="hidden" name="action" value="add_course"/>
            <div class="modal-body">
              <div class="grid-2" style="gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin-bottom:0">
                  <label>Course Code <span style="color:var(--red)">*</span></label>
                  <div class="input-wrap"><input type="text" name="code" placeholder="e.g. CC101" required style="text-transform:uppercase"/></div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label>Units <span style="color:var(--red)">*</span></label>
                  <div class="input-wrap"><input type="number" name="units" min="1" max="6" value="3" required/></div>
                </div>
              </div>
              <div class="form-group">
                <label>Course Name <span style="color:var(--red)">*</span></label>
                <div class="input-wrap"><input type="text" name="name" placeholder="e.g. Introduction to Computing" required/></div>
              </div>
              <div class="form-group">
                <label>Description</label>
                <div class="input-wrap"><input type="text" name="description" placeholder="Short course description"/></div>
              </div>
              <div class="grid-2" style="gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin-bottom:0">
                  <label>Program <span style="color:var(--red)">*</span></label>
                  <div class="input-wrap">
                    <select name="program" required>
                      <option value="ALL">All Programs</option>
                      <?php foreach (allPrograms() as $code => $label): ?>
                      <option value="<?= $code ?>"><?= htmlspecialchars($code) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label>Year Level</label>
                  <div class="input-wrap">
                    <select name="yearLevel">
                      <?php for ($y=1;$y<=5;$y++): ?><option value="<?= $y ?>"><?= yearLevelLabel($y) ?></option><?php endfor; ?>
                    </select>
                  </div>
                </div>
              </div>
              <div class="grid-2" style="gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin-bottom:0">
                  <label>Semester</label>
                  <div class="input-wrap">
                    <select name="semester">
                      <option value="1">1st Semester</option>
                      <option value="2">2nd Semester</option>
                    </select>
                  </div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label>Max Slots</label>
                  <div class="input-wrap"><input type="number" name="slots" min="1" max="200" value="40"/></div>
                </div>
              </div>
              <div class="grid-2" style="gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin-bottom:0">
                  <label>Schedule</label>
                  <div class="input-wrap"><input type="text" name="schedule" placeholder="e.g. MWF 7:30-8:30 AM"/></div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label>Room</label>
                  <div class="input-wrap"><input type="text" name="room" placeholder="e.g. ICT-101"/></div>
                </div>
              </div>
              <div class="form-group">
                <label>Assigned Professor</label>
                <div class="input-wrap">
                  <select name="professorId">
                    <option value="">— None —</option>
                    <?php foreach ($professors as $p): ?>
                    <option value="<?= htmlspecialchars($p['id']) ?>">
                      <?= htmlspecialchars(($p['title'] ?? '') . ' ' . $p['firstName'] . ' ' . $p['lastName']) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn-secondary" onclick="closeModal('addCourseModal')">Cancel</button>
              <button type="submit" class="btn-primary">Add Course</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Edit Course Modal -->
      <div class="modal-overlay" id="editCourseModal">
        <div class="modal-box">
          <div class="modal-header">
            <h3>Edit Course</h3>
            <button class="modal-close" onclick="closeModal('editCourseModal')">×</button>
          </div>
          <form method="POST" action="?tab=courses">
            <input type="hidden" name="action" value="edit_course"/>
            <input type="hidden" name="course_id" id="ecId"/>
            <div class="modal-body">
              <div class="grid-2" style="gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin-bottom:0">
                  <label>Course Code</label>
                  <div class="input-wrap"><input type="text" name="code" id="ecCode" required style="text-transform:uppercase"/></div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label>Units</label>
                  <div class="input-wrap"><input type="number" name="units" id="ecUnits" min="1" max="6" required/></div>
                </div>
              </div>
              <div class="form-group">
                <label>Course Name</label>
                <div class="input-wrap"><input type="text" name="name" id="ecName" required/></div>
              </div>
              <div class="form-group">
                <label>Description</label>
                <div class="input-wrap"><input type="text" name="description" id="ecDesc"/></div>
              </div>
              <div class="grid-2" style="gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin-bottom:0">
                  <label>Program</label>
                  <div class="input-wrap">
                    <select name="program" id="ecProgram">
                      <option value="ALL">All Programs</option>
                      <?php foreach (allPrograms() as $code => $label): ?>
                      <option value="<?= $code ?>"><?= htmlspecialchars($code) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label>Year Level</label>
                  <div class="input-wrap">
                    <select name="yearLevel" id="ecYearLevel">
                      <?php for ($y=1;$y<=5;$y++): ?><option value="<?= $y ?>"><?= yearLevelLabel($y) ?></option><?php endfor; ?>
                    </select>
                  </div>
                </div>
              </div>
              <div class="grid-2" style="gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin-bottom:0">
                  <label>Semester</label>
                  <div class="input-wrap">
                    <select name="semester" id="ecSemester">
                      <option value="1">1st Semester</option>
                      <option value="2">2nd Semester</option>
                    </select>
                  </div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label>Max Slots</label>
                  <div class="input-wrap"><input type="number" name="slots" id="ecSlots" min="1" max="200"/></div>
                </div>
              </div>
              <div class="grid-2" style="gap:12px;margin-bottom:12px">
                <div class="form-group" style="margin-bottom:0">
                  <label>Schedule</label>
                  <div class="input-wrap"><input type="text" name="schedule" id="ecSchedule"/></div>
                </div>
                <div class="form-group" style="margin-bottom:0">
                  <label>Room</label>
                  <div class="input-wrap"><input type="text" name="room" id="ecRoom"/></div>
                </div>
              </div>
              <div class="form-group">
                <label>Assigned Professor</label>
                <div class="input-wrap">
                  <select name="professorId" id="ecProfessor">
                    <option value="">— None —</option>
                    <?php foreach ($professors as $p): ?>
                    <option value="<?= htmlspecialchars($p['id']) ?>">
                      <?= htmlspecialchars(($p['title'] ?? '') . ' ' . $p['firstName'] . ' ' . $p['lastName']) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn-secondary" onclick="closeModal('editCourseModal')">Cancel</button>
              <button type="submit" class="btn-primary">Save Changes</button>
            </div>
          </form>
        </div>
      </div>

      <?php
      // ══════════════════════════════════════════════════════════════
      //  REPORTS TAB (Registrar only)
      // ══════════════════════════════════════════════════════════════
      elseif ($tab === 'reports' && $role === 'registrar'):
        $reportType = $_GET['report'] ?? '';
        // Pre-compute report data
        $enrolledCount    = count(array_filter($allStudents, fn($s) => $s['enrollmentStatus'] === 'enrolled'));
        $pendingCount     = count(array_filter($allStudents, fn($s) => $s['enrollmentStatus'] === 'pending'));
        $notEnrolledCount = count(array_filter($allStudents, fn($s) => $s['enrollmentStatus'] === 'not-enrolled'));

        // Per program breakdown
        $programBreakdown = [];
        foreach ($allStudents as $s) {
            $p = $s['program'];
            if (!isset($programBreakdown[$p])) $programBreakdown[$p] = ['total'=>0,'enrolled'=>0,'pending'=>0];
            $programBreakdown[$p]['total']++;
            if ($s['enrollmentStatus'] === 'enrolled') $programBreakdown[$p]['enrolled']++;
            if ($s['enrollmentStatus'] === 'pending')  $programBreakdown[$p]['pending']++;
        }
      ?>
      <div class="page-header">
        <div class="page-header-left">
          <h1>Reports</h1>
          <p>Academic and enrollment reports for <?= htmlspecialchars($sem['label'] ?? 'current semester') ?></p>
        </div>
        <div class="page-header-actions no-print">
          <?php if ($reportType): ?>
          <button onclick="window.print()" class="btn-secondary">🖨 Print Report</button>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!$reportType): ?>
      <!-- Report selection cards -->
      <div class="grid-2">
        <a href="?tab=reports&report=enrollment_summary" style="text-decoration:none">
          <div class="report-card">
            <div class="report-card-head">
              <div style="font-size:28px;margin-bottom:8px">📊</div>
              <div style="font-family:var(--font-display);font-size:17px;color:white">Enrollment Summary</div>
              <div style="font-size:12px;opacity:.75;margin-top:4px">Overview of all enrollments by program and status</div>
            </div>
            <div class="report-card-body">
              <span class="badge badge-green"><?= $stats['verifiedEnrollments'] ?> Verified</span>
              <span class="badge badge-yellow" style="margin-left:6px"><?= $stats['pendingEnrollments'] ?> Pending</span>
            </div>
          </div>
        </a>
        <a href="?tab=reports&report=class_list" style="text-decoration:none">
          <div class="report-card">
            <div class="report-card-head">
              <div style="font-size:28px;margin-bottom:8px">📋</div>
              <div style="font-family:var(--font-display);font-size:17px;color:white">Class Lists</div>
              <div style="font-size:12px;opacity:.75;margin-top:4px">Student rosters per course for this semester</div>
            </div>
            <div class="report-card-body">
              <span class="badge badge-navy"><?= count($allCourses) ?> Courses</span>
            </div>
          </div>
        </a>
        <a href="?tab=reports&report=grades_report" style="text-decoration:none">
          <div class="report-card">
            <div class="report-card-head">
              <div style="font-size:28px;margin-bottom:8px">🎯</div>
              <div style="font-family:var(--font-display);font-size:17px;color:white">Grades Report</div>
              <div style="font-size:12px;opacity:.75;margin-top:4px">Student grades and GWA across all subjects</div>
            </div>
            <div class="report-card-body">
              <span class="badge badge-navy"><?= count(readJSON(GRADES_FILE)) ?> Grade Records</span>
            </div>
          </div>
        </a>
        <a href="?tab=reports&report=program_breakdown" style="text-decoration:none">
          <div class="report-card">
            <div class="report-card-head">
              <div style="font-size:28px;margin-bottom:8px">🎓</div>
              <div style="font-family:var(--font-display);font-size:17px;color:white">Program Breakdown</div>
              <div style="font-size:12px;opacity:.75;margin-top:4px">Student distribution across programs</div>
            </div>
            <div class="report-card-body">
              <span class="badge badge-navy"><?= count($programBreakdown) ?> Programs</span>
            </div>
          </div>
        </a>
      </div>

      <?php elseif ($reportType === 'enrollment_summary'): ?>
      <div class="card" style="margin-bottom:0">
        <div class="card-header">
          <div class="card-title">Enrollment Summary — <?= htmlspecialchars($sem['label'] ?? '') ?></div>
          <a href="?tab=reports" class="btn-secondary btn-sm no-print">← Back</a>
        </div>
        <div class="card-body">
          <div class="stat-grid" style="grid-template-columns:repeat(3,1fr)">
            <div class="stat-card"><div class="stat-icon stat-icon-navy">🎓</div><div class="stat-value"><?= count($allStudents) ?></div><div class="stat-label">Total Students</div></div>
            <div class="stat-card"><div class="stat-icon stat-icon-green">✅</div><div class="stat-value"><?= $enrolledCount ?></div><div class="stat-label">Enrolled</div></div>
            <div class="stat-card"><div class="stat-icon stat-icon-yellow">⏳</div><div class="stat-value"><?= $pendingCount ?></div><div class="stat-label">Pending</div></div>
          </div>
        </div>
        <div class="table-wrap">
          <table class="custom-table">
          <table>
            <thead>
              <tr><th>#</th><th>Student ID</th><th>Name</th><th>Program</th><th>Year</th><th>Section</th><th>Subjects</th><th>Status</th></tr>
            </thead>
            <tbody>
              <?php foreach ($allStudents as $i => $stu):
                $enr = getActiveEnrollmentForStudent($stu['id'], $allEnrollments, $semId);
              ?>
              <tr>
                <td class="text-sm text-gray"><?= $i + 1 ?></td>
                <td class="text-sm"><code style="background:var(--gray-100);padding:2px 6px;border-radius:4px"><?= htmlspecialchars($stu['id']) ?></code></td>
                <td><?= htmlspecialchars($stu['firstName'] . ' ' . $stu['lastName']) ?></td>
                <td><?= htmlspecialchars($stu['program']) ?></td>
                <td class="text-sm"><?= yearLevelLabel((int)$stu['yearLevel']) ?></td>
                <td class="text-sm"><?= htmlspecialchars($stu['section'] ?: '—') ?></td>
                <td class="text-sm"><?= $enr ? count($enr['subjects']) : 0 ?></td>
                <td><?= enrollmentStatusBadge($stu['enrollmentStatus']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php elseif ($reportType === 'class_list'): ?>
      <?php $selCourse = isset($_GET['course']) ? getCourseById($_GET['course']) : null; ?>
      <div class="card" style="margin-bottom:16px">
        <div class="card-header">
          <div class="card-title">Class List Report</div>
          <a href="?tab=reports" class="btn-secondary btn-sm no-print">← Back</a>
        </div>
        <div class="card-body no-print">
          <form method="GET" action="" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <input type="hidden" name="tab" value="reports"/>
            <input type="hidden" name="report" value="class_list"/>
            <select name="course" onchange="this.form.submit()" style="min-width:260px">
              <option value="">— Select Course —</option>
              <?php foreach ($allCourses as $c): ?>
              <option value="<?= htmlspecialchars($c['id']) ?>" <?= (isset($_GET['course']) && $_GET['course'] === $c['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </form>
        </div>
        <?php if ($selCourse):
          $cl = getClassList($selCourse['id']);
          $clProf = getUserById($selCourse['professorId']);
        ?>
        <div class="card-body" style="padding:12px 20px;border-bottom:1px solid var(--gray-100);background:var(--gray-50)">
          <strong><?= htmlspecialchars($selCourse['code'] . ' — ' . $selCourse['name']) ?></strong>
          &nbsp;·&nbsp;<?= htmlspecialchars($selCourse['schedule']) ?>
          &nbsp;·&nbsp;<?= htmlspecialchars($selCourse['room']) ?>
          <?php if ($clProf): ?>
          &nbsp;·&nbsp;<?= htmlspecialchars(($clProf['title'] ?? '') . ' ' . $clProf['firstName'] . ' ' . $clProf['lastName']) ?>
          <?php endif; ?>
          &nbsp;·&nbsp;<strong><?= count($cl) ?> students</strong>
        </div>
        <div class="table-wrap">
          <table class="custom-table">
          <table>
            <thead>
              <tr><th>#</th><th>Student ID</th><th>Name</th><th>Program</th><th>Year</th><th>Section</th><th>Email</th></tr>
            </thead>
            <tbody>
              <?php foreach ($cl as $i => $stu): ?>
              <tr>
                <td class="text-sm text-gray"><?= $i + 1 ?></td>
                <td class="text-sm"><?= htmlspecialchars($stu['id']) ?></td>
                <td><?= htmlspecialchars($stu['firstName'] . ' ' . $stu['lastName']) ?></td>
                <td><?= htmlspecialchars($stu['program']) ?></td>
                <td class="text-sm"><?= yearLevelLabel((int)$stu['yearLevel']) ?></td>
                <td class="text-sm"><?= htmlspecialchars($stu['section'] ?: '—') ?></td>
                <td class="text-sm"><?= htmlspecialchars($stu['email']) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($cl)): ?>
              <tr><td colspan="7" class="text-center text-gray" style="padding:24px">No enrolled students for this course.<?= '' ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <?php elseif ($reportType === 'grades_report'): ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title">Grades Report — All Students</div>
          <a href="?tab=reports" class="btn-secondary btn-sm no-print">← Back</a>
        </div>
        <div class="table-wrap">
          <table class="custom-table">
          <table>
            <thead>
              <tr><th>Student ID</th><th>Student Name</th><th>Course</th><th>Semester</th><th>Grade</th><th>Remarks</th></tr>
            </thead>
            <tbody>
              <?php
              $allGradesData = readJSON(GRADES_FILE);
              foreach ($allGradesData as $g):
                $stu = getUserById($g['studentId']);
                $crs = getCourseById($g['courseId']);
                $smstr = getSemesterById($g['semesterId']);
                if (!$stu || !$crs) continue;
              ?>
              <tr>
                <td class="text-sm"><?= htmlspecialchars($stu['id']) ?></td>
                <td><?= htmlspecialchars($stu['firstName'] . ' ' . $stu['lastName']) ?></td>
                <td><span class="badge badge-navy"><?= htmlspecialchars($crs['code']) ?></span> <?= htmlspecialchars($crs['name']) ?></td>
                <td class="text-sm"><?= htmlspecialchars($smstr['label'] ?? '—') ?></td>
                <td>
                  <span class="badge <?= getGradeColorClass($g['grade']) ?>">
                    <?= $g['grade'] !== null ? number_format($g['grade'], 2) : 'INC' ?>
                  </span>
                </td>
                <td><?= htmlspecialchars(gradeRemarks($g['grade'])) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($allGradesData)): ?>
              <tr><td colspan="6" class="text-center text-gray" style="padding:24px">No grades submitted yet.<?= '' ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php elseif ($reportType === 'program_breakdown'): ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title">Student Distribution by Program</div>
          <a href="?tab=reports" class="btn-secondary btn-sm no-print">← Back</a>
        </div>
        <div class="table-wrap">
          <table class="custom-table">
          <table>
            <thead>
              <tr><th>Program Code</th><th>Program Name</th><th>Total Students</th><th>Enrolled</th><th>Pending</th><th>Not Enrolled</th></tr>
            </thead>
            <tbody>
              <?php foreach ($programBreakdown as $code => $data):
                $notEnr = $data['total'] - $data['enrolled'] - $data['pending'];
              ?>
              <tr>
                <td><span class="badge badge-gold"><?= htmlspecialchars($code) ?></span></td>
                <td><?= htmlspecialchars(programLabel($code)) ?></td>
                <td><strong><?= $data['total'] ?></strong></td>
                <td><span class="badge badge-green"><?= $data['enrolled'] ?></span></td>
                <td><span class="badge badge-yellow"><?= $data['pending'] ?></span></td>
                <td><span class="badge badge-gray"><?= max(0, $notEnr) ?></span></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($programBreakdown)): ?>
              <tr><td colspan="6" class="text-center text-gray" style="padding:24px">No data available.<?= '' ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; // end report type ?>

      <?php
      // ══════════════════════════════════════════════════════════════
      //  REGISTRAR GRADE ENTRY TAB
      // ══════════════════════════════════════════════════════════════
      elseif ($tab === 'registrar_grades' && $role === 'registrar'):
      ?>
      <div class="page-header">
        <div class="page-header-left">
          <h1>Input Grades</h1>
          <p>Manage historical prerequisites and backlog grades for students.</p>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><div class="card-title">Grade Submission Form</div></div>
        <div class="card-body">
          <form method="POST" action="">
            <input type="hidden" name="action" value="registrar_submit_grade">
            <div class="grid-3" style="gap: 16px;">
              <div class="form-group">
                <label>Student</label>
                <select name="student_id" required class="form-input">
                  <?php foreach ($allStudents as $u): ?>
                  <option value="<?= $u['id'] ?>"><?= $u['id'] ?> - <?= htmlspecialchars($u['lastName'] . ', ' . $u['firstName']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label>Course / Prerequisite</label>
                <select name="course_id" required class="form-input">
                  <?php foreach ($allCourses as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['code'] . ' - ' . $c['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label>Grade (1.00 - 5.00)</label>
                <input type="number" step="0.25" min="1.00" max="5.00" name="grade" placeholder="e.g. 1.25" required class="form-input">
              </div>
            </div>
            <div class="mt-16"><button type="submit" class="btn-primary" style="width:auto">Save Grade Entry</button></div>
          </form>
        </div>
      </div>

      <?php
      // ══════════════════════════════════════════════════════════════
      //  CLASS LIST TAB (Professor only)
      // ══════════════════════════════════════════════════════════════
      elseif ($tab === 'classlist' && $role === 'professor'):
        $viewCourseId = $_GET['course'] ?? ($myCourses[0]['id'] ?? '');
        $viewCourse   = getCourseById($viewCourseId);
        $viewClassList= $viewCourse ? getClassList($viewCourseId) : [];
      ?>
      <div class="page-header">
        <div class="page-header-left">
          <h1>Class List</h1>
          <p>Students enrolled in your courses this semester</p>
        </div>
        <div class="page-header-actions no-print">
          <?php if ($viewCourse): ?>
          <button onclick="window.print()" class="btn-secondary">🖨 Print</button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Course selector -->
      <div class="course-select-bar no-print">
        <label>Select Course:</label>
        <form method="GET" action="" style="display:contents">
          <input type="hidden" name="tab" value="classlist"/>
          <select name="course" onchange="this.form.submit()">
            <?php foreach ($myCourses as $c): ?>
            <option value="<?= htmlspecialchars($c['id']) ?>" <?= $viewCourseId===$c['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <?php if ($viewCourse): ?>
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title"><?= htmlspecialchars($viewCourse['code'] . ' — ' . $viewCourse['name']) ?></div>
            <div class="card-subtitle"><?= htmlspecialchars($viewCourse['schedule']) ?> · <?= htmlspecialchars($viewCourse['room']) ?> · <?= count($viewClassList) ?> students enrolled</div>
          </div>
          <span class="badge badge-navy"><?= count($viewClassList) ?>/<?= (int)$viewCourse['slots'] ?> slots</span>
        </div>
        <div class="table-wrap">
          <table class="custom-table">
          <table>
            <thead>
              <tr><th>#</th><th>Student ID</th><th>Name</th><th>Program</th><th>Year Level</th><th>Section</th><th>Email</th></tr>
            </thead>
            <tbody>
              <?php foreach ($viewClassList as $i => $stu): ?>
              <tr>
                <td class="text-sm text-gray"><?= $i + 1 ?></td>
                <td class="text-sm"><code style="background:var(--gray-100);padding:2px 6px;border-radius:4px"><?= htmlspecialchars($stu['id']) ?></code></td>
                <td>
                  <div style="display:flex;align-items:center;gap:8px">
                    <div class="avatar" style="width:28px;height:28px;font-size:11px;border-radius:7px;flex-shrink:0"><?= avatarInitials($stu['firstName'], $stu['lastName']) ?></div>
                    <strong><?= htmlspecialchars($stu['firstName'] . ' ' . $stu['lastName']) ?></strong>
                  </div>
                </td>
                <td><span class="badge badge-navy"><?= htmlspecialchars($stu['program']) ?></span></td>
                <td class="text-sm"><?= yearLevelLabel((int)$stu['yearLevel']) ?></td>
                <td class="text-sm"><?= htmlspecialchars($stu['section'] ?: '—') ?></td>
                <td class="text-sm"><?= htmlspecialchars($stu['email']) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($viewClassList)): ?>
              <tr><td colspan="7" class="text-center text-gray" style="padding:32px">
                <div class="empty-state">
                  <div class="empty-icon">📭</div>
                  <h3>No Students Yet</h3>
                  <p>No students with verified enrollment in this course for the current semester.</p>
                </div>
              </td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php else: ?>
      <div class="card">
        <div class="empty-state"><div class="empty-icon">📭</div><h3>No Courses</h3><p>You have no courses assigned this semester.</p></div>
      </div>
      <?php endif; ?>

      <?php
      // ══════════════════════════════════════════════════════════════
      //  GRADES TAB (Professor only)
      // ══════════════════════════════════════════════════════════════
      elseif ($tab === 'grades' && $role === 'professor'):
        $gradeCourseId = $_GET['course'] ?? ($myCourses[0]['id'] ?? '');
        $gradeCourse   = getCourseById($gradeCourseId);
        $gradeClassList= $gradeCourse ? getClassList($gradeCourseId) : [];
      ?>
      <div class="page-header">
        <div class="page-header-left">
          <h1>Submit Grades</h1>
          <p>Enter and submit grades for enrolled students</p>
        </div>
      </div>

      <!-- Course selector -->
      <div class="course-select-bar no-print">
        <label>Select Course:</label>
        <form method="GET" action="" style="display:contents">
          <input type="hidden" name="tab" value="grades"/>
          <select name="course" onchange="this.form.submit()">
            <?php foreach ($myCourses as $c): ?>
            <option value="<?= htmlspecialchars($c['id']) ?>" <?= $gradeCourseId===$c['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['code'] . ' — ' . $c['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <?php if ($gradeCourse): ?>
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title"><?= htmlspecialchars($gradeCourse['code'] . ' — ' . $gradeCourse['name']) ?></div>
            <div class="card-subtitle"><?= htmlspecialchars($sem['label'] ?? '') ?> · <?= count($gradeClassList) ?> students</div>
          </div>
          <div style="display:flex;gap:8px;align-items:center">
            <span class="badge badge-navy"><?= (int)$gradeCourse['units'] ?> units</span>
          </div>
        </div>

        <?php if (empty($gradeClassList)): ?>
        <div class="empty-state" style="padding:48px 24px">
          <div class="empty-icon">📭</div>
          <h3>No Students Enrolled</h3>
          <p>No students with verified enrollment in this course yet.</p>
        </div>
        <?php else: ?>
        <!-- Grade info box -->
        <div style="padding:14px 20px;background:rgba(11,29,87,.04);border-bottom:1px solid var(--gray-100);font-size:12.5px;color:var(--gray-600)">
          <strong>Grading Scale:</strong>
          1.00 (A+) · 1.25 (A) · 1.50 (A-) · 1.75 (B+) · 2.00 (B) · 2.25 (B-) · 2.50 (C+) · 2.75 (C) · 3.00 (D) · 5.00 (F) · INC = Incomplete
        </div>
        <div class="table-wrap">
          <table class="custom-table">
          <table>
            <thead>
              <tr><th>#</th><th>Student ID</th><th>Student Name</th><th>Program</th><th>Current Grade</th><th>Submit Grade</th></tr>
            </thead>
            <tbody>
              <?php foreach ($gradeClassList as $i => $stu):
                $existingGrades = getStudentGrades($stu['id'], $semId);
                $existingGrade  = null;
                foreach ($existingGrades as $eg) {
                    if ($eg['courseId'] === $gradeCourseId) { $existingGrade = $eg; break; }
                }
              ?>
              <tr>
                <td class="text-sm text-gray"><?= $i + 1 ?></td>
                <td class="text-sm"><code style="background:var(--gray-100);padding:2px 6px;border-radius:4px"><?= htmlspecialchars($stu['id']) ?></code></td>
                <td>
                  <div style="display:flex;align-items:center;gap:8px">
                    <div class="avatar" style="width:28px;height:28px;font-size:11px;border-radius:7px;flex-shrink:0"><?= avatarInitials($stu['firstName'], $stu['lastName']) ?></div>
                    <strong><?= htmlspecialchars($stu['firstName'] . ' ' . $stu['lastName']) ?></strong>
                  </div>
                </td>
                <td><span class="badge badge-navy"><?= htmlspecialchars($stu['program']) ?></span></td>
                <td>
                  <?php if ($existingGrade): ?>
                  <span class="badge <?= getGradeColorClass($existingGrade['grade']) ?>">
                    <?= $existingGrade['grade'] !== null ? number_format($existingGrade['grade'], 2) : 'INC' ?>
                    (<?= getLetterGrade($existingGrade['grade']) ?>)
                  </span>
                  <?php else: ?>
                  <span class="badge badge-gray">Not set</span>
                  <?php endif; ?>
                </td>
                <td>
                  <form method="POST" action="?tab=grades&course=<?= urlencode($gradeCourseId) ?>" style="display:flex;align-items:center;gap:8px">
                    <input type="hidden" name="action" value="submit_grade"/>
                    <input type="hidden" name="student_id" value="<?= htmlspecialchars($stu['id']) ?>"/>
                    <input type="hidden" name="course_id" value="<?= htmlspecialchars($gradeCourseId) ?>"/>
                    <select name="grade" class="grade-input" style="width:100px">
                      <option value="INC" <?= ($existingGrade && $existingGrade['grade'] === null) ? 'selected' : '' ?>>INC</option>
                      <?php
                      $gradeOptions = [1.00,1.25,1.50,1.75,2.00,2.25,2.50,2.75,3.00,5.00];
                      foreach ($gradeOptions as $go):
                        $sel = ($existingGrade && $existingGrade['grade'] !== null && abs($existingGrade['grade'] - $go) < 0.001) ? 'selected' : '';
                      ?>
                      <option value="<?= number_format($go, 2) ?>" <?= $sel ?>><?= number_format($go, 2) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn-primary btn-sm">Save</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <?php else: ?>
      <div class="card">
        <div class="empty-state"><div class="empty-icon">📭</div><h3>No Courses Assigned</h3><p>You have no courses for this semester.</p></div>
      </div>
      <?php endif; ?>

      <?php
      // ══════════════════════════════════════════════════════════════
      //  ENROLLMENT PERIOD TAB (Registrar only) — NEW FEATURE INTEGRATED
      // ══════════════════════════════════════════════════════════════
      elseif ($tab === 'enrollment_period' && $role === 'registrar'):
        $period = getEnrollmentPeriod();
        $isOpen = isEnrollmentOpen();
      ?>
      <div class="page-header">
        <div class="page-header-left">
          <h1>Enrollment Period Control</h1>
          <p>Set the dates when students can enroll for the current semester.</p>
        </div>
        <div class="page-header-actions">
          <span class="badge <?= $isOpen ? 'badge-green' : 'badge-red' ?>">
            <?= $isOpen ? 'OPEN' : 'CLOSED' ?>
          </span>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Current Enrollment Period</div>
        </div>
        <div class="card-body">
          <?php if ($period['start'] && $period['end']): ?>
            <p><strong>Start:</strong> <?= htmlspecialchars($period['start']) ?></p>
            <p><strong>End:</strong> <?= htmlspecialchars($period['end']) ?></p>
          <?php else: ?>
            <p>No enrollment period set.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Set New Enrollment Period</div>
        </div>
        <div class="card-body">
          <form method="POST" action="">
            <input type="hidden" name="action" value="update_enrollment_period">
            <div class="form-group">
              <label>Start Date</label>
              <input type="date" name="enrollment_start" required
                     value="<?= htmlspecialchars($period['start'] ?? date('Y-m-d')) ?>">
            </div>
            <div class="form-group">
              <label>End Date (optional)</label>
              <input type="date" name="enrollment_end">
              <small>If left empty, the end date will be automatically set to 7 days after the start date.</small>
            </div>
            <button type="submit" class="btn-primary">Save Dates</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Force Close Enrollment</div>
        </div>
        <div class="card-body">
          <form method="POST" action="" onsubmit="return confirm('Close enrollment immediately? Students will no longer be able to enroll.')">
            <input type="hidden" name="action" value="update_enrollment_period">
            <input type="hidden" name="force_close" value="1">
            <button type="submit" class="btn-danger">Close Enrollment Now</button>
          </form>
        </div>
      </div>

      <?php
      // ══════════════════════════════════════════════════════════════
      //  ACCESS DENIED (wrong role for tab)
      // ══════════════════════════════════════════════════════════════
      elseif (in_array($tab, ['enrollments','students','courses','reports','enrollment_period']) && $role !== 'registrar'):
      ?>
      <div class="card" style="margin-top:40px;text-align:center;padding:48px">
        <div style="font-size:48px;margin-bottom:16px">🚫</div>
        <h2 style="color:var(--navy);font-family:var(--font-display)">Access Restricted</h2>
        <p style="color:var(--gray-500);margin-top:8px">This section is only accessible to Registrar staff.</p>
        <a href="?tab=overview" class="btn-primary" style="display:inline-flex;margin-top:20px;width:auto">← Back to Dashboard</a>
      </div>

      <?php
      elseif (in_array($tab, ['classlist','grades']) && $role !== 'professor'):
      ?>
      <div class="card" style="margin-top:40px;text-align:center;padding:48px">
        <div style="font-size:48px;margin-bottom:16px">🚫</div>
        <h2 style="color:var(--navy);font-family:var(--font-display)">Access Restricted</h2>
        <p style="color:var(--gray-500);margin-top:8px">This section is only accessible to Professors.</p>
        <a href="?tab=overview" class="btn-primary" style="display:inline-flex;margin-top:20px;width:auto">← Back to Dashboard</a>
      </div>
      <?php endif; ?>

    </div><!-- /page-content -->
  </div><!-- /main-content -->
</div><!-- /app-layout -->

<script>
// Sidebar toggle
const sidebar   = document.getElementById('sidebar');
const overlay   = document.getElementById('sidebarOverlay');
const hamburger = document.getElementById('hamburger');
const closeBtn  = document.getElementById('sidebarClose');

function openSidebar()  { sidebar.classList.add('open');    overlay.classList.add('visible');    document.body.style.overflow='hidden'; }
function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('visible'); document.body.style.overflow=''; }

hamburger.addEventListener('click', openSidebar);
closeBtn.addEventListener('click', closeSidebar);
overlay.addEventListener('click', closeSidebar);

function openTab(tabId) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => tab.style.display = 'none');
    // Show selected
    document.getElementById(tabId).style.display = 'block';
    }
// Modal helpers
function openModal(id) {
  document.getElementById(id).classList.add('open');
  document.body.style.overflow = 'hidden';
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
  document.body.style.overflow = '';
}
// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(mo => {
  mo.addEventListener('click', function(e) {
    if (e.target === this) closeModal(this.id);
  });
});
// Close on Esc
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(mo => closeModal(mo.id));
  }
});

// Edit Student Modal fill
function openEditStudent(stu) {
  document.getElementById('editStudentId').value     = stu.id          || '';
  document.getElementById('editFirstName').value     = stu.firstName   || '';
  document.getElementById('editLastName').value      = stu.lastName    || '';
  document.getElementById('editEmail').value         = stu.email       || '';
  document.getElementById('editPhone').value         = stu.phone       || '';
  document.getElementById('editSection').value       = stu.section     || '';
  document.getElementById('editAddress').value       = stu.address     || '';
  document.getElementById('editProgram').value       = stu.program     || '';
  document.getElementById('editYearLevel').value     = stu.yearLevel   || 1;
  document.getElementById('editEnrollStatus').value  = stu.enrollmentStatus || 'not-enrolled';
  openModal('editStudentModal');
}

// Edit Course Modal fill
function openEditCourse(c) {
  document.getElementById('ecId').value          = c.id           || '';
  document.getElementById('ecCode').value        = c.code         || '';
  document.getElementById('ecName').value        = c.name         || '';
  document.getElementById('ecDesc').value        = c.description  || '';
  document.getElementById('ecUnits').value       = c.units        || 3;
  document.getElementById('ecProgram').value     = c.program      || '';
  document.getElementById('ecYearLevel').value   = c.yearLevel    || 1;
  document.getElementById('ecSemester').value    = c.semester     || 1;
  document.getElementById('ecSlots').value       = c.slots        || 40;
  document.getElementById('ecSchedule').value    = c.schedule     || '';
  document.getElementById('ecRoom').value        = c.room         || '';
  document.getElementById('ecProfessor').value   = c.professorId  || '';
  openModal('editCourseModal');
}

// Flash auto-dismiss
setTimeout(() => {
  document.querySelectorAll('.flash').forEach(f => {
    f.style.transition = 'opacity .5s ease, transform .5s ease';
    f.style.opacity = '0';
    f.style.transform = 'translateY(-8px)';
    setTimeout(() => f.remove(), 500);
  });
}, 4500);

function addGradeRow() {
    const container = document.getElementById('grade-inputs');
    const newRow = document.createElement('div');
    newRow.className = 'input-row';
    newRow.style.display = 'flex';
    newRow.style.gap = '5px';
    newRow.style.marginTop = '5px';
    newRow.innerHTML = `
        <input type="text" name="prev_course[]" placeholder="Course ID">
        <input type="number" step="0.25" name="prev_grade[]" placeholder="Grade">
    `;
    container.appendChild(newRow);
}
</script>
</body>
</html>