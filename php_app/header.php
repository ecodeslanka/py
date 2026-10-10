<?php
// Include config if $conn not already available
if (!isset($conn)) { include_once 'config.php'; }
include_once 'auth.php';

// Require login for all pages using this header
requireLogin();

// Get current user data
$current_user    = getCurrentUser();
$user_initials   = getUserInitials($current_user['username']);

// ── Fetch live notifications for bell icon ──────────────────────────────────
$header_notifications = [];
$header_notif_count   = 0;
if (isset($conn) && $conn) {
    $hn_sql    = "SELECT * FROM notifications
                  WHERE active = 1
                    AND valid_until >= UTC_TIMESTAMP()
                  ORDER BY valid_from ASC
                  LIMIT 10";
    $hn_result = mysqli_query($conn, $hn_sql);
    if ($hn_result && mysqli_num_rows($hn_result) > 0) {
        $header_notifications = mysqli_fetch_all($hn_result, MYSQLI_ASSOC);
        $header_notif_count   = count($header_notifications);
    }
}

// ── Fetch active company name ───────────────────────────────────────────────
$active_company_name = null;
if (isset($conn) && $conn) {
    $ac_sql    = "SELECT company_name FROM companies WHERE active = 1 LIMIT 1";
    $ac_result = mysqli_query($conn, $ac_sql);
    if ($ac_result && mysqli_num_rows($ac_result) > 0) {
        $ac_row              = mysqli_fetch_assoc($ac_result);
        $active_company_name = $ac_row['company_name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YMS - Yelo Group Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css">

    <?php date_default_timezone_set('Asia/Colombo'); ?>

    <!-- Fixed Header/Footer Layout Styles -->
    <style>
        html, body {
            height: 100%;
            overflow: hidden;
            margin: 0;
            padding: 0;
        }
        .header {
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 1000;
        }
        .footer {
            position: fixed;
            bottom: 0; left: 0; right: 0;
            z-index: 1000;
        }
        .container {
            position: fixed;
            top: 60px;
            bottom: 40px;
            left: 0; right: 0;
            display: flex;
            overflow: hidden;
        }
        .sidebar {
            position: relative;
            height: 100%;
            overflow-y: auto;
            overflow-x: hidden;
        }
        .main-content {
            flex: 1;
            height: 100%;
            overflow-y: auto;
            overflow-x: hidden;
            position: relative;
        }
        .overlay { z-index: 999; }
    </style>

    <!-- YMS Loader Styles -->
    <style>
        .yms-loader {
            position: fixed; top: 0; left: 0;
            width: 100%; height: 100%;
            background: #ffffff;
            display: flex; align-items: center; justify-content: center;
            z-index: 99999;
            transition: opacity 0.4s ease, visibility 0.4s ease;
        }
        .yms-loader.loaded { opacity: 0; visibility: hidden; }
        .loader-content { text-align: center; }
        .loader-logo { margin-bottom: 30px; animation: logoFade 1.5s ease-in-out infinite; }
        .loader-logo img { width: 80px; height: 80px; object-fit: contain; }
        @keyframes logoFade {
            0%,100% { opacity:1; transform:scale(1); }
            50%      { opacity:0.7; transform:scale(0.95); }
        }
        .loader-spinner { margin-bottom: 20px; }
        .spinner {
            width: 50px; height: 50px; margin: 0 auto;
            border: 4px solid #f0f0f0;
            border-top: 4px solid #000000;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 0%{transform:rotate(0deg)} 100%{transform:rotate(360deg)} }
        .loader-text {
            font-family: 'Inter', sans-serif;
            font-size: 14px; font-weight: 500; color: #666666;
            letter-spacing: 1px;
            animation: textPulse 1.5s ease-in-out infinite;
        }
        @keyframes textPulse { 0%,100%{opacity:1} 50%{opacity:0.5} }
        @media (max-width:768px) {
            .loader-logo img { width:60px; height:60px; }
            .spinner { width:40px; height:40px; border-width:3px; }
            .loader-text { font-size:12px; }
        }
    </style>

    <!-- Company Badge Styles -->
    <style>
        .header-company-badge {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            pointer-events: none;
            z-index: 10;
        }
        .company-badge {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 5px 14px;
            background: #fefce8;
            color: #854d0e;
            border: 1.5px solid #fde68a;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.3px;
            white-space: nowrap;
            font-family: 'Inter', sans-serif;
        }
        .company-badge i {
            font-size: 12px;
            color: #ca8a04;
        }
        @media (max-width: 900px) {
            .header-company-badge { display: none; }
        }
    </style>

    <!-- Notification Bell Styles -->
    <style>
        .notif-bell-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 8px;
        }
        .notif-bell-btn {
            position: relative;
            width: 38px; height: 38px;
            border-radius: 10px;
            border: 1.5px solid rgba(0,0,0,0.08);
            background: #ffffff;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            transition: all 0.25s cubic-bezier(.4,0,.2,1);
            box-shadow: 0 1px 4px rgba(0,0,0,0.07);
            outline: none;
        }
        .notif-bell-btn:hover {
            background: #f8f8f8;
            border-color: rgba(0,0,0,0.15);
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(0,0,0,0.10);
        }
        .notif-bell-btn i {
            font-size: 16px;
            color: #333;
            transition: color 0.2s;
        }
        .notif-bell-btn:hover i { color: #000; }
        .notif-bell-btn.has-notifs i {
            animation: bellShake 3s ease-in-out infinite;
            transform-origin: top center;
        }
        @keyframes bellShake {
            0%,55%,100% { transform: rotate(0deg); }
            60%          { transform: rotate(14deg); }
            65%          { transform: rotate(-12deg); }
            70%          { transform: rotate(10deg); }
            75%          { transform: rotate(-8deg); }
            80%          { transform: rotate(6deg); }
            85%          { transform: rotate(-4deg); }
            90%          { transform: rotate(2deg); }
            95%          { transform: rotate(0deg); }
        }
        .notif-badge {
            position: absolute;
            top: -5px; right: -5px;
            min-width: 18px; height: 18px;
            padding: 0 5px;
            background: #ef4444;
            color: #fff;
            font-size: 10px; font-weight: 700;
            border-radius: 999px;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid #fff;
            animation: badgePop 0.4s cubic-bezier(.36,.07,.19,.97);
            box-shadow: 0 2px 6px rgba(239,68,68,0.45);
        }
        @keyframes badgePop {
            0%   { transform: scale(0); opacity:0; }
            70%  { transform: scale(1.25); }
            100% { transform: scale(1); opacity:1; }
        }
        .notif-badge::after {
            content: '';
            position: absolute;
            inset: -3px;
            border-radius: 999px;
            border: 2px solid #ef4444;
            animation: rippleRing 2s ease-out infinite;
            opacity: 0;
        }
        @keyframes rippleRing {
            0%   { transform: scale(1);   opacity: 0.7; }
            100% { transform: scale(2.2); opacity: 0; }
        }
        .notif-dropdown {
            position: absolute;
            top: calc(100% + 12px);
            right: 0;
            width: 360px;
            background: #fff;
            border-radius: 16px;
            border: 1px solid rgba(0,0,0,0.08);
            box-shadow: 0 20px 60px rgba(0,0,0,0.15), 0 4px 16px rgba(0,0,0,0.07);
            z-index: 9999;
            overflow: hidden;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-8px) scale(0.97);
            transform-origin: top right;
            transition: opacity 0.22s ease, transform 0.22s cubic-bezier(.4,0,.2,1), visibility 0.22s;
        }
        .notif-dropdown.open {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }
        .notif-dropdown::before {
            content: '';
            position: absolute;
            top: -7px; right: 14px;
            width: 13px; height: 13px;
            background: #fff;
            border-left: 1px solid rgba(0,0,0,0.08);
            border-top: 1px solid rgba(0,0,0,0.08);
            transform: rotate(45deg);
            border-radius: 2px;
        }
        .notif-drop-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 16px 18px 12px;
            border-bottom: 1px solid #f0f0f0;
        }
        .notif-drop-header h4 {
            font-size: 14px; font-weight: 700; color: #111;
            margin: 0; display: flex; align-items: center; gap: 8px;
        }
        .notif-drop-header h4 i { color: #f59e0b; font-size: 15px; }
        .notif-live-pill {
            font-size: 10px; font-weight: 700;
            background: #f0fdf4; color: #16a34a;
            border: 1px solid #bbf7d0;
            padding: 2px 8px; border-radius: 999px;
            display: flex; align-items: center; gap: 5px;
        }
        .notif-live-dot {
            width: 6px; height: 6px; border-radius: 50%; background: #16a34a;
            animation: livePulse 1.4s ease-in-out infinite;
        }
        @keyframes livePulse {
            0%,100% { transform:scale(1); opacity:1; }
            50%      { transform:scale(1.5); opacity:0.5; }
        }
        .notif-drop-list {
            max-height: 340px;
            overflow-y: auto;
            padding: 8px 0;
            scrollbar-width: thin;
            scrollbar-color: #e5e5e5 transparent;
        }
        .notif-drop-list::-webkit-scrollbar { width: 4px; }
        .notif-drop-list::-webkit-scrollbar-track { background: transparent; }
        .notif-drop-list::-webkit-scrollbar-thumb { background: #e5e5e5; border-radius: 4px; }
        .notif-drop-item {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 12px 18px;
            border-bottom: 1px solid #f7f7f7;
            border-left: 3px solid transparent;
            cursor: default;
            transition: background 0.15s;
            animation: itemSlideIn 0.3s ease both;
        }
        .notif-drop-item:last-child { border-bottom: none; }
        .notif-drop-item:hover { background: #fafafa; }
        @keyframes itemSlideIn {
            from { opacity:0; transform: translateX(10px); }
            to   { opacity:1; transform: translateX(0); }
        }
        .notif-drop-item:nth-child(1) { animation-delay: 0.05s; }
        .notif-drop-item:nth-child(2) { animation-delay: 0.10s; }
        .notif-drop-item:nth-child(3) { animation-delay: 0.15s; }
        .notif-drop-item:nth-child(4) { animation-delay: 0.20s; }
        .notif-drop-item:nth-child(5) { animation-delay: 0.25s; }
        .notif-drop-icon {
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; flex-shrink: 0; margin-top: 1px;
        }
        .notif-drop-icon.di-info    { background:#eff6ff; color:#2563eb; }
        .notif-drop-icon.di-success { background:#f0fdf4; color:#16a34a; }
        .notif-drop-icon.di-warning { background:#fffbeb; color:#d97706; }
        .notif-drop-icon.di-danger  { background:#fef2f2; color:#dc2626; }
        .notif-drop-item.di-info    { border-left-color: #3b82f6; }
        .notif-drop-item.di-success { border-left-color: #22c55e; }
        .notif-drop-item.di-warning { border-left-color: #f59e0b; }
        .notif-drop-item.di-danger  { border-left-color: #ef4444; }
        .notif-drop-body { flex: 1; min-width: 0; }
        .notif-drop-title {
            font-size: 13px; font-weight: 600; color: #111;
            margin: 0 0 3px; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }
        .notif-drop-msg {
            font-size: 11.5px; color: #666; line-height: 1.45;
            margin: 0 0 5px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .notif-drop-time {
            font-size: 10.5px; color: #aaa; font-weight: 500;
            display: flex; align-items: center; gap: 4px;
        }
        .notif-drop-time i { font-size: 9px; }
        .notif-drop-empty {
            text-align: center; padding: 36px 20px; color: #aaa;
        }
        .notif-drop-empty i { font-size: 36px; margin-bottom: 10px; display: block; opacity: 0.4; }
        .notif-drop-empty p { font-size: 13px; margin: 0; }
        .notif-drop-footer {
            padding: 10px 18px;
            border-top: 1px solid #f0f0f0;
            text-align: center;
        }
        .notif-drop-footer a {
            font-size: 12px; font-weight: 600; color: #555;
            text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
            transition: color 0.2s;
        }
        .notif-drop-footer a:hover { color: #000; }
        @media (max-width: 480px) {
            .notif-dropdown { width: calc(100vw - 24px); right: -8px; }
        }
    </style>
    
    <style>
    .submenu.open { 
        max-height: 9999px !important; 
        overflow: visible !important; 
    }
    .nested-submenu.open { 
        max-height: 9999px !important; 
        overflow: visible !important; 
    }
</style>

<!-- Sidebar Header Restyle (gradient brand + search + section polish) -->
<style>
    .ms-sidebar-brand {
        margin: 12px 14px 14px;
        padding: 16px 14px;
        border-radius: 16px;
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 55%, #9333ea 100%);
        box-shadow: 0 8px 20px rgba(124,58,237,0.28);
        position: relative;
        overflow: hidden;
    }
    .ms-sidebar-brand::after {
        content: '';
        position: absolute;
        top: -30%; right: -20%;
        width: 140px; height: 140px;
        background: radial-gradient(circle, rgba(255,255,255,0.18) 0%, rgba(255,255,255,0) 70%);
        pointer-events: none;
    }
    .ms-sidebar-brand-row {
        display: flex;
        align-items: center;
        gap: 10px;
        position: relative;
        z-index: 1;
    }
    .ms-sidebar-logo-icon {
        flex-shrink: 0;
        width: 40px; height: 40px;
        border-radius: 11px;
        background: rgba(255,255,255,0.18);
        border: 1px solid rgba(255,255,255,0.35);
        display: flex; align-items: center; justify-content: center;
    }
    .ms-sidebar-logo-icon img { width: 22px; height: 22px; object-fit: contain; }
    .ms-sidebar-brand-text { flex: 1; min-width: 0; }
    .ms-sidebar-brand-text h2 {
        margin: 0; font-size: 15px; font-weight: 700; color: #ffffff;
        letter-spacing: 0.3px; font-family: 'Inter', sans-serif;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .ms-sidebar-brand-text p {
        margin: 1px 0 0; font-size: 11px; color: rgba(255,255,255,0.8);
        font-family: 'Inter', sans-serif; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }

    /* Floating edge toggle — always visible, positioned via JS so it can never be
       clipped by the sidebar's own overflow/width, in expanded or collapsed state */
    .ms-edge-toggle {
        position: fixed;
        width: 30px; height: 30px;
        border-radius: 50%;
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        border: 3px solid #ffffff;
        color: #ffffff;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer;
        box-shadow: 0 3px 10px rgba(0,0,0,0.22);
        transition: transform 0.2s ease, background 0.2s ease;
        z-index: 500;
        padding: 0;
    }
    .ms-edge-toggle:hover { transform: scale(1.1); }
    .ms-edge-toggle i { font-size: 11px; }

    .ms-sidebar-search {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 0 14px 14px;
        padding: 9px 12px;
        border-radius: 12px;
        background: #f3f4f6;
        border: 1px solid #e5e7eb;
        transition: border-color 0.2s ease, background 0.2s ease;
    }
    .ms-sidebar-search:focus-within {
        border-color: #a78bfa;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(124,58,237,0.12);
    }
    .ms-sidebar-search i { color: #9ca3af; font-size: 13px; flex-shrink: 0; }
    .ms-sidebar-search input {
        border: none; background: transparent; outline: none;
        font-size: 13px; font-family: 'Inter', sans-serif; color: #374151;
        flex: 1; min-width: 0;
    }
    .ms-sidebar-search input::placeholder { color: #9ca3af; }
    .ms-search-kbd {
        flex-shrink: 0;
        font-size: 10px; font-weight: 600; color: #9ca3af;
        background: #ffffff; border: 1px solid #e5e7eb;
        border-radius: 5px; padding: 2px 6px;
        font-family: 'Inter', sans-serif;
    }

    /* Section title icons */
    .menu-title {
        display: flex !important;
        align-items: center;
        gap: 8px;
    }
    .menu-title-icon {
        width: 20px; height: 20px;
        border-radius: 6px;
        display: flex; align-items: center; justify-content: center;
        font-size: 10px; color: #ffffff; flex-shrink: 0;
    }
    .mti-blue   { background: linear-gradient(135deg,#3b82f6,#2563eb); }
    .mti-green  { background: linear-gradient(135deg,#22c55e,#16a34a); }
    .mti-cyan   { background: linear-gradient(135deg,#06b6d4,#0891b2); }
    .mti-orange { background: linear-gradient(135deg,#f59e0b,#d97706); }
    .mti-purple { background: linear-gradient(135deg,#a855f7,#7c3aed); }

    /* Color-coded per-module icon badges (top-level menu items, matches reference image) */
    #sidebar .menu-item-content .menu-icon {
        width: 30px; height: 30px;
        border-radius: 9px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
        color: #ffffff;
        font-size: 13px;
        background: #9ca3af;
        box-shadow: 0 1px 3px rgba(0,0,0,0.12);
    }
    #sidebar .menu-item-content .menu-icon img {
        width: 16px; height: 16px; object-fit: contain;
        border-radius: 3px;
    }
    #sidebar .menu-icon.icon-purple { background: linear-gradient(135deg,#a855f7,#7c3aed); }
    #sidebar .menu-icon.icon-teal   { background: linear-gradient(135deg,#14b8a6,#0d9488); }
    #sidebar .menu-icon.icon-blue   { background: linear-gradient(135deg,#3b82f6,#2563eb); }
    #sidebar .menu-icon.icon-pink   { background: linear-gradient(135deg,#ec4899,#db2777); }
    #sidebar .menu-icon.icon-green  { background: linear-gradient(135deg,#22c55e,#16a34a); }
    #sidebar .menu-icon.icon-orange { background: linear-gradient(135deg,#f59e0b,#d97706); }
    #sidebar .menu-icon.icon-cyan   { background: linear-gradient(135deg,#06b6d4,#0891b2); }
    #sidebar .menu-icon.icon-navy   { background: linear-gradient(135deg,#1e3a8a,#1e40af); }
    #sidebar .ms-dashboard-link .menu-icon.mti-icon-blue { background: linear-gradient(135deg,#4f46e5,#7c3aed); }

    /* Dashboard highlight (matches active nav card style) */
    #sidebar a.ms-dashboard-link {
        border-radius: 12px !important;
        margin: 0 10px 4px !important;
    }
    #sidebar a.ms-dashboard-link.active {
        background: linear-gradient(135deg, rgba(79,70,229,0.12), rgba(147,51,234,0.10)) !important;
        border: 1px solid rgba(124,58,237,0.25) !important;
        color: #4f46e5 !important;
        position: relative;
    }
    #sidebar a.ms-dashboard-link.active::before {
        content: '';
        position: absolute;
        left: -10px; top: 50%; transform: translateY(-50%);
        width: 6px; height: 6px; border-radius: 50%;
        background: #4f46e5;
        box-shadow: 0 0 0 4px rgba(79,70,229,0.15);
    }
    #sidebar a.ms-dashboard-link .mti-icon-blue {
        background: linear-gradient(135deg,#4f46e5,#7c3aed);
        border-radius: 8px;
        color: #fff !important;
    }
    #sidebar a.ms-dashboard-link .ms-dash-chevron {
        margin-left: auto; font-size: 11px; opacity: 0.6;
    }

    /* General modern/clean card polish for nav items */
    #sidebar .menu-item {
        border-radius: 10px;
        transition: background 0.2s ease, box-shadow 0.2s ease, transform 0.15s ease;
    }
    #sidebar .menu-item:hover {
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    }

    /* Filter-hidden state (used by JS search) */
    #sidebar .ms-hidden { display: none !important; }
    /* small group headings inside a submenu (Bank Reconciliation) */
    #sidebar .submenu-group { padding: 10px 14px 3px 38px; font-size: 10px; font-weight: 700; letter-spacing: .06em;
                              text-transform: uppercase; color: #9ca3af; pointer-events: none; }
    #sidebar .submenu-group:first-child { padding-top: 4px; }
    body.ms-dark #sidebar .submenu-group { color: #6b7280; }

    /* ── Icon-only collapsed sidebar ── */
    #sidebar.icon-collapsed { width: 78px !important; transition: width 0.25s ease; }
    #sidebar.icon-collapsed .ms-sidebar-brand-text,
    #sidebar.icon-collapsed .ms-sidebar-search,
    #sidebar.icon-collapsed .menu-title span,
    #sidebar.icon-collapsed .menu-item-content span:not(.menu-icon),
    #sidebar.icon-collapsed .submenu-toggle,
    #sidebar.icon-collapsed .ms-dash-chevron,
    #sidebar.icon-collapsed .submenu,
    #sidebar.icon-collapsed .ms-sidebar-user-text,
    #sidebar.icon-collapsed .ms-sidebar-user-more,
    #sidebar.icon-collapsed .ms-theme-toggle-row span {
        display: none !important;
    }
    #sidebar.icon-collapsed .ms-sidebar-brand-row {
        justify-content: center;
    }

    /* ── Sidebar footer: theme toggle + user card ── */
    .ms-sidebar-footer {
        margin: 6px 14px 14px;
        padding-top: 12px;
        border-top: 1px solid #eef0f3;
    }
    .ms-theme-toggle-row {
        display: flex; align-items: center; gap: 10px;
        padding: 6px 4px 14px;
        color: #6b7280; font-size: 13px;
    }
    .ms-theme-toggle-row i.fa-moon { color: #6366f1; }
    .ms-theme-toggle-row i.fa-sun  { color: #f59e0b; }
    .ms-theme-switch {
        position: relative; width: 38px; height: 20px; flex-shrink: 0;
        display: inline-block;
    }
    .ms-theme-switch input { opacity: 0; width: 0; height: 0; }
    .ms-theme-slider {
        position: absolute; inset: 0; cursor: pointer;
        background: #d1d5db; border-radius: 999px; transition: background 0.2s ease;
    }
    .ms-theme-slider::before {
        content: ''; position: absolute; width: 16px; height: 16px;
        left: 2px; top: 2px; background: #fff; border-radius: 50%;
        transition: transform 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.25);
    }
    .ms-theme-switch input:checked + .ms-theme-slider { background: linear-gradient(135deg,#4f46e5,#7c3aed); }
    .ms-theme-switch input:checked + .ms-theme-slider::before { transform: translateX(18px); }

    .ms-sidebar-user {
        position: relative;
        display: flex; align-items: center; gap: 10px;
        padding: 8px; border-radius: 12px;
        cursor: pointer; transition: background 0.2s ease;
    }
    .ms-sidebar-user:hover { background: #f7f7fb; }
    .ms-sidebar-avatar {
        position: relative; flex-shrink: 0;
        width: 36px; height: 36px; border-radius: 50%;
        background: linear-gradient(135deg,#4f46e5,#7c3aed);
        color: #fff; font-size: 13px; font-weight: 700;
        display: flex; align-items: center; justify-content: center;
        font-family: 'Inter', sans-serif;
    }
    .ms-online-dot {
        position: absolute; right: -1px; bottom: -1px;
        width: 10px; height: 10px; border-radius: 50%;
        background: #22c55e; border: 2px solid #fff;
    }
    .ms-sidebar-user-text { flex: 1; min-width: 0; display: flex; flex-direction: column; }
    .ms-sidebar-user-name {
        font-size: 13px; font-weight: 700; color: #111827;
        font-family: 'Inter', sans-serif;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .ms-sidebar-user-role {
        font-size: 11px; color: #9ca3af;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .ms-sidebar-user-more {
        flex-shrink: 0; background: none; border: none; color: #9ca3af;
        cursor: pointer; padding: 4px; font-size: 13px;
    }
    .ms-sidebar-user-dropdown {
        position: absolute; bottom: calc(100% + 8px); left: 8px; right: 8px;
        background: #fff; border-radius: 12px;
        border: 1px solid rgba(0,0,0,0.08);
        box-shadow: 0 12px 32px rgba(0,0,0,0.14);
        padding: 6px; z-index: 50;
        opacity: 0; visibility: hidden; transform: translateY(6px);
        transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
    }
    .ms-sidebar-user-dropdown.active { opacity: 1; visibility: visible; transform: translateY(0); }
    .ms-sidebar-user-dropdown .dropdown-item {
        display: flex; align-items: center; gap: 10px;
        padding: 9px 10px; border-radius: 8px;
        font-size: 13px; color: #374151; text-decoration: none;
        transition: background 0.15s ease;
    }
    .ms-sidebar-user-dropdown .dropdown-item:hover { background: #f3f4f6; }
    .ms-sidebar-user-dropdown .dropdown-item.logout { color: #dc2626; }
    .ms-sidebar-user-dropdown .dropdown-item i { width: 14px; text-align: center; font-size: 12px; }

    /* ── Basic dark theme (sidebar + header + main shell) ── */
    body.ms-dark { background: #0f1117; }
    body.ms-dark .sidebar { background: #171923; border-right: 1px solid #262a38; }
    body.ms-dark .main-content { background: #0f1117; color: #e5e7eb; }
    body.ms-dark .header { background: #171923; border-bottom: 1px solid #262a38; }
    body.ms-dark .menu-title { color: #9ca3af; }
    body.ms-dark #sidebar .menu-item { color: #d1d5db; }
    body.ms-dark #sidebar a.ms-dashboard-link.active { background: linear-gradient(135deg, rgba(99,102,241,0.22), rgba(147,51,234,0.18)) !important; }
    body.ms-dark .ms-sidebar-search { background: #1f2230; border-color: #2b2f40; }
    body.ms-dark .ms-sidebar-search input { color: #e5e7eb; }
    body.ms-dark .ms-sidebar-footer { border-top-color: #262a38; }
    body.ms-dark .ms-sidebar-user:hover { background: #1f2230; }
    body.ms-dark .ms-sidebar-user-name { color: #f3f4f6; }
    body.ms-dark .ms-sidebar-user-dropdown { background: #1f2230; border-color: #2b2f40; }
    body.ms-dark .ms-sidebar-user-dropdown .dropdown-item { color: #d1d5db; }
    body.ms-dark .ms-sidebar-user-dropdown .dropdown-item:hover { background: #2b2f40; }
</style>
</head>
<body>
    <!-- YMS Page Loader -->
    <div id="yms-loader" class="yms-loader">
        <div class="loader-content">
            <div class="loader-logo">
                <img src="images/logo.webp" alt="YMS Logo">
            </div>
            <div class="loader-spinner">
                <div class="spinner"></div>
            </div>
            <div class="loader-text">Loading...</div>
        </div>
    </div>

    <!-- Header -->
    <header class="header" style="position:fixed; display:flex; align-items:center;">

        <!-- LEFT: Logo -->
        <div class="logo">
            <div class="logo-icon"><img src="images/logo.webp" style="width:25px;" alt="YMS Logo"></div>
            <div class="logo-text">
                <h1>YMS</h1>
                <p>Yelo Group Management</p>
            </div>
        </div>

        <!-- CENTER: Active Company Badge -->
        <div class="header-company-badge">
            <?php if ($active_company_name): ?>
            <span class="company-badge">
                <i class="fa-solid fa-building"></i>
                <?php echo htmlspecialchars($active_company_name); ?>
            </span>
            <?php endif; ?>
        </div>

        <!-- RIGHT: Bell + User -->
        <div class="header-right">
            <button class="menu-toggle" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>

            <!-- NOTIFICATION BELL -->
            <div class="notif-bell-wrapper" id="notifBellWrapper">
                <button
                    class="notif-bell-btn <?php echo $header_notif_count > 0 ? 'has-notifs' : ''; ?>"
                    id="notifBellBtn"
                    onclick="toggleNotifDropdown(event)"
                    title="Notifications"
                    aria-label="Notifications">
                    <i class="fa-solid fa-bell"></i>
                    <?php if ($header_notif_count > 0): ?>
                    <span class="notif-badge"><?php echo $header_notif_count > 9 ? '9+' : $header_notif_count; ?></span>
                    <?php endif; ?>
                </button>

                <!-- Dropdown -->
                <div class="notif-dropdown" id="notifDropdown">
                    <div class="notif-drop-header">
                        <h4><i class="fa-solid fa-bell"></i> Notifications</h4>
                        <?php if ($header_notif_count > 0): ?>
                        <span class="notif-live-pill">
                            <span class="notif-live-dot"></span>
                            <?php echo $header_notif_count; ?> Active
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="notif-drop-list">
                        <?php if (!empty($header_notifications)):
                            foreach ($header_notifications as $hn):
                                $nt   = htmlspecialchars($hn['type']);
                                $icon = $nt === 'info'    ? 'fa-circle-info' :
                                       ($nt === 'success' ? 'fa-circle-check' :
                                       ($nt === 'warning' ? 'fa-triangle-exclamation' :
                                                            'fa-circle-exclamation'));
                                $now         = time();
                                $fromTs      = strtotime($hn['valid_from']);
                                $untilTs     = strtotime($hn['valid_until']);
                                $isLive      = $fromTs <= $now && $untilTs >= $now;
                                $isScheduled = $fromTs > $now;
                                if ($isLive) {
                                    $diff = $untilTs - $now;
                                    $d = floor($diff/86400); $h = floor(($diff%86400)/3600); $m = floor(($diff%3600)/60);
                                    $timeLabel = $d>0 ? "Expires in {$d}d {$h}h" : ($h>0 ? "Expires in {$h}h {$m}m" : "Expires in {$m}m");
                                } elseif ($isScheduled) {
                                    $diff = $fromTs - $now;
                                    $d = floor($diff/86400); $h = floor(($diff%86400)/3600);
                                    $timeLabel = "Starts in {$d}d {$h}h";
                                } else {
                                    $timeLabel = "Expiring soon";
                                }
                        ?>
                        <div class="notif-drop-item di-<?php echo $nt; ?>">
                            <div class="notif-drop-icon di-<?php echo $nt; ?>">
                                <i class="fa-solid <?php echo $icon; ?>"></i>
                            </div>
                            <div class="notif-drop-body">
                                <p class="notif-drop-title"><?php echo htmlspecialchars($hn['title']); ?></p>
                                <p class="notif-drop-msg"><?php echo htmlspecialchars($hn['message']); ?></p>
                                <span class="notif-drop-time">
                                    <i class="fa-solid fa-clock"></i>
                                    <?php echo $timeLabel; ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; else: ?>
                        <div class="notif-drop-empty">
                            <i class="fa-solid fa-bell-slash"></i>
                            <p>No active notifications</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($header_notif_count > 0): ?>
                    <div class="notif-drop-footer">
                        <a href="notifications.php">
                            <i class="fa-solid fa-arrow-right"></i> Manage all notifications
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <!-- END NOTIFICATION BELL -->

            <div class="user-profile" onclick="toggleUserDropdown()">
                <div class="user-avatar"><?php echo $user_initials; ?></div>
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars($current_user['username']); ?></span>
                    <span class="user-role"><?php echo htmlspecialchars($current_user['role_name']); ?></span>
                </div>
                <div class="user-dropdown" id="userDropdown">
                    <a href="profile.php" class="dropdown-item">
                        <i class="fa-solid fa-user"></i>
                        <span>Profile</span>
                    </a>
                    <a href="settings.php" class="dropdown-item">
                        <i class="fa-solid fa-gear"></i>
                        <span>Settings</span>
                    </a>
                    <a href="help.php" class="dropdown-item">
                        <i class="fa-solid fa-circle-question"></i>
                        <span>Help</span>
                    </a>
                    <a href="logout.php" class="dropdown-item logout">
                        <i class="fa-solid fa-right-from-bracket"></i>
                        <span>Logout</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Container -->
    <div class="container">
        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">

            <button class="ms-edge-toggle" id="sidebarCollapseBtn" onclick="toggleSidebarIconOnly()" title="Hide Menu">
                <i class="fa-solid fa-angles-left"></i>
            </button>

            <div class="ms-sidebar-brand">
                <div class="ms-sidebar-brand-row">
                    <div class="ms-sidebar-logo-icon"><img src="images/logo.webp" alt="YMS Logo"></div>
                    <div class="ms-sidebar-brand-text">
                        <h2>YMS</h2>
                        <p>Yelo Group Management</p>
                    </div>
                </div>
            </div>

            <div class="ms-sidebar-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="msSidebarSearch" placeholder="Search Menu..." autocomplete="off" oninput="filterSidebarMenu(this.value)">
                <span class="ms-search-kbd">Ctrl+K</span>
            </div>

            <div class="menu-section">
                <div class="menu-title"><i class="fa-solid fa-grip menu-title-icon mti-blue"></i><span>Main Menu</span></div>
                <a href="index.php" data-tooltip="Dashboard" class="menu-item ms-dashboard-link <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php') ? 'active' : ''; ?>">
                    <div class="menu-item-content">
                        <span class="menu-icon mti-icon-blue"><i class="fa-solid fa-chart-line"></i></span>
                        <span>Dashboard</span>
                    </div>
                    <i class="fa-solid fa-chevron-right ms-dash-chevron"></i>
                </a>
                <div class="menu-item has-submenu" data-tooltip="Master" onclick="toggleSubmenu(this)">
                    <div class="menu-item-content">
                        <span class="menu-icon icon-purple"><i class="fa-solid fa-briefcase"></i></span>
                        <span>Master</span>
                    </div>
                    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
                </div>
                <div class="submenu">
                    <a href="company.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'company.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Company</span>
                    </a>
                    <a href="branches.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'branches.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Branch</span>
                    </a>
                    <a href="staff_category.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'staff_category.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Staff Category</span>
                    </a>
                    <a href="designations.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'designations.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Designations</span>
                    </a>
                    <a href="routes.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'routes.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Route</span>
                    </a>
                    <a href="vehicles.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'vehicles.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Vehicles</span>
                    </a>
                    <a href="cash_collectors.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cash_collectors.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-hand-holding-dollar"></i><span>Cash Collectors</span>
                    </a>
                    <a href="emergency_credit_reasons.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'emergency_credit_reasons.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>EM Credit Reasons</span>
                    </a>
                    <a href="bill_cancel_reasons.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'bill_cancel_reasons.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Bill Cancel Reasons</span>
                    </a>                    
                   
                          <a href="credit_bill_return_reasons.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'credit_bill_return_reasons.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Credit Bill Return Reasons</span>
                    </a>
                    
                    
                      <a href="field_summary_risk_bill_reasons.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'field_summary_risk_bill_reasons.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Field Summary Risk Bill Reasons</span>
                    </a>
                    
                    <a href="send_back_cheque_reasons.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'send_back_cheque_reasons.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>SB Cheques Reasons</span>
                    </a>
                    <a href="company_bank_accounts.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'company_bank_accounts.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Company Bank Accounts</span>
                    </a>
                        <a href="cheque_book_entry.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cheque_book_entry.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Cheque Book Entry</span>
                    </a>
                    
                  
                    <a href="ai_settings.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'ai_settings.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>AI Settings</span>
                    </a>
                    <a href="company_letterhead.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'company_letterhead.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Letter Head</span>
                    </a>
                    <a href="stl.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'stl.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>STL Settings</span>
                    </a>
                    <a href="sms_settings.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sms_settings.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>SMS Settings</span>
                    </a>
                    <a href="backup_settings.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'backup_settings.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-database"></i><span>DB Backup</span>
                    </a>
                    
                        <a href="bank_holidays.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'bank_holidays.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Bank Holidays</span>
                    </a>
                     
                </div>

                <div class="menu-item has-submenu" data-tooltip="User Master" onclick="toggleSubmenu(this)">
                    <div class="menu-item-content">
                        <span class="menu-icon icon-teal"><i class="fa-solid fa-users-gear"></i></span>
                        <span>User Master</span>
                    </div>
                    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
                </div>
                <div class="submenu">
                    <a href="users.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'users.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-calendar"></i><span>Users</span>
                    </a>
                    <a href="role.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'role.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-calendar-plus"></i><span>User Role</span>
                    </a>
                    <a href="role_manage.php" class="submenu-item <?php echo in_array(basename($_SERVER['PHP_SELF']), ['role_manage.php','role_add.php','role_edit.php']) ? 'active' : ''; ?>">
                        <i class="fa-solid fa-shield-halved"></i><span>Role Manager (Page Permissions)</span>
                    </a>
                </div>

                <div class="menu-item has-submenu" data-tooltip="IT" onclick="toggleSubmenu(this)">
                    <div class="menu-item-content">
                        <span class="menu-icon icon-blue"><i class="fa-solid fa-display"></i></span>
                        <span>IT</span>
                    </div>
                    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
                </div>
                <div class="submenu">
                    <a href="notifications.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'notifications.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Notification Center</span>
                    </a>
                </div>
                
                
                
                
                  <div class="menu-item has-submenu" data-tooltip="User Master" onclick="toggleSubmenu(this)">
                    <div class="menu-item-content">
                        <span class="menu-icon icon-pink"><i class="fa-solid fa-brain"></i></span>
                        <span>AI Analys</span>
                    </div>
                    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
                </div>
                <div class="submenu">
                    <a href="outlet_service_info_upload.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'outlet_service_info_upload.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-calendar"></i><span>Outlet Service Information Upload</span>
                    </a>
                    
                        <a href="outlet_service_map.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'outlet_service_map.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-calendar"></i><span>Map Analysing</span>
                    </a>
                  
                </div>

               
            </div>
<div class="menu-section">
    <div class="menu-title"><i class="fa-solid fa-users menu-title-icon mti-green"></i><span>Management</span></div>

    <div class="menu-item has-submenu" data-tooltip="Human Resources" onclick="toggleSubmenu(this)">
        <div class="menu-item-content">
            <span class="menu-icon icon-green"><i class="fa-solid fa-user-tie"></i></span>
            <span>Human Resources</span>
        </div>
        <i class="fa-solid fa-chevron-down submenu-toggle"></i>
    </div>
    <div class="submenu">
        <a href="employees.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'employees.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-users"></i><span>Employees</span>
        </a>
        <a href="employee_register.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'employee_register.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-user-plus"></i><span>Employee Register</span>
        </a>

        <!-- ===================== LEAVE & ATTENDANCE NESTED SUBMENU ===================== -->
        <?php
        $leaveAttendancePages = ['leave_list.php','leave_register.php','add_attendance.php','attendance_report.php','attendance_summary_salary.php','biolink.php','special_working_days.php'];
        $isLeaveAttendanceActive = in_array(basename($_SERVER['PHP_SELF']), $leaveAttendancePages);
        ?>
        <div class="submenu-item has-submenu <?php echo $isLeaveAttendanceActive ? 'active' : ''; ?>"
             onclick="toggleSubmenu(this)"
             style="justify-content:space-between; cursor:pointer;">
            <span style="display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-calendar-check"></i>
                <span>Leave & Attendance</span>
            </span>
            <i class="fa-solid fa-chevron-down submenu-toggle"></i>
        </div>
        <div class="submenu" style="padding-left:12px;">
            <a href="leave_list.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'leave_list.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-calendar-plus"></i><span>Leave Application</span>
            </a>
            <a href="leave_register.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'leave_register.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-calendar-plus"></i><span>Leave Register</span>
            </a>
            <a href="add_attendance.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'add_attendance.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-folder"></i><span>Attendance Manual Entry</span>
            </a>
            <a href="attendance_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'attendance_report.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-folder"></i><span>Attendance</span>
            </a>
            <a href="attendance_summary_salary.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'attendance_summary_salary.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-folder"></i><span>Attendance Summary</span>
            </a>
            
            
               <a href="attendance_monthly_roster.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'attendance_monthly_roster.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-folder"></i><span>Attendance Monthly Roster</span>
            </a>
            
            
             <a href="attendance_import.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'attendance_import.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-folder"></i><span>Import Attendance</span>
            </a>
            
              <a href="import_to_attendance.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'import_to_attendance.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-folder"></i><span>Import to Attendance</span>
            </a>
            <a href="biolink.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'biolink.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-folder"></i><span>Biometric Machine</span>
            </a>
            <a href="special_working_days.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'special_working_days.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-folder"></i><span>Company Holidays</span>
            </a>
        </div>
        <!-- ====================================================================== -->

        <a href="payroll_months.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'payroll_months.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-calendar-plus"></i><span>Payroll Months</span>
        </a>
        <a href="salary_advance.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'salary_advance.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-calendar-plus"></i><span>Salary Advance</span>
        </a>
        <a href="loans.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'loans.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-calendar-plus"></i><span>Loans</span>
        </a>
    
        <a href="sr_fuel_entry.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sr_fuel_entry.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-folder"></i><span>SR Fuel Entry</span>
        </a>
        <a href="dlink_budget_entry.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'dlink_budget_entry.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-folder"></i><span>MR D Link</span>
        </a>
        <a href="sr_salary_config.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sr_salary_config.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-folder"></i><span>Salary Configuration</span>
        </a>
        
          <a href="incentive_types.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'incentive_types.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Incentive</span>
                    </a>

        <!-- ===================== SALARY SHEETS NESTED SUBMENU ===================== -->
        <?php
        $salaryPages = ['sr_salary.php','mr_salary.php','off_salary.php','st_salary.php','cc_salary.php'];
        $isSalaryActive = in_array(basename($_SERVER['PHP_SELF']), $salaryPages);
        ?>
        <div class="submenu-item has-submenu <?php echo $isSalaryActive ? 'active' : ''; ?>"
             onclick="toggleSubmenu(this)"
             style="justify-content:space-between; cursor:pointer;">
            <span style="display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-file-invoice-dollar"></i>
                <span>Salary Sheets</span>
            </span>
            <i class="fa-solid fa-chevron-down submenu-toggle"></i>
        </div>
        <div class="submenu" style="padding-left:12px;">
            <a href="sr_salary.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sr_salary.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-invoice"></i><span>SR Salary Sheet</span>
            </a>
            <a href="mr_salary.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'mr_salary.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-invoice"></i><span>MR Salary Sheet</span>
            </a>
            <a href="off_salary.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'off_salary.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-invoice"></i><span>Office Staff Salary Sheet</span>
            </a>
            <a href="st_salary.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'st_salary.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-invoice"></i><span>Stores Salary Sheet</span>
            </a>
            <a href="cc_salary.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cc_salary.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-invoice"></i><span>CC Salary Sheet</span>
            </a>
            
            <a href="acc_salary.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'acc_salary.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-invoice"></i><span>ACC Salary Sheet</span>
            </a>
            
              <a href="salary_excel_generate.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'salary_excel_generate.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-invoice"></i><span>Total Salary</span>
            </a>
            
             <a href="salary_reconciliation_yearly.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'salary_reconciliation_yearly.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-invoice"></i><span>Basic Salary Reconcilation</span>
            </a>
            
            
               <a href="salary_reconciliation.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'salary_reconciliation.php') ? 'active' : ''; ?>">
                <i class="fa-solid fa-file-invoice"></i><span>Total Salary Reconcilation</span>
            </a>
            
        </div>
        
        
        
            <a href="epf.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'epf.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-folder"></i><span>EPF/ETF Settings</span>
        </a>
        
          <a href="epf_batches.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'epf_batches.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-folder"></i><span>EPF Report</span>
        </a>
   
   
       <a href="etf_batches.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'etf_batches.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-folder"></i><span>ETF Report</span>
        </a>
        
        
        
        
       <a href="print_payslips.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'print_payslips.php') ? 'active' : ''; ?>">
            <i class="fa-solid fa-folder"></i><span>Print Payslips</span>
        </a>
        <!-- ====================================================================== -->

    </div>



    
                
                   <div class="menu-item has-submenu" data-tooltip="Receivable Tracker" onclick="toggleSubmenu(this)">
                    <div class="menu-item-content">
                        <span class="menu-icon icon-orange"><i class="fa-solid fa-user"></i></span>
                        <span>Customers</span>
                    </div>
                    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
                </div>
                <div class="submenu">
                    <a href="customers.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'customers.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Customers</span>
                    </a>
                    <a href="credit_policy_import.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'credit_policy_import.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Import Credit Policy</span>
                    </a>
                </div>
            </div>

            <div class="menu-section">
                <div class="menu-title"><i class="fa-solid fa-car menu-title-icon mti-cyan"></i><span>Vehicle Management</span></div>

                <div class="menu-item has-submenu" data-tooltip="Lorry Maintenance" onclick="toggleSubmenu(this)">
                    <div class="menu-item-content">
                        <span class="menu-icon icon-blue"><i class="fa-solid fa-truck-monster"></i></span>
                        <span>Lorry Maintenance</span>
                    </div>
                    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
                </div>
                <div class="submenu">
                    <a href="vehicles.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'vehicles.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck"></i><span>Vehicle Master</span>
                    </a>
                    
                    
                      <a href="vehicle_types.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'vehicle_types.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-oil-can"></i><span>Vehicle Types</span>
                    </a>
                    
                    
                    <a href="vehicle_fuel_log.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'vehicle_fuel_log.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-gas-pump"></i><span>Fuel Log (KM Sheet)</span>
                    </a>
                    <a href="vehicle_insurance.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'vehicle_insurance.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-file-shield"></i><span>Insurance</span>
                    </a>
                    <a href="vehicle_revenue_license.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'vehicle_revenue_license.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-id-card"></i><span>Revenue License</span>
                    </a>
                    <a href="vehicle_tyres.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'vehicle_tyres.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-circle-dot"></i><span>Tyres</span>
                    </a>
                    <a href="vehicle_dag_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'vehicle_dag_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-chart-column"></i><span>Tyre Dag Report</span>
                    </a>
                    
                    
                    
                    <a href="vehicle_service_item_types.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'vehicle_service_item_types.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-oil-can"></i><span>Service Items Creation</span>
                    </a>
                    
                    <a href="vehicle_service.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'vehicle_service.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-oil-can"></i><span>Service</span>
                    </a>
                    <a href="vehicle_repair.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'vehicle_repair.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-screwdriver-wrench"></i><span>Repair</span>
                    </a>
                    <a href="vehicle_spare_parts.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'vehicle_spare_parts.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-boxes-stacked"></i><span>Spare Parts Stock</span>
                    </a>
                    <a href="vehicle_employee_damage.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'vehicle_employee_damage.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-car-burst"></i><span>Employee Damage Claims</span>
                    </a>
                </div>

                <div class="menu-item has-submenu" data-tooltip="Vehicle Tracking" onclick="toggleSubmenu(this)">
                    <div class="menu-item-content">
                        <span class="menu-icon icon-cyan"><i class="fa-solid fa-location-crosshairs"></i></span>
                        <span>Vehicle Tracking</span>
                    </div>
                    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
                </div>
                <div class="submenu">
                   
                    <a href="vehicle_schedule.php" class="submenu-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['vehicle_schedule.php','vehicle_schedule_view.php'])) ? 'active' : ''; ?>">
                        <i class="fa-solid fa-calendar-days"></i><span>Vehicle Schedule</span>
                    </a>
                    
                       <a href="position_report_import.php" class="submenu-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['position_report_import.php','position_report_import.php'])) ? 'active' : ''; ?>">
                        <i class="fa-solid fa-calendar-days"></i><span>Position Report Import</span>
                    </a>
                    
                      <a href="park_report_import.php" class="submenu-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['park_report_import.php','park_report_import.php'])) ? 'active' : ''; ?>">
                        <i class="fa-solid fa-calendar-days"></i><span>Import Parking Report</span>
                    </a>
                    
                        <a href="vehicle_daily_summary.php" class="submenu-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['vehicle_daily_summary.php','vehicle_daily_summary.php'])) ? 'active' : ''; ?>">
                        <i class="fa-solid fa-calendar-days"></i><span>Daily Vehicle Summary</span>
                    </a>
                    
                       <a href="vehicle_routing_efficiency_report.php" class="submenu-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['vehicle_routing_efficiency_report.php','vehicle_routing_efficiency_report.php'])) ? 'active' : ''; ?>">
                        <i class="fa-solid fa-calendar-days"></i><span>Daily Vehicle Summary Report</span>
                    </a>
                    
                    
                    
                        <a href="drive_mileage_report.php" class="submenu-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['drive_mileage_report.php','drive_mileage_report.php'])) ? 'active' : ''; ?>">
                        <i class="fa-solid fa-calendar-days"></i><span>Drive Millage Report</span>
                    </a>
                        <a href="parking_report.php" class="submenu-item <?php echo (in_array(basename($_SERVER['PHP_SELF']), ['parking_report.php','parking_report.php'])) ? 'active' : ''; ?>">
                        <i class="fa-solid fa-calendar-days"></i><span>Parking Report</span>
                    </a>
                    
                    
                </div>
            </div>

            <div class="menu-section">
                <div class="menu-title"><i class="fa-solid fa-coins menu-title-icon mti-orange"></i><span>Finance</span></div>

                <div class="menu-item has-submenu" data-tooltip="Daily Transaction Management" onclick="toggleSubmenu(this)">
                    <div class="menu-item-content">
                        <span class="menu-icon icon-green"><i class="fa-solid fa-arrow-trend-up"></i></span>
                        <span>Daily Transaction Management</span>
                    </div>
                    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
                </div>
                <div class="submenu">
                    <a href="import_history.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'import_history.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Create Field Summary</span>
                    </a>
                    <a href="blacklisted_import_review.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'blacklisted_import_review.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-user-lock"></i><span>Blacklisted Import Review</span>
                    </a>
                    <a href="cancelled_to_field_summary.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cancelled_to_field_summary.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-ban"></i><span>Cancelled → Field Summary</span>
                    </a>
                    <a href="customer_ledger.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'customer_ledger.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-book"></i><span>Customer Ledger</span>
                    </a>
                    <a href="field_summary_list.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'field_summary_list.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Field Summary List</span>
                    </a>
                    
                    
                    
                        <a href="fs_se.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'fs_se.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Field Summary Short/Excess</span>
                    </a>
             
               
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    <a href="unloading_import_history.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'unloading_import_history.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Create Unloading Sheet</span>
                    </a>
                    <a href="gse_list.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'gse_list.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Good Short/Excess</span>
                    </a>
                    
                      <a href="reconcile_unloading.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'reconcile_unloading.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Unloading Reconcile</span>
                    </a>
                    <a href="payments_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'payments_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Payments</span>
                    </a>
                    <a href="invoices.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'invoices.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Invoices</span>
                    </a>
                    
                       <a href="customer_summary.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'customer_summary.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Invoices Summary</span>
                    </a>
                  
                    <a href="cc_cash_deposit.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cc_cash_deposit.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>CC Cash Deposit (SR)</span>
                    </a>
                    
                      <a href="cc_cash_deposit_dp.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cc_cash_deposit_dp.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>CC Cash Deposit (DP)</span>
                    </a>
                    <a href="bo_cash_deposit.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'bo_cash_deposit.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>BO Cash Deposit (SR)</span>
                    </a>
                    
                    
                       <a href="bo_cash_deposit_dp.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'bo_cash_deposit_dp.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>BO Cash Deposit (DP)</span>
                    </a>
                    
             
                    
                     <a href="grn_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'grn_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>GRN Report</span>
                    </a>
                    
               
                </div>
                
                
                
                <div class="menu-item has-submenu" data-tooltip="Inventory Management" onclick="toggleSubmenu(this)">
    <div class="menu-item-content">
        <span class="menu-icon icon-orange"><i class="fa-solid fa-building-columns"></i></span>
        <span>Sampath Operation</span>
        <span style="
            background-color: #e53935;
            color: #ffffff;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 4px;
            margin-left: 6px;
            letter-spacing: 0.5px;
            line-height: 1.4;
            display: inline-block;
            vertical-align: middle;
        ">S</span>
    </div>
    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
</div>
<div class="submenu">
    <a href="sampath_damage_batches.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sampath_damage_batches.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>Sampath Damage Entry</span>
    </a>
    <a href="sampath_super_payment.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sampath_super_payment.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>Sampath Super Payments</span>
    </a>
    
      <a href="grn_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'grn_report.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>Sampath Goods Rejected</span>
    </a>
    
     <a href="sampath_grn_reasons.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sampath_grn_reasons.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>Sampath GRN Reasons</span>
    </a>
    
     <a href="sampath_grn_batches.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sampath_grn_batches.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>Sampath GRN Summary</span>
    </a>
    
      <a href="sampath_grn_reconciliation.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sampath_grn_reconciliation.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>Sampath GRN Reconcilation</span>
    </a>

    <a href="sampath_stock_reconciliation.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sampath_stock_reconciliation.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-boxes-stacked"></i><span>Sampath Stock Reconcilation</span>
    </a>

    <a href="unilever_purchase_import.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'unilever_purchase_import.php' || basename($_SERVER['PHP_SELF']) == 'unilever_purchase_batch_view.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-file-invoice"></i><span>Unilever Purchase Import</span>
    </a>

    <a href="unilever_items_return.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'unilever_items_return.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-rotate-left"></i><span>Unilever Items Return</span>
    </a>
    
    
    
</div>


  
                <div class="menu-item has-submenu" data-tooltip="Inventory Management" onclick="toggleSubmenu(this)">
    <div class="menu-item-content">
        <span class="menu-icon icon-purple"><i class="fa-solid fa-cart-shopping"></i></span>
        <span>U Shop</span>
        <span style="
            background-color: #0999b3;
            color: #ffffff;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 4px;
            margin-left: 6px;
            letter-spacing: 0.5px;
            line-height: 1.4;
            display: inline-block;
            vertical-align: middle;
        ">U</span>
    </div>
    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
</div>
<div class="submenu">
    <a href="ushop_items_import_history.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'ushop_items_import_history.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>U Shop Opening Stock Entry</span>
    </a>
    <a href="ushop_invoice_history.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'ushop_invoice_history.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>U Shop Invoices</span>
    </a>
    
       <a href="monthly_invoice_summary.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'monthly_invoice_summary.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>Monthly Summary</span>
    </a>
    
    
      <a href="ushop_letter_categories.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'ushop_letter_categories.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>Letter Categories</span>
    </a>
    
    
    
          <a href="ushop_payment_invoice_list.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'ushop_payment_invoice_list.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>Payment Invoice Creation</span>
    </a>
    
      
          <a href="ushop_letter_create.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'ushop_letter_create.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>Letter Creation</span>
    </a>
    
    
          <a href="ushop_payment_reconciliation.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'ushop_payment_reconciliation.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>Payment Reconcilation</span>
    </a>
    
    
    
           <a href="visa_reconciliation.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'visa_reconciliation.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>Visa Card Reconcilation</span>
    </a>
    
            <a href="ushop_visa_reconciliation_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'ushop_visa_reconciliation_report.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>Visa Reconcilation Report</span>
    </a>
    
    

    
     <a href="ushop_monthly_category_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'ushop_monthly_category_report.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>Monthly Report</span>
    </a>
    
    
      <a href="ushop_dashboard_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'ushop_dashboard_report.php') ? 'active' : ''; ?>">
        <i class="fa-solid fa-truck-loading"></i><span>U Shop Dashboard</span>
    </a>
    
</div>
                

                <div class="menu-item has-submenu" data-tooltip="Inventory Management" onclick="toggleSubmenu(this)">
                    <div class="menu-item-content">
                        <span class="menu-icon icon-orange"><i class="fa-solid fa-box"></i></span>
                        <span>Inventory Management</span>
                    </div>
                    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
                </div>
                <div class="submenu">
                    <a href="current_stock_history.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'current_stock_history.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Daily Current Stock</span>
                    </a>
                    <a href="invoice_wise_sales_history.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'invoice_wise_sales_history.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Daily Secondary Stock</span>
                    </a>
                    <a href="primary_invoice_wise_sales_history.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'primary_invoice_wise_sales_history.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Daily Pre Secondary Stock</span>
                    </a>
                    <a href="physical_stock_count_upload.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'physical_stock_count_upload.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-clipboard-check"></i><span>Physical Stock Count</span>
                    </a>
                </div>

                <div class="menu-item has-submenu" data-tooltip="Credit Bill Management" onclick="toggleSubmenu(this)">
                    <div class="menu-item-content">
                        <span class="menu-icon icon-pink"><i class="fa-solid fa-file-invoice-dollar"></i></span>
                        <span>Credit Bill Management</span>
                    </div>
                    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
                </div>
                <div class="submenu">
                    <a href="credit_bill_summary2.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'credit_bill_summary2.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Credit Bill Summary</span>
                    </a>
                    <a href="credit_bill_issue.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'credit_bill_issue.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Credit Bill Issue</span>
                    </a>
                    <a href="credit_notes_list.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'credit_notes_list.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Credit Notes</span>
                    </a>
                    <a href="payments.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'payments.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Credit Payments</span>
                    </a>
                    <a href="bulk_credit_bill_upload.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'bulk_credit_bill_upload.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Upload Credit Bills</span>
                    </a>
                    <a href="emergency_credit_bill_upload.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'emergency_credit_bill_upload.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Upload Em.Credit Bills</span>
                    </a>
           
           
                    <a href="import_credit_notes.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'import_credit_notes.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Import Bulk Credit Note</span>
                    </a>
                    
                    
                       <a href="sr_code_bulk_update.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sr_code_bulk_update.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Import Bulk SR Code Change</span>
                    </a>
                    
                    
                       <a href="credit_bill_beat_update.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'credit_bill_beat_update.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Import Bulk SR Code Change (T Code Wise)</span>
                    </a>
                   
                </div>

                <div class="menu-item has-submenu" data-tooltip="Cheque Management" onclick="toggleSubmenu(this)">
                    <div class="menu-item-content">
                        <span class="menu-icon icon-teal"><i class="fa-solid fa-money-check-dollar"></i></span>
                        <span>Cheque Management</span>
                    </div>
                    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
                </div>
                <div class="submenu">
                    <a href="cheques.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cheques.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Cheque Register</span>
                    </a>
                    
                    
                
                    
           
                    
                    <a href="return_cheques.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'return_cheques.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Payment-Returned Chq.</span>
                    </a>
                    <a href="return_charges.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'return_charges.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Payment-CRN Charges</span>
                    </a>
                    <a href="sentback_cheques.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sentback_cheques.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Payment-Sent Back Chq.</span>
                    </a>
               
                    <a href="cheque_deposit_letter.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cheque_deposit_letter.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Cheque Deposit Letters</span>
                    </a>
                    
                  
                    
                    <a href="stl_letters.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'stl_letters.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>STL Request Letters</span>
                    </a>
                    <a href="stl_settlement.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'stl_settlement.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>STL Settlement</span>
                    </a>
                    <a href="stl_forecasting.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'stl_forecasting.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>STL Forecasting</span>
                    </a>
 <a href="cheque_aging_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cheque_aging_report.php') ? 'active' : ''; ?>">
    <i class="fa-solid fa-chart-pie"></i><span>Cheque Aging Report</span>
</a>



<a href="cheque_mode_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cheque_mode_report.php') ? 'active' : ''; ?>">
    <i class="fa-solid fa-chart-pie"></i><span>Cheque Mode Report</span>
</a>    


<a href="cheque_mode_report_dp.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cheque_mode_report_dp.php') ? 'active' : ''; ?>">
    <i class="fa-solid fa-chart-pie"></i><span>Cheque Mode Report (DP)</span>
</a> 
                    <a href="deposit.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'deposit.php') ? 'active' : ''; ?>">
                       <i class="fa-solid fa-scale-balanced"></i><span>Cheque Reconciliation</span>
                    </a>
                    
                    <a href="manual.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'manual.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-scale-balanced"></i><span>Manual Reconciliation</span>
                    </a>
                    
                    
                         <a href="bulk_cheque_verify.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'bulk_cheque_verify.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-file-import"></i><span>Import Cheques</span>
                    </a>
                </div>

                <div class="menu-item has-submenu" data-tooltip="Customer Ledger" onclick="toggleSubmenu(this)">
                    <div class="menu-item-content">
                        <span class="menu-icon icon-blue"><img src="images/uniliver.png" style="width:16px;"></span>
                        <span>Customer Ledger</span>
                    </div>
                    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
                </div>
                <div class="submenu">
                    <a href="primary_invoices.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'primary_invoices.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Primary Invoices</span>
                    </a>
                
                 
                    <a href="purchase_return_summary_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'purchase_return_summary_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Primary Sales Return</span>
                    </a>
                    <a href="damaged_claim.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'damaged_claim.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Damaged Claim</span>
                    </a>
                
                   <a href="pi_other_adjustments.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'pi_other_adjustments.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Other Adjustments</span>
                    </a>
                    
                 <a href="pi_reconciliation.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'pi_reconciliation.php') ? 'active' : ''; ?>">
    <i class="fa-solid fa-scale-balanced"></i><span>Primary Invoices Reconciliation</span>
</a>
                    
                    <div class="submenu-item has-submenu" onclick="toggleSubmenu(this)" style="cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <i class="fa-solid fa-file-import"></i><span>Import</span>
                        </div>
                        <i class="fa-solid fa-chevron-right submenu-toggle"></i>
                    </div>
                    
                    
                    
                    
                    <div class="submenu nested-submenu">
                        <a href="damage_proposal_history.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'damage_proposal_history.php') ? 'active' : ''; ?>">
                            <i class="fa-solid fa-plus"></i><span>Damage Shortage Proposal Report</span>
                        </a>
                        <a href="purchase_return_history.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'purchase_return_history.php') ? 'active' : ''; ?>">
                            <i class="fa-solid fa-plus"></i><span>Product Wise Purchase Return</span>
                        </a>
                        <a href="purchase_return_register_history.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'purchase_return_register_history.php') ? 'active' : ''; ?>">
                            <i class="fa-solid fa-plus"></i><span>Purchase Return Register</span>
                        </a>
                        <a href="ulcl_history.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'ulcl_history.php') ? 'active' : ''; ?>">
                            <i class="fa-solid fa-plus"></i><span>Unilever Customer Ledger</span>
                        </a>
                    </div>
                    
                    
                    
                </div>

                <div class="menu-item has-submenu" data-tooltip="Vendor Ledger" onclick="toggleSubmenu(this)">
                    <div class="menu-item-content">
                        <span class="menu-icon icon-purple"><img src="images/uniliver.png" style="width:16px;"></span>
                        <span>Vendor Ledger</span>
                    </div>
                    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
                </div>
                <div class="submenu">
                    <a href="daily_scheme_discounts.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'daily_scheme_discounts.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Daily Scheme Discounts</span>
                    </a>
                    <a href="scheme_discount_receivable.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'scheme_discount_receivable.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Scheme Discount</span>
                    </a>
                    <a href="free_issue_cc_receivable.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'free_issue_cc_receivable.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Free Issues</span>
                    </a>
                    <a href="debit_note.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'debit_note.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Muthupalasa BP Claims</span>
                    </a>
                    <a href="promoters_salary_cl.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'promoters_salary_cl.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Promoters Salary</span>
                    </a>
                    <a href="sr_incentive_cc.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sr_incentive_cc.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>SR Incentive</span>
                    </a>
                    <a href="sscl_vat.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sscl_vat.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>SSCL &amp; VAT</span>
                    </a>
              
                    <a href="dal.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'dal.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Drivers &amp; Loyalty</span>
                    </a>
                          <a href="others_cc.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'others_cc.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Others</span>
                    </a>
                    

                    
                    
                    
                      <a href="cheque_acknowledgments.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cheque_acknowledgments.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Acknowledgment</span>
                    </a>
                    
                  
                
                
                  <div class="submenu-item has-submenu" onclick="toggleSubmenu(this)" style="cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <i class="fa-solid fa-file-import"></i><span>Import</span>
                        </div>
                        <i class="fa-solid fa-chevron-right submenu-toggle"></i>
                    </div>
                    
                    
                    
                    
                    <div class="submenu nested-submenu">
                  <a href="customer_claim.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'customer_claim.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-file-import"></i><span>Customer Claim Certificate Import</span>
                    </a>
                    <a href="billwise_scheme.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'billwise_scheme.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-file-import"></i><span>Bill Wise Scheme Analysis Import</span>
                    </a>
                
                    </div>
                    
                    </div>
                  
           



  <div class="menu-item has-submenu" data-tooltip="Bank Reconciliation" onclick="toggleSubmenu(this)">
                    <div class="menu-item-content">
                        <span class="menu-icon icon-teal"><i class="fa-solid fa-building-columns"></i></span>
                        <span>Bank Reconciliation</span>
                    </div>
                    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
                </div>
                <div class="submenu">
                    <a href="bank_account_dashboard.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'bank_account_dashboard.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-wallet"></i><span>Bank Account Dashboard</span>
                    </a>
                   
                    <a href="bank_statements.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'bank_statements.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-file-import"></i><span>Import Bank Statement</span>
                    </a>

                    <a href="bank_datewise_transactions.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'bank_datewise_transactions.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-list-check"></i><span>Date-wise Transactions</span>
                    </a>

                    <a href="bank_recon_reasons.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'bank_recon_reasons.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-tags"></i><span>Reconcile Reasons</span>
                    </a>
                   
                    <a href="cc_deposit_dp_bank_recon.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cc_deposit_dp_bank_recon.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-hand-holding-dollar"></i><span>Deposit Reoncilation</span>
                    </a>
                 
                  
                    <a href="cheque_reconciliation.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cheque_reconciliation.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-money-check"></i><span>Cheque Reconcilation</span>
                    </a>
                
                    <a href="pi_cheque_bank_recon.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'pi_cheque_bank_recon.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-money-check-dollar"></i><span>Unilver Payment Reconcilation</span>
                    </a>
                    
                    
                       <a href="unilever_reconcile.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'unilever_reconcile.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-handshake"></i><span>Reimbursment Reconcilation</span>
                    </a>
                
                    
                    <a href="ca_issued_cheque_bank_recon.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'ca_issued_cheque_bank_recon.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-file-signature"></i><span>Customer Claims Reconcilation</span>
                    </a>
                  
                 
                  
                    <a href="stl_loan_grant_recon.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'stl_loan_grant_recon.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-hand-holding-dollar"></i><span>STL Loan Granted Reconcilation</span>
                    </a>
                    <a href="stl_loan_settle_bank.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'stl_loan_settle_bank.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-money-bill-transfer"></i><span>STL Settlement Reconcilation</span>
                    </a>
                    
                   
                    <a href="expense_payment_bank_recon.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'expense_payment_bank_recon.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-receipt"></i><span>Expense Reconcilation</span>
                    </a>
                 
                </div>

  <div class="menu-item has-submenu" data-tooltip="Accounts" onclick="toggleSubmenu(this)">
                    <div class="menu-item-content">
                        <span class="menu-icon icon-navy"><i class="fa-solid fa-folder"></i></span>
                        <span>Accounts</span>
                    </div>
                    <i class="fa-solid fa-chevron-down submenu-toggle"></i>
                </div>
                <div class="submenu">
                    <a href="mybos_accounts.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'mybos_accounts.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>MYBOS Accounts</span>
                    </a>
                   
                      <a href="budget.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'budget.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Budget</span>
                    </a>
                    
                       <a href="roi.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'roi.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>ROI</span>
                    </a>
                  
                  
                     <a href="expense_category.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'expense_category.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Expense Categories</span>
                    </a>
                  
                     <a href="expenses.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'expenses.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Expenses</span>
                    </a>
                  
                 <a href="expense_payments.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'expense_payments.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Expense Payments</span>
                    </a>
                
                
                   <a href="cash_float.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cash_float.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-plus"></i><span>Cash Floats</span>
                    </a>
                
               
                         </div>
                 
                  
                </div>
      
      <div class="menu-section">
                <div class="menu-title"><i class="fa-solid fa-chart-simple menu-title-icon mti-purple"></i><span>Reports</span></div>

                <!-- ===================== CREDIT REPORTS ===================== -->
                <div class="menu-item" style="background-color:#FFF2AE; font-weight:bold; cursor:default;">
                    <div class="menu-item-content">
                        <span class="menu-icon"><i class="fa-solid fa-chart-bar"></i></span>
                        <span>Credit Reports</span>
                    </div>
                </div>
                <div class="submenu" style="display:block !important; max-height:none !important; overflow:visible !important; opacity:1 !important; visibility:visible !important;">
                    <a href="credit_aging_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'credit_aging_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Credit Aging Report</span>
                    </a>
                    <a href="rep_wise_credit_aging_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'rep_wise_credit_aging_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Credit Aging Report (Rep)</span>
                    </a>
                    <a href="customer_credit_risk_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'customer_credit_risk_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Customer Risk</span>
                    </a>
                    <a href="route_wise_credit_summary.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'route_wise_credit_summary.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Route Wise Credit Summary</span>
                    </a>
                    
                     <a href="market_credit_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'market_credit_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Market Credit Report</span>
                    </a>
                    
                     <a href="cheque_aging_report_group.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cheque_aging_report_group.php') ? 'active' : ''; ?>">
    <i class="fa-solid fa-chart-pie"></i><span>Cheque Aging Report Group</span>
</a>

                     <a href="blacklisted_customers_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'blacklisted_customers_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Customer Blacklist Report</span>
                    </a>
                    <a href="risk_customer_bills_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'risk_customer_bills_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-triangle-exclamation"></i><span>Risk Customer Bills Report</span>
                    </a>
                    
                    
                             <a href="credit_bill_scan_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'credit_bill_scan_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-images"></i><span>Credit Bill Upload Report</span>
                    </a>
                    
                    <a href="emergency_credit_bill_upload_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'emergency_credit_bill_upload_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-file-circle-check"></i><span>Em.Credit Bill Upload Report</span>
                    </a>
                </div>

                <!-- ===================== DAY END REPORTS ===================== -->
                <div class="menu-item" style="background-color:#FFF2AE; font-weight:bold; cursor:default;">
                    <div class="menu-item-content">
                        <span class="menu-icon"><i class="fa-solid fa-calendar-day"></i></span>
                        <span>Day End Reports</span>
                    </div>
                </div>
                <div class="submenu" style="display:block !important; max-height:none !important; overflow:visible !important; opacity:1 !important; visibility:visible !important;">
                    <a href="final_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'final_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Day End Full Report-T</span>
                    </a>
                    <a href="full_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'full_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Full Report (SR)</span>
                    </a>
                    <a href="full_report_dp.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'full_report_dp.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Full Report (DP)</span>
                    </a>
                    <a href="sum.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'sum.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Collection Summary (SR)</span>
                    </a>
                    <a href="cash_collection_by_delivery_person.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cash_collection_by_delivery_person.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Collection Summary (DP)</span>
                    </a>
                </div>

                <!-- ===================== CASH & BANK REPORTS ===================== -->
                <div class="menu-item" style="background-color:#FFF2AE; font-weight:bold; cursor:default;">
                    <div class="menu-item-content">
                        <span class="menu-icon"><i class="fa-solid fa-piggy-bank"></i></span>
                        <span>Cash &amp; Bank Reports</span>
                    </div>
                </div>
                <div class="submenu" style="display:block !important; max-height:none !important; overflow:visible !important; opacity:1 !important; visibility:visible !important;">
                    <a href="cc_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cc_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Full Cash Collection (DP)</span>
                    </a>
                    <a href="cash_shortage_employee_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cash_shortage_employee_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Cash Shortage Report (SR)</span>
                    </a>
                    <a href="cash_shortage_employee_report_dp.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'cash_shortage_employee_report_dp.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Cash Shortage Report (DP)</span>
                    </a>
                    <a href="fs_shortage_charge_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'fs_shortage_charge_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-user-tag"></i><span>Field Summary Shortage Charge Report</span>
                    </a>
                    <a href="bank_deposit_summary.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'bank_deposit_summary.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Bank Deposit Summary (SR)</span>
                    </a>
                    <a href="bank_deposit_summary_dp.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'bank_deposit_summary_dp.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Bank Deposit Summary (DP)</span>
                    </a>
                    <a href="bank_deposit_summary_slip.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'bank_deposit_summary_slip.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Bank Deposit Summary (Slip Wise SR)</span>
                    </a>
                    <a href="bank_deposit_summary_slip_dp.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'bank_deposit_summary_slip_dp.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Bank Deposit Summary (Slip Wise DP)</span>
                    </a>
                </div>

                <!-- ===================== UNLOADING REPORTS ===================== -->
                <div class="menu-item" style="background-color:#FFF2AE; font-weight:bold; cursor:default;">
                    <div class="menu-item-content">
                        <span class="menu-icon"><i class="fa-solid fa-truck-ramp-box"></i></span>
                        <span>Unloading Reports</span>
                    </div>
                </div>
                <div class="submenu" style="display:block !important; max-height:none !important; overflow:visible !important; opacity:1 !important; visibility:visible !important;">
                    <a href="item_shortage_employee_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'item_shortage_employee_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Good/Short Excess Report</span>
                    </a>
                    <a href="employee_shortage_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'employee_shortage_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Employee Wise GSE Report</span>
                    </a>
                    
                       <a href="daily_gse_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'daily_gse_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Daily GSE Report</span>
                    </a>
                    <a href="unloading_monthly_summary.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'unloading_monthly_summary.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-loading"></i><span>Item Wise GSE Summary</span>
                    </a>
                </div>

                <!-- ===================== OTHER REPORTS ===================== -->
                <div class="menu-item" style="background-color:#FFF2AE; font-weight:bold; cursor:default;">
                    <div class="menu-item-content">
                        <span class="menu-icon"><i class="fa-solid fa-ellipsis"></i></span>
                        <span>Other Reports</span>
                    </div>
                </div>
                <div class="submenu" style="display:block !important; max-height:none !important; overflow:visible !important; opacity:1 !important; visibility:visible !important;">
               
                    <a href="ccf_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'ccf_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>CCF Report (DP &amp; SR)</span>
                    </a>
                    
                         <a href="daily_delivery_monitor.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'daily_delivery_monitor.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-truck-fast"></i><span>Daily Delivery Monitoring Report</span>
                    </a>
                    
                    
                      <a href="customer_claim_report.php" class="submenu-item <?php echo (basename($_SERVER['PHP_SELF']) == 'customer_claim_report.php') ? 'active' : ''; ?>">
                        <i class="fa-solid fa-folder"></i><span>Customer Claim Report</span>
                    </a>
                </div>

            </div>

            <div class="ms-sidebar-footer">
                <div class="ms-theme-toggle-row">
                    <i class="fa-solid fa-moon"></i>
                    <label class="ms-theme-switch">
                        <input type="checkbox" id="msThemeToggle" onchange="toggleMsTheme(this.checked)">
                        <span class="ms-theme-slider"></span>
                    </label>
                    <i class="fa-solid fa-sun"></i>
                </div>

                <div class="ms-sidebar-user" onclick="toggleMsUserDropdown(event)">
                    <div class="ms-sidebar-avatar"><?php echo $user_initials; ?><span class="ms-online-dot"></span></div>
                    <div class="ms-sidebar-user-text">
                        <span class="ms-sidebar-user-name"><?php echo htmlspecialchars($current_user['username']); ?></span>
                        <span class="ms-sidebar-user-role"><?php echo htmlspecialchars($current_user['role_name']); ?></span>
                    </div>
                    <button class="ms-sidebar-user-more" type="button"><i class="fa-solid fa-ellipsis-vertical"></i></button>

                    <div class="ms-sidebar-user-dropdown" id="msSidebarUserDropdown">
                        <a href="profile.php" class="dropdown-item"><i class="fa-solid fa-user"></i><span>Profile</span></a>
                        <a href="settings.php" class="dropdown-item"><i class="fa-solid fa-gear"></i><span>Settings</span></a>
                        <a href="help.php" class="dropdown-item"><i class="fa-solid fa-circle-question"></i><span>Help</span></a>
                        <a href="logout.php" class="dropdown-item logout"><i class="fa-solid fa-right-from-bracket"></i><span>Logout</span></a>
                    </div>
                </div>
            </div>

        </aside>

        <!-- Main Content -->
        <main class="main-content">

        <script>
        // ── YMS Loader ──
        window.addEventListener('load', function() {
            const loader = document.getElementById('yms-loader');
            if (loader) {
                setTimeout(function() {
                    loader.classList.add('loaded');
                    setTimeout(function() { loader.style.display = 'none'; }, 400);
                }, 300);
            }
        });
        function showLoader() {
            const loader = document.getElementById('yms-loader');
            if (loader) { loader.style.display = 'flex'; loader.classList.remove('loaded'); }
        }
        function hideLoader() {
            const loader = document.getElementById('yms-loader');
            if (loader) {
                loader.classList.add('loaded');
                setTimeout(function() { loader.style.display = 'none'; }, 400);
            }
        }
        document.addEventListener('DOMContentLoaded', function() {
            const links = document.querySelectorAll('a[href]:not([href^="http"]):not([href^="#"]):not([target="_blank"])');
            links.forEach(link => {
                link.addEventListener('click', function(e) {
                    if (e.ctrlKey || e.metaKey || e.shiftKey) return;
                    const href = this.getAttribute('href');
                    if (!href || href === '#' || href.startsWith('javascript:')) return;
                    showLoader();
                });
            });
        });
        setTimeout(function() {
            const loader = document.getElementById('yms-loader');
            if (loader && !loader.classList.contains('loaded')) hideLoader();
        }, 10000);

        // ── Sidebar ──
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.querySelector('.overlay');
            sidebar.classList.toggle('collapsed');
            if (!overlay) {
                const newOverlay = document.createElement('div');
                newOverlay.className = 'overlay';
                newOverlay.onclick = toggleSidebar;
                document.body.appendChild(newOverlay);
                setTimeout(() => newOverlay.classList.add('active'), 10);
            } else {
                overlay.classList.toggle('active');
                if (!overlay.classList.contains('active')) {
                    setTimeout(() => overlay.remove(), 300);
                }
            }
        }

        // ── Sidebar icon-only collapse ──
        function positionSidebarEdgeToggle() {
            const sidebar = document.getElementById('sidebar');
            const btn = document.getElementById('sidebarCollapseBtn');
            if (!sidebar || !btn) return;
            const rect = sidebar.getBoundingClientRect();
            btn.style.left = (rect.right - 15) + 'px';
            btn.style.top = (rect.top + 40) + 'px';
        }
        function toggleSidebarIconOnly() {
            const sidebar = document.getElementById('sidebar');
            const btn = document.getElementById('sidebarCollapseBtn');
            if (!sidebar) return;
            sidebar.classList.toggle('icon-collapsed');
            const icon = btn ? btn.querySelector('i') : null;
            if (icon) {
                icon.classList.toggle('fa-angles-left');
                icon.classList.toggle('fa-angles-right');
            }
            try { localStorage.setItem('msSidebarIconOnly', sidebar.classList.contains('icon-collapsed') ? '1' : '0'); } catch (e) {}
            // Wait for the width transition to finish before repositioning
            setTimeout(positionSidebarEdgeToggle, 260);
        }
        document.addEventListener('DOMContentLoaded', function () {
            const sidebar = document.getElementById('sidebar');
            const btn = document.getElementById('sidebarCollapseBtn');
            try {
                if (localStorage.getItem('msSidebarIconOnly') === '1') {
                    if (sidebar) sidebar.classList.add('icon-collapsed');
                    const icon = btn ? btn.querySelector('i') : null;
                    if (icon) { icon.classList.remove('fa-angles-left'); icon.classList.add('fa-angles-right'); }
                }
            } catch (e) {}
            positionSidebarEdgeToggle();
        });
        window.addEventListener('resize', positionSidebarEdgeToggle);

        // ── Theme toggle (light/dark) ──
        function toggleMsTheme(isDark) {
            document.body.classList.toggle('ms-dark', isDark);
            try { localStorage.setItem('msTheme', isDark ? 'dark' : 'light'); } catch (e) {}
        }
        (function restoreMsTheme() {
            try {
                const saved = localStorage.getItem('msTheme');
                if (saved === 'dark') {
                    document.addEventListener('DOMContentLoaded', function () {
                        document.body.classList.add('ms-dark');
                        const cb = document.getElementById('msThemeToggle');
                        if (cb) cb.checked = true;
                    });
                }
            } catch (e) {}
        })();

        // ── Sidebar user mini-dropdown ──
        function toggleMsUserDropdown(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('msSidebarUserDropdown');
            const userCard = document.querySelector('.ms-sidebar-user');
            if (!dropdown || !userCard) return;
            const wasActive = dropdown.classList.contains('active');
            if (!wasActive) {
                const rect = userCard.getBoundingClientRect();
                dropdown.style.position = 'fixed';
                dropdown.style.left = rect.left + 'px';
                dropdown.style.width = rect.width + 'px';
                dropdown.style.bottom = (window.innerHeight - rect.top + 8) + 'px';
            }
            dropdown.classList.toggle('active', !wasActive);
        }
        document.addEventListener('click', function (e) {
            const dropdown = document.getElementById('msSidebarUserDropdown');
            const userCard = document.querySelector('.ms-sidebar-user');
            if (dropdown && dropdown.classList.contains('active') && userCard && !userCard.contains(e.target)) {
                dropdown.classList.remove('active');
            }
        });
        window.addEventListener('resize', function () {
            const dropdown = document.getElementById('msSidebarUserDropdown');
            if (dropdown) dropdown.classList.remove('active');
        });

        // ── Sidebar Menu Search ──
        function filterSidebarMenu(query) {
            const q = query.trim().toLowerCase();
            const sections = document.querySelectorAll('#sidebar .menu-section');
            sections.forEach(section => {
                let sectionHasMatch = false;
                // Walk top-level menu-items and their following submenu (if any)
                const items = section.querySelectorAll(':scope > .menu-item, :scope > a.menu-item');
                items.forEach(item => {
                    const submenu = item.classList.contains('has-submenu') ? item.nextElementSibling : null;
                    const label = item.textContent.toLowerCase();
                    let itemMatches = q === '' || label.includes(q);
                    let subHasMatch = false;

                    if (submenu && submenu.classList.contains('submenu')) {
                        const subItems = submenu.querySelectorAll('.submenu-item');
                        submenu.querySelectorAll('.submenu-group').forEach(g => g.classList.toggle('ms-hidden', q !== ''));
                        subItems.forEach(sub => {
                            const subLabel = sub.textContent.toLowerCase();
                            const match = q === '' || subLabel.includes(q);
                            sub.classList.toggle('ms-hidden', !match);
                            if (match) subHasMatch = true;
                        });
                        if (q !== '') {
                            submenu.classList.toggle('open', subHasMatch);
                            const toggle = item.querySelector('.submenu-toggle');
                            if (toggle) toggle.classList.toggle('open', subHasMatch);
                        }
                        submenu.classList.toggle('ms-hidden', q !== '' && !subHasMatch && !itemMatches);
                    }

                    const showItem = q === '' || itemMatches || subHasMatch;
                    item.classList.toggle('ms-hidden', !showItem);
                    if (showItem) sectionHasMatch = true;
                });
                section.classList.toggle('ms-hidden', q !== '' && !sectionHasMatch);
            });
        }
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                const box = document.getElementById('msSidebarSearch');
                if (box) box.focus();
            }
        });

        // ── User dropdown ──
        function toggleUserDropdown() {
            const dropdown = document.getElementById('userDropdown');
            dropdown.classList.toggle('active');
            document.addEventListener('click', function closeDropdown(e) {
                if (!e.target.closest('.user-profile')) {
                    dropdown.classList.remove('active');
                    document.removeEventListener('click', closeDropdown);
                }
            });
        }

        // ── Submenu ──
        function toggleSubmenu(element) {
            const submenu = element.nextElementSibling;
            const toggle  = element.querySelector('.submenu-toggle');
            submenu.classList.toggle('open');
            toggle.classList.toggle('open');
        }
        document.addEventListener('DOMContentLoaded', function() {
            const activeSubmenuItem = document.querySelector('.submenu-item.active');
            if (activeSubmenuItem) {
                const submenu  = activeSubmenuItem.closest('.submenu');
                const menuItem = submenu.previousElementSibling;
                const toggle   = menuItem.querySelector('.submenu-toggle');
                submenu.classList.add('open');
                if (toggle) toggle.classList.add('open');   /* always-open Reports groups have no toggle arrow */
            }
        });

        // ── Notification Bell ──
        function toggleNotifDropdown(e) {
            e.stopPropagation();
            const dropdown = document.getElementById('notifDropdown');
            const isOpen   = dropdown.classList.contains('open');
            document.getElementById('userDropdown').classList.remove('active');
            dropdown.classList.toggle('open', !isOpen);
        }
        document.addEventListener('click', function(e) {
            if (!e.target.closest('#notifBellWrapper')) {
                document.getElementById('notifDropdown').classList.remove('open');
            }
        });
        </script>