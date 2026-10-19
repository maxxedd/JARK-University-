<?php
/**
 * JARK University Enrollment System
 * logout.php — Session Termination
 */
require_once 'functions.php';
$role = $_SESSION['role'] ?? null;
sessionLogout();
redirect(in_array($role, ['professor', 'registrar'], true) ? 'staff-login.php' : 'index.php');
