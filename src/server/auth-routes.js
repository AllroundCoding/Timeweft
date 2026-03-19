'use strict';
const express = require('express');
const fs      = require('fs');
const { getAccountsDb, getUserDb, migrateLegacyDb, LEGACY_DB_PATH } = require('../db/connection');
const {
  findUserByUsername, findUserById, createUser, listUsers, updateUser, deleteUser, countUsers,
  createApiKey, listApiKeys, revokeApiKey,
  getGlobalSetting, setGlobalSetting, getAllGlobalSettings,
  createShare, getSharesForOwner, getSharesForGrantee, updateShare, deleteShare,
} = require('../db/auth');
const { hashPassword, verifyPassword, generateJwt, generateApiKey, hashApiKey } = require('./auth');
const { authenticate, requireAdmin } = require('./middleware');

const router = express.Router();

// ── Helpers ──────────────────────────────────────────────────────────────────

function sanitizeUser(user) {
  if (!user) return null;
  const { password_hash, ...safe } = user;
  return safe;
}

// ── Public routes ────────────────────────────────────────────────────────────

// Check if app is initialized (any users exist?)
router.get('/auth/status', (req, res) => {
  try {
    const db = getAccountsDb();
    const count = countUsers(db);
    const registrationOpen = getGlobalSetting(db, 'registration_open') === '1';
    res.json({ initialized: count > 0, registrationOpen });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Register
router.post('/auth/register', (req, res) => {
  try {
    const { username, password, display_name } = req.body;
    if (!username || !password) {
      return res.status(400).json({ error: 'username and password are required' });
    }
    if (username.length < 3) {
      return res.status(400).json({ error: 'Username must be at least 3 characters' });
    }
    if (password.length < 8) {
      return res.status(400).json({ error: 'Password must be at least 8 characters' });
    }

    const db = getAccountsDb();
    const userCount = countUsers(db);

    // If users exist, check if registration is open
    if (userCount > 0) {
      const registrationOpen = getGlobalSetting(db, 'registration_open') === '1';
      if (!registrationOpen) {
        return res.status(403).json({ error: 'Registration is closed. Contact an admin.' });
      }
    }

    // Check if username is taken
    if (findUserByUsername(db, username)) {
      return res.status(409).json({ error: 'Username is already taken' });
    }

    const passwordHash = hashPassword(password);
    const role = userCount === 0 ? 'admin' : 'user';
    const user = createUser(db, { username, passwordHash, displayName: display_name, role });

    // Initialize user's timeline DB
    getUserDb(user.id);

    // If first user, migrate legacy data if present
    if (userCount === 0 && fs.existsSync(LEGACY_DB_PATH)) {
      migrateLegacyDb(user.id);
    }

    const token = generateJwt(user.id, user.role);
    res.status(201).json({ user: sanitizeUser(user), token });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Login
router.post('/auth/login', (req, res) => {
  try {
    const { username, password } = req.body;
    if (!username || !password) {
      return res.status(400).json({ error: 'username and password are required' });
    }

    const db = getAccountsDb();
    const user = findUserByUsername(db, username);
    if (!user || !verifyPassword(password, user.password_hash)) {
      return res.status(401).json({ error: 'Invalid username or password' });
    }
    if (!user.is_active) {
      return res.status(403).json({ error: 'Account is disabled' });
    }

    const token = generateJwt(user.id, user.role);
    res.json({ user: sanitizeUser(user), token });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ── Authenticated routes ─────────────────────────────────────────────────────

// Get current user
router.get('/auth/me', authenticate, (req, res) => {
  try {
    const db = getAccountsDb();
    const user = findUserById(db, req.user.id);
    res.json(sanitizeUser(user));
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Update own profile
router.put('/user/profile', authenticate, (req, res) => {
  try {
    const { display_name, current_password, new_password } = req.body;
    const db = getAccountsDb();

    const fields = {};
    if (display_name !== undefined) fields.displayName = display_name;

    if (new_password) {
      if (!current_password) {
        return res.status(400).json({ error: 'Current password is required to change password' });
      }
      const user = findUserById(db, req.user.id);
      if (!verifyPassword(current_password, user.password_hash)) {
        return res.status(401).json({ error: 'Current password is incorrect' });
      }
      if (new_password.length < 8) {
        return res.status(400).json({ error: 'New password must be at least 8 characters' });
      }
      fields.passwordHash = hashPassword(new_password);
    }

    const user = updateUser(db, req.user.id, fields);
    res.json(sanitizeUser(user));
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ── API Key management ──────────────────────────────────────────────────────

router.get('/user/api-keys', authenticate, (req, res) => {
  try {
    const db = getAccountsDb();
    res.json({ keys: listApiKeys(db, req.user.id) });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.post('/user/api-keys', authenticate, (req, res) => {
  try {
    const { name } = req.body;
    const db = getAccountsDb();
    const { fullKey, hash } = generateApiKey();
    const keyRow = createApiKey(db, { userId: req.user.id, keyHash: hash, name: name || 'Default' });
    // Return the full key ONCE — it won't be retrievable again
    res.status(201).json({ ...keyRow, key: fullKey });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.delete('/user/api-keys/:id', authenticate, (req, res) => {
  try {
    const db = getAccountsDb();
    const result = revokeApiKey(db, req.params.id, req.user.id);
    if (!result) return res.status(404).json({ error: 'API key not found' });
    res.json({ ok: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ── Timeline Sharing ──────────────────────────────────────────────────────

// Timelines shared WITH me
router.get('/user/shares', authenticate, (req, res) => {
  try {
    const db = getAccountsDb();
    res.json({ shares: getSharesForGrantee(db, req.user.id) });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Shares I've granted
router.get('/user/shared-by-me', authenticate, (req, res) => {
  try {
    const db = getAccountsDb();
    res.json({ shares: getSharesForOwner(db, req.user.id) });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Create or update a share
router.post('/user/shares', authenticate, (req, res) => {
  try {
    const { username, preset, perm_timeline, perm_docs, perm_entities, perm_settings } = req.body;
    if (!username) return res.status(400).json({ error: 'username is required' });

    const db = getAccountsDb();
    const grantee = findUserByUsername(db, username);
    if (!grantee) return res.status(404).json({ error: 'User not found' });
    if (grantee.id === req.user.id) return res.status(400).json({ error: 'Cannot share with yourself' });

    const perms = {};
    if (perm_timeline) perms.perm_timeline = perm_timeline;
    if (perm_docs) perms.perm_docs = perm_docs;
    if (perm_entities) perms.perm_entities = perm_entities;
    if (perm_settings) perms.perm_settings = perm_settings;

    const share = createShare(db, {
      ownerId: req.user.id,
      granteeId: grantee.id,
      preset: preset || 'read',
      perms,
    });
    res.status(201).json(share);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Update share permissions
router.put('/user/shares/:granteeId', authenticate, (req, res) => {
  try {
    const db = getAccountsDb();
    const share = updateShare(db, req.user.id, req.params.granteeId, req.body);
    if (!share) return res.status(404).json({ error: 'Share not found' });
    res.json(share);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Revoke share
router.delete('/user/shares/:granteeId', authenticate, (req, res) => {
  try {
    const db = getAccountsDb();
    deleteShare(db, req.user.id, req.params.granteeId);
    res.json({ ok: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ── Admin routes ────────────────────────────────────────────────────────────

router.get('/admin/users', authenticate, requireAdmin, (req, res) => {
  try {
    const db = getAccountsDb();
    res.json({ users: listUsers(db) });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.post('/admin/users', authenticate, requireAdmin, (req, res) => {
  try {
    const { username, password, display_name, role } = req.body;
    if (!username || !password) {
      return res.status(400).json({ error: 'username and password are required' });
    }
    if (username.length < 3) {
      return res.status(400).json({ error: 'Username must be at least 3 characters' });
    }
    if (password.length < 8) {
      return res.status(400).json({ error: 'Password must be at least 8 characters' });
    }

    const db = getAccountsDb();
    if (findUserByUsername(db, username)) {
      return res.status(409).json({ error: 'Username is already taken' });
    }

    const passwordHash = hashPassword(password);
    const user = createUser(db, { username, passwordHash, displayName: display_name, role: role || 'user' });

    // Initialize user's timeline DB
    getUserDb(user.id);

    res.status(201).json(sanitizeUser(user));
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.put('/admin/users/:id', authenticate, requireAdmin, (req, res) => {
  try {
    const { role, is_active, password, display_name } = req.body;
    const db = getAccountsDb();

    const target = findUserById(db, req.params.id);
    if (!target) return res.status(404).json({ error: 'User not found' });

    // Don't allow demoting yourself
    if (req.params.id === req.user.id && role && role !== 'admin') {
      return res.status(400).json({ error: 'Cannot change your own role' });
    }

    const fields = {};
    if (role !== undefined) fields.role = role;
    if (is_active !== undefined) fields.isActive = is_active;
    if (display_name !== undefined) fields.displayName = display_name;
    if (password) fields.passwordHash = hashPassword(password);

    const user = updateUser(db, req.params.id, fields);
    res.json(sanitizeUser(user));
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.delete('/admin/users/:id', authenticate, requireAdmin, (req, res) => {
  try {
    if (req.params.id === req.user.id) {
      return res.status(400).json({ error: 'Cannot delete your own account' });
    }
    const db = getAccountsDb();
    const target = findUserById(db, req.params.id);
    if (!target) return res.status(404).json({ error: 'User not found' });
    deleteUser(db, req.params.id);
    res.json({ ok: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Admin global settings
router.get('/admin/settings', authenticate, requireAdmin, (req, res) => {
  try {
    const db = getAccountsDb();
    const settings = getAllGlobalSettings(db);
    res.json(settings);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.put('/admin/settings', authenticate, requireAdmin, (req, res) => {
  try {
    const db = getAccountsDb();
    const allowed = ['registration_open'];
    for (const key of allowed) {
      if (Object.prototype.hasOwnProperty.call(req.body, key)) {
        setGlobalSetting(db, key, req.body[key]);
      }
    }
    res.json({ ok: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

module.exports = router;
