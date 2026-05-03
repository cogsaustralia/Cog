# CCC Session 17: wallets/member.html — GNAF address search + DOB, grade-6 identity copy
# Pull main before starting: git pull --rebase origin main
# Read wallets/member.html before every edit. Show diff. STOP before committing.

## GROUND TRUTH RULES
- Read exact current file state before every edit
- Stage only wallets/member.html
- Verify div balance, script balance, </body>, </html> after all changes
- Zero AI tells — Grade 6 Australian plain English only
- No em-dashes in user-visible text
- Commit to review/session-17 — do NOT push to main

---

## BACKGROUND
The onboarding modal has three steps: jvpa → dob → address.
The address step (key === 'address' in obRenderStep) currently renders four separate fields:
  ob-street, ob-suburb, ob-state, ob-postcode
The DOB step (key === 'dob') has plain copy that does not explain why DOB is needed.
The governance-banner (id="governance-banner") has a separate older multi-column address form
that also needs updating to match.

The API call at vault/governance-complete expects:
  { street_address, suburb, state_code, postcode, date_of_birth }

---

## CHANGE 1 — Address step: replace multi-field with GNAF single-line search

In the obRenderStep function, find the block: } else if (key === 'address') {
Replace the body.innerHTML assignment for the address step with this:

body.innerHTML = ''
  + '<p style="font-size:.88rem;color:rgba(220,196,148,.85);line-height:1.65;margin:0 0 6px">'
  + 'We need your home address to work out which mining and energy projects are near you.'
  + ' That decides your governance zone and your vote weight on local issues.'
  + ' Your address is stored securely and never shown publicly.</p>'
  + '<p style="font-size:.82rem;color:rgba(220,196,148,.6);line-height:1.55;margin:0 0 16px">'
  + 'Start typing your address below. Select your address from the list to confirm it.</p>'
  + '<div style="position:relative">'
  + '<input type="text" id="ob-address-search" placeholder="Start typing your address..." autocomplete="off" '
  + 'style="width:100%;background:rgba(255,255,255,.08);border:1.5px solid rgba(240,209,138,.25);border-radius:10px;'
  + 'padding:11px 14px;color:#f0e8d6;font-size:.95rem;font-family:inherit;outline:none;box-sizing:border-box">'
  + '<ul id="ob-address-suggestions" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:200;'
  + 'background:#1a1206;border:1px solid rgba(240,209,138,.25);border-radius:0 0 10px 10px;'
  + 'list-style:none;margin:0;padding:0;max-height:220px;overflow-y:auto"></ul>'
  + '</div>'
  + '<p id="ob-address-selected" style="display:none;font-size:.82rem;color:#4ade80;margin:8px 0 0;font-weight:600"></p>'
  + '<input type="hidden" id="ob-street">'
  + '<input type="hidden" id="ob-suburb">'
  + '<input type="hidden" id="ob-state">'
  + '<input type="hidden" id="ob-postcode">';

Then, immediately after setting body.innerHTML (still inside the address key block),
wire up the GNAF autocomplete using the Australian Address Search API (no key required):

setTimeout(function() {
  var searchInput = document.getElementById('ob-address-search');
  var suggList    = document.getElementById('ob-address-suggestions');
  var selectedLbl = document.getElementById('ob-address-selected');
  var nextBtn     = document.getElementById('ob-next');
  var _debounce;
  var _selected   = false;

  if (nextBtn) { nextBtn.style.opacity = '.45'; nextBtn.style.pointerEvents = 'none'; }

  searchInput.addEventListener('input', function() {
    _selected = false;
    if (nextBtn) { nextBtn.style.opacity = '.45'; nextBtn.style.pointerEvents = 'none'; }
    selectedLbl.style.display = 'none';
    clearTimeout(_debounce);
    var q = (searchInput.value || '').trim();
    if (q.length < 4) { suggList.style.display = 'none'; suggList.innerHTML = ''; return; }
    _debounce = setTimeout(function() {
      fetch('https://api.addressr.io/addresses?q=' + encodeURIComponent(q) + '&max=6')
        .then(function(r) { return r.json(); })
        .then(function(results) {
          suggList.innerHTML = '';
          if (!results || !results.length) { suggList.style.display = 'none'; return; }
          results.forEach(function(item) {
            var li = document.createElement('li');
            li.textContent = item.sla || item.formattedAddress || '';
            li.style.cssText = 'padding:10px 14px;cursor:pointer;font-size:.88rem;color:#f0e8d6;border-bottom:1px solid rgba(255,255,255,.06)';
            li.addEventListener('mouseenter', function() { li.style.background = 'rgba(240,209,138,.08)'; });
            li.addEventListener('mouseleave', function() { li.style.background = ''; });
            li.addEventListener('click', function() {
              // Parse structured address components
              var addr = item.structured || {};
              var street  = ((addr.number || '') + ' ' + (addr.street || '')).trim() || (item.sla || '').split(',')[0] || '';
              var suburb  = addr.locality || '';
              var state   = addr.state    || '';
              var postcode= addr.postcode || '';
              // Fallback: parse from sla string if structured not present
              if (!suburb && item.sla) {
                var parts = item.sla.split(',');
                if (parts.length >= 2) suburb = parts[1].trim().split(' ')[0];
                if (parts.length >= 2) {
                  var last = parts[parts.length - 1].trim().split(' ');
                  if (last.length >= 2) { state = last[last.length - 2]; postcode = last[last.length - 1]; }
                }
              }
              document.getElementById('ob-street').value   = street;
              document.getElementById('ob-suburb').value   = suburb;
              document.getElementById('ob-state').value    = state;
              document.getElementById('ob-postcode').value = postcode;
              searchInput.value = item.sla || item.formattedAddress || '';
              suggList.style.display = 'none';
              _selected = true;
              selectedLbl.textContent = 'Address confirmed.';
              selectedLbl.style.display = 'block';
              if (nextBtn) { nextBtn.style.opacity = '1'; nextBtn.style.pointerEvents = ''; }
            });
            suggList.appendChild(li);
          });
          suggList.style.display = 'block';
        })
        .catch(function() { suggList.style.display = 'none'; });
    }, 300);
  });

  // Close suggestions on outside click
  document.addEventListener('click', function onOutside(e) {
    if (!searchInput.contains(e.target) && !suggList.contains(e.target)) {
      suggList.style.display = 'none';
    }
  });
}, 0);

NOTE: The hidden fields ob-street, ob-suburb, ob-state, ob-postcode are kept so the
existing obNextStep address handler continues to read them without any changes.
The ob-next button stays disabled until an address is confirmed from the list.

---

## CHANGE 2 — DOB step: grade-6 copy explaining legal need

In the obRenderStep function, find the block: } else if (key === 'dob') {
Replace the <p> copy inside body.innerHTML with:

'<p style="font-size:.88rem;color:rgba(220,196,148,.85);line-height:1.65;margin:0 0 8px">'
+ 'This business partnership requires every member to be a real person.'
+ ' Your date of birth is one of the checks we use to confirm that.</p>'
+ '<p style="font-size:.82rem;color:rgba(220,196,148,.6);line-height:1.55;margin:0 0 18px">'
+ 'It is stored securely, never shown to other members, and only used for identity purposes inside the Foundation.</p>'

Keep the date input unchanged.

---

## CHANGE 3 — governance-banner: replace multi-column form with single-line GNAF + DOB

Find the div id="governance-banner".
Inside it, find the div id="gov-form-container".
Replace the entire contents of gov-form-container (the grid with gov-street, gov-suburb,
gov-state, gov-dob, gov-submit-btn) with:

<p style="font-size:.82rem;color:rgba(220,196,148,.7);line-height:1.6;margin:0 0 6px">
Start typing your address. Select from the list to confirm it.
</p>
<div style="position:relative;margin-bottom:12px">
  <input type="text" id="gov-address-search" placeholder="Start typing your address..."
    autocomplete="off"
    style="width:100%;background:#0f172a;border:1px solid #334155;border-radius:6px;
    padding:8px 10px;color:#f0ede8;font-size:0.9em;box-sizing:border-box;">
  <ul id="gov-address-suggestions"
    style="display:none;position:absolute;top:100%;left:0;right:0;z-index:200;
    background:#0f172a;border:1px solid #334155;border-radius:0 0 6px 6px;
    list-style:none;margin:0;padding:0;max-height:200px;overflow-y:auto"></ul>
</div>
<p id="gov-address-confirmed" style="display:none;font-size:.82rem;color:#4ade80;margin:0 0 12px;font-weight:600">Address confirmed.</p>
<input type="hidden" id="gov-street">
<input type="hidden" id="gov-suburb">
<input type="hidden" id="gov-state">
<input type="hidden" id="gov-postcode">
<div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:flex-end;margin-bottom:12px">
  <div>
    <label style="font-size:0.78em;color:#94a3b8;display:block;margin-bottom:4px;">Date of birth</label>
    <input id="gov-dob" type="date"
      style="width:100%;background:#0f172a;border:1px solid #334155;border-radius:6px;
      padding:8px 10px;color:#f0ede8;font-size:0.9em;box-sizing:border-box;">
  </div>
  <button id="gov-submit-btn" onclick="submitGovernanceRecord()"
    style="background:var(--gold-1,#8b6914);color:#fff;border:none;border-radius:6px;
    padding:9px 16px;font-weight:700;font-size:0.85em;cursor:pointer;white-space:nowrap">
    Save
  </button>
</div>
<p id="gov-error" style="display:none;color:#f87171;font-size:0.82em;margin:0;"></p>

Then find the existing submitGovernanceRecord() function.
It reads gov-street, gov-suburb, gov-state, gov-dob — these hidden fields are preserved so
the function continues to work unchanged.

Add a script block (or inline script) to wire up the gov-address-search GNAF autocomplete
using the same pattern as CHANGE 1 above, reading into gov-street, gov-suburb, gov-state, gov-postcode.
This can be added inside the existing block 3 JS or as an init call from submitGovernanceRecord's
surrounding code. Use the same addressr.io API endpoint.

---

## VERIFICATION CHECKLIST
1. Div balance: unchanged count
2. Script brace balance: all blocks OK
3. </body> x 1, </html> x 1
4. No em-dashes in user-visible text
5. No escaped operators
6. ob-street, ob-suburb, ob-state, ob-postcode hidden inputs present in address step HTML
7. gov-street, gov-suburb, gov-state, gov-postcode hidden inputs present in governance-banner
8. ob-address-search input present
9. gov-address-search input present
10. gov-dob input still present
11. ob-next button disabled until address selected

## COMMIT
git add wallets/member.html
git commit -m "feat(vault): GNAF address autocomplete + grade-6 identity copy in onboarding modal and governance banner"
git checkout -b review/session-17
git push origin review/session-17
