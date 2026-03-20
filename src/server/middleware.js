'use strict';
const { verifyJwt, hashApiKey } = require('./auth');
const { getAccountsDb, getTimelineDb } = require('../db/connection');
const { findUserById, findByApiKeyHash, touchApiKeyUsage,
        getShare, getTimeline, getDefaultTimeline } = require('../db/auth');

// ── Authentication middleware ───────────────────────────────────────────────
// Accepts JWT (Authorization: Bearer <jwt>) or API key (Authorization: Bearer tl_...)
// Sets req.user = { id, username, role } and req.db = timeline DB
// Resolves timeline via X-Timeline-Id header (own or shared)

function authenticate(req, res, next) {
  const accountsDb = getAccountsDb();

  // Extract token from Authorization header
  const authHeader = req.headers.authorization;
  let token = null;
  if (authHeader && authHeader.startsWith('Bearer ')) {
    token = authHeader.slice(7);
  }

  // Fallback: X-API-Key header
  if (!token && req.headers['x-api-key']) {
    token = req.headers['x-api-key'];
  }

  if (!token) {
    return res.status(401).json({ error: 'Authentication required' });
  }

  // API key (starts with tl_)
  if (token.startsWith('tl_')) {
    const keyHash = hashApiKey(token);
    const keyRow = findByApiKeyHash(accountsDb, keyHash);
    if (!keyRow) {
      return res.status(401).json({ error: 'Invalid or revoked API key' });
    }
    if (!keyRow.user_is_active) {
      return res.status(403).json({ error: 'Account is disabled' });
    }
    touchApiKeyUsage(accountsDb, keyHash);
    req.user = { id: keyRow.user_id, role: keyRow.role };
    return _resolveTimeline(req, res, next);
  }

  // JWT
  try {
    const payload = verifyJwt(token);
    const user = findUserById(accountsDb, payload.userId);
    if (!user) {
      return res.status(401).json({ error: 'User not found' });
    }
    if (!user.is_active) {
      return res.status(403).json({ error: 'Account is disabled' });
    }
    req.user = { id: user.id, username: user.username, role: user.role };
    return _resolveTimeline(req, res, next);
  } catch (err) {
    return res.status(401).json({ error: 'Invalid or expired token' });
  }
}

// ── Resolve X-Timeline-Id header for timeline + shared access ───────────────

function _resolveTimeline(req, res, next) {
  const accountsDb = getAccountsDb();
  const timelineId = req.headers['x-timeline-id'];

  if (!timelineId) {
    // No header — use user's default timeline
    const tl = getDefaultTimeline(accountsDb, req.user.id);
    if (!tl) {
      // User has no timelines yet (e.g. during registration flow)
      req.timelineOwner = null;
      req.sharePerms = null;
      req.db = null;
      return next();
    }
    req.timelineId = tl.id;
    req.db = getTimelineDb(req.user.id, tl.id);
    req.timelineOwner = null;
    req.sharePerms = null;
    return next();
  }

  // Header present — look up the timeline
  const tl = getTimeline(accountsDb, timelineId);
  if (!tl) {
    return res.status(404).json({ error: 'Timeline not found' });
  }

  if (tl.owner_id === req.user.id) {
    // Own timeline
    req.timelineId = tl.id;
    req.db = getTimelineDb(req.user.id, tl.id);
    req.timelineOwner = null;
    req.sharePerms = null;
    return next();
  }

  // Shared timeline — check permissions
  const share = getShare(accountsDb, tl.id, req.user.id);
  if (!share) {
    return res.status(403).json({ error: 'You do not have access to this timeline' });
  }

  req.timelineId = tl.id;
  req.db = getTimelineDb(tl.owner_id, tl.id);
  req.timelineOwner = tl.owner_id;
  req.sharePerms = {
    timeline: share.perm_timeline,
    docs:     share.perm_docs,
    entities: share.perm_entities,
    settings: share.perm_settings,
  };
  return next();
}

// ── Admin check middleware ──────────────────────────────────────────────────

function requireAdmin(req, res, next) {
  if (!req.user || req.user.role !== 'admin') {
    return res.status(403).json({ error: 'Admin access required' });
  }
  next();
}

// ── Permission enforcement middleware ────────────────────────────────────────

const PERM_LEVELS = { read: 0, edit: 1, review: 2, delete: 3 };

// Require at least `minLevel` permission on `area` for non-delete mutations.
// No-op when accessing own timeline.
function requirePerm(area, minLevel) {
  return (req, res, next) => {
    if (!req.timelineOwner) return next();
    const userLevel = PERM_LEVELS[req.sharePerms[area]] ?? 0;
    const required  = PERM_LEVELS[minLevel] ?? 0;
    if (userLevel < required) {
      return res.status(403).json({ error: `Requires '${minLevel}' permission for ${area}` });
    }
    next();
  };
}

// For delete routes: allows if perm is 'delete'; sets req.deleteMode='review'
// if perm is 'review'; otherwise 403.
function handleDelete(area) {
  return (req, res, next) => {
    if (!req.timelineOwner) return next();
    const perm = req.sharePerms[area] || 'read';
    if (perm === 'delete') return next();
    if (perm === 'review') {
      req.deleteMode = 'review';
      return next();
    }
    return res.status(403).json({ error: `Cannot delete ${area} items on this timeline` });
  };
}

module.exports = { authenticate, requireAdmin, requirePerm, handleDelete };
