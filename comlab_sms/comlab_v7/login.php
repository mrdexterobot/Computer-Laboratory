<?php
// COMLAB - Login Page
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/includes/require_auth.php';

initSession();

// Already logged in → straight to dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: /comlab/dashboard.php');
    exit;
}

// Flash messages
$flashMsg = '';
$msgType  = 'warning';
if (isset($_GET['msg']) && $_GET['msg'] === 'loggedout') {
    $flashMsg = 'You have been signed out.';
    $msgType  = 'success';
}
if (isset($_COOKIE['comlab_msg'])) {
    $flashMsg = htmlspecialchars($_COOKIE['comlab_msg']);
    $msgType  = 'warning';
    setcookie('comlab_msg', '', time() - 1, '/');
}

$csrf = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>COMLAB — Sign In</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.0/css/all.min.css">
  <link rel="stylesheet" href="/comlab/assets/comlab.css">
</head>
<body style="background:#1a2a4a;background-image:none;display:flex;align-items:center;justify-content:center;min-height:100vh;overflow:hidden;position:relative">

<div class="bg-pattern"></div>

<div class="login-box">

  <div class="login-logo">
    <div class="logo-icon"><i class="fas fa-laptop-code"></i></div>
    <div>
      <div class="logo-title">COMLAB</div>
      <div class="logo-sub">Computer Laboratory Management</div>
      <div class="logo-module"><i class="fas fa-link" style="font-size:.55rem;margin-right:2px"></i>School Management System</div>
    </div>
  </div>

  <h2 class="login-heading">Sign in to your account</h2>

  <?php if ($flashMsg): ?>
  <div class="alert-error" style="background:<?= $msgType==='success'?'#dcfce7':'#fee2e2' ?>;border-color:<?= $msgType==='success'?'#86efac':'#fca5a5' ?>;color:<?= $msgType==='success'?'#15803d':'#991b1b' ?>">
    <i class="fas fa-<?= $msgType==='success'?'circle-check':'circle-exclamation' ?>"></i>
    <?= htmlspecialchars($flashMsg) ?>
  </div>
  <?php endif; ?>

  <div class="alert-error" id="loginErr" style="display:none">
    <i class="fas fa-circle-exclamation"></i>
    <span id="loginErrTxt"></span>
  </div>

  <div class="field">
    <label for="username">Username</label>
    <input type="text" id="username" name="username"
           placeholder="Enter your username"
           autocomplete="username" autofocus
           onkeydown="if(event.key==='Enter')document.getElementById('password').focus()">
  </div>

  <div class="field" style="margin-bottom:1.25rem">
    <label for="password">Password</label>
    <div class="pw-wrap">
      <input type="password" id="password" name="password"
             placeholder="Enter your password"
             autocomplete="current-password"
             onkeydown="if(event.key==='Enter')doLogin()">
      <button type="button" class="pw-toggle" onclick="togglePw()" tabindex="-1">
        <i class="fas fa-eye" id="pwIcon"></i>
      </button>
    </div>
  </div>

  <button class="btn-login" id="loginBtn" onclick="doLogin()">
    <i class="fas fa-right-to-bracket"></i> Sign In
  </button>

  <!-- Demo accounts — remove in production -->
  <div class="demo-section">
    <div class="demo-title"><i class="fas fa-flask" style="margin-right:4px;opacity:.65"></i> Demo Accounts</div>
    <div class="demo-grid">
      <div class="demo-card" onclick="fill('admin','Admin@123')">
        <div class="demo-icon ic-admin"><i class="fas fa-user-shield"></i></div>
        <div>
          <div class="demo-role">Administrator</div>
          <div class="demo-creds">admin / Admin@123</div>
        </div>
      </div>
      <div class="demo-card" onclick="fill('msantos','Faculty@123')">
        <div class="demo-icon ic-faculty"><i class="fas fa-chalkboard-teacher"></i></div>
        <div>
          <div class="demo-role">Faculty · Santos</div>
          <div class="demo-creds">msantos / Faculty@123</div>
        </div>
      </div>
      <div class="demo-card" onclick="fill('jreyes','Faculty@123')">
        <div class="demo-icon ic-faculty"><i class="fas fa-chalkboard-teacher"></i></div>
        <div>
          <div class="demo-role">Faculty · Reyes</div>
          <div class="demo-creds">jreyes / Faculty@123</div>
        </div>
      </div>
      <div class="demo-card" onclick="fill('acruz','Faculty@123')">
        <div class="demo-icon ic-faculty"><i class="fas fa-chalkboard-teacher"></i></div>
        <div>
          <div class="demo-role">Faculty · Cruz</div>
          <div class="demo-creds">acruz / Faculty@123</div>
        </div>
      </div>
    </div>
  </div>

</div>

<script>
const CSRF = '<?= htmlspecialchars($csrf) ?>';

function fill(u, p) {
  document.getElementById('username').value = u;
  document.getElementById('password').value = p;
  document.getElementById('loginErr').style.display = 'none';
  document.getElementById('password').focus();
}

async function doLogin() {
  const u   = document.getElementById('username').value.trim();
  const p   = document.getElementById('password').value;
  const err = document.getElementById('loginErr');
  const btn = document.getElementById('loginBtn');

  err.style.display = 'none';
  if (!u || !p) {
    document.getElementById('loginErrTxt').textContent = 'Please enter your username and password.';
    err.style.display = 'flex'; return;
  }

  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Signing in…';

  const fd = new FormData();
  fd.append('username', u);
  fd.append('password', p);
  fd.append('csrf_token', CSRF);

  try {
    const res  = await fetch('/comlab/api/auth/login.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (data.success) {
      btn.innerHTML = '<i class="fas fa-circle-check"></i> Redirecting…';
      window.location.href = data.redirect || '/comlab/dashboard.php';
    } else {
      document.getElementById('loginErrTxt').textContent = data.message || 'Login failed.';
      err.style.display = 'flex';
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-right-to-bracket"></i> Sign In';
      document.getElementById('password').value = '';
      document.getElementById('password').focus();
    }
  } catch (e) {
    document.getElementById('loginErrTxt').textContent = 'Connection error. Please try again.';
    err.style.display = 'flex';
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-right-to-bracket"></i> Sign In';
  }
}

function togglePw() {
  const inp = document.getElementById('password');
  const ico = document.getElementById('pwIcon');
  inp.type  = inp.type === 'password' ? 'text' : 'password';
  ico.className = inp.type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
}
</script>
</body>
</html>
