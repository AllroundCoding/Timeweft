'use strict';
const bcrypt = require('bcryptjs');
const jwt    = require('jsonwebtoken');
const crypto = require('crypto');
const { getAccountsDb } = require('../db/connection');
const { getGlobalSetting, setGlobalSetting } = require('../db/auth');

const SALT_ROUNDS = 10;
const JWT_EXPIRY  = '24h';

// ── JWT Secret (auto-generated, persisted in global_settings) ───────────────

function getJwtSecret() {
  const db = getAccountsDb();
  let secret = getGlobalSetting(db, 'jwt_secret');
  if (!secret) {
    secret = crypto.randomBytes(64).toString('hex');
    setGlobalSetting(db, 'jwt_secret', secret);
  }
  return secret;
}

// ── Password hashing ────────────────────────────────────────────────────────

function hashPassword(plain) {
  return bcrypt.hashSync(plain, SALT_ROUNDS);
}

function verifyPassword(plain, hash) {
  return bcrypt.compareSync(plain, hash);
}

// ── JWT ──────────────────────────────────────────────────────────────────────

function generateJwt(userId, role) {
  return jwt.sign({ userId, role }, getJwtSecret(), { expiresIn: JWT_EXPIRY });
}

function verifyJwt(token) {
  return jwt.verify(token, getJwtSecret());
}

// ── API Keys ─────────────────────────────────────────────────────────────────

function generateApiKey() {
  const raw = crypto.randomBytes(32).toString('hex');
  const fullKey = `tl_${raw}`;
  const hash = hashApiKey(fullKey);
  return { fullKey, hash };
}

function hashApiKey(key) {
  return crypto.createHash('sha256').update(key).digest('hex');
}

module.exports = {
  hashPassword,
  verifyPassword,
  generateJwt,
  verifyJwt,
  generateApiKey,
  hashApiKey,
};
