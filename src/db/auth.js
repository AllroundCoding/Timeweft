'use strict';
const { randomUUID: generateUUID } = require('crypto');

// ── Users ────────────────────────────────────────────────────────────────────

function findUserByUsername(db, username) {
  return db.prepare('SELECT * FROM users WHERE username = ? COLLATE NOCASE').get(username);
}

function findUserById(db, id) {
  return db.prepare('SELECT * FROM users WHERE id = ?').get(id);
}

function createUser(db, { username, passwordHash, displayName, role }) {
  const id = generateUUID();
  db.prepare(`INSERT INTO users (id, username, password_hash, display_name, role)
    VALUES (?, ?, ?, ?, ?)`).run(id, username, passwordHash, displayName || null, role || 'user');
  return findUserById(db, id);
}

function listUsers(db) {
  return db.prepare('SELECT id, username, display_name, role, is_active, created_at, updated_at FROM users ORDER BY created_at').all();
}

function updateUser(db, id, fields) {
  const sets = [];
  const vals = [];
  if (fields.username !== undefined)     { sets.push('username = ?');      vals.push(fields.username); }
  if (fields.displayName !== undefined)  { sets.push('display_name = ?'); vals.push(fields.displayName); }
  if (fields.passwordHash !== undefined) { sets.push('password_hash = ?'); vals.push(fields.passwordHash); }
  if (fields.role !== undefined)         { sets.push('role = ?');          vals.push(fields.role); }
  if (fields.isActive !== undefined)     { sets.push('is_active = ?');    vals.push(fields.isActive ? 1 : 0); }
  if (!sets.length) return findUserById(db, id);
  sets.push("updated_at = datetime('now')");
  vals.push(id);
  db.prepare(`UPDATE users SET ${sets.join(', ')} WHERE id = ?`).run(...vals);
  return findUserById(db, id);
}

function deleteUser(db, id) {
  db.prepare('DELETE FROM users WHERE id = ?').run(id);
}

function countUsers(db) {
  return db.prepare('SELECT COUNT(*) as count FROM users').get().count;
}

// ── API Keys ─────────────────────────────────────────────────────────────────

function findByApiKeyHash(db, keyHash) {
  return db.prepare(`
    SELECT ak.*, u.role, u.is_active AS user_is_active
    FROM api_keys ak
    JOIN users u ON u.id = ak.user_id
    WHERE ak.key_hash = ? AND ak.is_revoked = 0
  `).get(keyHash);
}

function createApiKey(db, { userId, keyHash, name }) {
  const id = 'key_' + generateUUID().replace(/-/g, '').substring(0, 12);
  db.prepare('INSERT INTO api_keys (id, user_id, key_hash, name) VALUES (?, ?, ?, ?)')
    .run(id, userId, keyHash, name || 'Default');
  return db.prepare('SELECT id, user_id, name, created_at, last_used_at, is_revoked FROM api_keys WHERE id = ?').get(id);
}

function listApiKeys(db, userId) {
  return db.prepare('SELECT id, name, created_at, last_used_at, is_revoked FROM api_keys WHERE user_id = ? ORDER BY created_at DESC')
    .all(userId);
}

function revokeApiKey(db, id, userId) {
  const key = db.prepare('SELECT * FROM api_keys WHERE id = ? AND user_id = ?').get(id, userId);
  if (!key) return null;
  db.prepare('UPDATE api_keys SET is_revoked = 1 WHERE id = ?').run(id);
  return { ok: true };
}

function touchApiKeyUsage(db, keyHash) {
  db.prepare("UPDATE api_keys SET last_used_at = datetime('now') WHERE key_hash = ?").run(keyHash);
}

// ── Global Settings ─────────────────────────────────────────────────────────

function getGlobalSetting(db, key) {
  const row = db.prepare('SELECT value FROM global_settings WHERE key = ?').get(key);
  return row ? row.value : null;
}

function setGlobalSetting(db, key, value) {
  db.prepare('INSERT INTO global_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
    .run(key, String(value));
}

function getAllGlobalSettings(db) {
  const rows = db.prepare('SELECT key, value FROM global_settings').all();
  const out = {};
  for (const r of rows) out[r.key] = r.value;
  return out;
}

// ── Timelines ──────────────────────────────────────────────────────────────

function createTimeline(db, { ownerId, name, description }) {
  const id = generateUUID();
  db.prepare('INSERT INTO timelines (id, owner_id, name, description) VALUES (?, ?, ?, ?)')
    .run(id, ownerId, name || 'Untitled', description || null);
  return getTimeline(db, id);
}

function getTimeline(db, timelineId) {
  return db.prepare('SELECT * FROM timelines WHERE id = ?').get(timelineId);
}

function getTimelinesForUser(db, ownerId) {
  return db.prepare('SELECT * FROM timelines WHERE owner_id = ? ORDER BY created_at').all(ownerId);
}

function updateTimeline(db, timelineId, { name, description }) {
  const sets = ["updated_at=datetime('now')"];
  const vals = [];
  if (name !== undefined) { sets.push('name=?'); vals.push(name); }
  if (description !== undefined) { sets.push('description=?'); vals.push(description); }
  vals.push(timelineId);
  db.prepare(`UPDATE timelines SET ${sets.join(', ')} WHERE id = ?`).run(...vals);
  return getTimeline(db, timelineId);
}

function deleteTimeline(db, timelineId) {
  db.prepare('DELETE FROM timelines WHERE id = ?').run(timelineId);
}

function getDefaultTimeline(db, ownerId) {
  return db.prepare('SELECT * FROM timelines WHERE owner_id = ? ORDER BY created_at LIMIT 1').get(ownerId);
}

// ── Timeline Shares ─────────────────────────────────────────────────────────

const PRESET_PERMS = {
  read: { perm_timeline: 'read', perm_docs: 'read', perm_entities: 'read', perm_settings: 'read' },
  edit: { perm_timeline: 'edit', perm_docs: 'edit', perm_entities: 'edit', perm_settings: 'read' },
  full: { perm_timeline: 'delete', perm_docs: 'delete', perm_entities: 'delete', perm_settings: 'edit' },
};

function createShare(db, { ownerId, granteeId, timelineId, preset, perms }) {
  const id = 'share_' + generateUUID().replace(/-/g, '').substring(0, 12);
  const base = PRESET_PERMS[preset] || PRESET_PERMS.read;
  const p = preset === 'custom' ? { ...base, ...perms } : base;
  db.prepare(`INSERT INTO timeline_shares
    (id, owner_id, grantee_id, timeline_id, preset, perm_timeline, perm_docs, perm_entities, perm_settings)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON CONFLICT(timeline_id, grantee_id) DO UPDATE SET
      preset=excluded.preset, perm_timeline=excluded.perm_timeline,
      perm_docs=excluded.perm_docs, perm_entities=excluded.perm_entities,
      perm_settings=excluded.perm_settings, updated_at=datetime('now')`)
    .run(id, ownerId, granteeId, timelineId, preset || 'read',
         p.perm_timeline, p.perm_docs, p.perm_entities, p.perm_settings);
  return getShare(db, timelineId, granteeId);
}

function getShare(db, timelineId, granteeId) {
  return db.prepare('SELECT * FROM timeline_shares WHERE timeline_id = ? AND grantee_id = ?')
    .get(timelineId, granteeId);
}

function getSharesForOwner(db, ownerId) {
  return db.prepare(`
    SELECT s.*, u.username AS grantee_username, u.display_name AS grantee_display_name,
           t.name AS timeline_name
    FROM timeline_shares s
    JOIN users u ON u.id = s.grantee_id
    JOIN timelines t ON t.id = s.timeline_id
    WHERE s.owner_id = ?
    ORDER BY t.name, s.created_at
  `).all(ownerId);
}

function getSharesForGrantee(db, granteeId) {
  return db.prepare(`
    SELECT s.*, u.username AS owner_username, u.display_name AS owner_display_name,
           t.name AS timeline_name
    FROM timeline_shares s
    JOIN users u ON u.id = s.owner_id
    JOIN timelines t ON t.id = s.timeline_id
    WHERE s.grantee_id = ?
    ORDER BY t.name, s.created_at
  `).all(granteeId);
}

function updateShare(db, timelineId, granteeId, fields) {
  const preset = fields.preset;
  if (preset && preset !== 'custom') {
    const p = PRESET_PERMS[preset] || PRESET_PERMS.read;
    db.prepare(`UPDATE timeline_shares SET
      preset=?, perm_timeline=?, perm_docs=?, perm_entities=?, perm_settings=?,
      updated_at=datetime('now')
      WHERE timeline_id=? AND grantee_id=?`)
      .run(preset, p.perm_timeline, p.perm_docs, p.perm_entities, p.perm_settings,
           timelineId, granteeId);
  } else {
    const sets = ["updated_at=datetime('now')"];
    const vals = [];
    if (fields.preset !== undefined) { sets.push('preset=?'); vals.push(fields.preset); }
    if (fields.perm_timeline !== undefined) { sets.push('perm_timeline=?'); vals.push(fields.perm_timeline); }
    if (fields.perm_docs !== undefined) { sets.push('perm_docs=?'); vals.push(fields.perm_docs); }
    if (fields.perm_entities !== undefined) { sets.push('perm_entities=?'); vals.push(fields.perm_entities); }
    if (fields.perm_settings !== undefined) { sets.push('perm_settings=?'); vals.push(fields.perm_settings); }
    vals.push(timelineId, granteeId);
    db.prepare(`UPDATE timeline_shares SET ${sets.join(', ')} WHERE timeline_id=? AND grantee_id=?`)
      .run(...vals);
  }
  return getShare(db, timelineId, granteeId);
}

function deleteShare(db, timelineId, granteeId) {
  db.prepare('DELETE FROM timeline_shares WHERE timeline_id = ? AND grantee_id = ?')
    .run(timelineId, granteeId);
}

module.exports = {
  findUserByUsername,
  findUserById,
  createUser,
  listUsers,
  updateUser,
  deleteUser,
  countUsers,
  findByApiKeyHash,
  createApiKey,
  listApiKeys,
  revokeApiKey,
  touchApiKeyUsage,
  getGlobalSetting,
  setGlobalSetting,
  getAllGlobalSettings,
  createTimeline,
  getTimeline,
  getTimelinesForUser,
  updateTimeline,
  deleteTimeline,
  getDefaultTimeline,
  PRESET_PERMS,
  createShare,
  getShare,
  getSharesForOwner,
  getSharesForGrantee,
  updateShare,
  deleteShare,
};
