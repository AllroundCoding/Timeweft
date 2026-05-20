'use strict';
const express  = require('express');
const fs       = require('fs');
const os       = require('os');
const path     = require('path');
const Database = require('better-sqlite3');
const multer   = require('multer');
const { getAccountsDb, getTimelineDb, closeTimelineDb, migrateLegacyDb,
        USERS_DIR, LEGACY_DB_PATH } = require('../db/connection');
const {
  findUserByUsername, findUserById, createUser, listUsers, updateUser, deleteUser, countUsers,
  createApiKey, listApiKeys, revokeApiKey,
  getGlobalSetting, setGlobalSetting, getAllGlobalSettings,
  createTimeline, getTimeline, getTimelinesForUser, updateTimeline, deleteTimeline, getDefaultTimeline,
  createShare, getSharesForOwner, getSharesForGrantee, updateShare, deleteShare,
} = require('../db/auth');
const { hashPassword, verifyPassword, generateJwt, generateApiKey, hashApiKey } = require('./auth');
const { authenticate, requireAdmin } = require('./middleware');

const router = express.Router();
const upload = multer({ dest: os.tmpdir(), limits: { fileSize: 100 * 1024 * 1024 } });

// ── Helpers ──────────────────────────────────────────────────────────────────

function sanitizeUser(user) {
  if (!user) return null;
  const { password_hash, ...safe } = user;
  return safe;
}

/** Verify timeline exists and belongs to user. Returns the timeline or sends a 404 and returns null. */
function requireOwnTimeline(db, timelineId, userId, res) {
  const tl = getTimeline(db, timelineId);
  if (!tl || tl.owner_id !== userId) {
    res.status(404).json({ error: 'Timeline not found' });
    return null;
  }
  return tl;
}

function unlinkSafe(filePath) {
  try { fs.unlinkSync(filePath); } catch (e) { if (e.code !== 'ENOENT') throw e; }
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

    // Create default timeline and initialize DB
    const tl = createTimeline(db, { ownerId: user.id, name: 'Default' });
    getTimelineDb(user.id, tl.id);

    // If first user, migrate legacy data if present
    if (userCount === 0 && fs.existsSync(LEGACY_DB_PATH)) {
      migrateLegacyDb(user.id, tl.id);
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

// ── Timeline Management ─────────────────────────────────────────────────────

router.get('/user/timelines', authenticate, (req, res) => {
  try {
    const db = getAccountsDb();
    res.json({ timelines: getTimelinesForUser(db, req.user.id) });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.post('/user/timelines', authenticate, (req, res) => {
  try {
    const { name, description } = req.body;
    if (!name?.trim()) return res.status(400).json({ error: 'name is required' });
    const db = getAccountsDb();
    const tl = createTimeline(db, { ownerId: req.user.id, name: name.trim(), description });
    getTimelineDb(req.user.id, tl.id); // initialize the DB file
    res.status(201).json(tl);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.put('/user/timelines/:id', authenticate, (req, res) => {
  try {
    const db = getAccountsDb();
    if (!requireOwnTimeline(db, req.params.id, req.user.id, res)) return;
    const updated = updateTimeline(db, req.params.id, req.body);
    res.json(updated);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.delete('/user/timelines/:id', authenticate, (req, res) => {
  try {
    const db = getAccountsDb();
    if (!requireOwnTimeline(db, req.params.id, req.user.id, res)) return;
    const all = getTimelinesForUser(db, req.user.id);
    if (all.length <= 1) return res.status(400).json({ error: 'Cannot delete your only timeline' });
    closeTimelineDb(req.user.id, req.params.id);
    // Delete DB file and WAL/SHM
    const dbPath = path.join(USERS_DIR, req.user.id, 'timelines', `${req.params.id}.db`);
    for (const suffix of ['', '-wal', '-shm']) {
      unlinkSafe(dbPath + suffix);
    }
    deleteTimeline(db, req.params.id); // cascade deletes shares
    res.json({ ok: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.post('/user/timelines/:id/duplicate', authenticate, (req, res) => {
  try {
    const db = getAccountsDb();
    const src = requireOwnTimeline(db, req.params.id, req.user.id, res);
    if (!src) return;
    const name = req.body?.name || `${src.name} (copy)`;
    const newTl = createTimeline(db, { ownerId: req.user.id, name, description: src.description });
    // Flush WAL and copy file
    closeTimelineDb(req.user.id, src.id);
    const srcPath = path.join(USERS_DIR, req.user.id, 'timelines', `${src.id}.db`);
    const dstDir = path.join(USERS_DIR, req.user.id, 'timelines');
    fs.mkdirSync(dstDir, { recursive: true });
    fs.copyFileSync(srcPath, path.join(dstDir, `${newTl.id}.db`));
    res.status(201).json(newTl);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.get('/user/timelines/:id/export', authenticate, (req, res) => {
  try {
    const db = getAccountsDb();
    const tl = requireOwnTimeline(db, req.params.id, req.user.id, res);
    if (!tl) return;
    // Flush WAL for a clean file
    closeTimelineDb(req.user.id, req.params.id);
    const dbPath = path.join(USERS_DIR, req.user.id, 'timelines', `${req.params.id}.db`);
    const safeName = tl.name.replace(/[^a-zA-Z0-9_-]/g, '_');
    res.download(dbPath, `${safeName}.db`);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

router.post('/user/timelines/import', authenticate, upload.single('file'), (req, res) => {
  try {
    if (!req.file) return res.status(400).json({ error: 'No file uploaded' });
    const name = req.body.name?.trim() || 'Imported Timeline';

    // Validate: must be a valid SQLite DB with timeline_nodes table
    let testDb;
    try {
      testDb = new Database(req.file.path, { readonly: true });
      const hasTable = testDb.prepare("SELECT name FROM sqlite_master WHERE type='table' AND name='timeline_nodes'").get();
      testDb.close();
      testDb = null;
      if (!hasTable) {
        fs.unlinkSync(req.file.path);
        return res.status(400).json({ error: 'Invalid timeline database: missing timeline_nodes table' });
      }
    } catch (err) {
      if (testDb) testDb.close();
      fs.unlinkSync(req.file.path);
      return res.status(400).json({ error: 'Invalid SQLite file' });
    }

    const db = getAccountsDb();
    const tl = createTimeline(db, { ownerId: req.user.id, name });
    const dstDir = path.join(USERS_DIR, req.user.id, 'timelines');
    fs.mkdirSync(dstDir, { recursive: true });
    fs.renameSync(req.file.path, path.join(dstDir, `${tl.id}.db`));
    // Run schema migration on the imported DB
    getTimelineDb(req.user.id, tl.id);
    res.status(201).json(tl);
  } catch (err) {
    // Clean up temp file on error
    if (req.file) unlinkSafe(req.file.path);
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
    const { username, timeline_id, preset, perm_timeline, perm_docs, perm_entities, perm_settings } = req.body;
    if (!username) return res.status(400).json({ error: 'username is required' });
    if (!timeline_id) return res.status(400).json({ error: 'timeline_id is required' });

    const db = getAccountsDb();

    if (!requireOwnTimeline(db, timeline_id, req.user.id, res)) return;

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
      timelineId: timeline_id,
      preset: preset || 'read',
      perms,
    });
    res.status(201).json(share);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Update share permissions
router.put('/user/shares/:timelineId/:granteeId', authenticate, (req, res) => {
  try {
    const db = getAccountsDb();
    if (!requireOwnTimeline(db, req.params.timelineId, req.user.id, res)) return;
    const share = updateShare(db, req.params.timelineId, req.params.granteeId, req.body);
    if (!share) return res.status(404).json({ error: 'Share not found' });
    res.json(share);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Revoke share
router.delete('/user/shares/:timelineId/:granteeId', authenticate, (req, res) => {
  try {
    const db = getAccountsDb();
    if (!requireOwnTimeline(db, req.params.timelineId, req.user.id, res)) return;
    deleteShare(db, req.params.timelineId, req.params.granteeId);
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

    // Create default timeline and initialize DB
    const tl = createTimeline(db, { ownerId: user.id, name: 'Default' });
    getTimelineDb(user.id, tl.id);

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
