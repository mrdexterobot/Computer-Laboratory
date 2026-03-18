<?php
// ============================================
// COMLAB - Setup & Seed Script (NEW)
// URL: /comlab/setup.php?key=comlab_setup_2025
//
// Seeds: 1 Admin + 3 Faculty, 3 labs, 15 devices,
//        4 recurring schedules, ~3 weeks attendance,
//        3 sample requests.
//
// Delete this file after confirming login works!
// ============================================

define('SETUP_KEY', 'comlab_setup_2025');

if (!isset($_GET['key']) || $_GET['key'] !== SETUP_KEY) {
    http_response_code(403);
    die('<h2>403 Forbidden</h2><p>Access: <code>?key='.SETUP_KEY.'</code></p>');
}

require_once __DIR__.'/config/db.php';

$lockFile = __DIR__.'/setup.lock';
if (file_exists($lockFile) && !isset($_GET['force'])) {
    http_response_code(403);
    die('<h2 style="font-family:sans-serif;color:#dc2626;padding:40px">
         Setup already completed.<br>
         <small style="color:#6b7280;font-size:13px">
           Add <code>&force=1</code> to re-seed (skips existing records).
         </small></h2>');
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>COMLAB Setup</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  body{font-family:'DM Sans',sans-serif;background:#0f172a;color:#94a3b8;padding:2rem;max-width:860px;margin:0 auto}
  h2{color:#60a5fa;margin-bottom:1.25rem;font-size:1.3rem}
  pre{background:#1e293b;padding:1rem;border-radius:10px;overflow:auto;line-height:1.8;font-family:'DM Mono',monospace;font-size:.8rem}
  .ok{color:#34d399}.err{color:#f87171}.warn{color:#fbbf24}.skip{color:#475569}
  strong.h{color:#93c5fd}
  .done{background:#052e16;border:1px solid #166534;border-radius:10px;padding:1.5rem;margin-top:1.5rem}
  table{border-collapse:collapse;width:100%;font-size:.82rem}
  th{color:#60a5fa;text-align:left;padding:.35rem .75rem .35rem 0;border-bottom:1px solid #1e293b}
  td{padding:.35rem .75rem .35rem 0;color:#94a3b8;vertical-align:top}
  code{background:#1e293b;padding:.1rem .4rem;border-radius:4px;font-family:'DM Mono',monospace;font-size:.78rem}
  a{color:#60a5fa}
  ul{padding-left:1.2rem;line-height:2;font-size:.82rem;color:#64748b}
</style>
</head>
<body>
<h2><i>🛠</i> COMLAB — Setup & Seed</h2>
<?php

$log = []; $errors = 0;

function ok(string $m):   void { global $log; $log[] = "<span class='ok'>  ✓ {$m}</span>"; }
function warn(string $m): void { global $log; $log[] = "<span class='warn'>  ⚠ {$m}</span>"; }
function skip(string $m): void { global $log; $log[] = "<span class='skip'>  · {$m}</span>"; }
function fail(string $m): void { global $log, $errors; $errors++; $log[] = "<span class='err'>  ✗ {$m}</span>"; }
function hd(string $m):   void { global $log; $log[] = "\n<strong class='h'>→ {$m}</strong>"; }

try {
    $db = getDB();

    // ── 1. USERS ──────────────────────────────────────────────────────────
    hd('Creating users…');

    $users = [
        ['username'=>'admin',   'email'=>'admin@comlab.edu',   'password'=>'Admin@123',
         'first_name'=>'System','last_name'=>'Administrator','role'=>'Administrator',
         'dept'=>'IT Department'],
        ['username'=>'msantos', 'email'=>'msantos@comlab.edu', 'password'=>'Faculty@123',
         'first_name'=>'Maria', 'last_name'=>'Santos','role'=>'Faculty',
         'dept'=>'College of Computer Studies'],
        ['username'=>'jreyes',  'email'=>'jreyes@comlab.edu',  'password'=>'Faculty@123',
         'first_name'=>'Jose',  'last_name'=>'Reyes', 'role'=>'Faculty',
         'dept'=>'College of Computer Studies'],
        ['username'=>'acruz',   'email'=>'acruz@comlab.edu',   'password'=>'Faculty@123',
         'first_name'=>'Ana',   'last_name'=>'Cruz',  'role'=>'Faculty',
         'dept'=>'College of Information Technology'],
    ];

    $uid = []; // username → user_id
    foreach ($users as $u) {
        $chk = $db->prepare('SELECT user_id FROM users WHERE username=? OR email=?');
        $chk->execute([$u['username'], $u['email']]);
        if ($existing = $chk->fetchColumn()) {
            skip("User '{$u['username']}' already exists — skipped.");
            $uid[$u['username']] = $existing; continue;
        }
        $hash = password_hash($u['password'], PASSWORD_BCRYPT, ['cost'=>10]);
        $uid[$u['username']] = insertReturningId(
            $db,
            'INSERT INTO users (username,email,password_hash,first_name,last_name,role,department,is_active)
             VALUES (?,?,?,?,?,?,?,1)',
            [$u['username'],$u['email'],$hash,$u['first_name'],$u['last_name'],$u['role'],$u['dept']],
            'user_id'
        );
        ok("[{$u['role']}] {$u['first_name']} {$u['last_name']} — <code>{$u['username']}</code> / <code>{$u['password']}</code>");
    }

    // ── 2. LAB LOCATIONS ──────────────────────────────────────────────────
    hd('Creating lab locations…');

    $labs = [
        ['Computer Lab A','LAB-A','Main Building',  '2nd Floor','201',30,'07:30:00','19:00:00'],
        ['Computer Lab B','LAB-B','Main Building',  '2nd Floor','202',25,'07:30:00','19:00:00'],
        ['Computer Lab C','LAB-C','Annex Building', '1st Floor','101',20,'08:00:00','17:00:00'],
    ];

    $lid = []; // lab_code → location_id
    foreach ($labs as $l) {
        $chk = $db->prepare('SELECT location_id FROM locations WHERE lab_code=?');
        $chk->execute([$l[1]]);
        if ($existing = $chk->fetchColumn()) {
            skip("Lab '{$l[1]}' already exists — skipped.");
            $lid[$l[1]] = $existing; continue;
        }
        $lid[$l[1]] = insertReturningId(
            $db,
            'INSERT INTO locations (lab_name,lab_code,building,floor,room_number,capacity,operating_hours_start,operating_hours_end,is_active)
             VALUES (?,?,?,?,?,?,?,?,1)',
            $l,
            'location_id'
        );
        ok("Lab: {$l[0]} ({$l[1]}) — cap {$l[5]}, {$l[6]}–{$l[7]}");
    }

    // ── 3. DEVICES ────────────────────────────────────────────────────────
    hd('Adding sample devices…');

    $devs = [
        ['PC-A-001','Desktop','Dell',   'OptiPlex 7090',       'SN-A001','Available',  $lid['LAB-A']??null],
        ['PC-A-002','Desktop','Dell',   'OptiPlex 7090',       'SN-A002','Available',  $lid['LAB-A']??null],
        ['PC-A-003','Desktop','Dell',   'OptiPlex 7090',       'SN-A003','Under Repair',$lid['LAB-A']??null],
        ['PC-A-004','Desktop','Dell',   'OptiPlex 7090',       'SN-A004','Available',  $lid['LAB-A']??null],
        ['PC-A-005','Desktop','Dell',   'OptiPlex 7090',       'SN-A005','Damaged',    $lid['LAB-A']??null],
        ['PC-A-006','Desktop','Dell',   'OptiPlex 7090',       'SN-A006','Available',  $lid['LAB-A']??null],
        ['PC-B-001','Desktop','HP',     'EliteDesk 805 G8',   'SN-B001','Available',  $lid['LAB-B']??null],
        ['PC-B-002','Desktop','HP',     'EliteDesk 805 G8',   'SN-B002','Available',  $lid['LAB-B']??null],
        ['PC-B-003','Desktop','HP',     'EliteDesk 805 G8',   'SN-B003','In Use',     $lid['LAB-B']??null],
        ['PC-B-004','Desktop','HP',     'EliteDesk 805 G8',   'SN-B004','Available',  $lid['LAB-B']??null],
        ['PC-C-001','Desktop','Lenovo', 'ThinkCentre M80s',   'SN-C001','Available',  $lid['LAB-C']??null],
        ['PC-C-002','Desktop','Lenovo', 'ThinkCentre M80s',   'SN-C002','Available',  $lid['LAB-C']??null],
        ['PRINT-A-001','Printer','Canon','iR-ADV 525i',       'SN-P001','Available',  $lid['LAB-A']??null],
        ['MON-A-001','Monitor','Dell',  'P2422H 24"',         'SN-M001','Available',  $lid['LAB-A']??null],
        ['KBD-A-001','Keyboard','Logitech','MK270 Wireless',  'SN-K001','Available',  $lid['LAB-A']??null],
    ];

    foreach ($devs as $d) {
        if (!$d[6]) { warn("Device '{$d[0]}' skipped — lab not found."); continue; }
        $chk = $db->prepare('SELECT device_id FROM devices WHERE device_code=?');
        $chk->execute([$d[0]]);
        if ($chk->fetchColumn()) { skip("Device '{$d[0]}' already exists."); continue; }
        $db->prepare(
            'INSERT INTO devices (device_code,device_type,brand,model,serial_number,status,location_id,purchase_date)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$d[0],$d[1],$d[2],$d[3],$d[4],$d[5],$d[6],'2023-06-01']);
        ok("Device: {$d[0]} ({$d[1]}) — {$d[5]}");
    }

    // ── 4. FACULTY SCHEDULES ──────────────────────────────────────────────
    hd('Creating recurring faculty schedules…');

    $adminId  = $uid['admin']   ?? null;
    $santosId = $uid['msantos'] ?? null;
    $reyesId  = $uid['jreyes']  ?? null;
    $cruzId   = $uid['acruz']   ?? null;
    $labA = $lid['LAB-A'] ?? null;
    $labB = $lid['LAB-B'] ?? null;
    $labC = $lid['LAB-C'] ?? null;

    $semStart = date('Y-01-01');
    $semEnd   = date('Y-06-30');

    // [faculty_id, assigned_by, location_id, class_name, day_of_week, start, end, dur, sem_start, sem_end, dept, notes]
    $scheds = [
        [$santosId,$adminId,$labA,'CIS101 - Introduction to Computing',
         'Monday,Wednesday','08:00:00','10:00:00',2.0,$semStart,$semEnd,
         'College of Computer Studies','Core subject — 1st year'],

        [$santosId,$adminId,$labB,'CS201 - Object-Oriented Programming',
         'Tuesday,Thursday','10:00:00','12:00:00',2.0,$semStart,$semEnd,
         'College of Computer Studies',''],

        [$reyesId,$adminId,$labB,'IT301 - Database Management Systems',
         'Monday,Wednesday,Friday','13:00:00','15:00:00',2.0,$semStart,$semEnd,
         'College of Computer Studies','SQL practicals included'],

        [$cruzId,$adminId,$labC,'IT101 - Computer Fundamentals',
         'Tuesday,Thursday','08:00:00','10:00:00',2.0,$semStart,$semEnd,
         'College of Information Technology',''],
    ];

    $schedIds = [];
    foreach ($scheds as $s) {
        if (!$s[0]||!$s[1]||!$s[2]) { warn("Schedule '{$s[3]}': missing ID — skipped."); continue; }
        $chk = $db->prepare('SELECT schedule_id FROM faculty_schedules WHERE faculty_id=? AND class_name=? AND semester_start=?');
        $chk->execute([$s[0],$s[3],$s[8]]);
        if ($existing = $chk->fetchColumn()) {
            skip("Schedule '{$s[3]}' already exists (id={$existing}).");
            $schedIds[] = $existing; continue;
        }
        $id = insertReturningId(
            $db,
            'INSERT INTO faculty_schedules
             (faculty_id,assigned_by,location_id,class_name,day_of_week,
              start_time,end_time,duration_hours,semester_start,semester_end,
              department,notes,is_active)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1)',
            $s,
            'schedule_id'
        );
        $schedIds[] = $id;
        ok("Schedule: {$s[3]} — {$s[4]}, {$s[5]}–{$s[6]}");
    }

    // ── 5. ATTENDANCE HISTORY (past 3 weeks) ──────────────────────────────
    hd('Seeding ~3 weeks of attendance history…');

    $allScheds = $db->query(
        "SELECT schedule_id, faculty_id, day_of_week, start_time, semester_start, semester_end
         FROM faculty_schedules WHERE is_active=1"
    )->fetchAll();

    $today = new DateTime(); $attCount = 0;

    foreach ($allScheds as $sc) {
        $days = array_map('trim', explode(',', $sc['day_of_week']));
        for ($dBack = 21; $dBack >= 1; $dBack--) {
            $dt      = (clone $today)->modify("-{$dBack} days");
            $dayName = $dt->format('l');
            if (!in_array($dayName, $days, true)) continue;
            $dateStr = $dt->format('Y-m-d');
            if ($dateStr < $sc['semester_start'] || $dateStr > $sc['semester_end']) continue;

            $chk = $db->prepare('SELECT attendance_id FROM schedule_attendance WHERE schedule_id=? AND attendance_date=?');
            $chk->execute([$sc['schedule_id'], $dateStr]);
            if ($chk->fetchColumn()) continue;

            $rand = mt_rand(1,100);
            if ($rand <= 78) {
                $status = 'Present';
                $checkIn = $dateStr.' '.date('H:i:s', strtotime($sc['start_time']) - mt_rand(0, 840));
                $auto = 0;
            } elseif ($rand <= 92) {
                $status = 'Absent'; $checkIn = null; $auto = 1;
            } else {
                $status = 'Excused'; $checkIn = null; $auto = 0;
            }

            $db->prepare(
                'INSERT INTO schedule_attendance
                 (schedule_id,faculty_id,attendance_date,status,checked_in_at,marked_by_system)
                 VALUES (?,?,?,?,?,?)
                 ON CONFLICT (schedule_id, attendance_date) DO NOTHING'
            )->execute([$sc['schedule_id'],$sc['faculty_id'],$dateStr,$status,$checkIn,$auto]);
            $attCount++;
        }
    }
    ok("{$attCount} attendance record(s) seeded across ".count($allScheds)." schedule(s).");

    // ── 6. SAMPLE REQUESTS ────────────────────────────────────────────────
    hd('Adding sample requests…');

    $devId = $db->query("SELECT device_id FROM devices WHERE device_code='PC-A-003'")->fetchColumn();

    $reqs = [
        ['Maintenance',$santosId??0,'College of Computer Studies',$labA??null,$devId??null,
         'PC-A-003 randomly shuts down mid-session. Needs hardware inspection.',
         date('Y-m-d',strtotime('+3 days')),'Pending'],
        ['Unit',$cruzId??0,'College of Information Technology',$labC??null,null,
         'Requesting 5 additional keyboards for Lab C — current units worn out.',
         date('Y-m-d',strtotime('+7 days')),'Pending'],
        ['Maintenance',$reyesId??0,'College of Computer Studies',$labB??null,null,
         'Lab B projector lamp flickering. Needs replacement.',
         date('Y-m-d',strtotime('+5 days')),'Approved'],
    ];

    foreach ($reqs as $r) {
        if (!$r[1]) { warn("Request skipped — no faculty ID."); continue; }
        $chk = $db->prepare('SELECT request_id FROM requests WHERE submitted_by=? AND issue_description=?');
        $chk->execute([$r[1],$r[5]]);
        if ($chk->fetchColumn()) { skip("Request already exists — skipped."); continue; }
        $db->prepare(
            'INSERT INTO requests (request_type,submitted_by,department,location_id,device_id,issue_description,date_needed,status)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute($r);
        ok("Request ({$r[0]}): {$r[7]} — ".mb_substr($r[5],0,50).'…');
    }

    ok("\nSetup complete!");

} catch (Exception $e) {
    fail("ERROR: ".htmlspecialchars($e->getMessage()));
}

echo '<pre>'.implode("\n",$log).'</pre>';

if (!$errors): ?>
<div class="done">
  <h3 style="color:#34d399;margin:0 0 1rem">✅ System ready to test</h3>

  <p style="margin-bottom:.65rem;font-size:.82rem"><strong>Login Credentials</strong></p>
  <table>
    <tr><th>Role</th><th>Username</th><th>Password</th><th>What to test</th></tr>
    <tr>
      <td>Administrator</td><td><code>admin</code></td><td><code>Admin@123</code></td>
      <td>Assign schedules · manage devices · review requests · run absence sweep · view attendance summary</td>
    </tr>
    <tr>
      <td>Faculty</td><td><code>msantos</code></td><td><code>Faculty@123</code></td>
      <td>View assigned schedule · check-in · submit request · view own attendance</td>
    </tr>
    <tr>
      <td>Faculty</td><td><code>jreyes</code></td><td><code>Faculty@123</code></td>
      <td>Mon/Wed/Fri 13:00–15:00 Lab B schedule</td>
    </tr>
    <tr>
      <td>Faculty</td><td><code>acruz</code></td><td><code>Faculty@123</code></td>
      <td>Tue/Thu Lab C — different department</td>
    </tr>
  </table>

  <p style="margin:1rem 0 .4rem;font-size:.8rem"><strong>What was seeded</strong></p>
  <ul>
    <li>4 users — 1 Administrator, 3 Faculty</li>
    <li>3 lab locations — Lab A (30 seats), Lab B (25), Lab C (20)</li>
    <li>15 devices across all labs (mixed statuses)</li>
    <li>4 recurring faculty schedules across different days &amp; labs</li>
    <li><?= $attCount ?? 0 ?> attendance records — past 3 weeks of history (78% present rate)</li>
    <li>3 sample requests — 2 Pending, 1 Approved</li>
  </ul>

  <p style="margin-top:1rem;color:#fbbf24;font-size:.78rem">
    ⚠ <strong>Delete or rename setup.php</strong> after confirming login works.
  </p>
  <p style="margin-top:.5rem"><a href="login.php">→ Go to Login Page</a></p>
</div>
<?php endif; ?>
</body></html>
<?php
file_put_contents($lockFile, date('Y-m-d H:i:s'));
