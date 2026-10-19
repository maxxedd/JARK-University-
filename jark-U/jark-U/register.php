<?php
require_once 'functions.php';

flash('warning', "Student account creation is handled by the Registrar's Office. Please request your student credentials from the registrar.");
redirect('index.php');
