'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Admin Module — user management + global settings
// ══════════════════════════════════════════════════════════════════════════════

function setupAdminPanel() {
  document.getElementById('admin-nav-btn')?.addEventListener('click', openAdminPanel);

  document.getElementById('admin-panel-close')?.addEventListener('click', closeAdminPanel);
  document.getElementById('admin-panel-overlay')?.addEventListener('click', e => {
    if (e.target.id === 'admin-panel-overlay') closeAdminPanel();
  });

  // Create user form
  document.getElementById('admin-create-user-btn')?.addEventListener('click', async () => {
    const username = document.getElementById('admin-new-username').value.trim();
    const password = document.getElementById('admin-new-password').value;
    const role = document.getElementById('admin-new-role').value;
    const errEl = document.getElementById('admin-create-error');
    errEl.textContent = '';

    if (!username || !password) { errEl.textContent = 'Username and password required'; return; }

    try {
      const res = await apiFetch(`${API}/admin/users`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username, password, role }),
      });
      const data = await res.json();
      if (!res.ok) { errEl.textContent = data.error; return; }
      document.getElementById('admin-new-username').value = '';
      document.getElementById('admin-new-password').value = '';
      loadUserList();
    } catch (err) {
      errEl.textContent = err.message;
    }
  });

  // Registration toggle
  document.getElementById('admin-reg-toggle')?.addEventListener('change', async (e) => {
    await apiFetch(`${API}/admin/settings`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ registration_open: e.target.checked ? '1' : '0' }),
    });
  });
}

function openAdminPanel() {
  const overlay = document.getElementById('admin-panel-overlay');
  if (!overlay) return;
  overlay.classList.add('open');
  loadUserList();
  loadAdminSettings();
}

function closeAdminPanel() {
  document.getElementById('admin-panel-overlay')?.classList.remove('open');
}

async function loadAdminSettings() {
  try {
    const res = await apiFetch(`${API}/admin/settings`);
    const data = await res.json();
    const toggle = document.getElementById('admin-reg-toggle');
    if (toggle) toggle.checked = data.registration_open === '1';
  } catch {}
}

async function loadUserList() {
  try {
    const res = await apiFetch(`${API}/admin/users`);
    const data = await res.json();
    const tbody = document.getElementById('admin-user-tbody');
    if (!tbody) return;

    tbody.innerHTML = data.users.map(u => `
      <tr>
        <td>${escapeHtml(u.username)}</td>
        <td>${escapeHtml(u.display_name || '—')}</td>
        <td><span class="badge badge-${u.role}">${u.role}</span></td>
        <td>${u.is_active ? 'Active' : '<span class="badge badge-inactive">Inactive</span>'}</td>
        <td>
          ${u.id !== AUTH_USER?.id ? `
            <button class="btn" style="font-size:0.7rem;padding:2px 6px;" data-toggle-user="${u.id}" data-active="${u.is_active}">
              ${u.is_active ? 'Deactivate' : 'Activate'}
            </button>
            <button class="btn" style="font-size:0.7rem;padding:2px 6px;" data-toggle-role="${u.id}" data-role="${u.role}">
              ${u.role === 'admin' ? 'Demote' : 'Promote'}
            </button>
          ` : '<span style="font-size:0.75rem;color:var(--text-secondary);">You</span>'}
        </td>
      </tr>
    `).join('');

    // Toggle active
    tbody.querySelectorAll('[data-toggle-user]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const isActive = btn.dataset.active === '1';
        await apiFetch(`${API}/admin/users/${btn.dataset.toggleUser}`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ is_active: isActive ? 0 : 1 }),
        });
        loadUserList();
      });
    });

    // Toggle role
    tbody.querySelectorAll('[data-toggle-role]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const newRole = btn.dataset.role === 'admin' ? 'user' : 'admin';
        await apiFetch(`${API}/admin/users/${btn.dataset.toggleRole}`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ role: newRole }),
        });
        loadUserList();
      });
    });
  } catch {}
}
