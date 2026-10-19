<?php
/**
 * JARK University Enrollment System
 * index.php — Student Login
 */
require_once 'functions.php';

// Already logged in redirect
if (isLoggedIn()) {
    $role = $_SESSION['role'];
    if ($role === 'student') redirect('student-dashboard.php');
    else redirect('staff-dashboard.php');
}

// ═══ Load and calculate statistics from actual data ═══
$studentsEnrolled = 0;
$programsOffered = 0;
$graduateRate = 97;

try {
    // Count enrolled students
    $usersData = json_decode(file_get_contents(__DIR__ . '/data/users.json'), true) ?: [];
    $enrolledStudents = array_filter($usersData, fn($u) => ($u['role'] ?? '') === 'student' && ($u['enrollmentStatus'] ?? '') === 'enrolled');
    $studentsEnrolled = count($enrolledStudents);
    if ($studentsEnrolled === 0) $studentsEnrolled = 8400; // Default if no data
    
    // Count unique programs
    $coursesData = json_decode(file_get_contents(__DIR__ . '/data/courses.json'), true) ?: [];
    $programs = array_unique(array_filter(array_column($coursesData, 'program')));
    $programsOffered = count($programs) ?: 120; // Default if no data
    
    // Calculate graduate rate
    $gradesData = json_decode(file_get_contents(__DIR__ . '/data/grades.json'), true) ?: [];
    if (!empty($gradesData)) {
        $passedCount = count(array_filter($gradesData, fn($g) => ($g['remarks'] ?? '') === 'Passed'));
        $totalGrades = count($gradesData);
        $graduateRate = $totalGrades > 0 ? round(($passedCount / $totalGrades) * 100) : 97;
    }
} catch (Exception $e) {
    // Fallback to defaults if data files unavailable
    $studentsEnrolled = 8400;
    $programsOffered = 120;
    $graduateRate = 97;
}

$error   = '';
$success = '';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/')), '/');
$baseUrl = $scheme . '://' . $host . ($basePath !== '' ? $basePath : '');
$pageUrl = $scheme . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');
$shareImageUrl = $baseUrl . '/JU.png';
$shareTitle = 'JARK University Student Portal';
$shareDescription = 'Access enrollment, subjects, and academic records through the official JARK University student portal.';
$styleVersion = @filemtime(__DIR__ . '/style.css') ?: time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = strtoupper(trim($_POST['student_id'] ?? ''));
    $password  = strtoupper(trim($_POST['password'] ?? ''));

    if (!$studentId || !$password) {
        $error = 'Please enter your Student ID and password.';
    } else {
        $user = authenticateStudent($studentId, $password);
        if ($user) {
            sessionLogin($user);
            redirect('student-dashboard.php');
        } else {
            $error = 'Invalid Student ID or password. Remember: password is your last name in ALL CAPS.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Student Login — JARK University</title>
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
    /* Login page specific extras */
    .login-page { animation: fadeIn .35s ease; }
    .form-group { animation: fadeSlideUp .4s ease both; }
    .form-group:nth-child(1) { animation-delay: .04s; }
    .form-group:nth-child(2) { animation-delay: .09s; }

    /* Student ID format hint */
    .id-format-hint {
      margin-top: 6px;
      font-size: 11.5px;
      color: var(--gray-400);
      display: flex; align-items: center; gap: 5px;
    }

    /* Login header above form */
    .mobile-login-header {
      display: block;
      text-align: center;
      padding-bottom: 28px;
      border-bottom: 1px solid var(--gray-200);
      margin-bottom: 28px;
    }
    .mobile-logo {
      width: 56px; height: 56px;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 12px;
    }
    .mobile-logo img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }
    .mobile-login-header h1 { font-family: var(--font-display); font-size: 22px; color: var(--navy); font-weight: 400; }
    .mobile-login-header p  { font-size: 12px; color: var(--gray-400); text-transform: uppercase; letter-spacing: .1em; margin-top: 2px; }

    @media (min-width: 769px) {
      .mobile-login-header {
        margin-top: 40px;
        padding-bottom: 22px;
        margin-bottom: 24px;
      }
      .mobile-logo {
        width: 60px;
        height: 60px;
        margin-bottom: 10px;
      }
    }

    @media (max-width: 768px) {
      .mobile-login-header { display: block; }
    }
  </style>
</head>
<body>
<div class="login-page">

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
          <small>Student Portal</small>
        </div>
      </div>

      <div class="hero-headline">
        <h1>Your Academic<br>Journey <em>Starts</em><br>Here.</h1>
        <p>Access your enrollment portal, manage your subjects, track your academic progress — all in one secure place.</p>
      </div>
    </div>

    <div class="hero-bottom">
      <div class="hero-stats">
        <div class="hero-stat">
          <span class="hero-stat-num"><?= number_format($studentsEnrolled) ?>+</span>
          <span class="hero-stat-label">Students Enrolled</span>
        </div>
        <div class="hero-stat">
          <span class="hero-stat-num"><?= $programsOffered ?>+</span>
          <span class="hero-stat-label">Programs Offered</span>
        </div>
        <div class="hero-stat">
          <span class="hero-stat-num"><?= $graduateRate ?>%</span>
          <span class="hero-stat-label">Graduate Rate</span>
        </div>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════ RIGHT FORM PANEL ═══════════════════════ -->
  <div class="login-form-panel">
    <div class="login-form-inner">

      <!-- Mobile only header -->
      <div class="mobile-login-header">
        <div class="mobile-logo"><img src="JARKULOGO.png" alt="JARK University logo"></div>
        <h1>JARK University</h1>
        <p>Excellence · Innovation · Service</p>
      </div>

      <div class="login-form-header">
        <div class="login-badge">🎓 Student Portal</div>
        <h2>Student Sign In</h2>
        <p>Access your enrollment, subjects, and academic records</p>
      </div>

      <?php if ($error): ?>
      <div class="error-msg">
        <span style="font-size:15px">⚠</span>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
      <?php endif; ?>

      <?php if ($success): ?>
      <div class="success-msg">
        <span style="font-size:15px">✓</span>
        <span><?= $success ?></span>
      </div>
      <?php endif; ?>

      <form method="POST" action="" autocomplete="off" novalidate>

        <!-- Student ID -->
        <div class="form-group">
          <label for="student_id">Student ID</label>
          <div class="input-wrap">
            <span class="input-icon">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0" />
              </svg>
            </span>
            <input
              type="text" id="student_id" name="student_id"
              placeholder="e.g. 2023-0001-A"
              value="<?= htmlspecialchars(strtoupper($_POST['student_id'] ?? '')) ?>"
              autocomplete="off" required
              style="text-transform:uppercase"
            />
          </div>
          <div class="id-format-hint">
            <svg width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path stroke-linecap="round" d="M12 16v-4M12 8h.01"/></svg>
            Format: YYYY-NNNN-A (e.g. 2023-0001-A)
          </div>
        </div>

        <!-- Password -->
        <div class="form-group">
          <label for="password">Password</label>
          <div class="input-wrap">
            <span class="input-icon">
              <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
              </svg>
            </span>
            <input
              type="password" id="password" name="password"
              placeholder="Your last name in ALL CAPS"
              autocomplete="current-password" required
            />
            <button type="button" class="pw-toggle" id="pwToggle" aria-label="Toggle password visibility">
              <svg id="eyeOpen" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
              <svg id="eyeClosed" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display:none">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
              </svg>
            </button>
          </div>
        </div>

        <div class="privacy-notice-box">
          <label class="privacy-check-row" for="privacy_notice">
            <div class="privacy-checkbox-wrapper">
              <input type="checkbox" id="privacy_notice" name="privacy_notice" required>
              <svg class="privacy-checkbox-icon" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
              </svg>
            </div>
            <span class="privacy-check-label">I have read the Privacy Notice</span>
          </label>

          <div class="privacy-notice-heading">Privacy Notice</div>
          <div class="privacy-notice-scroll">
            <p>
              The JARK University values and protects your personal information. By logging in, you acknowledge that your student credentials are for your personal use only and must not be shared with others.
            </p>
            <p>
              Information collected through this portal may be used for enrollment, academic records, student services, and other official university processes in accordance with applicable data privacy policies and regulations.
            </p>
            <p>
              Please review the information carefully before proceeding. If you have concerns about your account or your personal data, contact the appropriate university office for assistance.
            </p>
          </div>
        </div>

        <button type="submit" class="btn-primary" id="studentLoginBtn" disabled>
          <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
          </svg>
          Sign In to Student Portal
        </button>
      </form>

      <div class="login-password-note">
        <span class="login-password-note-label">Note</span>
        <p>For those who have <strong>&Ntilde;</strong> in their last name, please replace it with an <strong>N</strong>. Example: <strong>NI&Ntilde;O</strong> will be <strong>NINO</strong>.</p>
      </div>

    </div>
  </div><!-- /login-form-panel -->
</div><!-- /login-page -->

<script>
  // ── Password toggle ──
  const pwToggle = document.getElementById('pwToggle');
  const pwInput  = document.getElementById('password');
  const eyeOpen  = document.getElementById('eyeOpen');
  const eyeClosed= document.getElementById('eyeClosed');
  const privacyNotice = document.getElementById('privacy_notice');
  const studentLoginBtn = document.getElementById('studentLoginBtn');

  pwToggle.addEventListener('click', () => {
    const isPass = pwInput.type === 'password';
    pwInput.type = isPass ? 'text' : 'password';
    eyeOpen.style.display  = isPass ? 'none' : '';
    eyeClosed.style.display= isPass ? '' : 'none';
  });

  function syncLoginButtonState() {
    studentLoginBtn.disabled = !privacyNotice.checked;
  }

  privacyNotice.addEventListener('change', syncLoginButtonState);
  syncLoginButtonState();

  // ── Student ID auto-uppercase ──
  document.getElementById('student_id').addEventListener('input', function() {
    const pos = this.selectionStart;
    this.value = this.value.toUpperCase();
    this.setSelectionRange(pos, pos);
  });

  // ── Demo auto-fill ──
  document.querySelectorAll('.cred-row code').forEach(el => {
    el.addEventListener('click', () => {
      const field = el.dataset.field;
      const val   = el.dataset.value;
      if (field) {
        const input = document.getElementById(field === 'student_id' ? 'student_id' : 'password');
        if (input) {
          input.value = val;
          input.focus();
          // flash effect
          el.style.background = 'var(--navy)';
          el.style.color = '#fff';
          setTimeout(() => {
            el.style.background = '';
            el.style.color = '';
          }, 600);
        }
      }
    });
  });
</script>
</body>
</html>
