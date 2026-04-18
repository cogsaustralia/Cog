const { createApp, ref, reactive, computed, onMounted } = Vue;
const apiBase = window.COGS_ADMIN_BOOT.apiBase;

async function api(route, options = {}) {
  const response = await fetch(apiBase + route, {
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    ...options,
  });
  const data = await response.json();
  if (!response.ok || data.ok === false) {
    throw new Error(data.error || 'Request failed');
  }
  return data;
}

createApp({
  setup() {
    const state = reactive({
      admin: null,
      route: 'dashboard',
      classes: [],
      members: [],
      payments: [],
      selectedMember: null,
      selectedSummary: null,
      loading: false,
      error: '',
      notice: '',
      login: { email: 'admin@cogsaustralia.org', password: '' },
      createClass: {
        class_code: '', display_name: '', member_type: 'both', unit_price_cents: 400,
        min_units: 0, max_units: 999999, step_units: 1, display_order: 100,
        is_active: true, is_locked: false, approval_required: true, payment_required: false,
        wallet_visible_by_default: true, wallet_editable_by_default: true,
      },
      paymentForm: { member_id: '', payment_type: 'manual', amount_cents: 0, currency_code: 'AUD', payment_status: 'paid', external_reference: '', notes: '' },
      allocationForm: { payment_id: '', allocations_text: '' }
    });

    const isAuthed = computed(() => !!state.admin);
    const dashboardStats = computed(() => ({
      classes: state.classes.length,
      activeClasses: state.classes.filter(x => Number(x.is_active) === 1).length,
      members: state.members.length,
      payments: state.payments.length,
    }));

    async function refreshSession() {
      const data = await api('auth/session');
      state.admin = data.admin;
    }

    async function login() {
      state.error = '';
      const data = await api('auth/login', { method: 'POST', body: JSON.stringify(state.login) });
      state.admin = data.admin;
      state.route = 'dashboard';
      await refreshAll();
    }

    async function logout() {
      await api('auth/logout', { method: 'POST', body: '{}' });
      state.admin = null;
      state.selectedMember = null;
      state.selectedSummary = null;
    }

    async function refreshClasses() {
      const data = await api('admin/token-classes');
      state.classes = data.items;
    }

    async function refreshMembers() {
      const data = await api('admin/members');
      state.members = data.items;
    }

    async function refreshPayments() {
      const data = await api('admin/payments');
      state.payments = data.items;
    }

    async function refreshAll() {
      if (!state.admin) return;
      state.loading = true;
      try {
        await Promise.all([refreshClasses(), refreshMembers(), refreshPayments()]);
      } finally {
        state.loading = false;
      }
    }

    async function createClass() {
      const data = await api('admin/token-classes/create', { method: 'POST', body: JSON.stringify(state.createClass) });
      state.notice = `Created class ${data.item.class_code}`;
      state.createClass.class_code = '';
      state.createClass.display_name = '';
      await refreshClasses();
    }

    async function updateClass(item) {
      await api('admin/token-classes/update', { method: 'POST', body: JSON.stringify(item) });
      state.notice = `Updated ${item.class_code}`;
      await refreshClasses();
    }

    async function toggleClass(item, active) {
      await api(`admin/token-classes/${active ? 'activate' : 'deactivate'}`, { method: 'POST', body: JSON.stringify({ id: item.id }) });
      state.notice = `${active ? 'Activated' : 'Deactivated'} ${item.class_code}`;
      await refreshClasses();
    }

    async function backfillClass(item) {
      await api('admin/token-classes/backfill', { method: 'POST', body: JSON.stringify({ token_class_id: item.id, member_type: item.member_type, only_active_members: true }) });
      state.notice = `Backfilled ${item.class_code}`;
    }

    async function moveClass(item, dir) {
      const idx = state.classes.findIndex(x => x.id === item.id);
      const swapIdx = idx + dir;
      if (swapIdx < 0 || swapIdx >= state.classes.length) return;
      const clone = state.classes.map(x => ({ ...x }));
      [clone[idx], clone[swapIdx]] = [clone[swapIdx], clone[idx]];
      const items = clone.map((x, i) => ({ id: x.id, display_order: (i + 1) * 10 }));
      await api('admin/token-classes/reorder', { method: 'POST', body: JSON.stringify({ items }) });
      await refreshClasses();
      state.notice = 'Class display order updated';
    }

    async function viewMember(memberId) {
      const data = await api(`admin/member&member_id=${memberId}`);
      state.selectedMember = data.item;
      state.selectedSummary = data.summary;
      state.route = 'members';
    }

    async function approveLine(line, approvedUnits = null) {
      const units = approvedUnits == null ? Number(line.requested_units) : Number(approvedUnits);
      await api('admin/member/approve-line', { method: 'POST', body: JSON.stringify({ line_id: line.id, approved_units: units, reason: 'Approved by admin UI' }) });
      await viewMember(line.member_id);
      state.notice = `Approved ${line.class_code}`;
    }

    async function rejectLine(line) {
      await api('admin/member/reject-line', { method: 'POST', body: JSON.stringify({ line_id: line.id, reason: 'Rejected by admin UI' }) });
      await viewMember(line.member_id);
      state.notice = `Rejected ${line.class_code}`;
    }

    async function confirmSignup(memberId) {
      await api('admin/member/confirm-signup-payment', { method: 'POST', body: JSON.stringify({ member_id: memberId }) });
      await viewMember(memberId);
      state.notice = 'Signup payment confirmed';
    }

    async function createPayment() {
      await api('admin/payments/create', { method: 'POST', body: JSON.stringify(state.paymentForm) });
      await refreshPayments();
      state.notice = 'Payment created';
    }

    async function allocatePayment() {
      const allocations = state.allocationForm.allocations_text.split('\n').map(line => line.trim()).filter(Boolean).map(line => {
        const [token_class_id, units_allocated, amount_cents] = line.split(',').map(x => x.trim());
        return { token_class_id: Number(token_class_id), units_allocated: Number(units_allocated), amount_cents: Number(amount_cents) };
      });
      await api('admin/payments/allocate', { method: 'POST', body: JSON.stringify({ payment_id: Number(state.allocationForm.payment_id), allocations }) });
      state.notice = 'Payment allocated';
    }

    function routeTo(name) {
      state.route = name;
      state.error = '';
      state.notice = '';
      if (name === 'members' && !state.selectedMember) {
        state.selectedSummary = null;
      }
    }

    onMounted(async () => {
      try {
        await refreshSession();
        if (state.admin) {
          await refreshAll();
        }
      } catch (err) {
        state.error = err.message;
      }
    });

    return { state, isAuthed, dashboardStats, login, logout, createClass, updateClass, toggleClass, backfillClass, moveClass, viewMember, approveLine, rejectLine, confirmSignup, createPayment, allocatePayment, routeTo };
  },
  template: `
    <div v-if="!isAuthed" class="auth-shell">
      <section class="auth-side">
        <div class="stack auth-copy">
          <div class="brand-mark"><span class="brand-coin"></span><span>COG$ ADMIN CONTROL</span></div>
          <div>
            <div class="eyebrow">Test environment</div>
            <h1>Steward the class system before the wallets go live.</h1>
            <p>Use this admin console to create and govern COG$ classes, approve signup holdings, confirm payments, and validate the database model before the public wallet UI is connected.</p>
          </div>
          <div class="auth-points">
            <div class="auth-point"><span class="point-dot"></span><div><strong>Class governance</strong><div class="muted">Create, activate, deactivate, reorder, and backfill token classes without schema changes.</div></div></div>
            <div class="auth-point"><span class="point-dot"></span><div><strong>Approval workflow</strong><div class="muted">Keep Membership Fund at $0 until admin approval, while members still receive wallet access.</div></div></div>
            <div class="auth-point"><span class="point-dot"></span><div><strong>Payment control</strong><div class="muted">Record and allocate signup payments for Personal S-NFT, Kids S-NFT, and future class requests.</div></div></div>
          </div>
        </div>
        <div class="auth-foot">COG$ of Australia Foundation · Admin test portal</div>
      </section>

      <section class="auth-panel-wrap">
        <div class="auth-panel stack">
          <div>
            <div class="eyebrow">Secure sign in</div>
            <h2>Admin Login</h2>
            <div class="muted">Sign in with your admin email and password hash-backed account. This page is isolated from the public member login flow.</div>
          </div>
          <div class="stack">
            <div>
              <label>Email address</label>
              <input v-model="state.login.email" type="email" autocomplete="username" placeholder="admin@cogsaustralia.org">
            </div>
            <div>
              <label>Password</label>
              <input v-model="state.login.password" type="password" autocomplete="current-password" placeholder="Enter admin password">
            </div>
            <div class="auth-meta">
              <span>Default seeded email</span>
              <strong>admin@cogsaustralia.org</strong>
            </div>
            <button @click="login">Enter Admin Console</button>
            <div v-if="state.error" class="notice error-box">{{ state.error }}</div>
          </div>
        </div>
      </section>
    </div>

    <div v-else class="page stack">
      <div class="topbar">
        <div>
          <h1 style="margin:0">COG$ Admin TEST</h1>
          <div class="muted">Manage classes, members, approvals, and payments before wallet UI integration.</div>
        </div>
        <div class="stack" style="justify-items:end">
          <div class="tag">{{ state.admin.email }}</div>
          <button class="secondary" @click="logout">Logout</button>
        </div>
      </div>

      <div class="nav">
        <a href="#" :class="{active: state.route==='dashboard'}" @click.prevent="routeTo('dashboard')">Dashboard</a>
        <a href="#" :class="{active: state.route==='classes'}" @click.prevent="routeTo('classes')">Classes</a>
        <a href="#" :class="{active: state.route==='members'}" @click.prevent="routeTo('members')">Members</a>
        <a href="#" :class="{active: state.route==='payments'}" @click.prevent="routeTo('payments')">Payments</a>
      </div>

      <div v-if="state.notice" class="notice">{{ state.notice }}</div>
      <div v-if="state.error" class="notice">{{ state.error }}</div>

      <div v-if="state.route==='dashboard'" class="stack">
        <div class="grid cols-4">
          <div class="card"><div class="muted">Classes</div><div class="kpi">{{ dashboardStats.classes }}</div></div>
          <div class="card"><div class="muted">Active classes</div><div class="kpi">{{ dashboardStats.activeClasses }}</div></div>
          <div class="card"><div class="muted">Members</div><div class="kpi">{{ dashboardStats.members }}</div></div>
          <div class="card"><div class="muted">Payments</div><div class="kpi">{{ dashboardStats.payments }}</div></div>
        </div>
        <div class="card stack">
          <h2 style="margin:0">What to test first</h2>
          <div class="muted">1. Confirm seeded classes. 2. Create a new class. 3. Backfill it to members. 4. Create a manual payment. 5. Approve core signup classes. 6. Confirm membership fund logic from approved units only.</div>
        </div>
      </div>

      <div v-if="state.route==='classes'" class="grid cols-2">
        <div class="card stack">
          <h2 style="margin:0">Create class</h2>
          <div class="form-grid">
            <div><label>Class code</label><input v-model="state.createClass.class_code"></div>
            <div><label>Display name</label><input v-model="state.createClass.display_name"></div>
            <div><label>Member type</label><select v-model="state.createClass.member_type"><option>both</option><option>personal</option><option>business</option></select></div>
            <div><label>Unit price (cents)</label><input v-model.number="state.createClass.unit_price_cents" type="number"></div>
            <div><label>Min units</label><input v-model.number="state.createClass.min_units" type="number"></div>
            <div><label>Max units</label><input v-model.number="state.createClass.max_units" type="number"></div>
            <div><label>Step units</label><input v-model.number="state.createClass.step_units" type="number"></div>
            <div><label>Display order</label><input v-model.number="state.createClass.display_order" type="number"></div>
            <div><label><input type="checkbox" v-model="state.createClass.approval_required"> Approval required</label></div>
            <div><label><input type="checkbox" v-model="state.createClass.payment_required"> Payment required</label></div>
            <div><label><input type="checkbox" v-model="state.createClass.wallet_visible_by_default"> Wallet visible</label></div>
            <div><label><input type="checkbox" v-model="state.createClass.wallet_editable_by_default"> Wallet editable</label></div>
          </div>
          <button @click="createClass">Create class</button>
        </div>

        <div class="card stack">
          <h2 style="margin:0">Existing classes</h2>
          <table class="table">
            <thead>
              <tr><th>Code</th><th>Type</th><th>Price</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
              <tr v-for="item in state.classes" :key="item.id">
                <td>
                  <strong>{{ item.class_code }}</strong><br>
                  <span class="muted small">{{ item.display_name }}</span>
                </td>
                <td>{{ item.member_type }}</td>
                <td>{{ '$' + ((item.unit_price_cents / 100).toFixed(2)) }}</td>
                <td>{{ Number(item.is_active) ? 'active' : 'inactive' }}</td>
                <td class="stack" style="grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;">
                  <button class="secondary" @click="moveClass(item, -1)">Up</button>
                  <button class="secondary" @click="moveClass(item, 1)">Down</button>
                  <button class="secondary" @click="backfillClass(item)">Backfill</button>
                  <button :class="Number(item.is_active) ? 'warn' : ''" @click="toggleClass(item, !Number(item.is_active))">{{ Number(item.is_active) ? 'Deactivate' : 'Activate' }}</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="state.route==='members'" class="grid cols-2">
        <div class="card stack">
          <h2 style="margin:0">Members</h2>
          <table class="table">
            <thead><tr><th>Member</th><th>Type</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="item in state.members" :key="item.id">
                <td><strong>{{ item.full_name }}</strong><br><span class="muted small">{{ item.email }}</span></td>
                <td>{{ item.member_type }}</td>
                <td>{{ Number(item.is_active) ? 'active' : 'inactive' }}</td>
                <td><button class="secondary" @click="viewMember(item.id)">View</button></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="card stack" v-if="state.selectedMember">
          <div class="topbar">
            <div>
              <h2 style="margin:0">{{ state.selectedMember.full_name }}</h2>
              <div class="muted">{{ state.selectedMember.member_number }} · {{ state.selectedMember.email }}</div>
            </div>
            <button @click="confirmSignup(state.selectedMember.id)">Confirm signup payment</button>
          </div>
          <div class="grid cols-3" v-if="state.selectedSummary">
            <div><div class="muted">Membership Fund</div><div class="kpi">{{ '$' + (((state.selectedSummary.membership_fund_cents || 0) / 100).toFixed(2)) }}</div></div>
            <div><div class="muted">Approved units</div><div class="kpi">{{ state.selectedSummary.approved_total_units || 0 }}</div></div>
            <div><div class="muted">Requested units</div><div class="kpi">{{ state.selectedSummary.requested_total_units || 0 }}</div></div>
          </div>
          <table class="table">
            <thead><tr><th>Class</th><th>Requested</th><th>Approved</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <tr v-for="line in state.selectedMember.reservation_lines" :key="line.id">
                <td><strong>{{ line.class_code }}</strong><br><span class="muted small">{{ line.display_name }}</span></td>
                <td>{{ line.requested_units }}</td>
                <td>{{ line.approved_units }}</td>
                <td>{{ line.approval_status }}</td>
                <td class="stack" style="grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;">
                  <button class="secondary" @click="approveLine(line)">Approve full</button>
                  <button class="bad" @click="rejectLine(line)">Reject</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div v-if="state.route==='payments'" class="grid cols-2">
        <div class="card stack">
          <h2 style="margin:0">Create payment</h2>
          <div class="form-grid">
            <div><label>Member ID</label><input v-model.number="state.paymentForm.member_id" type="number"></div>
            <div><label>Type</label><select v-model="state.paymentForm.payment_type"><option>manual</option><option>signup</option><option>adjustment</option></select></div>
            <div><label>Amount cents</label><input v-model.number="state.paymentForm.amount_cents" type="number"></div>
            <div><label>Status</label><select v-model="state.paymentForm.payment_status"><option>paid</option><option>pending</option><option>failed</option></select></div>
            <div><label>Reference</label><input v-model="state.paymentForm.external_reference"></div>
            <div class="full"><label>Notes</label><textarea v-model="state.paymentForm.notes" rows="3"></textarea></div>
          </div>
          <button @click="createPayment">Create payment</button>
        </div>
        <div class="card stack">
          <h2 style="margin:0">Allocate payment</h2>
          <div><label>Payment ID</label><input v-model.number="state.allocationForm.payment_id" type="number"></div>
          <div><label>Allocations</label><textarea v-model="state.allocationForm.allocations_text" rows="7" placeholder="token_class_id, units_allocated, amount_cents\n1, 1, 400\n2, 3, 300"></textarea></div>
          <button @click="allocatePayment">Allocate payment</button>
          <div class="muted small">Use one line per allocation: token_class_id, units_allocated, amount_cents</div>
        </div>
        <div class="card stack" style="grid-column:1 / -1;">
          <h2 style="margin:0">Recent payments</h2>
          <table class="table">
            <thead><tr><th>ID</th><th>Member</th><th>Type</th><th>Amount</th><th>Status</th><th>Reference</th></tr></thead>
            <tbody>
              <tr v-for="payment in state.payments" :key="payment.id">
                <td>{{ payment.id }}</td>
                <td>{{ payment.member_id }}</td>
                <td>{{ payment.payment_type }}</td>
                <td>{{ '$' + ((payment.amount_cents / 100).toFixed(2)) }}</td>
                <td>{{ payment.payment_status }}</td>
                <td>{{ payment.external_reference }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  `
}).mount('#app');
