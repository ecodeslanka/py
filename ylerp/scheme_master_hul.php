<?php
/** scheme_master_hul.php — HUL Scheme Master Report import (yellow columns: Activity Code, Activity Desc., Date From, Date To) */
date_default_timezone_set('Asia/Colombo');
ob_start();
include 'config.php';
require_once __DIR__ . '/scheme_master_lib.php';
$CFG = ['type' => 'HUL', 'title' => 'HUL Scheme Master', 'file' => 'scheme_master_hul.php', 'cols' => 'Date From, Date To', 'lbl' => ['Activity Code', 'Activity Desc.', 'Date From', 'Date To'], 'example' => 'HUL_Scheme_Master_Report_2024_09.xlsx'];
$D = sm_handle($conn, $CFG);
ob_end_flush();
include 'header.php';
sm_render($D, $CFG);
include 'footer.php';
