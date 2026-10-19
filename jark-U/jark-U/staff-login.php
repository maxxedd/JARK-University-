<?php
/**
 * JARK University Enrollment System
 * staff-login.php — Professor / Registrar Login
 * Note: This page is not linked from the student portal.
 * Staff access this directly via URL.
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'functions.php';

// Already logged in
if (isLoggedIn()) {
    $role = $_SESSION['role'];
    if (in_array($role, ['professor', 'registrar'])) redirect('staff-dashboard.php');
    else redirect('index.php'); // student trying to access staff
}

$error = '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/')), '/');
$baseUrl = $scheme . '://' . $host . ($basePath !== '' ? $basePath : '');
$pageUrl = $scheme . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');
$shareImageUrl = $baseUrl . '/JU.png';
$shareTitle = 'JARK University Portal';
$shareDescription = 'Official JARK University portal for student enrollment and staff access.';
$styleVersion = @filemtime(__DIR__ . '/style.css') ?: time();

// Inside POST structural router inside staff-dashboard.php:
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // New Registrar historical previous grade entry handler
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
    }
}

$styleVersion = @filemtime(__DIR__ . '/style.css') ?: time();
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Staff Login — JARK University</title>
  <meta name="description" content="<?= htmlspecialchars($shareDescription) ?>" />
  <meta property="og:type" content="website" />
  <meta property="og:site_name" content="JARK University" />
  <meta property="og:title" content="<?= htmlspecialchars($shareTitle) ?>" />
  <meta property="og:description" content="<?= htmlspecialchars($shareDescription) ?>" />
  <meta property="og:url" content="<?= htmlspecialchars($pageUrl) ?>" />
  <meta property="og:image" content="<?= htmlspecialchars($shareImageUrl) ?>" />
  <meta property="og:image:alt" content="JARK University portal preview" />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="<?= htmlspecialchars($shareTitle) ?>" />
  <meta name="twitter:description" content="<?= htmlspecialchars($shareDescription) ?>" />
  <meta name="twitter:image" content="<?= htmlspecialchars($shareImageUrl) ?>" />
  <link rel="stylesheet" href="style.css?v=<?= urlencode((string)$styleVersion) ?>" />
  <style>
    /* Staff login: dark academic tone */
    .login-hero {
      background: linear-gradient(160deg, #040a1e 0%, #0b1d57 55%, #1a2f80 100%) !important;
    }
    .staff-role-tabs {
      display: flex; gap: 8px;
      background: var(--gray-100); border-radius: 10px;
      padding: 4px; margin-bottom: 26px;
    }
    .role-tab {
      flex: 1; padding: 9px 12px; border-radius: 7px;
      font-size: 13px; font-weight: 600; color: var(--gray-500);
      text-align: center; cursor: pointer; transition: var(--transition);
      border: none; background: none; font-family: var(--font-body);
    }
    .role-tab.active { background: var(--white); color: var(--navy); box-shadow: var(--shadow-sm); }

    .staff-badge { background: rgba(11,29,87,.08); color: var(--navy); border-color: rgba(11,29,87,.15); }

    .security-note {
      margin-top: 20px; padding: 13px 14px;
      background: rgba(11,29,87,.04); border: 1px solid rgba(11,29,87,.1);
      border-radius: var(--radius-sm); font-size: 12px; color: var(--gray-600);
      display: flex; gap: 8px; align-items: flex-start;
    }
    .security-note svg { flex-shrink: 0; margin-top: 1px; color: var(--navy); }

    .mobile-login-header {
      display: none; text-align: center;
      padding-bottom: 28px; border-bottom: 1px solid var(--gray-200); margin-bottom: 28px;
    }
    .mobile-logo {
      width: 56px; height: 56px;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 12px;
    }
    .mobile-logo img { width: 100%; height: 100%; object-fit: contain; }
    .mobile-login-header h1 { font-family: var(--font-display); font-size: 22px; color: var(--navy); font-weight: 400; }
    .mobile-login-header p { font-size: 12px; color: var(--gray-400); text-transform: uppercase; letter-spacing: .1em; margin-top: 2px; }

    @media (max-width: 768px) {
      .mobile-login-header { display: block; }
    }

    .cred-hint { margin-top: 20px; }

    @media (min-width: 769px) {
      .login-page {
        overflow: hidden;
      }

      .login-hero {
        padding: 40px 42px;
      }

      .hero-brand {
        margin-bottom: 40px;
      }

      .hero-headline h1 {
        font-size: clamp(32px, 3.1vw, 50px);
        margin-bottom: 14px;
      }

      .hero-headline p {
        font-size: 14px;
        line-height: 1.6;
        max-width: 34ch;
      }

      .hero-stats {
        gap: 10px;
      }

      .hero-stat {
        padding: 16px 12px;
      }

      .hero-stat-num {
        font-size: 24px;
      }

      .hero-stat-label {
        font-size: 10px;
      }

      .login-form-panel {
        padding: 18px 24px;
      }

      .login-form-inner {
        max-width: 400px;
      }

      .login-form-header {
        margin-bottom: 18px;
      }

      .login-badge {
        margin-bottom: 8px;
      }

      .login-form-header h2 {
        font-size: 27px;
      }

      .login-form-header p {
        font-size: 12.5px;
      }

      .staff-role-tabs {
        margin-bottom: 18px;
      }

      .role-tab {
        padding: 8px 10px;
        font-size: 12px;
      }

      .form-group {
        margin-bottom: 12px;
      }

      .security-note {
        margin-top: 12px;
        padding: 10px 12px;
        font-size: 11px;
      }

      .btn-primary {
        padding: 12px 18px;
      }
    }

    @media (min-width: 769px) and (max-height: 920px) {
      .login-hero {
        padding: 34px 36px;
      }

      .hero-brand {
        margin-bottom: 30px;
      }

      .hero-headline h1 {
        font-size: clamp(28px, 2.8vw, 44px);
        margin-bottom: 12px;
      }

      .hero-headline p {
        font-size: 13px;
        line-height: 1.5;
      }

      .login-form-panel {
        padding: 14px 20px;
      }

      .login-form-inner {
        max-width: 400px;
      }

      .staff-role-tabs {
        margin-bottom: 14px;
      }

      .security-note {
        margin-top: 10px;
        padding: 9px 10px;
        font-size: 10.5px;
      }
    }
  </style>
</head>
<body>
<div class="login-page" style="animation:fadeIn .35s ease">

  <!-- ═══════════════════════ LEFT HERO PANEL ═══════════════════════ -->
  <div class="login-hero">
    <div class="hero-grid-lines"></div>
    <div class="hero-orb hero-orb-1"></div>
    <div class="hero-orb hero-orb-2"></div>
    <div class="hero-orb hero-orb-3"></div>

    <div class="hero-top">
      <div class="hero-brand">
        <div class="hero-logo"><img src="JARKULOGO.png" alt="JARK University logo"></div>
        <div class="hero-brand-text">
          JARK University
          <small>Administration Portal</small>
        </div>
      </div>

      <div class="hero-headline">
        <h1>Staff <em>Control</em><br>Center.</h1>
        <p>Manage student records, verify enrollments, submit grades, and generate reports — all from your secure staff dashboard.</p>
      </div>
    </div>

    <div class="hero-bottom">
      <div class="hero-stats">
        <div class="hero-stat">
          <span class="hero-stat-num">2</span>
          <span class="hero-stat-label">Staff Roles</span>
        </div>
        <div class="hero-stat">
          <span class="hero-stat-num">8,400+</span>
          <span class="hero-stat-label">Students Managed</span>
        </div>
        <div class="hero-stat">
          <span class="hero-stat-num">Secure</span>
          <span class="hero-stat-label">Access Only</span>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════ RIGHT FORM PANEL ═══════════════════════ -->
  <div class="login-form-panel">
    <div class="login-form-inner">

      <div class="mobile-login-header">
        <div class="mobile-logo"><img src="JARKULOGO.png" alt="JARK University logo"></div>
        <h1>JARK University</h1>
        <p>Staff Administration Portal</p>
      </div>

      <div class="login-form-header">
        <div class="login-badge staff-badge">🏛️ Staff Portal</div>
        <h2>Staff Sign In</h2>
        <p>Restricted access for professors and registrar staff only</p>
      </div>

      <!-- Role tabs (visual only, both use same form) -->
      <div class="staff-role-tabs">
        <button type="button" class="role-tab active" onclick="setRole(this, 'professor')">
          👨‍🏫 Professor
        </button>
        <button type="button" class="role-tab" onclick="setRole(this, 'registrar')">
          📋 Registrar
        </button>
      </div>

      <?php if ($error): ?>
      <div class="error-msg">
        <span style="font-size:15px">⚠</span>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
      <?php endif; ?>

      <form method="POST" action="" autocomplete="off" novalidate>

        <!-- Username -->
        <div class="form-group" style="animation:fadeSlideUp .4s .04s ease both">
          <label for="username">Username</label>
          <div class="input-wrap">
            <span class="input-icon">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
            </span>
            <input
              type="text" id="username" name="username"
              placeholder="e.g. prof.mendoza"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
              autocomplete="username" required
            />
          </div>
        </div>

        <!-- Password -->
        <div class="form-group" style="animation:fadeSlideUp .4s .09s ease both">
          <label for="password">Password</label>
          <div class="input-wrap">
            <span class="input-icon">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
              </svg>
            </span>
            <input
              type="password" id="password" name="password"
              placeholder="Enter your password"
              autocomplete="current-password" required
            />
            <button type="button" class="pw-toggle" id="pwToggle" aria-label="Toggle password">
              <svg id="eyeOpen" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
              <svg id="eyeClosed" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:none">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
              </svg>
            </button>
          </div>
        </div>

        <div class="privacy-notice-box" style="animation:fadeSlideUp .4s .13s ease both">
          <label class="privacy-check-row" for="staff_privacy_notice">
            <div class="privacy-checkbox-wrapper">
              <input type="checkbox" id="staff_privacy_notice" name="staff_privacy_notice" required>
              <svg class="privacy-checkbox-icon" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <span class="privacy-check-label">I have read the Privacy Notice</span>
          </label>

          <div class="privacy-notice-heading">Privacy Notice</div>
          <div class="privacy-notice-scroll">
            <p>
              Staff access to this portal is limited to authorized professors and registrar personnel only. All account activity may be monitored to protect university records and services.
            </p>
            <p>
              Student credentials, enrollment records, and grades must be handled with strict confidentiality and used only for legitimate university duties in accordance with data privacy policies and institutional regulations.
            </p>
            <p>
              For student passwords based on surnames with <strong>&Ntilde;</strong>, replace it with <strong>N</strong>. Example: <strong>NI&Ntilde;O</strong> becomes <strong>NINO</strong>.
            </p>
          </div>
        </div>

        <button type="submit" class="btn-primary" id="staffLoginBtn" disabled style="animation:fadeSlideUp .4s .17s ease both">
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
          </svg>
          Sign In to Staff Portal
        </button>
      </form>

      <!-- Security notice -->
      <div class="security-note">
        <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
        </svg>
        <span>This page is restricted to authorized JARK University staff only. Unauthorized access attempts are logged. Students should use the <a href="index.php" style="color:var(--navy);font-weight:700;">Student Portal</a>.</span>
      </div>

    </div>
  </div>
</div>

<script>
  // Password toggle
  const pwToggle = document.getElementById('pwToggle');
  const pwInput  = document.getElementById('password');
  const eyeOpen  = document.getElementById('eyeOpen');
  const eyeClosed= document.getElementById('eyeClosed');
  const staffPrivacyNotice = document.getElementById('staff_privacy_notice');
  const staffLoginBtn = document.getElementById('staffLoginBtn');

  pwToggle.addEventListener('click', () => {
    const isPass = pwInput.type === 'password';
    pwInput.type = isPass ? 'text' : 'password';
    eyeOpen.style.display  = isPass ? 'none' : '';
    eyeClosed.style.display= isPass ? '' : 'none';
  });

  function syncStaffLoginButtonState() {
    staffLoginBtn.disabled = !staffPrivacyNotice.checked;
  }

  staffPrivacyNotice.addEventListener('change', syncStaffLoginButtonState);
  syncStaffLoginButtonState();

  // Role tab switch (visual only)
  function setRole(el, role) {
    document.querySelectorAll('.role-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    // Update placeholder hints
    const uInput = document.getElementById('username');
    if (role === 'professor') uInput.placeholder = 'e.g. prof.mendoza';
    else uInput.placeholder = 'e.g. registrar.main';
  }
</script>
</body>
</html>

