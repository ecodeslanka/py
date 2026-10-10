<?php
// privacy_policy.php - Yelo Group Management System
if (file_exists(__DIR__ . '/config.php')) {
    include __DIR__ . '/config.php';
}
if (file_exists(__DIR__ . '/auth.php')) {
    include __DIR__ . '/auth.php';
}
$last_updated = "26 May 2026";
$company_name = "Yelo Group";
$system_name  = "YMS – Yelo Group Management System";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy – YMS Yelo Group Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --yelo:        #f5c518;
            --yelo-dark:   #d4a710;
            --ink:         #0f1117;
            --ink-soft:    #1e2130;
            --ink-muted:   #2d3149;
            --surface:     #f7f8fa;
            --surface2:    #eef0f5;
            --border:      #dde1ec;
            --text:        #1a1d2e;
            --text-muted:  #5a6080;
            --accent-teal: #1ec4a8;
            --accent-red:  #e84c4c;
            --radius:      12px;
            --shadow:      0 4px 24px rgba(15,17,23,0.09);
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--surface);
            color: var(--text);
            line-height: 1.75;
            min-height: 100vh;
        }

        /* ── Hero banner ── */
        .pp-hero {
            background: var(--ink);
            position: relative;
            overflow: hidden;
            padding: 60px 24px 56px;
            text-align: center;
        }
        .pp-hero::before {
            content: '';
            position: absolute; inset: 0;
            background: repeating-linear-gradient(
                45deg,
                transparent,
                transparent 38px,
                rgba(245,197,24,.04) 38px,
                rgba(245,197,24,.04) 39px
            );
        }
        .pp-hero::after {
            content: '';
            position: absolute;
            width: 480px; height: 480px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(245,197,24,.18) 0%, transparent 70%);
            top: -160px; right: -80px;
            pointer-events: none;
        }
        .pp-hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(245,197,24,.12);
            border: 1px solid rgba(245,197,24,.3);
            color: var(--yelo);
            font-size: .72rem;
            font-weight: 600;
            letter-spacing: .12em;
            text-transform: uppercase;
            padding: 6px 16px;
            border-radius: 100px;
            margin-bottom: 22px;
            position: relative; z-index: 1;
        }
        .pp-hero h1 {
            font-family: 'DM Serif Display', serif;
            font-size: clamp(2rem, 5vw, 3.2rem);
            color: #fff;
            line-height: 1.15;
            margin-bottom: 16px;
            position: relative; z-index: 1;
        }
        .pp-hero h1 em {
            font-style: italic;
            color: var(--yelo);
        }
        .pp-hero p {
            color: rgba(255,255,255,.55);
            font-size: .95rem;
            max-width: 520px;
            margin: 0 auto 28px;
            position: relative; z-index: 1;
        }
        .pp-meta {
            display: inline-flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            justify-content: center;
            position: relative; z-index: 1;
        }
        .pp-meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
            color: rgba(255,255,255,.45);
            font-size: .8rem;
        }
        .pp-meta-item i { color: var(--yelo); font-size: .75rem; }

        /* ── Layout ── */
        .pp-layout {
            max-width: 1100px;
            margin: 0 auto;
            padding: 48px 24px 80px;
            display: grid;
            grid-template-columns: 240px 1fr;
            gap: 40px;
            align-items: start;
        }

        /* ── Sidebar TOC ── */
        .pp-toc {
            position: sticky;
            top: 24px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px 20px;
            box-shadow: var(--shadow);
        }
        .pp-toc-title {
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
        }
        .pp-toc a {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 7px 10px;
            border-radius: 7px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: .82rem;
            font-weight: 500;
            transition: all .15s;
            margin-bottom: 2px;
        }
        .pp-toc a:hover, .pp-toc a.active {
            background: rgba(245,197,24,.1);
            color: #8a6d00;
        }
        .pp-toc a i { font-size: .72rem; width: 14px; text-align: center; }
        .pp-toc-sep {
            height: 1px;
            background: var(--border);
            margin: 10px 0;
        }
        .pp-toc-back {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: .78rem;
            color: var(--text-muted);
            text-decoration: none;
            padding: 8px 10px;
            border-radius: 7px;
            transition: all .15s;
            margin-top: 6px;
        }
        .pp-toc-back:hover { background: var(--surface2); color: var(--text); }

        /* ── Content ── */
        .pp-content {
            min-width: 0;
        }

        /* Alert banner */
        .pp-alert {
            display: flex;
            gap: 14px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-left: 4px solid var(--yelo-dark);
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 36px;
            font-size: .88rem;
            color: #78490a;
        }
        .pp-alert i { margin-top: 2px; flex-shrink: 0; color: var(--yelo-dark); }

        /* Section cards */
        .pp-section {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 36px 36px 32px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
            scroll-margin-top: 24px;
            transition: box-shadow .2s;
        }
        .pp-section:hover { box-shadow: 0 8px 32px rgba(15,17,23,.13); }

        .pp-section-header {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 22px;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--border);
        }
        .pp-icon-wrap {
            width: 44px; height: 44px;
            border-radius: 10px;
            background: rgba(245,197,24,.12);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .pp-icon-wrap i { color: var(--yelo-dark); font-size: 1rem; }
        .pp-section-title {
            font-family: 'DM Serif Display', serif;
            font-size: 1.35rem;
            color: var(--ink);
            line-height: 1.25;
        }
        .pp-section-subtitle {
            font-size: .8rem;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .pp-section-num {
            font-size: .65rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--yelo-dark);
            background: rgba(245,197,24,.1);
            padding: 3px 8px;
            border-radius: 4px;
            margin-top: 4px;
            display: inline-block;
        }

        .pp-section p { margin-bottom: 14px; color: var(--text); font-size: .92rem; }
        .pp-section p:last-child { margin-bottom: 0; }

        /* Data table */
        .pp-data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .85rem;
            margin: 18px 0;
        }
        .pp-data-table thead tr {
            background: var(--ink);
            color: #fff;
        }
        .pp-data-table thead th {
            padding: 11px 14px;
            text-align: left;
            font-weight: 600;
            font-size: .78rem;
            letter-spacing: .04em;
        }
        .pp-data-table thead th:first-child { border-radius: 8px 0 0 0; }
        .pp-data-table thead th:last-child { border-radius: 0 8px 0 0; }
        .pp-data-table tbody tr:nth-child(even) { background: var(--surface); }
        .pp-data-table tbody td {
            padding: 10px 14px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
            color: var(--text);
        }
        .pp-data-table tbody tr:last-child td { border-bottom: none; }
        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: .72rem;
            font-weight: 600;
        }
        .tag-emp  { background:#e0f2fe; color:#0369a1; }
        .tag-fin  { background:#dcfce7; color:#166534; }
        .tag-ops  { background:#fef3c7; color:#92400e; }
        .tag-sys  { background:#f3e8ff; color:#7e22ce; }

        /* Feature list */
        .pp-list {
            list-style: none;
            padding: 0;
            margin: 14px 0;
        }
        .pp-list li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 0;
            font-size: .9rem;
            border-bottom: 1px dashed var(--border);
        }
        .pp-list li:last-child { border-bottom: none; }
        .pp-list li i {
            margin-top: 3px;
            font-size: .75rem;
            flex-shrink: 0;
        }
        .pp-list li i.ok  { color: var(--accent-teal); }
        .pp-list li i.no  { color: var(--accent-red); }
        .pp-list li i.dot { color: var(--yelo-dark); }

        /* Rights grid */
        .rights-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 14px;
            margin: 18px 0;
        }
        .right-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 18px 16px;
            text-align: center;
            transition: transform .15s, box-shadow .15s;
        }
        .right-card:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(15,17,23,.08); }
        .right-card i {
            font-size: 1.4rem;
            color: var(--yelo-dark);
            display: block;
            margin-bottom: 10px;
        }
        .right-card h4 { font-size: .88rem; font-weight: 600; color: var(--ink); margin-bottom: 5px; }
        .right-card p  { font-size: .78rem; color: var(--text-muted); margin: 0; }

        /* Highlight box */
        .pp-highlight {
            background: var(--ink-soft);
            color: #fff;
            border-radius: var(--radius);
            padding: 22px 24px;
            margin: 18px 0;
        }
        .pp-highlight p { color: rgba(255,255,255,.8); font-size: .88rem; margin-bottom: 8px; }
        .pp-highlight p:last-child { margin-bottom: 0; }
        .pp-highlight strong { color: var(--yelo); }

        /* Contact card */
        .contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 14px;
            margin: 20px 0;
        }
        .contact-card {
            display: flex;
            align-items: center;
            gap: 14px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
        }
        .contact-card .c-icon {
            width: 40px; height: 40px;
            background: var(--ink);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .contact-card .c-icon i { color: var(--yelo); font-size: .9rem; }
        .contact-card .c-label { font-size: .7rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; }
        .contact-card .c-val   { font-size: .88rem; font-weight: 600; color: var(--ink); }

        /* Footer */
        .pp-footer {
            background: var(--ink);
            color: rgba(255,255,255,.5);
            text-align: center;
            padding: 32px 24px;
            font-size: .82rem;
        }
        .pp-footer strong { color: var(--yelo); }
        .pp-footer-links { margin-top: 10px; display: flex; justify-content: center; gap: 20px; flex-wrap: wrap; }
        .pp-footer-links a { color: rgba(255,255,255,.45); text-decoration: none; font-size: .78rem; transition: color .15s; }
        .pp-footer-links a:hover { color: var(--yelo); }

        /* Responsive */
        @media (max-width: 768px) {
            .pp-layout { grid-template-columns: 1fr; }
            .pp-toc { display: none; }
            .pp-section { padding: 24px 20px; }
            .rights-grid { grid-template-columns: 1fr 1fr; }
        }

        /* Print */
        @media print {
            .pp-toc, .pp-hero::after, .pp-hero::before { display: none; }
            .pp-layout { display: block; }
            .pp-section { box-shadow: none; border: 1px solid #ccc; break-inside: avoid; }
        }

        /* Fade-in animation */
        .pp-section {
            opacity: 0;
            transform: translateY(16px);
            animation: fadeUp .45s ease forwards;
        }
        .pp-section:nth-child(1)  { animation-delay: .05s; }
        .pp-section:nth-child(2)  { animation-delay: .10s; }
        .pp-section:nth-child(3)  { animation-delay: .15s; }
        .pp-section:nth-child(4)  { animation-delay: .20s; }
        .pp-section:nth-child(5)  { animation-delay: .25s; }
        .pp-section:nth-child(6)  { animation-delay: .30s; }
        .pp-section:nth-child(7)  { animation-delay: .35s; }
        .pp-section:nth-child(8)  { animation-delay: .40s; }
        .pp-section:nth-child(9)  { animation-delay: .45s; }
        .pp-section:nth-child(10) { animation-delay: .50s; }
        .pp-section:nth-child(11) { animation-delay: .55s; }
        .pp-section:nth-child(12) { animation-delay: .60s; }
        .pp-alert { opacity: 0; animation: fadeUp .4s ease .03s forwards; }
        @keyframes fadeUp {
            to { opacity: 1; transform: none; }
        }
    </style>
</head>
<body>

<!-- ── Hero ────────────────────────────────────────────────────────────────── -->
<div class="pp-hero">
    <div class="pp-hero-badge">
        <i class="fa-solid fa-shield-halved"></i>
        Official Policy Document
    </div>
    <h1>Privacy &amp; <em>Data Protection</em> Policy</h1>
    <p>How Yelo Group collects, uses, protects and manages personal information within the YMS platform.</p>
    <div class="pp-meta">
        <span class="pp-meta-item"><i class="fa-solid fa-calendar-check"></i> Last updated: <?php echo $last_updated; ?></span>
        <span class="pp-meta-item"><i class="fa-solid fa-building"></i> Yelo Group (Pvt) Ltd</span>
        <span class="pp-meta-item"><i class="fa-solid fa-flag"></i> Sri Lanka</span>
        <span class="pp-meta-item"><i class="fa-solid fa-file-lines"></i> Version 1.0</span>
    </div>
</div>

<!-- ── Main layout ─────────────────────────────────────────────────────────── -->
<div class="pp-layout">

    <!-- Sidebar TOC -->
    <aside class="pp-toc">
        <div class="pp-toc-title"><i class="fa-solid fa-list"></i> &nbsp;Contents</div>
        <a href="#overview"        ><i class="fa-solid fa-eye"></i>          Overview</a>
        <a href="#scope"           ><i class="fa-solid fa-sitemap"></i>      Scope &amp; Applicability</a>
        <a href="#data-collected"  ><i class="fa-solid fa-database"></i>     Data We Collect</a>
        <a href="#purpose"         ><i class="fa-solid fa-bullseye"></i>     Purpose of Processing</a>
        <a href="#legal-basis"     ><i class="fa-solid fa-scale-balanced"></i> Legal Basis</a>
        <a href="#access-control"  ><i class="fa-solid fa-lock"></i>         Access &amp; Control</a>
        <a href="#retention"       ><i class="fa-solid fa-clock-rotate-left"></i> Data Retention</a>
        <a href="#security"        ><i class="fa-solid fa-shield"></i>       Security Measures</a>
        <a href="#sharing"         ><i class="fa-solid fa-share-nodes"></i>  Data Sharing</a>
        <a href="#rights"          ><i class="fa-solid fa-user-check"></i>   Your Rights</a>
        <a href="#biometric"       ><i class="fa-solid fa-fingerprint"></i>  Biometric &amp; AI</a>
        <a href="#changes"         ><i class="fa-solid fa-pen-to-square"></i>Policy Changes</a>
        <a href="#contact"         ><i class="fa-solid fa-envelope"></i>     Contact Us</a>
        <div class="pp-toc-sep"></div>
        <a href="index.php" class="pp-toc-back"><i class="fa-solid fa-arrow-left"></i> Back to Dashboard</a>
    </aside>

    <!-- Content -->
    <main class="pp-content">

        <div class="pp-alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div>
                <strong>Important Notice:</strong> This Privacy Policy applies to all users of the YMS – Yelo Group Management System, including employees, managers, administrators, and any third parties granted system access. By logging into and using YMS, you acknowledge that you have read and understood this policy.
            </div>
        </div>

        <!-- 1. Overview -->
        <section class="pp-section" id="overview">
            <div class="pp-section-header">
                <div class="pp-icon-wrap"><i class="fa-solid fa-eye"></i></div>
                <div>
                    <div class="pp-section-num">Section 01</div>
                    <div class="pp-section-title">Overview</div>
                    <div class="pp-section-subtitle">Who we are and what this policy covers</div>
                </div>
            </div>
            <p><strong>Yelo Group (Pvt) Ltd</strong> ("Yelo Group", "we", "us", or "our") operates the <strong>YMS – Yelo Group Management System</strong>, an internal enterprise resource planning (ERP) and human resource management platform used across all Yelo Group branches and subsidiaries in Sri Lanka.</p>
            <p>This Privacy Policy ("Policy") explains how we collect, store, use, disclose, protect, and retain personal data and sensitive business information processed through the YMS platform. It applies to all authorised users of the system including permanent employees, contract workers, delivery personnel, branch managers, area sales managers, and system administrators.</p>
            <p>We are committed to processing personal data in accordance with applicable Sri Lankan law, including the provisions of the <strong>Personal Data Protection Act No. 9 of 2022 (PDPA)</strong>, and in alignment with internationally recognised data protection principles.</p>
            <p>YMS is a <strong>strictly internal system</strong> — it is not a public-facing web application. Access is restricted to authorised personnel only and protected by role-based access controls.</p>
        </section>

        <!-- 2. Scope -->
        <section class="pp-section" id="scope">
            <div class="pp-section-header">
                <div class="pp-icon-wrap"><i class="fa-solid fa-sitemap"></i></div>
                <div>
                    <div class="pp-section-num">Section 02</div>
                    <div class="pp-section-title">Scope &amp; Applicability</div>
                    <div class="pp-section-subtitle">Who and what this policy covers</div>
                </div>
            </div>
            <p>This Policy applies to all data processing activities performed through the YMS platform, regardless of the user's role, branch, or employment type. The following categories of individuals are covered:</p>
            <ul class="pp-list">
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>Employees</strong> – All permanent and contract staff of Yelo Group registered in the system.</li>
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>Delivery Personnel &amp; Promoters</strong> – Field staff whose daily activities, attendance, and performance are tracked in YMS.</li>
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>Customers</strong> – Trade customers and business partners whose credit, cheque, and transaction records are maintained.</li>
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>System Users</strong> – Managers, administrators, IT staff, and any other individuals granted login credentials.</li>
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>Third Parties</strong> – Banks, financial institutions, and external service providers whose data is entered in connection with business operations.</li>
            </ul>
            <p>This Policy covers all modules of YMS including but not limited to: Human Resources, Attendance &amp; Leave, Payroll &amp; Salary, Cheque Management, Credit &amp; Collections, Sales &amp; Invoicing, Loan &amp; Advances, Reporting, and AI-powered tools.</p>
        </section>

        <!-- 3. Data We Collect -->
        <section class="pp-section" id="data-collected">
            <div class="pp-section-header">
                <div class="pp-icon-wrap"><i class="fa-solid fa-database"></i></div>
                <div>
                    <div class="pp-section-num">Section 03</div>
                    <div class="pp-section-title">Data We Collect</div>
                    <div class="pp-section-subtitle">Categories and types of personal and business data</div>
                </div>
            </div>
            <p>YMS processes the following categories of data in the course of normal business operations:</p>

            <table class="pp-data-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Examples</th>
                        <th>Module</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Personal Identity</strong></td>
                        <td>Full name, NIC/ID number, date of birth, gender, marital status, blood group</td>
                        <td><span class="tag tag-emp">HR</span></td>
                    </tr>
                    <tr>
                        <td><strong>Contact Information</strong></td>
                        <td>Mobile, home &amp; office telephone numbers, WhatsApp number, email address, home address</td>
                        <td><span class="tag tag-emp">HR</span></td>
                    </tr>
                    <tr>
                        <td><strong>Employment Data</strong></td>
                        <td>Employee ID, designation, staff category, branch, company code, date of joining, TR code, driving licence number</td>
                        <td><span class="tag tag-emp">HR</span></td>
                    </tr>
                    <tr>
                        <td><strong>Financial Data</strong></td>
                        <td>Bank name, branch, account number, basic salary, gratuity, insurance &amp; welfare amounts, EPF/ETF details, salary advances, loans</td>
                        <td><span class="tag tag-fin">Payroll</span></td>
                    </tr>
                    <tr>
                        <td><strong>Attendance &amp; Leave</strong></td>
                        <td>Daily check-in/check-out timestamps, attendance status, leave types, leave dates, leave history</td>
                        <td><span class="tag tag-emp">Attendance</span></td>
                    </tr>
                    <tr>
                        <td><strong>Biometric Data</strong></td>
                        <td>Fingerprint scan data from Hikvision devices; used solely for attendance verification</td>
                        <td><span class="tag tag-emp">Attendance</span></td>
                    </tr>
                    <tr>
                        <td><strong>Performance &amp; Incentive</strong></td>
                        <td>Field summary data, daily GSE records, incentive calculations, monthly targets, SR collection summaries</td>
                        <td><span class="tag tag-ops">Operations</span></td>
                    </tr>
                    <tr>
                        <td><strong>Customer Data</strong></td>
                        <td>Customer name, business name, credit limit, cheque details, collection history, credit ageing</td>
                        <td><span class="tag tag-fin">Finance</span></td>
                    </tr>
                    <tr>
                        <td><strong>Cheque &amp; Banking</strong></td>
                        <td>Cheque numbers, bank details, deposit records, cheque images (where uploaded), reconciliation data</td>
                        <td><span class="tag tag-fin">Finance</span></td>
                    </tr>
                    <tr>
                        <td><strong>System Access Logs</strong></td>
                        <td>Login timestamps, session activity, user actions, IP addresses, browser/device information</td>
                        <td><span class="tag tag-sys">System</span></td>
                    </tr>
                    <tr>
                        <td><strong>Uploaded Documents</strong></td>
                        <td>Profile photos, application forms, NIC copies, driving licence copies</td>
                        <td><span class="tag tag-emp">HR</span></td>
                    </tr>
                    <tr>
                        <td><strong>AI Interaction Data</strong></td>
                        <td>Queries submitted to the AI Chatbot and Gemini-powered cheque analysis tools</td>
                        <td><span class="tag tag-sys">AI Tools</span></td>
                    </tr>
                </tbody>
            </table>
            <p>We collect only the data that is necessary for the stated business purposes. Employees are informed of all data fields requested at the time of registration.</p>
        </section>

        <!-- 4. Purpose -->
        <section class="pp-section" id="purpose">
            <div class="pp-section-header">
                <div class="pp-icon-wrap"><i class="fa-solid fa-bullseye"></i></div>
                <div>
                    <div class="pp-section-num">Section 04</div>
                    <div class="pp-section-title">Purpose of Processing</div>
                    <div class="pp-section-subtitle">Why we collect and use your data</div>
                </div>
            </div>
            <p>All personal and business data collected through YMS is processed for the following clearly defined, legitimate business purposes:</p>
            <ul class="pp-list">
                <li><i class="fa-solid fa-check ok"></i><strong>Human Resource Management:</strong> Maintaining accurate employee records, managing employment contracts, designations, and organisational structure across all Yelo Group companies and branches.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>Attendance &amp; Leave Administration:</strong> Recording daily attendance (including biometric check-in/check-out), managing leave applications, approvals, and leave balances.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>Payroll Processing:</strong> Calculating salaries, deductions, EPF/ETF contributions, gratuity, incentives, salary advances, and loans based on attendance and performance data.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>Financial Operations:</strong> Managing cheque issuances, deposits, reconciliations, credit collections, customer payments, and bank statements.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>Sales &amp; Operations:</strong> Tracking field summaries, delivery performance, loading/unloading data, credit bill issuance, and route-wise collections.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>Compliance &amp; Audit:</strong> Maintaining records required under Sri Lankan labour law, EPF/ETF regulations, and internal audit requirements.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>System Security:</strong> Monitoring login activity, session management, and user access logs to detect and prevent unauthorised access.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>AI-Assisted Processing:</strong> Using AI tools (Gemini API, internal chatbot) to assist with cheque image analysis, data queries, and operational decision support. AI-generated outputs are reviewed by authorised personnel before acting upon them.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>Reporting &amp; Analytics:</strong> Generating management reports, branch performance dashboards, and statutory returns.</li>
                <li><i class="fa-solid fa-times no"></i><strong>We do NOT use personal data for advertising, marketing to third parties, or any purpose unrelated to Yelo Group's internal business operations.</strong></li>
            </ul>
        </section>

        <!-- 5. Legal Basis -->
        <section class="pp-section" id="legal-basis">
            <div class="pp-section-header">
                <div class="pp-icon-wrap"><i class="fa-solid fa-scale-balanced"></i></div>
                <div>
                    <div class="pp-section-num">Section 05</div>
                    <div class="pp-section-title">Legal Basis for Processing</div>
                    <div class="pp-section-subtitle">Why we are lawfully permitted to process your data</div>
                </div>
            </div>
            <p>Under the <strong>Personal Data Protection Act No. 9 of 2022 (Sri Lanka)</strong>, data processing must have a lawful basis. Yelo Group relies on the following lawful bases for processing personal data in YMS:</p>
            <table class="pp-data-table">
                <thead>
                    <tr><th>Lawful Basis</th><th>How It Applies to YMS</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Employment Contract</strong></td>
                        <td>Processing employee personal, attendance, payroll, and performance data is necessary to fulfil obligations under employment contracts.</td>
                    </tr>
                    <tr>
                        <td><strong>Legal Obligation</strong></td>
                        <td>EPF/ETF reporting, statutory payroll deductions, and maintaining employee registers are required by Sri Lankan labour law.</td>
                    </tr>
                    <tr>
                        <td><strong>Legitimate Business Interest</strong></td>
                        <td>Managing financial transactions, cheque handling, customer credit records, and operational data to run the business effectively.</td>
                    </tr>
                    <tr>
                        <td><strong>Consent</strong></td>
                        <td>Biometric fingerprint data collection requires and is obtained with explicit employee consent at the time of enrolment.</td>
                    </tr>
                    <tr>
                        <td><strong>Vital Interests</strong></td>
                        <td>Emergency contact and medical information (blood group) is retained for employee safety and welfare.</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- 6. Access & Control -->
        <section class="pp-section" id="access-control">
            <div class="pp-section-header">
                <div class="pp-icon-wrap"><i class="fa-solid fa-lock"></i></div>
                <div>
                    <div class="pp-section-num">Section 06</div>
                    <div class="pp-section-title">Access &amp; Role-Based Control</div>
                    <div class="pp-section-subtitle">Who can see what data, and how access is governed</div>
                </div>
            </div>
            <p>Access to data within YMS is strictly governed by a <strong>Role-Based Access Control (RBAC)</strong> model. Each user account is assigned a role that determines which modules, records, and actions are available to them.</p>
            <ul class="pp-list">
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>System Administrator:</strong> Full access to all modules, user management, permissions configuration, and system settings.</li>
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>HR Manager:</strong> Access to employee records, attendance, leave, payroll, and salary modules.</li>
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>Branch Manager / Area Sales Manager:</strong> Access limited to their assigned branch, field summaries, collections, and operational reports.</li>
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>Finance Officer:</strong> Access to cheque management, cash collections, deposits, and bank reconciliations.</li>
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>Field Representative / Delivery Person:</strong> Read-only or limited operational access specific to their duties.</li>
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>Auditor / Report Viewer:</strong> Read-only access to reports and financial summaries with no ability to modify records.</li>
            </ul>
            <div class="pp-highlight">
                <p><strong>Access Principles:</strong></p>
                <p>• <strong>Least Privilege:</strong> Users are granted only the minimum access necessary for their job function.</p>
                <p>• <strong>Need-to-Know:</strong> Sensitive data such as salary details and biometric records are accessible only to authorised HR and payroll personnel.</p>
                <p>• <strong>Session Security:</strong> All sessions are time-limited. Inactive sessions are automatically terminated to prevent unauthorised access.</p>
                <p>• <strong>Audit Trail:</strong> All significant user actions within YMS are logged and traceable to individual user accounts.</p>
            </div>
        </section>

        <!-- 7. Retention -->
        <section class="pp-section" id="retention">
            <div class="pp-section-header">
                <div class="pp-icon-wrap"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <div>
                    <div class="pp-section-num">Section 07</div>
                    <div class="pp-section-title">Data Retention</div>
                    <div class="pp-section-subtitle">How long we keep your data and why</div>
                </div>
            </div>
            <p>Yelo Group retains personal and business data only for as long as necessary to fulfil the purposes described in this Policy, or as required by applicable law.</p>
            <table class="pp-data-table">
                <thead>
                    <tr><th>Data Type</th><th>Retention Period</th><th>Reason</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Employee personal records</td>
                        <td>Duration of employment + 7 years</td>
                        <td>Labour law compliance, EPF/ETF claims</td>
                    </tr>
                    <tr>
                        <td>Attendance &amp; leave records</td>
                        <td>5 years from record date</td>
                        <td>Payroll audits, dispute resolution</td>
                    </tr>
                    <tr>
                        <td>Payroll &amp; salary data</td>
                        <td>7 years from payment date</td>
                        <td>Tax compliance, statutory requirements</td>
                    </tr>
                    <tr>
                        <td>Cheque &amp; financial records</td>
                        <td>7 years from transaction date</td>
                        <td>Audit trails, banking regulations</td>
                    </tr>
                    <tr>
                        <td>Customer credit records</td>
                        <td>5 years from last transaction</td>
                        <td>Credit management, legal disputes</td>
                    </tr>
                    <tr>
                        <td>System access logs</td>
                        <td>2 years</td>
                        <td>Security monitoring, incident investigation</td>
                    </tr>
                    <tr>
                        <td>Biometric fingerprint data</td>
                        <td>Duration of employment only</td>
                        <td>Deleted upon resignation or termination</td>
                    </tr>
                    <tr>
                        <td>Uploaded documents (photos, NIC, etc.)</td>
                        <td>Duration of employment + 3 years</td>
                        <td>HR records, reference purposes</td>
                    </tr>
                    <tr>
                        <td>AI chatbot interaction logs</td>
                        <td>90 days</td>
                        <td>System improvement, error resolution</td>
                    </tr>
                </tbody>
            </table>
            <p>After the applicable retention period has elapsed, data will be securely deleted or anonymised in accordance with our data deletion procedures.</p>
        </section>

        <!-- 8. Security -->
        <section class="pp-section" id="security">
            <div class="pp-section-header">
                <div class="pp-icon-wrap"><i class="fa-solid fa-shield"></i></div>
                <div>
                    <div class="pp-section-num">Section 08</div>
                    <div class="pp-section-title">Security Measures</div>
                    <div class="pp-section-subtitle">Technical and organisational controls to protect your data</div>
                </div>
            </div>
            <p>Yelo Group implements comprehensive technical and organisational security measures to protect personal data processed through YMS against unauthorised access, disclosure, alteration, loss, or destruction.</p>
            <ul class="pp-list">
                <li><i class="fa-solid fa-check ok"></i><strong>Authentication:</strong> All system access requires a username and password. Passwords are hashed using industry-standard algorithms and are never stored in plain text.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>Session Management:</strong> Sessions are secured with PHP session tokens; idle session timeout is enforced across all modules.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>Input Validation &amp; SQL Injection Prevention:</strong> All user inputs are sanitised using <code>mysqli_real_escape_string()</code> and validated before database operations.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>Encrypted Transmission:</strong> YMS is served over HTTPS. All data transmitted between the browser and server is encrypted in transit.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>Database Security:</strong> The database is hosted on a secured server with restricted network access. Database credentials are stored in a dedicated <code>config.php</code> file outside the public web root where applicable.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>File Upload Security:</strong> Uploaded documents (photos, NIC copies, etc.) are stored in access-controlled directories. File type and size are validated before storage.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>Error Handling:</strong> PHP error display is disabled in production (<code>display_errors = 0</code>); errors are logged internally and not exposed to end users.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>Backup &amp; Recovery:</strong> Regular database backups are maintained. Backup files are stored securely and tested periodically.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>Physical Security:</strong> Server infrastructure is hosted in a secure environment with restricted physical access.</li>
                <li><i class="fa-solid fa-check ok"></i><strong>Staff Awareness:</strong> Authorised system users are briefed on data security responsibilities and confidentiality obligations.</li>
            </ul>
            <p>In the event of a data breach, Yelo Group will take immediate steps to contain the incident and will notify affected individuals and relevant authorities in accordance with its obligations under the PDPA.</p>
        </section>

        <!-- 9. Sharing -->
        <section class="pp-section" id="sharing">
            <div class="pp-section-header">
                <div class="pp-icon-wrap"><i class="fa-solid fa-share-nodes"></i></div>
                <div>
                    <div class="pp-section-num">Section 09</div>
                    <div class="pp-section-title">Data Sharing &amp; Disclosure</div>
                    <div class="pp-section-subtitle">When and with whom data is shared</div>
                </div>
            </div>
            <p>Yelo Group does not sell, rent, or trade personal data to any third party. Data may be shared only in the following strictly limited circumstances:</p>
            <ul class="pp-list">
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>Within Yelo Group:</strong> Data may be accessed by authorised personnel across different Yelo Group companies and branches as required for legitimate operational purposes, subject to the role-based access controls described in Section 06.</li>
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>Financial Institutions:</strong> Bank account details, cheque data, and payment information are shared with banks and financial institutions as part of normal business operations (e.g., cheque deposits, EPF/ETF remittances).</li>
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>Government &amp; Statutory Bodies:</strong> EPF/ETF contribution data and other statutory information is reported to the Employees' Provident Fund Board, Inland Revenue, and other regulatory bodies as required by law.</li>
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>IT Service Providers:</strong> The system is hosted on a managed hosting platform. The hosting provider has access to server infrastructure but not to application-level data. Appropriate data processing agreements are in place.</li>
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>AI Service Providers:</strong> Certain YMS features use the <strong>Google Gemini API</strong> for cheque image analysis and the <strong>Anthropic API</strong> for AI chatbot functionality. Data submitted to these services is governed by the respective providers' terms of service and privacy policies. Users should avoid submitting highly sensitive personal information through AI-powered tools.</li>
                <li><i class="fa-solid fa-circle-dot dot"></i><strong>Legal &amp; Regulatory Requirements:</strong> We may disclose data when required to do so by law, court order, or at the request of law enforcement authorities.</li>
                <li><i class="fa-solid fa-times no"></i><strong>We will never share personal employee or customer data with any advertising networks, data brokers, or unauthorised third parties.</strong></li>
            </ul>
        </section>

        <!-- 10. Rights -->
        <section class="pp-section" id="rights">
            <div class="pp-section-header">
                <div class="pp-icon-wrap"><i class="fa-solid fa-user-check"></i></div>
                <div>
                    <div class="pp-section-num">Section 10</div>
                    <div class="pp-section-title">Your Data Rights</div>
                    <div class="pp-section-subtitle">Rights you have under the PDPA and how to exercise them</div>
                </div>
            </div>
            <p>Under the <strong>Personal Data Protection Act No. 9 of 2022 (Sri Lanka)</strong>, individuals whose personal data is processed have the following rights:</p>

            <div class="rights-grid">
                <div class="right-card">
                    <i class="fa-solid fa-eye"></i>
                    <h4>Right to Access</h4>
                    <p>Request a copy of the personal data we hold about you.</p>
                </div>
                <div class="right-card">
                    <i class="fa-solid fa-pen"></i>
                    <h4>Right to Rectification</h4>
                    <p>Request correction of inaccurate or incomplete personal data.</p>
                </div>
                <div class="right-card">
                    <i class="fa-solid fa-trash"></i>
                    <h4>Right to Erasure</h4>
                    <p>Request deletion of your data where there is no longer a lawful basis for retention.</p>
                </div>
                <div class="right-card">
                    <i class="fa-solid fa-hand"></i>
                    <h4>Right to Object</h4>
                    <p>Object to processing of your data in certain circumstances.</p>
                </div>
                <div class="right-card">
                    <i class="fa-solid fa-pause"></i>
                    <h4>Right to Restriction</h4>
                    <p>Request that we limit how we use your data in certain situations.</p>
                </div>
                <div class="right-card">
                    <i class="fa-solid fa-file-export"></i>
                    <h4>Right to Portability</h4>
                    <p>Receive your data in a structured, machine-readable format.</p>
                </div>
                <div class="right-card">
                    <i class="fa-solid fa-user-slash"></i>
                    <h4>Withdraw Consent</h4>
                    <p>Withdraw consent for biometric data collection at any time.</p>
                </div>
                <div class="right-card">
                    <i class="fa-solid fa-robot"></i>
                    <h4>Automated Decisions</h4>
                    <p>Not to be subject to solely automated decision-making with significant effects.</p>
                </div>
            </div>

            <p>To exercise any of these rights, employees should contact their immediate HR representative or the designated Data Protection Officer. Requests will be processed within <strong>30 days</strong> of receipt. Where requests are complex or numerous, this period may be extended by a further 60 days with notification.</p>
            <p>Note that some rights are subject to limitations. For example, payroll and EPF/ETF records must be retained for the legally required period regardless of an erasure request.</p>
        </section>

        <!-- 11. Biometric & AI -->
        <section class="pp-section" id="biometric">
            <div class="pp-section-header">
                <div class="pp-icon-wrap"><i class="fa-solid fa-fingerprint"></i></div>
                <div>
                    <div class="pp-section-num">Section 11</div>
                    <div class="pp-section-title">Biometric Data &amp; AI Tools</div>
                    <div class="pp-section-subtitle">Special provisions for sensitive data categories</div>
                </div>
            </div>

            <p><strong>Biometric Attendance (Hikvision Fingerprint Devices)</strong></p>
            <p>YMS integrates with Hikvision fingerprint-based biometric attendance devices. Biometric data (fingerprint templates) is classified as <strong>sensitive personal data</strong> under the PDPA and is subject to additional protections:</p>
            <ul class="pp-list">
                <li><i class="fa-solid fa-check ok"></i>Biometric enrolment requires written or acknowledged consent from the employee.</li>
                <li><i class="fa-solid fa-check ok"></i>Fingerprint templates are stored on the Hikvision device and transmitted only as attendance log entries (date/time/employee ID) to YMS — raw biometric templates are never stored in the YMS database.</li>
                <li><i class="fa-solid fa-check ok"></i>Biometric data is used exclusively for attendance verification and no other purpose.</li>
                <li><i class="fa-solid fa-check ok"></i>Fingerprint data is deleted from devices upon an employee's departure from the organisation.</li>
                <li><i class="fa-solid fa-check ok"></i>Employees who do not consent to biometric enrolment are provided an alternative attendance recording method.</li>
            </ul>

            <p style="margin-top:20px;"><strong>AI-Powered Tools (Gemini API &amp; AI Chatbot)</strong></p>
            <p>YMS includes AI-assisted features including a Gemini-powered cheque image reader and an internal AI chatbot. The following principles apply:</p>
            <ul class="pp-list">
                <li><i class="fa-solid fa-check ok"></i>AI tools are provided to assist authorised staff — they do not make autonomous decisions on matters with significant impact on individuals.</li>
                <li><i class="fa-solid fa-check ok"></i>Users should exercise caution when submitting data to AI tools. Avoid entering sensitive personal data (NIC numbers, bank accounts, health information) into AI prompts unless strictly necessary.</li>
                <li><i class="fa-solid fa-check ok"></i>AI-generated outputs are advisory only. Authorised personnel are responsible for verifying and acting on such outputs.</li>
                <li><i class="fa-solid fa-check ok"></i>Interaction data with Google Gemini API is subject to Google's privacy policy and terms of service. Interaction data with Anthropic's API is subject to Anthropic's privacy policy.</li>
                <li><i class="fa-solid fa-check ok"></i>YMS does not use AI tools for employee performance evaluation or disciplinary decisions without human review.</li>
            </ul>
        </section>

        <!-- 12. Changes -->
        <section class="pp-section" id="changes">
            <div class="pp-section-header">
                <div class="pp-icon-wrap"><i class="fa-solid fa-pen-to-square"></i></div>
                <div>
                    <div class="pp-section-num">Section 12</div>
                    <div class="pp-section-title">Policy Changes</div>
                    <div class="pp-section-subtitle">How we update this policy and notify you</div>
                </div>
            </div>
            <p>Yelo Group reserves the right to update this Privacy Policy at any time to reflect changes in our business practices, legal requirements, or system capabilities. When we make significant changes, we will:</p>
            <ul class="pp-list">
                <li><i class="fa-solid fa-circle-dot dot"></i>Update the "Last Updated" date at the top of this page.</li>
                <li><i class="fa-solid fa-circle-dot dot"></i>Display a notice within YMS informing users of the update.</li>
                <li><i class="fa-solid fa-circle-dot dot"></i>Where changes are material, notify affected users via system notifications or through their HR representatives.</li>
            </ul>
            <p>Your continued use of YMS after such changes constitutes acceptance of the updated Policy. We encourage all users to review this page periodically.</p>
            <p>Previous versions of this Policy are available from the IT / System Administration team upon request.</p>
        </section>

        <!-- 13. Contact -->
        <section class="pp-section" id="contact">
            <div class="pp-section-header">
                <div class="pp-icon-wrap"><i class="fa-solid fa-envelope"></i></div>
                <div>
                    <div class="pp-section-num">Section 13</div>
                    <div class="pp-section-title">Contact Us</div>
                    <div class="pp-section-subtitle">Data Protection Officer &amp; how to raise concerns</div>
                </div>
            </div>
            <p>If you have any questions, concerns, or requests regarding this Privacy Policy or the handling of your personal data within YMS, please contact us through the following channels:</p>

            <div class="contact-grid">
                <div class="contact-card">
                    <div class="c-icon"><i class="fa-solid fa-building"></i></div>
                    <div>
                        <div class="c-label">Organisation</div>
                        <div class="c-val">Yelo Group (Pvt) Ltd</div>
                    </div>
                </div>
                <div class="contact-card">
                    <div class="c-icon"><i class="fa-solid fa-user-tie"></i></div>
                    <div>
                        <div class="c-label">Data Protection Contact</div>
                        <div class="c-val">HR / IT Department</div>
                    </div>
                </div>
                <div class="contact-card">
                    <div class="c-icon"><i class="fa-solid fa-location-dot"></i></div>
                    <div>
                        <div class="c-label">Jurisdiction</div>
                        <div class="c-val">Sri Lanka</div>
                    </div>
                </div>
                <div class="contact-card">
                    <div class="c-icon"><i class="fa-solid fa-flag"></i></div>
                    <div>
                        <div class="c-label">Governing Law</div>
                        <div class="c-val">PDPA No. 9 of 2022</div>
                    </div>
                </div>
            </div>

            <p>If you believe that your data protection rights have been violated and your concern has not been adequately addressed, you have the right to lodge a complaint with the <strong>Data Protection Authority of Sri Lanka</strong> once it is fully established under the PDPA, or with any other applicable supervisory authority.</p>

            <div class="pp-highlight">
                <p><strong>Internal Escalation:</strong> Employees should first raise data privacy concerns with their direct HR representative. If the matter is not resolved satisfactorily, it may be escalated to the Senior Management or IT Administration team. All complaints will be acknowledged within 5 working days and resolved within 30 working days.</p>
            </div>
        </section>

    </main>
</div>

<!-- ── Footer ──────────────────────────────────────────────────────────────── -->
<footer class="pp-footer">
    <p>© <?php echo date('Y'); ?> <strong>Yelo Group (Pvt) Ltd</strong> · All rights reserved · YMS v1.0</p>
    <p style="margin-top:6px;">This document was last reviewed and updated on <strong><?php echo $last_updated; ?></strong>.</p>
    <div class="pp-footer-links">
        <a href="index.php"><i class="fa-solid fa-chart-line"></i> Dashboard</a>
        <a href="login.php"><i class="fa-solid fa-right-to-bracket"></i> Login</a>
        <a href="help.php"><i class="fa-solid fa-circle-question"></i> Help</a>
        <a href="mailto:it@yelogroup.lk"><i class="fa-solid fa-envelope"></i> Contact IT</a>
    </div>
</footer>

<script>
    // Highlight active TOC link on scroll
    const sections = document.querySelectorAll('.pp-section[id]');
    const tocLinks = document.querySelectorAll('.pp-toc a[href^="#"]');

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                tocLinks.forEach(l => l.classList.remove('active'));
                const active = document.querySelector('.pp-toc a[href="#' + entry.target.id + '"]');
                if (active) active.classList.add('active');
            }
        });
    }, { rootMargin: '-20% 0px -70% 0px' });

    sections.forEach(s => observer.observe(s));
</script>
</body>
</html>
