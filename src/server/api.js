'use strict';
const express = require('express');
const cors    = require('cors');
const path    = require('path');
const { randomUUID: generateUUID } = require('crypto');

const {
  getNodes,
  getNode,
  searchNodes,
} = require('../db/connection');
const { dateToDecimal, DEFAULT_CALENDAR } = require('./calendar');
const { authenticate, requirePerm, handleDelete } = require('./middleware');
const authRoutes = require('./auth-routes');

function getActiveCalendar(db) {
  const row = db.prepare("SELECT config FROM calendars WHERE is_active = 1 LIMIT 1").get();
  if (row?.config) { try { const c = JSON.parse(row.config); if (c?.months?.length) return c; } catch {} }
  const sRow = db.prepare("SELECT value FROM settings WHERE key = 'calendar'").get();
  if (sRow?.value) { try { const c = JSON.parse(sRow.value); if (c?.months?.length) return c; } catch {} }
  return DEFAULT_CALENDAR;
}

function computeReal(cal, body) {
  const sd = dateToDecimal(cal,
    body.start_year, body.start_month ?? 0, body.start_day ?? 0,
    body.start_hour ?? 0, body.start_minute ?? 0);
  let ed = null;
  if (body.end_year != null) {
    ed = dateToDecimal(cal,
      body.end_year, body.end_month ?? 0, body.end_day ?? 0,
      body.end_hour ?? 0, body.end_minute ?? 0);
  }
  return { start_date: sd, end_date: ed };
}

const app = express();
app.use(cors());
app.use(express.json());
app.use(express.static(path.join(__dirname, '../public')));

// ── Auth routes (public + authenticated) ─────────────────────────────────────
app.use('/api', authRoutes);

// ── All data routes below require authentication ─────────────────────────────
app.use('/api', authenticate);

// ── Timeline Nodes (recursive tree) ──────────────────────────────────────────

app.get('/api/nodes', (req, res) => {
  try {
    const raw = req.query.parent_id;
    const parentId = (raw === undefined || raw === 'null') ? null : raw;
    res.json(getNodes(req.db, parentId));
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.get('/api/nodes/search', (req, res) => {
  try {
    const { q, limit, date_min, date_max, node_type } = req.query;
    if (!q) return res.json({ nodes: [] });
    const opts = { limit: limit || 10 };
    if (date_min) opts.date_min = date_min;
    if (date_max) opts.date_max = date_max;
    if (node_type) opts.node_type = node_type;
    const nodes = searchNodes(req.db, q, opts);
    res.json({ nodes });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.get('/api/nodes/by-title/:title', (req, res) => {
  try {
    const title = req.params.title;
    const node = req.db.prepare('SELECT * FROM timeline_nodes WHERE LOWER(title) = LOWER(?) LIMIT 1').get(title);
    if (!node) return res.status(404).json({ error: 'Node not found' });
    res.json(node);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.get('/api/nodes/:id', (req, res) => {
  const node = getNode(req.db, req.params.id);
  if (!node) return res.status(404).json({ error: 'Node not found' });
  res.json(node);
});

app.post('/api/nodes', requirePerm('timeline', 'edit'), (req, res) => {
  try {
    const b = req.body;
    const title = b.title;
    const hasComponents = b.start_year != null;
    let start_date = b.start_date, end_date = b.end_date ?? null;
    if (hasComponents) {
      const cal = getActiveCalendar(req.db);
      const reals = computeReal(cal, b);
      start_date = reals.start_date;
      end_date = reals.end_date;
    }
    if (!title || start_date == null) return res.status(400).json({ error: 'title and start_date (or start_year) are required' });
    const newId = b.id || generateUUID();
    req.db.prepare(`INSERT INTO timeline_nodes
      (id,parent_id,type,title,start_date,end_date,
       start_year,start_month,start_day,start_hour,start_minute,
       end_year,end_month,end_day,end_hour,end_minute,
       description,color,opacity,importance,node_type,sort_order,metadata)
      VALUES (?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,?,?)`)
      .run(newId, b.parent_id ?? null, b.type ?? 'point', title, start_date, end_date,
           b.start_year ?? null, b.start_month ?? null, b.start_day ?? null, b.start_hour ?? null, b.start_minute ?? null,
           b.end_year ?? null, b.end_month ?? null, b.end_day ?? null, b.end_hour ?? null, b.end_minute ?? null,
           b.description ?? null, b.color ?? '#5566bb', b.opacity ?? 0.75, b.importance ?? 'moderate',
           b.node_type ?? 'event', b.sort_order ?? 0, b.metadata ?? '{}');
    res.status(201).json(getNode(req.db, newId));
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.put('/api/nodes/:id', requirePerm('timeline', 'edit'), (req, res) => {
  try {
    const b = req.body;
    if (req.params.id === b.parent_id) {
      return res.status(400).json({ error: 'A node cannot be its own parent' });
    }
    const hasComponents = b.start_year != null;
    let start_date = b.start_date, end_date = b.end_date;
    if (hasComponents) {
      const cal = getActiveCalendar(req.db);
      const reals = computeReal(cal, b);
      start_date = reals.start_date;
      end_date = reals.end_date;
    }
    req.db.prepare(`UPDATE timeline_nodes SET
      type=COALESCE(?,type), title=COALESCE(?,title),
      start_date=COALESCE(?,start_date), end_date=?,
      start_year=COALESCE(?,start_year), start_month=?, start_day=?, start_hour=?, start_minute=?,
      end_year=?, end_month=?, end_day=?, end_hour=?, end_minute=?,
      description=COALESCE(?,description), color=COALESCE(?,color),
      opacity=COALESCE(?,opacity),
      importance=COALESCE(?,importance), node_type=COALESCE(?,node_type),
      sort_order=COALESCE(?,sort_order), metadata=COALESCE(?,metadata),
      updated_at=datetime('now')
      WHERE id=?`)
      .run(b.type, b.title,
           start_date, end_date ?? null,
           b.start_year ?? null, b.start_month ?? null, b.start_day ?? null, b.start_hour ?? null, b.start_minute ?? null,
           b.end_year ?? null, b.end_month ?? null, b.end_day ?? null, b.end_hour ?? null, b.end_minute ?? null,
           b.description, b.color, b.opacity ?? null, b.importance, b.node_type, b.sort_order, b.metadata, req.params.id);
    if (Object.prototype.hasOwnProperty.call(b, 'parent_id')) {
      const newParent = b.parent_id === '' ? null : (b.parent_id ?? null);
      req.db.prepare('UPDATE timeline_nodes SET parent_id=?, updated_at=datetime(\'now\') WHERE id=?')
        .run(newParent, req.params.id);
    }
    const node = getNode(req.db, req.params.id);
    if (!node) return res.status(404).json({ error: 'Node not found' });
    res.json(node);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.delete('/api/nodes/:id', handleDelete('timeline'), (req, res) => {
  try {
    if (req.deleteMode === 'review') {
      const node = getNode(req.db, req.params.id);
      if (!node) return res.status(404).json({ error: 'Node not found' });
      req.db.prepare(`INSERT OR IGNORE INTO pending_deletions
        (id, resource_type, resource_id, resource_title, requested_by)
        VALUES (?, 'node', ?, ?, ?)`)
        .run(generateUUID(), req.params.id, node.title, req.user.id);
      return res.json({ pending: true, message: 'Deletion submitted for review' });
    }
    // Also delete any entity bound to this node
    req.db.prepare('DELETE FROM entities WHERE node_id = ?').run(req.params.id);
    req.db.prepare('DELETE FROM timeline_nodes WHERE id = ?').run(req.params.id);
    res.json({ ok: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ── Settings ──────────────────────────────────────────────────────────────────

const JSON_SETTINGS = new Set(['calendar']);

app.get('/api/settings', (req, res) => {
  try {
    const rows = req.db.prepare('SELECT key, value FROM settings').all();
    const out = {};
    for (const r of rows) {
      if (r.value === null) { out[r.key] = null; continue; }
      if (JSON_SETTINGS.has(r.key)) { try { out[r.key] = JSON.parse(r.value); } catch { out[r.key] = null; } }
      else out[r.key] = Number(r.value);
    }
    const activeCal = req.db.prepare('SELECT id, name, config FROM calendars WHERE is_active = 1 LIMIT 1').get();
    if (activeCal) {
      out.active_calendar_id = activeCal.id;
      out.active_calendar_name = activeCal.name;
      try { out.calendar = JSON.parse(activeCal.config); } catch {}
    }
    res.json(out);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.put('/api/settings', requirePerm('settings', 'edit'), (req, res) => {
  try {
    const allowed = ['world_start', 'world_end', 'default_view_start', 'default_view_end', 'calendar'];
    const upsert = req.db.prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    const tx = req.db.transaction(body => {
      for (const key of allowed) {
        if (Object.prototype.hasOwnProperty.call(body, key)) {
          if (body[key] === null) { upsert.run(key, null); }
          else if (JSON_SETTINGS.has(key)) { upsert.run(key, JSON.stringify(body[key])); }
          else { upsert.run(key, String(body[key])); }
        }
      }
    });
    tx(req.body);
    res.json({ ok: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ── Calendars ────────────────────────────────────────────────────────────────

app.get('/api/calendars', (req, res) => {
  try {
    const rows = req.db.prepare('SELECT * FROM calendars ORDER BY name').all();
    for (const r of rows) { try { r.config = JSON.parse(r.config); } catch { r.config = {}; } }
    res.json(rows);
  } catch (err) { res.status(500).json({ error: err.message }); }
});

app.get('/api/calendars/:id', (req, res) => {
  try {
    const row = req.db.prepare('SELECT * FROM calendars WHERE id = ?').get(req.params.id);
    if (!row) return res.status(404).json({ error: 'Calendar not found' });
    try { row.config = JSON.parse(row.config); } catch { row.config = {}; }
    res.json(row);
  } catch (err) { res.status(500).json({ error: err.message }); }
});

app.post('/api/calendars', requirePerm('settings', 'edit'), (req, res) => {
  try {
    const { name, config, timeline_id } = req.body;
    if (!name) return res.status(400).json({ error: 'name is required' });
    const id = 'cal_' + generateUUID().replace(/-/g, '').substring(0, 12);
    req.db.prepare('INSERT INTO calendars (id, timeline_id, name, is_active, config) VALUES (?,?,?,0,?)')
      .run(id, timeline_id ?? 'default', name, JSON.stringify(config ?? DEFAULT_CALENDAR));
    const row = req.db.prepare('SELECT * FROM calendars WHERE id = ?').get(id);
    try { row.config = JSON.parse(row.config); } catch { row.config = {}; }
    res.status(201).json(row);
  } catch (err) { res.status(500).json({ error: err.message }); }
});

app.put('/api/calendars/:id', requirePerm('settings', 'edit'), (req, res) => {
  try {
    const { name, config } = req.body;
    const sets = [];
    const vals = [];
    if (name != null) { sets.push('name=?'); vals.push(name); }
    if (config != null) { sets.push('config=?'); vals.push(JSON.stringify(config)); }
    if (!sets.length) return res.status(400).json({ error: 'Nothing to update' });
    sets.push("updated_at=datetime('now')");
    vals.push(req.params.id);
    req.db.prepare(`UPDATE calendars SET ${sets.join(',')} WHERE id=?`).run(...vals);
    const row = req.db.prepare('SELECT * FROM calendars WHERE id = ?').get(req.params.id);
    if (!row) return res.status(404).json({ error: 'Calendar not found' });
    try { row.config = JSON.parse(row.config); } catch { row.config = {}; }
    if (row.is_active && config != null) {
      req.db.prepare("INSERT INTO settings (key, value) VALUES ('calendar', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")
        .run(JSON.stringify(config));
    }
    res.json(row);
  } catch (err) { res.status(500).json({ error: err.message }); }
});

app.delete('/api/calendars/:id', requirePerm('settings', 'edit'), (req, res) => {
  try {
    const row = req.db.prepare('SELECT is_active FROM calendars WHERE id = ?').get(req.params.id);
    if (row?.is_active) return res.status(400).json({ error: 'Cannot delete the active calendar' });
    req.db.prepare('DELETE FROM calendars WHERE id = ?').run(req.params.id);
    res.json({ ok: true });
  } catch (err) { res.status(500).json({ error: err.message }); }
});

app.put('/api/calendars/:id/activate', requirePerm('settings', 'edit'), (req, res) => {
  try {
    const cal = req.db.prepare('SELECT * FROM calendars WHERE id = ?').get(req.params.id);
    if (!cal) return res.status(404).json({ error: 'Calendar not found' });
    let config;
    try { config = JSON.parse(cal.config); } catch { return res.status(400).json({ error: 'Invalid calendar config' }); }
    if (!config?.months?.length) return res.status(400).json({ error: 'Calendar has no months' });

    const tx = req.db.transaction(() => {
      req.db.prepare('UPDATE calendars SET is_active = 0 WHERE timeline_id = ?').run(cal.timeline_id);
      req.db.prepare('UPDATE calendars SET is_active = 1 WHERE id = ?').run(cal.id);

      req.db.prepare("INSERT INTO settings (key, value) VALUES ('calendar', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")
        .run(cal.config);

      const mc = config.months.length;
      function clampMonth(m) { return m ? Math.min(m, mc) : m; }
      function clampDay(d, m) {
        if (!d || !m) return d;
        const maxD = config.months[m - 1]?.days ?? 30;
        return Math.min(d, maxD);
      }
      const nodes = req.db.prepare('SELECT id, start_year, start_month, start_day, start_hour, start_minute, end_year, end_month, end_day, end_hour, end_minute FROM timeline_nodes WHERE start_year IS NOT NULL').all();
      const update = req.db.prepare('UPDATE timeline_nodes SET start_date=?, end_date=?, start_month=?, start_day=?, end_month=?, end_day=? WHERE id=?');
      for (const n of nodes) {
        const sm = clampMonth(n.start_month);
        const sd_day = clampDay(n.start_day, sm);
        const sdVal = dateToDecimal(config, n.start_year, sm ?? 0, sd_day ?? 0, n.start_hour ?? 0, n.start_minute ?? 0);
        let edVal = null, em = null, ed_day = null;
        if (n.end_year != null) {
          em = clampMonth(n.end_month);
          ed_day = clampDay(n.end_day, em);
          edVal = dateToDecimal(config, n.end_year, em ?? 0, ed_day ?? 0, n.end_hour ?? 0, n.end_minute ?? 0);
        }
        update.run(sdVal, edVal, sm, sd_day, em, ed_day, n.id);
      }
    });
    tx();

    try { cal.config = JSON.parse(cal.config); } catch { cal.config = {}; }
    cal.is_active = 1;
    res.json(cal);
  } catch (err) { res.status(500).json({ error: err.message }); }
});

// ── Docs CRUD ─────────────────────────────────────────────────────────────────

app.get('/api/docs', (req, res) => {
  try {
    const { search, category, timeline_id } = req.query;
    const clauses = [], params = [];

    if (timeline_id) { clauses.push('d.timeline_id = ?'); params.push(timeline_id); }
    if (category)    { clauses.push('d.category = ?');    params.push(category); }
    if (search) {
      const s = `%${search.toLowerCase()}%`;
      clauses.push(`(
        LOWER(d.title) LIKE ? OR LOWER(d.content) LIKE ? OR
        EXISTS (SELECT 1 FROM document_tags dt WHERE dt.doc_id = d.id AND LOWER(dt.tag) LIKE ?)
      )`);
      params.push(s, s, s);
    }

    const where = clauses.length ? 'WHERE ' + clauses.join(' AND ') : '';
    const docs  = req.db.prepare(`SELECT * FROM documents d ${where} ORDER BY d.updated_at DESC`).all(params);

    const tagRows = req.db.prepare('SELECT doc_id, tag FROM document_tags').all();
    const tagMap  = {};
    for (const r of tagRows) (tagMap[r.doc_id] ??= []).push(r.tag);

    res.json({
      documents: docs.map(d => ({
        id:          d.id,
        timeline_id: d.timeline_id,
        title:       d.title,
        category:    d.category,
        tags:        tagMap[d.id] || [],
        updated_at:  d.updated_at,
        excerpt:     (d.content || '').replace(/[#*`]/g, '').slice(0, 120).trim(),
      })),
    });
  } catch (err) {
    res.status(500).json({ error: 'Failed to load documents', details: err.message });
  }
});

app.get('/api/docs/by-title/:title', (req, res) => {
  try {
    const doc = req.db.prepare('SELECT * FROM documents WHERE LOWER(title) = LOWER(?) LIMIT 1').get(req.params.title);
    if (!doc) return res.status(404).json({ error: 'Document not found' });
    const tags = req.db.prepare('SELECT tag FROM document_tags WHERE doc_id = ?').all(doc.id).map(r => r.tag);
    res.json({ ...doc, tags });
  } catch (err) {
    res.status(500).json({ error: 'Failed to look up document', details: err.message });
  }
});

app.get('/api/docs/:id', (req, res) => {
  try {
    const doc = req.db.prepare('SELECT * FROM documents WHERE id = ?').get(req.params.id);
    if (!doc) return res.status(404).json({ error: 'Document not found' });
    const tags = req.db.prepare('SELECT tag FROM document_tags WHERE doc_id = ?').all(req.params.id).map(r => r.tag);
    res.json({ ...doc, tags });
  } catch (err) {
    res.status(500).json({ error: 'Failed to load document', details: err.message });
  }
});

app.post('/api/docs', requirePerm('docs', 'edit'), (req, res) => {
  try {
    const now = new Date().toISOString();
    const id  = 'doc_' + generateUUID().replace(/-/g, '').substring(0, 8);
    const doc = {
      id,
      timeline_id: req.body.timeline_id || 'default',
      title:       req.body.title    || 'Untitled Document',
      category:    req.body.category || 'Other',
      tags:        req.body.tags     || [],
      content:     req.body.content  || '',
      created_at:  now,
      updated_at:  now,
    };

    req.db.transaction(() => {
      req.db.prepare('INSERT INTO documents (id, timeline_id, title, category, content, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        .run(doc.id, doc.timeline_id, doc.title, doc.category, doc.content, doc.created_at, doc.updated_at);
      for (const tag of doc.tags)
        req.db.prepare('INSERT OR IGNORE INTO document_tags (doc_id, tag) VALUES (?, ?)').run(doc.id, tag);
    })();

    res.status(201).json(doc);
  } catch (err) {
    res.status(500).json({ error: 'Failed to create document', details: err.message });
  }
});

app.put('/api/docs/:id', requirePerm('docs', 'edit'), (req, res) => {
  try {
    const existing = req.db.prepare('SELECT * FROM documents WHERE id = ?').get(req.params.id);
    if (!existing) return res.status(404).json({ error: 'Document not found' });

    const b      = req.body;
    const now    = new Date().toISOString();
    const merged = { ...existing, ...b, id: req.params.id, updated_at: now };

    req.db.transaction(() => {
      req.db.prepare('UPDATE documents SET title=?, category=?, content=?, updated_at=? WHERE id=?')
        .run(merged.title, merged.category, merged.content, now, req.params.id);
      if (b.tags !== undefined) {
        req.db.prepare('DELETE FROM document_tags WHERE doc_id = ?').run(req.params.id);
        for (const tag of b.tags)
          req.db.prepare('INSERT OR IGNORE INTO document_tags (doc_id, tag) VALUES (?, ?)').run(req.params.id, tag);
      }
    })();

    const tags = req.db.prepare('SELECT tag FROM document_tags WHERE doc_id = ?').all(req.params.id).map(r => r.tag);
    res.json({ ...merged, tags });
  } catch (err) {
    res.status(500).json({ error: 'Failed to update document', details: err.message });
  }
});

app.delete('/api/docs/:id', handleDelete('docs'), (req, res) => {
  try {
    const doc = req.db.prepare('SELECT * FROM documents WHERE id = ?').get(req.params.id);
    if (!doc) return res.status(404).json({ error: 'Document not found' });
    if (req.deleteMode === 'review') {
      req.db.prepare(`INSERT OR IGNORE INTO pending_deletions
        (id, resource_type, resource_id, resource_title, requested_by)
        VALUES (?, 'doc', ?, ?, ?)`)
        .run(generateUUID(), req.params.id, doc.title, req.user.id);
      return res.json({ pending: true, message: 'Deletion submitted for review' });
    }
    const tags = req.db.prepare('SELECT tag FROM document_tags WHERE doc_id = ?').all(req.params.id).map(r => r.tag);
    req.db.prepare('DELETE FROM documents WHERE id = ?').run(req.params.id);
    res.json({ message: 'Document deleted', document: { ...doc, tags } });
  } catch (err) {
    res.status(500).json({ error: 'Failed to delete document', details: err.message });
  }
});

// ── Mentions search (entities + events in one query) ─────────────────────────

app.get('/api/mentions/search', (req, res) => {
  try {
    const { q, limit } = req.query;
    if (!q) return res.json({ items: [] });
    const prefix = q.replace(/[%_]/g, '') + '%';   // prefix match — uses index
    const lim = Math.min(parseInt(limit, 10) || 10, 20);

    const thirdLim = Math.ceil(lim / 3);

    const entities = req.db.prepare(`
      SELECT id, name, entity_type AS type_label, color, 'entity' AS _kind
      FROM entities
      WHERE name LIKE ? COLLATE NOCASE
      ORDER BY name
      LIMIT ?
    `).all(prefix, thirdLim);

    const events = req.db.prepare(`
      SELECT id, title AS name, node_type AS type_label, color, 'event' AS _kind
      FROM timeline_nodes
      WHERE title LIKE ? COLLATE NOCASE
      ORDER BY title
      LIMIT ?
    `).all(prefix, thirdLim);

    const docs = req.db.prepare(`
      SELECT id, title AS name, category AS type_label, 'doc' AS _kind
      FROM documents
      WHERE title LIKE ? COLLATE NOCASE
      ORDER BY title
      LIMIT ?
    `).all(prefix, thirdLim);

    res.json({ items: [...entities, ...events, ...docs] });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ── Entities CRUD ────────────────────────────────────────────────────────────

app.get('/api/entities', (req, res) => {
  try {
    const { search, entity_type, timeline_id, limit } = req.query;
    const clauses = [], params = [];
    if (timeline_id)  { clauses.push('e.timeline_id = ?'); params.push(timeline_id); }
    if (entity_type)  { clauses.push('e.entity_type = ?'); params.push(entity_type); }
    if (search) {
      const s = `%${search.toLowerCase()}%`;
      clauses.push("(LOWER(e.name) LIKE ? OR LOWER(COALESCE(e.description,'')) LIKE ?)");
      params.push(s, s);
    }
    const where = clauses.length ? 'WHERE ' + clauses.join(' AND ') : '';
    const limitClause = limit ? 'LIMIT ?' : '';
    const orderBy = search
      ? `ORDER BY (CASE WHEN LOWER(e.name) LIKE ? THEN 0 ELSE 1 END), e.name`
      : 'ORDER BY e.name';
    if (search) params.push(`%${search.toLowerCase()}%`);
    if (limit) params.push(parseInt(limit, 10));
    const entities = req.db.prepare(`SELECT * FROM entities e ${where} ${orderBy} ${limitClause}`).all(params);

    // Attach linked node count
    const countStmt = req.db.prepare('SELECT COUNT(*) as c FROM entity_node_links WHERE entity_id = ?');
    for (const e of entities) {
      e.link_count = countStmt.get(e.id).c;
      try { e.metadata = JSON.parse(e.metadata); } catch { e.metadata = {}; }
    }
    res.json({ entities });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.get('/api/entities/:id', (req, res) => {
  try {
    const entity = req.db.prepare('SELECT * FROM entities WHERE id = ?').get(req.params.id);
    if (!entity) return res.status(404).json({ error: 'Entity not found' });
    try { entity.metadata = JSON.parse(entity.metadata); } catch { entity.metadata = {}; }

    // Attach bound node data (1:1 timeline presence)
    if (entity.node_id) {
      entity.node = req.db.prepare('SELECT * FROM timeline_nodes WHERE id = ?').get(entity.node_id) || null;
    }

    // Attach linked nodes (excluding the bound node)
    entity.linked_nodes = req.db.prepare(`
      SELECT n.id, n.title, n.type, n.node_type, n.start_date, n.color, l.role
      FROM entity_node_links l
      JOIN timeline_nodes n ON n.id = l.node_id
      WHERE l.entity_id = ? AND n.id != COALESCE(?, '')
      ORDER BY n.start_date`).all(req.params.id, entity.node_id);

    res.json(entity);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.get('/api/entities/by-name/:name', (req, res) => {
  try {
    const entity = req.db.prepare('SELECT * FROM entities WHERE name = ? COLLATE NOCASE').get(req.params.name);
    if (!entity) return res.status(404).json({ error: 'Entity not found' });
    try { entity.metadata = JSON.parse(entity.metadata); } catch { entity.metadata = {}; }
    if (entity.node_id) {
      entity.node = req.db.prepare('SELECT * FROM timeline_nodes WHERE id = ?').get(entity.node_id) || null;
    }
    entity.linked_nodes = req.db.prepare(`
      SELECT n.id, n.title, n.type, n.node_type, n.start_date, n.color, l.role
      FROM entity_node_links l
      JOIN timeline_nodes n ON n.id = l.node_id
      WHERE l.entity_id = ? AND n.id != COALESCE(?, '')
      ORDER BY n.start_date`).all(entity.id, entity.node_id);
    res.json(entity);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.post('/api/entities', requirePerm('entities', 'edit'), (req, res) => {
  try {
    const b = req.body;
    if (!b.name) return res.status(400).json({ error: 'name is required' });
    const id = 'ent_' + generateUUID().replace(/-/g, '').substring(0, 8);
    req.db.prepare(`INSERT INTO entities (id, timeline_id, name, entity_type, description, color, metadata, node_id)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)`).run(
      id, b.timeline_id || 'default', b.name, b.entity_type || 'character',
      b.description || null, b.color || '#7c6bff', JSON.stringify(b.metadata || {}),
      b.node_id || null);
    const entity = req.db.prepare('SELECT * FROM entities WHERE id = ?').get(id);
    try { entity.metadata = JSON.parse(entity.metadata); } catch { entity.metadata = {}; }
    res.status(201).json(entity);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.put('/api/entities/:id', requirePerm('entities', 'edit'), (req, res) => {
  try {
    const b = req.body;
    req.db.prepare(`UPDATE entities SET
      name=COALESCE(?,name), entity_type=COALESCE(?,entity_type),
      description=COALESCE(?,description), color=COALESCE(?,color),
      metadata=COALESCE(?,metadata), updated_at=datetime('now')
      WHERE id=?`).run(
      b.name, b.entity_type, b.description, b.color,
      b.metadata != null ? JSON.stringify(b.metadata) : null, req.params.id);
    const entity = req.db.prepare('SELECT * FROM entities WHERE id = ?').get(req.params.id);
    if (!entity) return res.status(404).json({ error: 'Entity not found' });
    try { entity.metadata = JSON.parse(entity.metadata); } catch { entity.metadata = {}; }
    res.json(entity);
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.delete('/api/entities/:id', handleDelete('entities'), (req, res) => {
  try {
    const ent = req.db.prepare('SELECT id, name, node_id FROM entities WHERE id = ?').get(req.params.id);
    if (!ent) return res.status(404).json({ error: 'Entity not found' });
    if (req.deleteMode === 'review') {
      req.db.prepare(`INSERT OR IGNORE INTO pending_deletions
        (id, resource_type, resource_id, resource_title, requested_by)
        VALUES (?, 'entity', ?, ?, ?)`)
        .run(generateUUID(), req.params.id, ent.name, req.user.id);
      return res.json({ pending: true, message: 'Deletion submitted for review' });
    }
    // Also delete the bound timeline node if this entity has one
    if (ent.node_id) {
      req.db.prepare('DELETE FROM timeline_nodes WHERE id = ?').run(ent.node_id);
    }
    req.db.prepare('DELETE FROM entities WHERE id = ?').run(req.params.id);
    res.json({ ok: true, deleted_node_id: ent.node_id || null });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// Entity ↔ Node linking

app.post('/api/entities/:id/links', requirePerm('entities', 'edit'), (req, res) => {
  try {
    const { node_id, role } = req.body;
    if (!node_id) return res.status(400).json({ error: 'node_id is required' });
    req.db.prepare('INSERT OR IGNORE INTO entity_node_links (entity_id, node_id, role) VALUES (?, ?, ?)')
      .run(req.params.id, node_id, role || '');
    res.status(201).json({ ok: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.delete('/api/entities/:id/links/:nodeId', handleDelete('entities'), (req, res) => {
  try {
    if (req.deleteMode === 'review') {
      const node = getNode(req.db, req.params.nodeId);
      req.db.prepare(`INSERT OR IGNORE INTO pending_deletions
        (id, resource_type, resource_id, resource_title, requested_by)
        VALUES (?, 'entity_link', ?, ?, ?)`)
        .run(generateUUID(), `${req.params.id}:${req.params.nodeId}`,
             node?.title || req.params.nodeId, req.user.id);
      return res.json({ pending: true, message: 'Deletion submitted for review' });
    }
    req.db.prepare('DELETE FROM entity_node_links WHERE entity_id = ? AND node_id = ?')
      .run(req.params.id, req.params.nodeId);
    res.json({ ok: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ── Pending Deletions ────────────────────────────────────────────────────────

const { getAccountsDb } = require('../db/connection');
const { findUserById } = require('../db/auth');

app.get('/api/pending-deletions', (req, res) => {
  try {
    const rows = req.db.prepare('SELECT * FROM pending_deletions ORDER BY requested_at DESC').all();
    // If viewing shared timeline, only show own requests
    const filtered = req.timelineOwner
      ? rows.filter(r => r.requested_by === req.user.id)
      : rows;
    // Attach requester info for the owner
    const accountsDb = getAccountsDb();
    const results = filtered.map(r => {
      const user = findUserById(accountsDb, r.requested_by);
      return { ...r, requested_by_username: user?.username || 'unknown' };
    });
    res.json({ pending_deletions: results });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.get('/api/pending-deletions/count', (req, res) => {
  try {
    // Only the timeline owner sees the full count
    if (req.timelineOwner) return res.json({ count: 0 });
    const row = req.db.prepare('SELECT COUNT(*) as count FROM pending_deletions').get();
    res.json({ count: row.count });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.post('/api/pending-deletions/:id/approve', (req, res) => {
  try {
    if (req.timelineOwner) {
      return res.status(403).json({ error: 'Only the timeline owner can approve deletions' });
    }
    const pd = req.db.prepare('SELECT * FROM pending_deletions WHERE id = ?').get(req.params.id);
    if (!pd) return res.status(404).json({ error: 'Pending deletion not found' });

    req.db.transaction(() => {
      // Execute the actual delete based on resource type
      switch (pd.resource_type) {
        case 'node':
          req.db.prepare('DELETE FROM entities WHERE node_id = ?').run(pd.resource_id);
          req.db.prepare('DELETE FROM timeline_nodes WHERE id = ?').run(pd.resource_id);
          break;
        case 'doc':
          req.db.prepare('DELETE FROM documents WHERE id = ?').run(pd.resource_id);
          break;
        case 'entity':
          {
            const ent = req.db.prepare('SELECT node_id FROM entities WHERE id = ?').get(pd.resource_id);
            if (ent?.node_id) req.db.prepare('DELETE FROM timeline_nodes WHERE id = ?').run(ent.node_id);
            req.db.prepare('DELETE FROM entities WHERE id = ?').run(pd.resource_id);
          }
          break;
        case 'entity_link':
          {
            const [entityId, nodeId] = pd.resource_id.split(':');
            req.db.prepare('DELETE FROM entity_node_links WHERE entity_id = ? AND node_id = ?')
              .run(entityId, nodeId);
          }
          break;
      }
      req.db.prepare('DELETE FROM pending_deletions WHERE id = ?').run(pd.id);
    })();

    res.json({ ok: true, deleted: pd.resource_type, resource_id: pd.resource_id });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

app.post('/api/pending-deletions/:id/reject', (req, res) => {
  try {
    if (req.timelineOwner) {
      return res.status(403).json({ error: 'Only the timeline owner can reject deletions' });
    }
    const pd = req.db.prepare('SELECT * FROM pending_deletions WHERE id = ?').get(req.params.id);
    if (!pd) return res.status(404).json({ error: 'Pending deletion not found' });
    req.db.prepare('DELETE FROM pending_deletions WHERE id = ?').run(pd.id);
    res.json({ ok: true });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// ── Serve frontend ────────────────────────────────────────────────────────────

app.get('/{*path}', (req, res) => {
  res.sendFile(path.join(__dirname, '../public/index.html'));
});

const PORT = process.env.PORT || 3000;
const server = app.listen(PORT, () => {
  console.log(`Timeline API running on http://localhost:${PORT}`);
});
server.on('error', (err) => {
  if (err.code === 'EADDRINUSE') {
    console.error(`Port ${PORT} is already in use. Kill the other process or use PORT=<number> to pick a different port.`);
  } else {
    console.error('Server error:', err);
  }
  process.exit(1);
});

module.exports = app;
