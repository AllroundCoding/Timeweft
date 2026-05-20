'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Auth Module — login, register, session management
// ══════════════════════════════════════════════════════════════════════════════

function showAuthOverlay(mode) {
  const overlay = document.getElementById('auth-overlay');
  if (!overlay) return;
  overlay.classList.add('open');
  document.getElementById('auth-error').textContent = '';
  // mode: 'login' or 'register'
  if (mode) setAuthMode(mode);
}

function hideAuthOverlay() {
  document.getElementById('auth-overlay')?.classList.remove('open');
}

function setAuthMode(mode) {
  const title = document.getElementById('auth-title');
  const submitBtn = document.getElementById('auth-submit');
  const toggleBtn = document.getElementById('auth-toggle');
  const displayNameRow = document.getElementById('auth-display-name-row');

  if (mode === 'register') {
    title.textContent = 'Create Account';
    submitBtn.textContent = 'Create Account';
    toggleBtn.innerHTML = 'Already have an account? <a href="#">Login</a>';
    displayNameRow.style.display = '';
    document.getElementById('auth-form').dataset.mode = 'register';
  } else {
    title.textContent = 'Login';
    submitBtn.textContent = 'Login';
    toggleBtn.innerHTML = 'Need an account? <a href="#">Register</a>';
    displayNameRow.style.display = 'none';
    document.getElementById('auth-form').dataset.mode = 'login';
  }
}

async function initAuth() {
  // Check app status
  let status;
  try {
    status = await fetch(`${API}/auth/status`).then(r => r.json());
  } catch {
    // Server unreachable
    throw new Error('Could not connect to server');
  }

  // If not initialized, force register mode (first user = admin)
  if (!status.initialized) {
    showAuthOverlay('register');
    document.getElementById('auth-toggle').style.display = 'none';
    document.getElementById('auth-title').textContent = 'Create Admin Account';
    document.getElementById('auth-subtitle').textContent = 'Set up the first admin account to get started.';
    document.getElementById('auth-subtitle').style.display = '';
    return false;
  }

  // If we have a token, validate it
  const token = getAuthToken();
  if (token) {
    try {
      const res = await fetch(`${API}/auth/me`, {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      if (res.ok) {
        AUTH_USER = await res.json();
        updateUserUI();
        return true; // authenticated
      }
    } catch {}
    // Token invalid
    setAuthToken(null);
  }

  // Show login (or register if open)
  const toggleEl = document.getElementById('auth-toggle');
  if (status.registrationOpen) {
    toggleEl.style.display = '';
  } else {
    toggleEl.style.display = 'none';
  }
  showAuthOverlay('login');
  return false;
}

function setupAuthListeners() {
  const form = document.getElementById('auth-form');
  const toggleBtn = document.getElementById('auth-toggle');

  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const mode = form.dataset.mode;
    const username = document.getElementById('auth-username').value.trim();
    const password = document.getElementById('auth-password').value;
    const errorEl = document.getElementById('auth-error');
    errorEl.textContent = '';

    const body = { username, password };
    if (mode === 'register') {
      body.display_name = document.getElementById('auth-display-name').value.trim() || null;
    }

    const endpoint = mode === 'register' ? `${API}/auth/register` : `${API}/auth/login`;

    try {
      const res = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await res.json();
      if (!res.ok) {
        errorEl.textContent = data.error || 'Something went wrong';
        return;
      }

      setAuthToken(data.token);
      AUTH_USER = data.user;
      hideAuthOverlay();
      updateUserUI();
      boot();
    } catch (err) {
      errorEl.textContent = 'Could not connect to server';
    }
  });

  toggleBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    const current = form.dataset.mode;
    setAuthMode(current === 'login' ? 'register' : 'login');
  });

  // Logout button
  document.getElementById('logout-btn')?.addEventListener('click', () => {
    setAuthToken(null);
    AUTH_USER = null;
    window.location.reload();
  });
}

function updateUserUI() {
  const userBtn = document.getElementById('user-nav-btn');
  if (userBtn && AUTH_USER) {
    userBtn.title = `${AUTH_USER.display_name || AUTH_USER.username} (${AUTH_USER.role})`;
  }
  // Show/hide admin button
  const adminBtn = document.getElementById('admin-nav-btn');
  if (adminBtn) {
    adminBtn.style.display = AUTH_USER?.role === 'admin' ? '' : 'none';
  }
  // Pending deletions button visibility is driven by updatePendingBadge()
  // (only shown when count > 0 and on own timeline)
}
