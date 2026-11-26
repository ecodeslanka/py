<?php
require __DIR__ . '/inc/bootstrap.php';
$_SESSION = []; session_destroy(); session_start(); flash('Logged out.'); redirect('login');
