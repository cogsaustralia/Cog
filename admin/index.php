<?php
declare(strict_types=1);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Login | COG$ of AUSTRALIA FOUNDATION</title>
<style>
:root{--bg:#0f1720;--panel:#17212b;--panel2:#1f2c38;--panel3:#243444;--text:#eef2f7;--muted:#9fb0c1;--line:rgba(255,255,255,.08);--gold:#d4b25c;--ok:#b8efc8;--bad:#ffb4be}
*{box-sizing:border-box}
body{margin:0;font-family:Inter,Arial,sans-serif;background:linear-gradient(180deg,#0c1319,#121d27 24%,#0f1720);color:var(--text)}
a{color:inherit}
.shell{display:grid;grid-template-columns:300px 1fr;min-height:100vh}
.sidebar{background:linear-gradient(180deg,#121a23,#16212b);border-right:1px solid var(--line);padding:24px 18px}
.brand{display:flex;gap:12px;align-items:center;margin-bottom:24px}.brand img{width:44px;height:44px;border-radius:50%}.brand strong{display:block}.brand span{color:var(--muted);font-size:.9rem}
.side-section{margin-bottom:24px}.side-label{font-size:.8rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin:0 0 10px}
.nav{display:grid;gap:10px}.nav a{display:block;text-decoration:none;color:var(--text);padding:12px 14px;border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.03)}
.nav a.active{background:linear-gradient(180deg,#d4b25c,#b98b2f);color:#201507;border-color:rgba(212,178,92,.35);font-weight:800}
.main{padding:26px}.topbar{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin-bottom:22px}.eyebrow{display:inline-block;font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:10px}
h1{margin:0 0 8px;font-size:2rem}.muted{color:var(--muted)}
.button{display:inline-block;text-decoration:none;padding:.85rem 1rem;border-radius:14px;font-weight:800;border:1px solid rgba(212,178,92,.35);background:linear-gradient(180deg,#d4b25c,#b98b2f);color:#201507}
.button.secondary{background:rgba(255,255,255,.04);color:var(--text);border-color:var(--line)}
.grid{display:grid;gap:18px}.stats{grid-template-columns:repeat(4,minmax(0,1fr))}.two{grid-template-columns:1fr 1fr}.three{grid-template-columns:repeat(3,minmax(0,1fr))}
.card{background:linear-gradient(180deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:24px;padding:20px;box-shadow:0 18px 45px rgba(0,0,0,.22)}
.stat-label{color:var(--muted);font-size:.86rem}.stat-value{font-size:1.7rem;font-weight:800;margin-top:8px}
.panel-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}.panel-head h2{margin:0;font-size:1.08rem}
.notice{padding:12px 14px;border-radius:14px;margin-bottom:12px}.notice.ok{background:rgba(47,143,87,.12);color:var(--ok);border:1px solid rgba(47,143,87,.35)}.notice.bad{background:rgba(200,61,75,.12);color:var(--bad);border:1px solid rgba(200,61,75,.35)}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.field{display:grid;gap:6px}.field label{font-size:.88rem;color:var(--muted)}
input,select,textarea{width:100%;background:var(--panel2);border:1px solid var(--line);color:var(--text);padding:.9rem 1rem;border-radius:14px;font:inherit}
textarea{min-height:120px;resize:vertical}
table{width:100%;border-collapse:collapse}th,td{padding:10px 8px;border-bottom:1px dashed rgba(255,255,255,.08);text-align:left;vertical-align:top}th{color:var(--muted);font-weight:600}
.chip{display:inline-block;padding:.35rem .65rem;border-radius:999px;background:rgba(255,255,255,.05);border:1px solid var(--line);font-size:.8rem}
.empty{color:var(--muted);padding:10px 0}.actions{display:flex;gap:8px;flex-wrap:wrap}.mini-btn{display:inline-block;padding:.55rem .75rem;border-radius:10px;border:1px solid var(--line);background:rgba(255,255,255,.04);color:var(--text);font:inherit;cursor:pointer}
.action-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.action-card{display:block;color:var(--text);text-decoration:none;padding:16px;border-radius:18px;background:linear-gradient(180deg,var(--panel2),var(--panel3));border:1px solid var(--line)}
.action-card strong{display:block;margin-bottom:8px}.action-card span{color:var(--muted);line-height:1.5}
.ok{color:var(--ok)} .bad{color:var(--bad)}
@media (max-width:1200px){.stats{grid-template-columns:repeat(2,minmax(0,1fr))}.three{grid-template-columns:1fr}}
@media (max-width:900px){.shell{grid-template-columns:1fr}.stats,.two,.three,.form-grid,.action-grid{grid-template-columns:1fr}.main{padding:18px}}
</style>
</head><body><main class="card" style="width:min(100%,520px);margin:24px auto;">
<div class="brand"><img src="../assets/logo_webcir.png" alt="COG$ Australia" onerror="this.style.display='none'"><div><strong>COG$ of AUSTRALIA FOUNDATION</strong><br><span style="color:#9fb0c1">Admin access</span></div></div>
<h1>Admin login</h1><p class="muted">Sign in with your username, password, and the current 6-digit code from your authenticator app.</p>
<form id="admin-login-form" method="post" novalidate>
<div class="field"><label for="username">Username</label><input id="username" name="username" type="text" autocomplete="username" required></div>
<div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
<div class="field"><label for="otp">2FA code</label><input id="otp" name="otp" type="text" inputmode="numeric" autocomplete="one-time-code" required></div>
<button id="submit-btn" class="button" type="submit">Open admin</button>
<div id="status" class="notice" style="display:none;margin-top:14px" role="status" aria-live="polite"></div>
</form>
<div style="margin-top:16px"><a href="./bootstrap.php">Set up admin 2FA</a></div>
</main>
<script>
(function(){
const form=document.getElementById('admin-login-form'); const statusBox=document.getElementById('status'); const submitBtn=document.getElementById('submit-btn');
function showStatus(message,kind){statusBox.style.display='block'; statusBox.className='notice '+(kind==='ok'?'ok':'bad'); statusBox.textContent=message;}
form.addEventListener('submit', async function(event){
 event.preventDefault();
 const payload={username:form.username.value.trim(),password:form.password.value,otp:form.otp.value.trim()};
 if(!payload.username||!payload.password||!payload.otp){showStatus('Username, password, and 2FA code are required.','bad'); return;}
 submitBtn.disabled=true; showStatus('Signing in…','ok');
 try{
   const response=await fetch('../_app/api/index.php?route=auth/admin-login',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
   let result={}; try{result=await response.json();}catch(e){}
   if(!response.ok||result.success===false){throw new Error(result.error||'Login failed');}
   showStatus('Login successful. Opening dashboard…','ok'); window.location.href='./dashboard.php';
 }catch(error){showStatus(error&&error.message?error.message:'Login failed','bad'); submitBtn.disabled=false;}
});
})();
</script></body></html>