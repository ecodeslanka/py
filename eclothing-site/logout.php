<?php
/** logout.php — signs the customer out. */
require_once __DIR__ . '/config/config.php';

unset($_SESSION['customer_id'], $_SESSION['customer_name']);
session_regenerate_id(true);

redirect('/');
