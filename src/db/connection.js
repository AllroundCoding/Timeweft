'use strict';
const Database = require('better-sqlite3');
const crypto   = require('crypto');
const path     = require('path');
const fs       = require('fs');
const { decimalToDate, DEFAULT_CALENDAR } = require('../server/calendar');

const DATA_DIR     = path.join(__dirname, '../../data');
const ACCOUNTS_DB  = path.join(DATA_DIR, 'accounts.db');
const USERS_DIR    = path.join(DATA_DIR, 'users');

// Legacy path (pre-auth migration)
const LEGACY_DB_PATH = path.join(DATA_DIR, 'timeline.db');

const TIMELINE_TABLES = [
  'timeline_nodes', 'settings', 'calendars', 'documents',
  'document_tags', 'entities', 'entity_node_links',
  'folders', 'entity_doc_links', 'doc_node_links',
  'entity_relationships', 'story_arcs', 'arc_node_links', 'arc_entity_links',
];

let _accountsDb = null;
const _timelineDbs = new Map(); // "userId/timelineId" → Database

// ── Accounts DB (shared: users, api_keys, global_settings) ──────────────────

function getAccountsDb() {
  if (_accountsDb) return _accountsDb;
  fs.mkdirSync(DATA_DIR, { recursive: true });
  _accountsDb = new Database(ACCOUNTS_DB);
  _accountsDb.pragma('journal_mode = WAL');
  _accountsDb.pragma('foreign_keys = ON');
  _initAccountsSchema(_accountsDb);
  _migrateAccountsSchema(_accountsDb);
  _migrateAllUsersToMultiTimeline(_accountsDb);
  return _accountsDb;
}

function _initAccountsSchema(db) {
  db.exec(`
    CREATE TABLE IF NOT EXISTS users (
      id            TEXT PRIMARY KEY,
      username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
      password_hash TEXT NOT NULL,
      display_name  TEXT,
      role          TEXT NOT NULL DEFAULT 'user' CHECK(role IN ('admin','user')),
      is_active     INTEGER NOT NULL DEFAULT 1,
      created_at    TEXT DEFAULT(datetime('now')),
      updated_at    TEXT DEFAULT(datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS api_keys (
      id          TEXT PRIMARY KEY,
      user_id     TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      key_hash    TEXT NOT NULL UNIQUE,
      name        TEXT NOT NULL DEFAULT 'Default',
      last_used_at TEXT,
      is_revoked  INTEGER NOT NULL DEFAULT 0,
      created_at  TEXT DEFAULT(datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_api_keys_hash ON api_keys(key_hash);

    CREATE TABLE IF NOT EXISTS global_settings (
      key   TEXT PRIMARY KEY,
      value TEXT
    );

    INSERT OR IGNORE INTO global_settings (key, value) VALUES
      ('registration_open', '0');

    CREATE TABLE IF NOT EXISTS timeline_shares (
      id             TEXT PRIMARY KEY,
      owner_id       TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      grantee_id     TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      preset         TEXT NOT NULL DEFAULT 'read'
                     CHECK(preset IN ('read','edit','full','custom')),
      perm_timeline  TEXT NOT NULL DEFAULT 'read'
                     CHECK(perm_timeline IN ('read','edit','delete','review')),
      perm_docs      TEXT NOT NULL DEFAULT 'read'
                     CHECK(perm_docs IN ('read','edit','delete','review')),
      perm_entities  TEXT NOT NULL DEFAULT 'read'
                     CHECK(perm_entities IN ('read','edit','delete','review')),
      perm_settings  TEXT NOT NULL DEFAULT 'read'
                     CHECK(perm_settings IN ('read','edit')),
      created_at     TEXT DEFAULT(datetime('now')),
      updated_at     TEXT DEFAULT(datetime('now')),
      UNIQUE(timeline_id, grantee_id)
    );
    CREATE INDEX IF NOT EXISTS idx_shares_grantee ON timeline_shares(grantee_id);
    CREATE INDEX IF NOT EXISTS idx_shares_owner ON timeline_shares(owner_id);

    CREATE TABLE IF NOT EXISTS timelines (
      id          TEXT PRIMARY KEY,
      owner_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      name        TEXT NOT NULL DEFAULT 'Default',
      description TEXT,
      created_at  TEXT DEFAULT(datetime('now')),
      updated_at  TEXT DEFAULT(datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_timelines_owner ON timelines(owner_id);
  `);
}

// Idempotent migration for accounts schema changes
function _migrateAccountsSchema(db) {
  const cols = db.prepare("PRAGMA table_info(timeline_shares)").all().map(r => r.name);
  if (!cols.includes('timeline_id')) {
    db.exec("ALTER TABLE timeline_shares ADD COLUMN timeline_id TEXT REFERENCES timelines(id) ON DELETE CASCADE");
    db.exec("CREATE INDEX IF NOT EXISTS idx_shares_timeline_grantee ON timeline_shares(timeline_id, grantee_id)");
  }
  // Migrate unique constraint from (owner_id, grantee_id) to (timeline_id, grantee_id)
  const indexes = db.prepare("PRAGMA index_list(timeline_shares)").all();
  const hasOldUnique = indexes.some(idx => {
    if (!idx.unique) return false;
    const idxCols = db.prepare(`PRAGMA index_info("${idx.name}")`).all().map(c => c.name);
    return idxCols.includes('owner_id') && idxCols.includes('grantee_id') && !idxCols.includes('timeline_id');
  });
  if (hasOldUnique) {
    db.exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_shares_tl_grantee_unique ON timeline_shares(timeline_id, grantee_id)");
  }
}

// Migrate existing single-timeline users to multi-timeline structure
function _migrateAllUsersToMultiTimeline(accountsDb) {
  if (!fs.existsSync(USERS_DIR)) return;
  for (const userId of fs.readdirSync(USERS_DIR)) {
    const oldPath = path.join(USERS_DIR, userId, 'timeline.db');
    if (!fs.existsSync(oldPath)) continue;
    // Check if user already has timelines
    const existing = accountsDb.prepare('SELECT id FROM timelines WHERE owner_id = ?').get(userId);
    if (existing) continue;
    // Create timeline record
    const tlId = crypto.randomUUID();
    accountsDb.prepare('INSERT INTO timelines (id, owner_id, name) VALUES (?, ?, ?)').run(tlId, userId, 'Default');
    // Move file to timelines subdirectory
    const newDir = path.join(USERS_DIR, userId, 'timelines');
    fs.mkdirSync(newDir, { recursive: true });
    fs.renameSync(oldPath, path.join(newDir, `${tlId}.db`));
    // Clean up WAL/SHM files
    for (const suffix of ['-wal', '-shm']) {
      try { fs.unlinkSync(oldPath + suffix); } catch (e) { if (e.code !== 'ENOENT') throw e; }
    }
    // Update existing shares to reference this timeline
    accountsDb.prepare('UPDATE timeline_shares SET timeline_id = ? WHERE owner_id = ? AND timeline_id IS NULL')
      .run(tlId, userId);
  }
}

// ── Per-timeline DB ─────────────────────────────────────────────────────────

function getTimelineDb(userId, timelineId) {
  const key = `${userId}/${timelineId}`;
  if (_timelineDbs.has(key)) return _timelineDbs.get(key);

  const dir = path.join(USERS_DIR, userId, 'timelines');
  fs.mkdirSync(dir, { recursive: true });

  const dbPath = path.join(dir, `${timelineId}.db`);
  const db = new Database(dbPath);
  db.pragma('journal_mode = WAL');
  db.pragma('foreign_keys = ON');
  _initTimelineSchema(db);
  _migrateSchema(db);
  _timelineDbs.set(key, db);
  return db;
}

function closeTimelineDb(userId, timelineId) {
  const key = `${userId}/${timelineId}`;
  const db = _timelineDbs.get(key);
  if (db) {
    db.close();
    _timelineDbs.delete(key);
  }
}

function _initTimelineSchema(db) {
  db.exec(`
    CREATE TABLE IF NOT EXISTS timeline_nodes (
      id          TEXT PRIMARY KEY,
      parent_id   TEXT REFERENCES timeline_nodes(id) ON DELETE CASCADE,
      type        TEXT NOT NULL DEFAULT 'point' CHECK(type IN ('point','span')),
      title       TEXT NOT NULL,
      start_date  REAL NOT NULL,
      end_date    REAL,
      description TEXT,
      color       TEXT DEFAULT '#5566bb',
      opacity     REAL DEFAULT 0.75,
      importance  TEXT DEFAULT 'moderate' CHECK(importance IN ('minor','moderate','major','critical')),
      node_type   TEXT DEFAULT 'event',
      sort_order  INTEGER DEFAULT 0,
      metadata    TEXT DEFAULT '{}',
      created_at  TEXT DEFAULT(datetime('now')),
      updated_at  TEXT DEFAULT(datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_tnodes_parent ON timeline_nodes(parent_id, start_date);
    CREATE INDEX IF NOT EXISTS idx_tnodes_title ON timeline_nodes(title COLLATE NOCASE);

    CREATE TABLE IF NOT EXISTS settings (
      key   TEXT PRIMARY KEY,
      value TEXT
    );
    INSERT OR IGNORE INTO settings (key, value) VALUES
      ('world_start', '-100000000'),
      ('world_end',   '2500');

    CREATE TABLE IF NOT EXISTS calendars (
      id          TEXT PRIMARY KEY,
      timeline_id TEXT NOT NULL DEFAULT 'default',
      name        TEXT NOT NULL,
      is_active   INTEGER NOT NULL DEFAULT 0,
      config      TEXT NOT NULL DEFAULT '{}',
      created_at  TEXT DEFAULT(datetime('now')),
      updated_at  TEXT DEFAULT(datetime('now'))
    );
    INSERT OR IGNORE INTO calendars (id, timeline_id, name, is_active, config) VALUES (
      'cal_001',
      'default',
      'Gregorian (default)',
      1,
      '{"months":[{"name":"Jan","days":31},{"name":"Feb","days":28},{"name":"Mar","days":31},{"name":"Apr","days":30},{"name":"May","days":31},{"name":"Jun","days":30},{"name":"Jul","days":31},{"name":"Aug","days":31},{"name":"Sep","days":30},{"name":"Oct","days":31},{"name":"Nov","days":30},{"name":"Dec","days":31}],"hours_per_day":24,"minutes_per_hour":60,"weekdays":["Sun","Mon","Tue","Wed","Thu","Fri","Sat"],"epoch_weekday":0,"weekdays_reset_each_month":false,"eras":[],"zero_year_exists":false,"moons":[{"name":"Moon","cycle_minutes":"42524.0463","phase_offset":"15238","color":"#fff"}],"date_format":"D MMMM YYYY","time_format":"24h"}'
    );

    CREATE TABLE IF NOT EXISTS documents (
      id          TEXT PRIMARY KEY,
      timeline_id TEXT DEFAULT 'default',
      title       TEXT NOT NULL,
      category    TEXT DEFAULT 'Other',
      content     TEXT,
      created_at  TEXT,
      updated_at  TEXT
    );

    CREATE TABLE IF NOT EXISTS document_tags (
      doc_id TEXT NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
      tag    TEXT NOT NULL,
      PRIMARY KEY (doc_id, tag)
    );

    CREATE TABLE IF NOT EXISTS entities (
      id          TEXT PRIMARY KEY,
      timeline_id TEXT DEFAULT 'default',
      name        TEXT NOT NULL,
      entity_type TEXT NOT NULL DEFAULT 'character',
      description TEXT,
      color       TEXT DEFAULT '#7c6bff',
      metadata    TEXT DEFAULT '{}',
      created_at  TEXT DEFAULT(datetime('now')),
      updated_at  TEXT DEFAULT(datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_entities_name ON entities(name COLLATE NOCASE);

    CREATE TABLE IF NOT EXISTS entity_node_links (
      entity_id TEXT NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
      node_id   TEXT NOT NULL REFERENCES timeline_nodes(id) ON DELETE CASCADE,
      role      TEXT DEFAULT '',
      PRIMARY KEY (entity_id, node_id)
    );

    CREATE TABLE IF NOT EXISTS pending_deletions (
      id             TEXT PRIMARY KEY,
      resource_type  TEXT NOT NULL
                     CHECK(resource_type IN ('node','doc','entity','entity_link')),
      resource_id    TEXT NOT NULL,
      resource_title TEXT,
      requested_by   TEXT NOT NULL,
      requested_at   TEXT DEFAULT(datetime('now')),
      UNIQUE(resource_type, resource_id)
    );

    CREATE TABLE IF NOT EXISTS entity_doc_links (
      entity_id  TEXT NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
      doc_id     TEXT NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
      role       TEXT DEFAULT '',
      created_at TEXT DEFAULT(datetime('now')),
      PRIMARY KEY (entity_id, doc_id)
    );

    CREATE TABLE IF NOT EXISTS doc_node_links (
      doc_id     TEXT NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
      node_id    TEXT NOT NULL REFERENCES timeline_nodes(id) ON DELETE CASCADE,
      role       TEXT DEFAULT '',
      created_at TEXT DEFAULT(datetime('now')),
      PRIMARY KEY (doc_id, node_id)
    );

    CREATE TABLE IF NOT EXISTS folders (
      id          TEXT PRIMARY KEY,
      parent_id   TEXT REFERENCES folders(id) ON DELETE CASCADE,
      name        TEXT NOT NULL,
      folder_type TEXT NOT NULL CHECK(folder_type IN ('docs','entities')),
      sort_order  INTEGER DEFAULT 0,
      color       TEXT,
      created_at  TEXT DEFAULT(datetime('now'))
    );
    CREATE INDEX IF NOT EXISTS idx_folders_parent ON folders(parent_id);
    CREATE INDEX IF NOT EXISTS idx_folders_type ON folders(folder_type);

    CREATE TABLE IF NOT EXISTS entity_relationships (
      id            TEXT PRIMARY KEY,
      source_id     TEXT NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
      target_id     TEXT NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
      relationship  TEXT NOT NULL,
      description   TEXT,
      start_node_id TEXT REFERENCES timeline_nodes(id) ON DELETE SET NULL,
      end_node_id   TEXT REFERENCES timeline_nodes(id) ON DELETE SET NULL,
      metadata      TEXT DEFAULT '{}',
      created_at    TEXT DEFAULT(datetime('now')),
      UNIQUE(source_id, target_id, relationship)
    );
    CREATE INDEX IF NOT EXISTS idx_rel_source ON entity_relationships(source_id);
    CREATE INDEX IF NOT EXISTS idx_rel_target ON entity_relationships(target_id);

    CREATE TABLE IF NOT EXISTS story_arcs (
      id          TEXT PRIMARY KEY,
      name        TEXT NOT NULL,
      description TEXT,
      color       TEXT DEFAULT '#c97b2a',
      status      TEXT DEFAULT 'active' CHECK(status IN ('planned','active','resolved','abandoned')),
      sort_order  INTEGER DEFAULT 0,
      metadata    TEXT DEFAULT '{}',
      created_at  TEXT DEFAULT(datetime('now')),
      updated_at  TEXT DEFAULT(datetime('now'))
    );

    CREATE TABLE IF NOT EXISTS arc_node_links (
      arc_id    TEXT NOT NULL REFERENCES story_arcs(id) ON DELETE CASCADE,
      node_id   TEXT NOT NULL REFERENCES timeline_nodes(id) ON DELETE CASCADE,
      position  INTEGER DEFAULT 0,
      arc_label TEXT,
      PRIMARY KEY (arc_id, node_id)
    );

    CREATE TABLE IF NOT EXISTS arc_entity_links (
      arc_id    TEXT NOT NULL REFERENCES story_arcs(id) ON DELETE CASCADE,
      entity_id TEXT NOT NULL REFERENCES entities(id) ON DELETE CASCADE,
      role      TEXT DEFAULT '',
      PRIMARY KEY (arc_id, entity_id)
    );
  `);
}

// ── Decompose existing REAL dates into component columns (one-time, idempotent) ──

function _migrateDecomposeDates(db) {
  const rows = db.prepare('SELECT id, start_date, end_date FROM timeline_nodes WHERE start_year IS NULL').all();
  if (!rows.length) return;

  const calRow = db.prepare("SELECT value FROM settings WHERE key = 'calendar'").get();
  let cal = DEFAULT_CALENDAR;
  if (calRow?.value) {
    try { const parsed = JSON.parse(calRow.value); if (parsed?.months?.length) cal = parsed; } catch {}
  }

  const update = db.prepare(`UPDATE timeline_nodes SET
    start_year=?, start_month=?, start_day=?, start_hour=?, start_minute=?,
    end_year=?, end_month=?, end_day=?, end_hour=?, end_minute=?
    WHERE id=?`);

  const tx = db.transaction(() => {
    for (const r of rows) {
      const s = decimalToDate(cal, r.start_date);
      let ey = null, em = null, ed = null, eh = null, emi = null;
      if (r.end_date != null) {
        const e = decimalToDate(cal, r.end_date);
        ey = e.year; em = e.month || null; ed = e.day || null; eh = e.hour || null; emi = e.minute || null;
      }
      update.run(s.year, s.month || null, s.day || null, s.hour || null, s.minute || null,
                 ey, em, ed, eh, emi, r.id);
    }
  });
  tx();

  const count = db.prepare('SELECT COUNT(*) as c FROM calendars').get().c;
  if (count === 0) {
    const config = JSON.stringify(cal);
    db.prepare("INSERT INTO calendars (id, timeline_id, name, is_active, config) VALUES ('cal_default', 'default', 'Default', 1, ?)")
      .run(config);
  }
}

// ── Identifier validation (defense-in-depth for interpolated SQL names) ──────

const VALID_IDENT = /^[A-Za-z_][A-Za-z0-9_]*$/;
function assertIdent(name) {
  if (!VALID_IDENT.test(name)) throw new Error(`Invalid SQL identifier: ${name}`);
  return name;
}

// ── Schema migrations (idempotent column additions) ───────────────────────────

function _migrateSchema(db) {
  const colNames = tbl => db.prepare(`PRAGMA table_info(${assertIdent(tbl)})`).all().map(r => r.name);

  const nodeCols = colNames('timeline_nodes');
  for (const col of ['start_year','start_month','start_day','start_hour','start_minute',
                      'end_year','end_month','end_day','end_hour','end_minute']) {
    if (!nodeCols.includes(col)) db.exec(`ALTER TABLE timeline_nodes ADD COLUMN ${assertIdent(col)} INTEGER`);
  }
  if (!nodeCols.includes('opacity')) db.exec('ALTER TABLE timeline_nodes ADD COLUMN opacity REAL DEFAULT 0.75');

  _migrateDecomposeDates(db);

  // Add node_id to entities for 1:1 binding (entity IS the timeline node)
  const entCols = colNames('entities');
  if (!entCols.includes('node_id')) db.exec('ALTER TABLE entities ADD COLUMN node_id TEXT REFERENCES timeline_nodes(id)');
  if (!entCols.includes('folder_id')) db.exec('ALTER TABLE entities ADD COLUMN folder_id TEXT REFERENCES folders(id) ON DELETE SET NULL');

  // Add folder_id to documents
  const docCols = colNames('documents');
  if (!docCols.includes('folder_id')) db.exec('ALTER TABLE documents ADD COLUMN folder_id TEXT REFERENCES folders(id) ON DELETE SET NULL');
}

// ── Legacy migration: copy old timeline.db data into a user's DB ────────────

function migrateLegacyDb(userId, timelineId) {
  if (!fs.existsSync(LEGACY_DB_PATH)) return false;

  const legacyDb = new Database(LEGACY_DB_PATH, { readonly: true });
  const userDb = getTimelineDb(userId, timelineId);

  userDb.transaction(() => {
    for (const table of TIMELINE_TABLES) {
      // Check table exists in legacy
      const exists = legacyDb.prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?").get(table);
      if (!exists) continue;

      assertIdent(table);
      const rows = legacyDb.prepare(`SELECT * FROM ${table}`).all();
      if (!rows.length) continue;

      // Clear defaults first (settings, calendars have INSERT OR IGNORE defaults)
      userDb.prepare(`DELETE FROM ${table}`).run();

      const cols = Object.keys(rows[0]);
      // Filter to only columns that exist in the user DB
      const userCols = userDb.prepare(`PRAGMA table_info(${table})`).all().map(r => r.name);
      const validCols = cols.filter(c => userCols.includes(c));
      validCols.forEach(assertIdent);

      const placeholders = validCols.map(() => '?').join(',');
      const insert = userDb.prepare(`INSERT OR IGNORE INTO ${table} (${validCols.join(',')}) VALUES (${placeholders})`);
      for (const row of rows) {
        insert.run(validCols.map(c => row[c]));
      }
    }
  })();

  legacyDb.close();

  // Rename legacy DB so it's not migrated again
  fs.renameSync(LEGACY_DB_PATH, LEGACY_DB_PATH + '.migrated');
  return true;
}

// ── Query helpers ─────────────────────────────────────────────────────────────

function getNodes(db, parentId) {
  if (parentId == null) {
    return db.prepare('SELECT * FROM timeline_nodes WHERE parent_id IS NULL ORDER BY start_date').all();
  }
  return db.prepare('SELECT * FROM timeline_nodes WHERE parent_id = ? ORDER BY start_date').all(parentId);
}

function getNode(db, id) {
  return db.prepare('SELECT * FROM timeline_nodes WHERE id = ?').get(id);
}

function getDescendants(db, parentId) {
  const result = [];
  const children = getNodes(db, parentId);
  for (const child of children) {
    result.push(child);
    result.push(...getDescendants(db, child.id));
  }
  return result;
}

function searchNodes(db, search, { parent_id, type, node_type, importance, date_min, date_max, limit } = {}) {
  const clauses = [], params = [];

  if (parent_id !== undefined) {
    if (parent_id === null) { clauses.push('parent_id IS NULL'); }
    else { clauses.push('parent_id = ?'); params.push(parent_id); }
  }
  if (type)       { clauses.push('type = ?');       params.push(type); }
  if (node_type)  { clauses.push('node_type = ?');  params.push(node_type); }
  if (importance) { clauses.push('importance = ?'); params.push(importance); }
  if (date_min !== undefined) { clauses.push('start_year >= ?'); params.push(Number(date_min)); }
  if (date_max !== undefined) { clauses.push('start_year <= ?'); params.push(Number(date_max)); }

  if (search) {
    const s = `%${search.toLowerCase()}%`;
    clauses.push("(LOWER(title) LIKE ? OR LOWER(COALESCE(description,'')) LIKE ?)");
    params.push(s, s);
  }

  const where = clauses.length ? 'WHERE ' + clauses.join(' AND ') : '';
  const limitClause = limit ? 'LIMIT ?' : '';
  // Rank: title matches first, then description-only matches
  const orderBy = search
    ? `ORDER BY (CASE WHEN LOWER(title) LIKE ? THEN 0 ELSE 1 END), title`
    : 'ORDER BY start_date';
  if (search) params.push(`%${search.toLowerCase()}%`);
  if (limit) params.push(parseInt(limit, 10));
  return db.prepare(`SELECT * FROM timeline_nodes ${where} ${orderBy} ${limitClause}`).all(params);
}

module.exports = {
  getAccountsDb,
  getTimelineDb,
  closeTimelineDb,
  migrateLegacyDb,
  getNodes,
  getNode,
  getDescendants,
  searchNodes,
  TIMELINE_TABLES,
  USERS_DIR,
  LEGACY_DB_PATH,
};
