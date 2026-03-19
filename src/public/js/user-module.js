'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// User Module — profile settings + API key management
// ══════════════════════════════════════════════════════════════════════════════

function setupUserPanel() {
  document.getElementById('user-nav-btn')?.addEventListener('click', openUserPanel);

  document.getElementById('user-panel-close')?.addEventListener('click', closeUserPanel);
  document.getElementById('user-panel-overlay')?.addEventListener('click', e => {
    if (e.target.id === 'user-panel-overlay') closeUserPanel();
  });

  // Profile form
  document.getElementById('profile-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const errEl = document.getElementById('profile-error');
    errEl.textContent = '';

    const body = {};
    const dn = document.getElementById('profile-display-name').value.trim();
    body.display_name = dn || null;

    const curPw = document.getElementById('profile-cur-pw').value;
    const newPw = document.getElementById('profile-new-pw').value;
    if (newPw) {
      if (!curPw) { errEl.textContent = 'Current password required'; return; }
      body.current_password = curPw;
      body.new_password = newPw;
    }

    try {
      const res = await apiFetch(`${API}/user/profile`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok) { errEl.textContent = data.error; return; }
      AUTH_USER = data;
      updateUserUI();
      document.getElementById('profile-cur-pw').value = '';
      document.getElementById('profile-new-pw').value = '';
      errEl.style.color = 'var(--accent)';
      errEl.textContent = 'Profile updated';
      setTimeout(() => { errEl.textContent = ''; errEl.style.color = ''; }, 2000);
    } catch (err) {
      errEl.textContent = err.message;
    }
  });

  // API key creation
  document.getElementById('apikey-create-btn')?.addEventListener('click', async () => {
    const nameInput = document.getElementById('apikey-name');
    const name = nameInput.value.trim() || 'Default';
    try {
      const res = await apiFetch(`${API}/user/api-keys`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name }),
      });
      const data = await res.json();
      if (!res.ok) return;
      nameInput.value = '';
      // Show the key once
      const reveal = document.getElementById('apikey-reveal');
      reveal.style.display = '';
      reveal.querySelector('.api-key-reveal').textContent = data.key;
      loadApiKeys();
    } catch {}
  });

  document.getElementById('apikey-reveal-close')?.addEventListener('click', () => {
    document.getElementById('apikey-reveal').style.display = 'none';
  });
}

function openUserPanel() {
  const overlay = document.getElementById('user-panel-overlay');
  if (!overlay) return;
  overlay.classList.add('open');

  // Fill profile fields
  if (AUTH_USER) {
    document.getElementById('profile-display-name').value = AUTH_USER.display_name || '';
    document.getElementById('profile-username').textContent = AUTH_USER.username;
    document.getElementById('profile-role').textContent = AUTH_USER.role;
  }
  document.getElementById('profile-error').textContent = '';
  document.getElementById('apikey-reveal').style.display = 'none';
  loadApiKeys();
  loadMyShares();
}

function closeUserPanel() {
  document.getElementById('user-panel-overlay')?.classList.remove('open');
}

async function loadApiKeys() {
  try {
    const res = await apiFetch(`${API}/user/api-keys`);
    const data = await res.json();
    const list = document.getElementById('apikey-list');
    if (!list) return;

    if (!data.keys?.length) {
      list.innerHTML = '<div style="font-size:0.85rem;color:var(--text-secondary);">No API keys yet</div>';
      return;
    }

    list.innerHTML = data.keys.map(k => `
      <div class="api-key-item">
        <div>
          <div class="key-name">${escapeHtml(k.name)}${k.is_revoked ? ' <span class="badge badge-inactive">revoked</span>' : ''}</div>
          <div class="key-meta">Created ${k.created_at || '—'}${k.last_used_at ? ' · Last used ' + k.last_used_at : ''}</div>
        </div>
        ${!k.is_revoked ? `<button class="btn" style="font-size:0.72rem;padding:3px 8px;color:var(--danger);border-color:var(--danger);" data-revoke-key="${k.id}">Revoke</button>` : ''}
      </div>
    `).join('');

    list.querySelectorAll('[data-revoke-key]').forEach(btn => {
      btn.addEventListener('click', async () => {
        await apiFetch(`${API}/user/api-keys/${btn.dataset.revokeKey}`, { method: 'DELETE' });
        loadApiKeys();
      });
    });
  } catch {}
}

function escapeHtml(str) {
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}
