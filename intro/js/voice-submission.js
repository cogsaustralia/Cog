/* voice-submission.js — Block 3 only. All window exports defined here.
 * Handles text submission form + dashboard panel (My voice submissions).
 * Audio/video recorder loaded by voice-audio.html / voice-video.html respectively. */

(function () {
  'use strict';

  var API_BASE = '/_app/api/index.php?route=voice-submissions';

  // ── Soft compliance pre-check ──────────────────────────────────────────────
  function checkBanned(text) {
    var terms = window.BANNED_FRAMING_TERMS || [];
    for (var i = 0; i < terms.length; i++) {
      var m = text.match(terms[i]);
      if (m) return m[0];
    }
    return null;
  }

  // ── Text submission form ───────────────────────────────────────────────────
  function initTextForm() {
    var textarea = document.getElementById('vs-text');
    var charCount = document.getElementById('vs-char-count');
    var warning = document.getElementById('vs-warning');
    var consent = document.getElementById('vs-consent');
    var submit = document.getElementById('vs-submit');
    if (!textarea || !submit) return;

    var debounceTimer;
    textarea.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function () {
        var text = textarea.value;
        var len = text.length;
        if (charCount) charCount.textContent = len + ' / 280';
        var hit = checkBanned(text);
        if (hit && warning) {
          warning.textContent = '\u26a0 The phrase \u201c' + hit + '\u201d sounds like a financial claim. Could you rephrase to focus on why you joined the community rather than expected returns?';
          warning.style.display = 'block';
        } else if (warning) {
          warning.style.display = 'none';
        }
        updateSubmitBtn();
      }, 250);
    });

    if (consent) {
      consent.addEventListener('change', updateSubmitBtn);
    }

    function updateSubmitBtn() {
      var hasText = textarea.value.trim().length > 0;
      var hasConsent = !consent || consent.checked;
      submit.disabled = !(hasText && hasConsent);
    }

    submit.addEventListener('click', function () {
      submitVoiceText();
    });
  }

  function submitVoiceText() {
    var textarea = document.getElementById('vs-text');
    var consent = document.getElementById('vs-consent');
    var submit = document.getElementById('vs-submit');
    var warning = document.getElementById('vs-warning');
    if (!textarea) return;

    var text = textarea.value.trim();
    if (!text) { showWarning('Please enter your submission.'); return; }
    if (consent && !consent.checked) { showWarning('Please tick the consent box to continue.'); return; }

    submit.disabled = true;
    submit.textContent = 'Submitting\u2026';

    var displayFirst = '';
    var displayState = '';
    var dpEl = document.getElementById('vs-display-first');
    var dsEl = document.getElementById('vs-display-state');
    if (dpEl) displayFirst = dpEl.value || dpEl.textContent || '';
    if (dsEl) displayState = dsEl.value || dsEl.textContent || '';

    var fd = new FormData();
    fd.append('submission_type', 'text');
    fd.append('text_content', text);
    fd.append('consent_given', '1');
    fd.append('consent_text_version', 'v1.0');
    fd.append('display_name_first', displayFirst.trim());
    fd.append('display_state', displayState.trim());

    fetch(API_BASE, { method: 'POST', body: fd, credentials: 'include' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) {
          window.location.href = '/intro/voice-confirmed.html';
        } else {
          showWarning(d.error || 'Something went wrong. Please try again.');
          submit.disabled = false;
          submit.textContent = 'Submit';
        }
      })
      .catch(function () {
        showWarning('Network error. Please check your connection and try again.');
        submit.disabled = false;
        submit.textContent = 'Submit';
      });
  }

  function showWarning(msg) {
    var w = document.getElementById('vs-warning');
    if (!w) return;
    w.textContent = msg;
    w.style.display = 'block';
  }

  // ── Audio/video submission helper (used by voice-audio.html / voice-video.html) ──
  function submitVoiceFile(blob, submissionType, displayFirst, displayState) {
    var submit = document.getElementById('vs-submit');
    var warning = document.getElementById('vs-warning');
    if (submit) { submit.disabled = true; submit.textContent = 'Uploading\u2026'; }

    var ext = submissionType === 'audio' ? 'webm' : 'mp4';
    var fd = new FormData();
    fd.append('submission_type', submissionType);
    fd.append('submission_file', blob, 'recording.' + ext);
    fd.append('consent_given', '1');
    fd.append('consent_text_version', 'v1.0');
    fd.append('display_name_first', (displayFirst || '').trim());
    fd.append('display_state', (displayState || '').trim());

    fetch(API_BASE, { method: 'POST', body: fd, credentials: 'include' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) {
          window.location.href = '/intro/voice-confirmed.html';
        } else {
          if (warning) { warning.textContent = d.error || 'Upload failed. Please try again.'; warning.style.display = 'block'; }
          if (submit) { submit.disabled = false; submit.textContent = 'Submit'; }
        }
      })
      .catch(function () {
        if (warning) { warning.textContent = 'Network error. Please check your connection and try again.'; warning.style.display = 'block'; }
        if (submit) { submit.disabled = false; submit.textContent = 'Submit'; }
      });
  }

  // ── Member dashboard panel — My voice submissions ──────────────────────────
  function fetchVoiceSubmissions() {
    var panel = document.getElementById('vs-panel-content');
    if (!panel) return;
    panel.innerHTML = '<p class="vs-loading">Loading\u2026</p>';

    fetch(API_BASE + '/me', { credentials: 'include' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.success) { panel.innerHTML = '<p class="vs-error">Could not load submissions.</p>'; return; }
        renderVoiceSubmissions(d.data);
      })
      .catch(function () { panel.innerHTML = '<p class="vs-error">Network error. Try refreshing.</p>'; });
  }

  function renderVoiceSubmissions(items) {
    var panel = document.getElementById('vs-panel-content');
    if (!panel) return;
    if (!items || items.length === 0) {
      panel.innerHTML = '<p class="vs-empty">No submissions yet. <a href="/intro/voice-welcome.html">Add your voice.</a></p>';
      return;
    }
    var statusLabels = {
      pending_review: 'In review.',
      cleared_for_use: 'Cleared. Ready to be shared.',
      rejected: 'Returned to you.',
      withdrawn: 'Withdrawn by you.',
    };
    var html = '<ul class="vs-list">';
    items.forEach(function (item) {
      var label = statusLabels[item.compliance_status] || item.compliance_status;
      var preview = item.submission_type === 'text'
        ? (item.text_content || '').slice(0, 100) + ((item.text_content || '').length > 100 ? '\u2026' : '')
        : item.submission_type + ' submission';
      html += '<li class="vs-list-item">';
      html += '<span class="vs-list-status vs-list-status--' + item.compliance_status + '">' + label + '</span>';
      html += '<span class="vs-list-preview">' + escHtml(preview) + '</span>';
      if (item.used_in_post_url) {
        html += '<a class="vs-list-link" href="' + escHtml(item.used_in_post_url) + '" target="_blank" rel="noopener">View post</a>';
      }
      if (item.compliance_status === 'rejected' && item.rejection_reason_to_member) {
        html += '<div class="vs-list-reason">' + escHtml(item.rejection_reason_to_member) + '</div>';
        html += '<a class="vs-list-resubmit" href="/intro/voice-welcome.html">Resubmit</a>';
      }
      if (item.compliance_status !== 'withdrawn') {
        html += '<button class="vs-list-withdraw" data-id="' + parseInt(item.id) + '" onclick="withdrawVoiceSubmission(' + parseInt(item.id) + ')">Withdraw consent</button>';
      }
      html += '</li>';
    });
    html += '</ul>';
    html += '<div style="margin-top:12px"><a href="/intro/voice-welcome.html" style="font-size:.82rem;color:var(--gold,#c9973d)">+ Add another submission</a></div>';
    panel.innerHTML = html;
  }

  function withdrawVoiceSubmission(id) {
    if (!confirm('Withdraw this submission? If it has been shared on social media, we will take it down within 24 hours.')) return;
    fetch(API_BASE + '/' + id + '/withdraw', {
      method: 'POST',
      credentials: 'include',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ withdrawn_reason: 'Member requested withdrawal' }),
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d.success) {
          fetchVoiceSubmissions();
          if (d.data && d.data.social_removal) {
            alert('Withdrawn. We will take down the social post within 24 hours.');
          }
        } else {
          alert('Could not withdraw: ' + (d.error || 'Unknown error.'));
        }
      })
      .catch(function () { alert('Network error. Please try again.'); });
  }

  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── Init on DOMContentLoaded ───────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTextForm);
  } else {
    initTextForm();
  }

  // ── Window exports (defined and exported in same block per platform rules) ──
  window.submitVoiceText           = submitVoiceText;
  window.submitVoiceFile           = submitVoiceFile;
  window.fetchVoiceSubmissions     = fetchVoiceSubmissions;
  window.renderVoiceSubmissions    = renderVoiceSubmissions;
  window.withdrawVoiceSubmission   = withdrawVoiceSubmission;

})();
