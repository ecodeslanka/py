<?php
/** scheme_master_tot.php — TOT Scheme Master import (same layout as the HUL report: yellow columns Activity Code, Activity Desc., Date From, Date To) */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
require_once __DIR__ . '/scheme_master_lib.php';
$CFG = ['type' => 'TOT', 'title' => 'TOT Scheme Master', 'file' => 'scheme_master_tot.php', 'example' => 'TOT_Scheme_Master_2025_05.xlsx'];
$D = sm_handle($conn, $CFG);
ob_end_flush();
include 'header.php';
sm_render($D, $CFG);
include 'footer.php';
