<?php
/**
 * JARK University Enrollment System
 * student-dashboard.php — Complete Student Portal Dashboard
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'functions.php';

// Force access control
requireRole(['student'], 'index.php');

$user  = currentUser();
$role  = $_SESSION['role'] ?? 'student';
$sem   = getActiveSemester();
$flash = showFlash();

// Define layout tabs
$tab = $_GET['tab'] ?? 'overview';

// Fetch student's current enrollment
$allEnrollments = readJSON(ENROLLMENTS_FILE);
$myEnrollment = null;
foreach ($allEnrollments as $enr) {
    if ($enr['studentId'] === $user['id'] && $enr['semesterId'] === ($sem['id'] ?? '')) {
        $myEnrollment = $enr;
        break;
    }
}
$enrollStatus = $myEnrollment ? $myEnrollment['status'] : ($user['enrollmentStatus'] ?? 'not-enrolled');

// Enrolled courses & total units
// Load all courses unconditionally so the enrollment form always has data
$allCourses = getAllCourses(); 

// Enrolled courses & total units
$myCourses = [];
$totalUnits = 0;
if ($myEnrollment && !empty($myEnrollment['subjects'])) {
    foreach ($myEnrollment['subjects'] as $subId) {
        foreach ($allCourses as $crs) {
            if ($crs['id'] === $subId) {
                $myCourses[] = $crs;
                $totalUnits += (int)($crs['units'] ?? 0);
                break;
            }
        }
    }
}

// Handle enrollment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enroll') {
    $subjectIds = $_POST['subjects'] ?? [];
    if (!empty($subjectIds)) {
        $res = submitEnrollment($user['id'], $subjectIds);
        flash($res['success'] ? 'success' : 'error', $res['message']);
    } else {
        flash('error', 'Please select at least one subject.');
    }
    redirect('student-dashboard.php?tab=overview');
}

// Handle drop subject (only if pending)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'drop') {
    $subjectId = $_POST['subject_id'] ?? '';
    if ($subjectId && $myEnrollment && $myEnrollment['status'] === 'pending') {
        $result = dropSubject($user['id'], $subjectId);
        flash($result['success'] ? 'success' : 'error', $result['message']);
    } else {
        flash('error', 'Cannot drop – enrollment already verified or not pending.');
    }
    redirect('student-dashboard.php?tab=subjects');
}

// Grade data
$allGrades = getStudentGrades($user['id']);
$gradeRows = [];
foreach ($allGrades as $g) {
    $c = getCourseById($g['courseId']);
    $s = getSemesterById($g['semesterId']);
    if ($c) {
        $gradeRows[] = array_merge($g, ['course' => $c, 'semester' => $s]);
    }
}
$failingGrades = array_filter($allGrades, fn($g) => isFailingGrade($g['grade'] ?? null));
$failingGradeRows = [];
foreach ($failingGrades as $g) {
    $c = getCourseById($g['courseId']);
    $s = getSemesterById($g['semesterId']);
    if ($c) $failingGradeRows[] = array_merge($g, ['course' => $c, 'semester' => $s]);
}
$gwa = calculateGWA($allGrades);

// Available subjects for enrollment
$availableSubjects = getCoursesByProgram($user['program'], (int)$user['yearLevel']);

$initials = avatarInitials($user['firstName'], $user['lastName']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Student Dashboard — JARK University</title>
  <link rel="stylesheet" href="style.css">
  <style>
    .grade-table-header { background: linear-gradient(135deg, var(--navy-dark), var(--navy)); color: #fff; }
    .grade-table-header th { color: rgba(255,255,255,.75) !important; background: transparent !important; }
    .gwa-display { font-family: var(--font-display); font-size: 48px; color: var(--navy); line-height: 1; }
    .course-pill {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 4px 10px; background: rgba(11,29,87,.07);
      border-radius: 99px; font-size: 11.5px; font-weight: 600; color: var(--navy);
      margin: 3px;
    }
    .drop-btn { background: none; border: none; cursor: pointer; color: var(--gray-400); transition: var(--transition); }
    .drop-btn:hover { color: var(--red); }
  </style>
</head>
<body>
<div class="app-layout">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <div class="sidebar-logo">
        <div class="sidebar-logo-icon"><img src="JARKULOGO.png" alt="JARK University logo"></div>
        <div class="sidebar-logo-text">
          <span class="sidebar-logo-name">JARK University</span>
          <span class="sidebar-logo-sub">Enrollment System</span>
        </div>
      </div>
      <button class="sidebar-close" id="sidebarClose">✕</button>
    </div>
    <div class="sidebar-user">
      <div class="sidebar-avatar"><?= $initials ?></div>
      <div>
        <div class="sidebar-user-name"><?= htmlspecialchars($user['firstName'] . ' ' . $user['lastName']) ?></div>
        <div class="sidebar-user-role">Student · <?= htmlspecialchars($user['program']) ?></div>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-section-label">Main</div>
      <a href="?tab=overview" class="nav-item<?= $tab==='overview'?' active':'' ?>">📊 Overview</a>
      <a href="?tab=enrollment" class="nav-item<?= $tab==='enrollment'?' active':'' ?>">📝 Enrollment</a>
      <a href="?tab=subjects" class="nav-item<?= $tab==='subjects'?' active':'' ?>">📚 My Subjects</a>
      <a href="?tab=grades" class="nav-item<?= $tab==='grades'?' active':'' ?>">🎓 My Grades</a>
      <a href="?tab=profile" class="nav-item<?= $tab==='profile'?' active':'' ?>">👤 My Profile</a>
    </nav>
    <div class="sidebar-footer">
      <a href="logout.php" class="sidebar-logout">🚪 Sign Out</a>
    </div>
  </aside>
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <!-- MAIN CONTENT -->
  <div class="main-content">
    <header class="topbar">
      <div class="topbar-left">
        <button class="hamburger-btn" id="hamburger"><span></span><span></span><span></span></button>
        <span class="page-title">
          <?= match($tab) {
            'enrollment' => 'Enrollment',
            'subjects'   => 'My Subjects',
            'grades'     => 'My Grades',
            'profile'    => 'My Profile',
            default      => 'Dashboard'
          } ?>
        </span>
      </div>
      <div class="topbar-right">
        <?php if ($sem): ?>
        <div class="topbar-sem">
          <span class="full"><?= htmlspecialchars($sem['label'] ?? 'Semester') ?></span>
          <span class="short">SEM <?= $sem['semester'] ?? '' ?></span>
        </div>
        <?php endif; ?>
        <a href="?tab=profile" class="topbar-avatar"><?= $initials ?></a>
      </div>
    </header>

    <div class="page-content">
      <?= $flash ?>

      <!-- ==================== OVERVIEW ==================== -->
      <?php if ($tab === 'overview'): ?>
      <div class="page-header">
        <div class="page-header-left">
          <h1>Welcome back, <?= htmlspecialchars($user['firstName']) ?>! 👋</h1>
          <p><?= htmlspecialchars(programLabel($user['program'])) ?> · <?= yearLevelLabel((int)$user['yearLevel']) ?></p>
        </div>
        <div><?= enrollmentStatusBadge($enrollStatus) ?></div>
      </div>

      <div class="stat-grid">
        <div class="stat-card"><div class="stat-icon stat-icon-navy">📚</div><div class="stat-value"><?= count($myCourses) ?></div><div class="stat-label">Subjects</div></div>
        <div class="stat-card"><div class="stat-icon stat-icon-gold">⚡</div><div class="stat-value"><?= $totalUnits ?></div><div class="stat-label">Total Units</div></div>
        <div class="stat-card"><div class="stat-icon stat-icon-green">🎯</div><div class="stat-value"><?= $gwa !== null ? number_format($gwa, 2) : 'N/A' ?></div><div class="stat-label">Current GWA</div></div>
        <div class="stat-card"><div class="stat-icon stat-icon-yellow">🎓</div><div class="stat-value"><?= yearLevelLabel((int)$user['yearLevel']) ?></div><div class="stat-label">Year Level</div></div>
      </div>

      <div class="enrollment-banner <?= match($enrollStatus) { 'verified','enrolled' => 'banner-green', 'pending' => 'banner-yellow', default => 'banner-navy' } ?>">
        <div class="banner-icon"><?= match($enrollStatus) { 'verified','enrolled' => '✅', 'pending' => '⏳', default => '📝' } ?></div>
        <div class="banner-content">
          <h3><?= match($enrollStatus) { 'verified','enrolled' => 'Enrollment Verified', 'pending' => 'Pending Verification', default => 'Not Yet Enrolled' } ?></h3>
          <p><?= match($enrollStatus) { 'verified','enrolled' => 'Your enrollment has been confirmed.', 'pending' => 'Your enrollment is awaiting verification.', default => 'Go to Enrollment tab to enroll.' } ?></p>
        </div>
        <?php if ($enrollStatus === 'not-enrolled'): ?>
        <a href="?tab=enrollment" class="btn-primary" style="margin-left:auto;width:auto">Enroll Now →</a>
        <?php endif; ?>
      </div>

      <?php if (!empty($myCourses)): ?>
      <div class="card">
        <div class="card-header"><div class="card-title">Current Subjects</div><a href="?tab=subjects" class="btn-secondary btn-sm">View All</a></div>
        <div class="table-wrap">
          <table class="custom-table">
            <thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Schedule</th><th>Room</th></tr></thead>
            <tbody>
              <?php foreach ($myCourses as $c): ?>
              <tr><td><span class="badge badge-navy"><?= htmlspecialchars($c['code']) ?></span></td><td><strong><?= htmlspecialchars($c['name']) ?></strong></td><td><?= (int)$c['units'] ?></td><td><?= htmlspecialchars($c['schedule']) ?></td><td><?= htmlspecialchars($c['room']) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <?php if (!empty($gradeRows)): ?>
      <div class="card mt-20">
        <div class="card-header"><div class="card-title">Recent Grades</div><a href="?tab=grades" class="btn-secondary btn-sm">View All</a></div>
        <div class="table-wrap">
          <table class="custom-table">
            <thead><tr><th>Subject</th><th>Grade</th><th>Remarks</th></tr></thead>
            <tbody>
              <?php foreach (array_slice($gradeRows, 0, 5) as $g): ?>
              <tr><td><strong><?= htmlspecialchars($g['course']['name']) ?></strong><br><span class="text-sm"><?= htmlspecialchars($g['course']['code']) ?></span></td>
              <td><span class="badge <?= getGradeColorClass($g['grade']) ?>"><?= $g['grade'] !== null ? number_format($g['grade'], 2) : 'INC' ?> (<?= getLetterGrade($g['grade']) ?>)</span></td>
              <td><?= htmlspecialchars(gradeRemarks($g['grade'])) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <!-- ==================== ENROLLMENT ==================== -->
      <?php elseif ($tab === 'enrollment'): ?>
      <div class="page-header"><div class="page-header-left"><h1>Enrollment</h1><p><?= htmlspecialchars($sem['label'] ?? 'Current semester') ?></p></div><div><?= enrollmentStatusBadge($enrollStatus) ?></div></div>

      <?php if (!empty($failingGradeRows)): ?>
      <div class="enrollment-banner banner-red">
        <div class="banner-icon">⚠️</div>
        <div class="banner-content"><h3>Failing Grades Detected</h3><p>Please review your failed subjects below and consult the Registrar before enrolling.</p></div>
      </div>
      <div class="card mb-20">
        <div class="card-header"><div class="card-title">Failed Subjects</div><span class="badge badge-red"><?= count($failingGradeRows) ?> failing</span></div>
        <div class="table-wrap">
          <table class="custom-table"><thead><tr><th>Code</th><th>Subject</th><th>Grade</th></tr></thead><tbody>
            <?php foreach ($failingGradeRows as $g): ?>
            <tr><td><?= htmlspecialchars($g['course']['code']) ?></td><td><?= htmlspecialchars($g['course']['name']) ?></td><td><span class="badge badge-red"><?= number_format((float)$g['grade'], 2) ?></span></td></tr>
            <?php endforeach; ?>
          </tbody></table>
        </div>
      </div>
      <?php endif; ?>

      <?php if (in_array($enrollStatus, ['verified','enrolled'])): ?>
        <div class="card"><div class="empty-state"><div class="empty-icon">✅</div><h3>Already Enrolled</h3><p>Your enrollment has been verified. No changes allowed.</p></div></div>
      <?php elseif ($enrollStatus === 'pending'): ?>
        <div class="card"><div class="empty-state"><div class="empty-icon">⏳</div><h3>Enrollment Pending</h3><p>Your enrollment is under review. You cannot modify it.</p></div></div>
      <?php elseif (!isEnrollmentOpen()): ?>
        <div class="card"><div class="empty-state"><div class="empty-icon">🔒</div><h3>Enrollment Closed</h3><p>The enrollment period is closed. Contact Registrar.</p></div></div>
      <?php elseif (in_array($enrollStatus, ['not-enrolled', 'rejected'], true)): ?>
          <div class="card">
              <div class="card-header">
                  <div class="card-title">Select Your Subjects (New accounts are preset to 3 core program recommendations)</div>
                  <div id="unitCounter" class="badge badge-navy">0 units selected</div>
              </div>
              <form method="POST" action="?tab=enrollment" id="enrollForm">
                  <input type="hidden" name="action" value="enroll">
                  <div class="card-body">
                      <?php
                      $studentObj = getStudentObject($user['id']);
                      $defaultCoreIds = $studentObj ? $studentObj->getDefaultCoreSubjects() : [];
                      
                      // Fetch existing draft choices if any exist
                      $currentDraftSelections = !empty($myEnrollment['subjects']) ? $myEnrollment['subjects'] : $defaultCoreIds;
                      ?>
                      <div id="subjectGrid" class="subject-grid">
                          <?php foreach ($allCourses as $c): 
                              // 1. Check eligibility
                              $isEligible = isEligibleForCourse($user['id'], $c['id']); 
                              // 2. Check if it's already in the student's draft/cart
                              $isChecked = in_array($c['id'], $currentDraftSelections);
                          ?>
                              
                              <label class="subject-card <?= !$isEligible ? 'blocked' : '' ?> <?= $isChecked ? 'selected' : '' ?>" style="display: block; cursor: <?= !$isEligible ? 'not-allowed' : 'pointer' ?>;">
                                  
                                  <div class="flex justify-between">
                                      <span class="badge badge-gold"><?= $c['code'] ?></span>
                                      <span class="badge badge-navy"><?= $c['units'] ?> units</span>
                                  </div>
                                  
                                  <div class="font-display" style="font-size: 18px; margin: 10px 0;"><?= $c['name'] ?></div>
                                  
                                  <?php if (!$isEligible): ?>
                                      <div class="restriction-notice" style="color: red; font-size: 0.8rem; margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--gray-200);">
                                          <strong>Blocked:</strong> Failed 
                                          <?php 
                                              foreach ($c['prerequisites'] as $prereqId) {
                                                  if (!isEligibleForCourse($user['id'], $prereqId)) {
                                                      echo getCourseNameById($prereqId);
                                                      break; 
                                                  }
                                              }
                                          ?>
                                      </div>
                                  <?php else: ?>
                                      <div style="margin-top: 10px; border-top: 1px solid var(--gray-200); padding-top: 10px; display: flex; justify-content: space-between; align-items: center;">
                                          
                                          <span class="text-sm font-bold status-text" style="color: <?= $isChecked ? 'var(--navy)' : 'var(--gray-500)' ?>;">
                                              <?= $isChecked ? '✓ Added to Enrollment' : '+ Click to Add' ?>
                                          </span>
                                          
                                          <input type="checkbox" name="subjects[]" value="<?= $c['id'] ?>" <?= $isChecked ? 'checked' : '' ?> 
                                                data-units="<?= $c['units'] ?>"
                                                onchange="
                                                    // Toggle the blue highlight on the card
                                                    this.closest('.subject-card').classList.toggle('selected', this.checked); 
                                                    
                                                    // Update the text dynamically
                                                    const textSpan = this.previousElementSibling;
                                                    textSpan.innerText = this.checked ? '✓ Added to Enrollment' : '+ Click to Add';
                                                    textSpan.style.color = this.checked ? 'var(--navy)' : 'var(--gray-500)';
                                                    
                                                    // Update the total units counter
                                                    updateUnits();
                                                " 
                                                style="transform: scale(1.3); cursor: pointer;">
                                      </div>
                                  <?php endif; ?>
                              </label>
                          <?php endforeach; ?>
                      </div>            
                       </div>
                  <div class="card-footer" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
                      <span class="text-sm text-gray">Add or drop subjects by checking/unchecking boxes before submitting.</span>
                      <button type="submit" class="btn-primary" style="width:auto">
                          Submit Enrollment Order
                      </button>
                  </div>
              </form>
          </div>
      <?php else: ?>
        <div class="card"><div class="empty-state"><div class="empty-icon">🔒</div><h3>Unknown Status</h3><p>Please contact the Registrar.</p></div></div>
      <?php endif; ?>

      <!-- ==================== MY SUBJECTS ==================== -->
      <?php elseif ($tab === 'subjects'): ?>
      <div class="page-header"><div class="page-header-left"><h1>My Subjects</h1><p><?= htmlspecialchars($sem['label'] ?? 'Current') ?></p></div><div class="flex gap-8"><span class="badge badge-navy"><?= count($myCourses) ?> subjects · <?= $totalUnits ?> units</span><?= enrollmentStatusBadge($enrollStatus) ?></div></div>
      <?php if (empty($myCourses)): ?>
        <div class="card"><div class="empty-state"><div class="empty-icon">📚</div><h3>No Subjects</h3><a href="?tab=enrollment" class="btn-primary mt-16">Enroll Now</a></div></div>
      <?php else: ?>
        <div class="subject-grid" style="grid-template-columns:repeat(auto-fill,minmax(300px,1fr))">
          <?php foreach ($myCourses as $c):
            $prof = getUserById($c['professorId']);
          ?>
          <div class="card">
            <div class="card-body">
              <div class="flex justify-between"><span class="badge badge-gold"><?= htmlspecialchars($c['code']) ?></span><span class="badge badge-navy"><?= (int)$c['units'] ?> units</span></div>
              <div class="font-display" style="font-size:18px;margin:10px 0"><?= htmlspecialchars($c['name']) ?></div>
              <div class="text-sm text-gray">📅 <?= htmlspecialchars($c['schedule']) ?> · 🏛️ <?= htmlspecialchars($c['room']) ?></div>
              <?php if ($prof): ?><div class="text-sm mt-8">👤 <?= htmlspecialchars(($prof['title']??'').' '.$prof['firstName'].' '.$prof['lastName']) ?></div><?php endif; ?>
              <?php if ($enrollStatus === 'pending'): ?>
              <form method="POST" class="mt-12" onsubmit="return confirm('Drop <?= htmlspecialchars($c['name']) ?>?')">
                <input type="hidden" name="action" value="drop"><input type="hidden" name="subject_id" value="<?= htmlspecialchars($c['id']) ?>">
                <button type="submit" class="btn-danger btn-sm">Drop Subject</button>
              </form>
              <?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- ==================== MY GRADES ==================== -->
      <?php elseif ($tab === 'grades'): ?>
      <div class="page-header">
        <div class="page-header-left"><h1>My Grades</h1><p>Academic performance summary</p></div>
        <?php if ($gwa !== null): ?>
        <div class="card" style="padding:16px 24px;text-align:center;border-color:var(--gold-bright)">
          <div class="text-xs font-bold uppercase text-gold">Current GWA</div>
          <div class="gwa-display"><?= number_format($gwa, 2) ?></div>
          <div class="text-sm"><?= getLetterGrade($gwa) ?> · <?= gradeRemarks($gwa) ?></div>
        </div>
        <?php endif; ?>
      </div>

      <?php if (empty($gradeRows)): ?>
        <div class="card"><div class="empty-state"><div class="empty-icon">🎯</div><h3>No Grades Yet</h3><p>Grades will appear once submitted.</p></div></div>
      <?php else: ?>
        <div class="card">
          <!-- Desktop table -->
          <div class="table-wrap grades-desktop-only">
            <table class="custom-table">
              <thead class="grade-table-header"><tr><th>Code</th><th>Subject</th><th>Semester</th><th>Grade</th><th>Letter</th><th>Remarks</th></tr></thead>
              <tbody>
                <?php foreach ($gradeRows as $g): ?>
                <tr>
                  <td><span class="badge badge-navy"><?= htmlspecialchars($g['course']['code']) ?></span></td>
                  <td><strong><?= htmlspecialchars($g['course']['name']) ?></strong><br><span class="text-xs"><?= htmlspecialchars(programLabel($g['course']['program'])) ?></span></td>
                  <td class="text-sm"><?= htmlspecialchars($g['semester']['label'] ?? '—') ?></td>
                  <td><span class="badge <?= getGradeColorClass($g['grade']) ?>"><?= $g['grade'] !== null ? number_format($g['grade'], 2) : 'INC' ?></span></td>
                  <td><strong><?= getLetterGrade($g['grade']) ?></strong></td>
                  <td><span class="badge <?= isPassingGrade($g['grade']) ? 'badge-green' : (isFailingGrade($g['grade']) ? 'badge-red' : 'badge-gray') ?>"><?= htmlspecialchars(gradeRemarks($g['grade'])) ?></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <!-- Mobile accordion -->
          <div class="grades-mobile-only">
            <?php foreach ($gradeRows as $g): ?>
            <details class="grade-mobile-card">
              <summary class="grade-mobile-summary">
                <div><span class="grade-mobile-code"><?= htmlspecialchars($g['course']['code']) ?></span><br><span class="grade-mobile-subject"><?= htmlspecialchars($g['course']['name']) ?></span></div>
                <div><span class="badge <?= getGradeColorClass($g['grade']) ?>"><?= $g['grade'] !== null ? number_format($g['grade'], 2) : 'INC' ?></span><span class="grade-mobile-chevron"></span></div>
              </summary>
              <div class="grade-mobile-details">
                <div><span class="grade-mobile-detail-label">Semester</span><span><?= htmlspecialchars($g['semester']['label'] ?? '-') ?></span></div>
                <div><span class="grade-mobile-detail-label">Program</span><span><?= htmlspecialchars(programLabel($g['course']['program'])) ?></span></div>
                <div><span class="grade-mobile-detail-label">Letter</span><span><strong><?= getLetterGrade($g['grade']) ?></strong></span></div>
                <div><span class="grade-mobile-detail-label">Remarks</span><span class="badge <?= isPassingGrade($g['grade']) ? 'badge-green' : 'badge-red' ?>"><?= htmlspecialchars(gradeRemarks($g['grade'])) ?></span></div>
              </div>
            </details>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- ==================== PROFILE ==================== -->
      <?php elseif ($tab === 'profile'): ?>
      <div class="page-header"><div class="page-header-left"><h1>My Profile</h1><p>Your student information</p></div></div>
      <div class="grid-2">
        <div class="card" style="text-align:center">
          <div class="card-body">
            <div class="avatar avatar-lg" style="margin:0 auto 16px;width:80px;height:80px;font-size:28px"><?= $initials ?></div>
            <h2 class="font-display"><?= htmlspecialchars($user['firstName'] . ' ' . $user['lastName']) ?></h2>
            <p class="text-gray"><?= htmlspecialchars($user['email']) ?></p>
            <div class="flex justify-center gap-8 mt-16"><?= enrollmentStatusBadge($user['enrollmentStatus']) ?><span class="badge badge-navy"><?= $user['program'] ?></span><span class="badge badge-gold"><?= yearLevelLabel((int)$user['yearLevel']) ?></span></div>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><div class="card-title">Student Information</div></div>
          <div class="card-body" style="padding:0">
            <?php $info = ['Student ID'=>$user['id'],'Full Name'=>$user['firstName'].' '.$user['lastName'],'Email'=>$user['email'],'Phone'=>$user['phone']?:'—','Program'=>programLabel($user['program']),'Year Level'=>yearLevelLabel((int)$user['yearLevel']),'Section'=>$user['section']?:'—','Birthday'=>formatDate($user['birthday']??''),'Address'=>$user['address']?:'—']; ?>
            <?php foreach ($info as $label=>$val): ?>
            <div class="flex justify-between p-12 border-bottom"><span class="text-gray"><?= $label ?></span><span><?= htmlspecialchars($val) ?></span></div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
  // Sidebar & unit counter
  const sidebar = document.getElementById('sidebar'), overlay = document.getElementById('sidebarOverlay');
  document.getElementById('hamburger').addEventListener('click', () => { sidebar.classList.add('open'); overlay.classList.add('visible'); document.body.style.overflow = 'hidden'; });
  document.getElementById('sidebarClose').addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('visible'); document.body.style.overflow = ''; });
  overlay.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('visible'); document.body.style.overflow = ''; });
  function updateUnits() { let total = 0; document.querySelectorAll('#subjectGrid input:checked').forEach(cb => total += parseInt(cb.dataset.units || 0)); let counter = document.getElementById('unitCounter'); if(counter) counter.textContent = total + ' units'; }
</script>
</body>
</html>