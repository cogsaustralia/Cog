# CCC Session 23: intro/voice-confirmed.html — share text, PTC, what-next
# Branch: review/session-23
# ONE FILE ONLY: intro/voice-confirmed.html
# Audit refs: 35.3 (High), 35.4 (High), 35.5 (High)

## SETUP
git pull --rebase origin main

## GROUND TRUTH
Read intro/voice-confirmed.html before touching anything.

## CHANGES — single Python pass

python3 << 'PYEOF'
with open('intro/voice-confirmed.html', 'r') as f:
    c = f.read()

orig = len(c)

# FIX 1 (audit 35.3 High) — ptcShareFB: strip pre-written share text
# The function currently posts "I joined COG$ of Australia..." as the user's own words.
# Replace: share URL only; let Facebook generate preview from OG tags.
old_fn = """window.ptcShareFB = function(){
    var link = document.getElementById('ptc-link').textContent;
    var text = 'I joined COG$ of Australia \u2014 a $4 community say in our minerals. Join before Foundation Day on 14 May: ' + link + ' Not financial advice.';
    window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(link) + '&quote=' + encodeURIComponent(text), '_blank', 'width="""

new_fn = """window.ptcShareFB = function(){
    var link = document.getElementById('ptc-link').textContent;
    window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(link), '_blank', 'width="""

c = c.replace(old_fn, new_fn)

# FIX 2 (audit 35.4 High) — add "what happens next" 3-step strip after thank-you heading
# Replace the bare "submission is in review" line with a 3-step strip
old_sub = """<p class="sub">Your submission is in review. We'll email you when it's cleared. Most submissions are reviewed within 24 hours. You can manage your submissions and withdraw consent at any time from you"""

new_sub = """<p class="sub">Your submission is in review. Here is what happens next:</p>
<ol style="margin:12px auto 18px;max-width:360px;text-align:left;font-size:.84rem;color:var(--text2);line-height:1.7;padding-left:20px">
  <li>A real person reads your message (usually within a day).</li>
  <li>If anything is unclear, we email and ask before posting.</li>
  <li>You will see a cleared tick in your account when it is ready to share.</li>
</ol>
<p class="sub" style="font-size:.78rem;margin-top:0">You can manage your submissions and withdraw consent any time from you"""

c = c.replace(old_sub, new_sub)

# FIX 3 (audit 35.5 High) — PTC: reduce 4 empty circles to 1, update copy
# Change heading "Now send this to 4 Australians" -> "Pass the link to one person"
c = c.replace(
    '>Now send this to 4 Australians<',
    '>Pass the link to one person who would want a say.<'
)
# Change body copy under PTC
c = c.replace(
    '>Share your unique link. Every person who joins through it becomes the next link in the chain.<',
    '>You can pass it again any time from your account.<'
)
# Remove three of the four empty circles (leave one)
# The four ptc-empty divs are on consecutive lines with connector divs between them.
# Pattern: keep the first [you] + [connector] + [one empty] and remove the rest.
import re
# Remove the last 3 empty circles and their connector lines
# Structure is: ptc-you [conn] ptc-empty [conn] ptc-empty [conn] ptc-empty [conn] ptc-empty
# We want: ptc-you [conn] ptc-empty
old_circles = (
    '<div style="width:14px;height:1px;background:rgba(240,209,138,.18)"></div>\n'
    '<div class="ptc-circle ptc-empty">?</div>\n'
    '<div style="width:14px;height:1px;background:rgba(240,209,138,.18)"></div>\n'
    '<div class="ptc-circle ptc-empty">?</div>\n'
    '<div style="width:14px;height:1px;background:rgba(240,209,138,.18)"></div>\n'
    '<div class="ptc-circle ptc-empty">?</div>'
)
new_circles = ''
if old_circles in c:
    c = c.replace(old_circles, '', 1)

with open('intro/voice-confirmed.html', 'w') as f:
    f.write(c)

print(f"Done. {orig} -> {len(c)} bytes")
PYEOF

## VERIFICATION
python3 << 'PYEOF2'
import re

with open('intro/voice-confirmed.html') as f:
    c = f.read()

em_hits = re.findall(r'>([^<]*\u2014[^<]*)<', c)
visible_em = [h for h in em_hits if h.strip()]

checks = [
    ('pre-written share text GONE', "a $4 community say in our minerals" not in c),
    ('FB share URL only',           "sharer.php?u=" in c and "&quote=" not in c),
    ('what-happens-next strip',     'A real person reads your message' in c),
    ('3-step list',                 '<ol' in c and '<li>' in c),
    ('4-circle copy GONE',          '4 Australians' not in c),
    ('pass one person copy',        'one person who would want a say' in c),
    ('em-dashes in visible text', len(visible_em) == 0),
    ('</style>',  c.count('</style>') == 1),
    ('</head>',   c.count('</head>')  == 1),
    ('<body',     c.count('<body')    >= 1),
    ('</html>',   c.count('</html>') == 1),
]

for label, ok in checks:
    print(f"[{'OK' if ok else 'FAIL'}] {label}")

if visible_em:
    print(f"  em-dash hits: {visible_em[:3]}")
PYEOF2

## COMMIT TO REVIEW BRANCH
git add intro/voice-confirmed.html
git commit -m "fix(voice-confirmed): strip pre-written share text, add what-next strip, reduce PTC to 1 circle (session-23)"
git checkout -b review/session-23
git push origin review/session-23

## STOP -- wait for review before merging to main
