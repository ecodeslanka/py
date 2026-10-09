<?php
require_once __DIR__ . '/includes/auth.php';
$_SESSION = [];
session_destroy();
redirect(base_url() . '/admin/login.php');
