#!/usr/bin/env node
/**
 * Timeline MCP Server
 * Exposes timeline read/write tools so Claude (or any LLM) can interact with
 * the recursive timeline node tree — add/query/update/delete nodes,
 * explore the tree structure, and search across all content.
 *
 * Run standalone: node mcp/server.js
 * Or add to Claude Desktop config (see README.md)
 */
'use strict';

const { Server }               = require('@modelcontextprotocol/sdk/server/index.js');
const { StdioServerTransport } = require('@modelcontextprotocol/sdk/server/stdio.js');
const { CallToolRequestSchema, ListToolsRequestSchema } = require('@modelcontextprotocol/sdk/types.js');
const { randomUUID: generateUUID } = require('crypto');

const {
  getAccountsDb,
  getTimelineDb,
  getNodes,
  getNode,
  getDescendants,
  searchNodes,
} = require('../db/connection');
const { hashApiKey } = require('../server/auth');
const { findByApiKeyHash, getDefaultTimeline, getTimeline } = require('../db/auth');
const { dateToDecimal, DEFAULT_CALENDAR } = require('../server/calendar');

// ── Authenticate via API key ─────────────────────────────────────────────────
const apiKey = process.env.TIMELINE_API_KEY;
if (!apiKey) {
  process.stderr.write('Error: TIMELINE_API_KEY environment variable is required.\n');
  process.stderr.write('Create an API key in the timeline web UI, then set it in your MCP config.\n');
  process.exit(1);
}

const accountsDb = getAccountsDb();
const keyHash = hashApiKey(apiKey);
const keyRow = findByApiKeyHash(accountsDb, keyHash);
if (!keyRow) {
  process.stderr.write('Error: Invalid or revoked API key.\n');
  process.exit(1);
}
if (!keyRow.user_is_active) {
  process.stderr.write('Error: User account is disabled.\n');
  process.exit(1);
}

// Resolve timeline: explicit TIMELINE_ID env var, or default to first timeline
const explicitTimelineId = process.env.TIMELINE_ID;
let resolvedTimeline;
if (explicitTimelineId) {
  resolvedTimeline = getTimeline(accountsDb, explicitTimelineId);
  if (!resolvedTimeline) {
    process.stderr.write(`Error: Timeline ${explicitTimelineId} not found.\n`);
    process.exit(1);
  }
  if (resolvedTimeline.owner_id !== keyRow.user_id) {
    process.stderr.write(`Error: Timeline ${explicitTimelineId} does not belong to this user.\n`);
    process.exit(1);
  }
} else {
  resolvedTimeline = getDefaultTimeline(accountsDb, keyRow.user_id);
  if (!resolvedTimeline) {
    process.stderr.write('Error: No timelines found for this user.\n');
    process.exit(1);
  }
}

const db = getTimelineDb(keyRow.user_id, resolvedTimeline.id);
process.stderr.write(`MCP server authenticated as user ${keyRow.user_id}, timeline "${resolvedTimeline.name}" (${resolvedTimeline.id})\n`);

// ── Helpers ───────────────────────────────────────────────────────────────────

function getActiveCalendar() {
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

const IMP_ICON = { critical: '!!', major: '!', moderate: '', minor: '~' };

function formatNode(node, indent = '') {
  const icon = node.type === 'span' ? '[===]' : '  *  ';
  const imp = IMP_ICON[node.importance] || '';
  const dateRange = node.end_date != null
    ? `${node.start_date} to ${node.end_date}`
    : `${node.start_date}`;

  let out = `${indent}${icon} **${node.title}**${imp ? ' ' + imp : ''} (${dateRange})\n`;
  out += `${indent}     ID: \`${node.id}\` | type: ${node.type} | node_type: ${node.node_type} | importance: ${node.importance}\n`;
  if (node.description) out += `${indent}     ${node.description}\n`;
  return out;
}

function formatNodeBrief(node) {
  const icon = node.type === 'span' ? '[===]' : '  *  ';
  return `${icon} **${node.title}** (\`${node.id}\`, ${node.start_date}${node.end_date != null ? ' to ' + node.end_date : ''})`;
}

// ── Tool definitions ─────────────────────────────────────────────────────────

const TOOL_DEFINITIONS = [
  {
    name: 'list_nodes',
    description: 'List direct children of a parent node. Pass parent_id=null (or omit) to list root-level nodes. Returns the immediate children sorted by start_date.',
    inputSchema: {
      type: 'object',
      properties: {
        parent_id: { type: ['string', 'null'], description: 'Parent node ID. Null or omitted for root-level nodes.' },
      },
    },
  },
  {
    name: 'get_node',
    description: 'Get full details of a single timeline node by its ID.',
    inputSchema: {
      type: 'object',
      properties: { node_id: { type: 'string', description: 'The node ID' } },
      required: ['node_id'],
    },
  },
  {
    name: 'get_tree',
    description: 'Get a node and all its descendants as a nested tree. Useful for understanding the full structure beneath a span.',
    inputSchema: {
      type: 'object',
      properties: { node_id: { type: 'string', description: 'Root node ID to expand' } },
      required: ['node_id'],
    },
  },
  {
    name: 'search_nodes',
    description: 'Search timeline nodes by text (title/description) and optional filters (type, node_type, importance, date range). Returns matching nodes across the entire tree.',
    inputSchema: {
      type: 'object',
      properties: {
        search:     { type: 'string',  description: 'Free text search across titles and descriptions' },
        type:       { type: 'string',  description: 'Filter by node type: "point" or "span"' },
        node_type:  { type: 'string',  description: 'Filter by node_type (event, milestone, conflict, disaster, cultural, legend, religious, region, kingdom, character, etc.)' },
        importance: { type: 'string',  description: 'Filter by importance: critical, major, moderate, minor' },
        date_min:   { type: 'number',  description: 'Minimum start_date (decimal year)' },
        date_max:   { type: 'number',  description: 'Maximum start_date (decimal year)' },
      },
    },
  },
  {
    name: 'add_node',
    description: 'Add a new node to the timeline tree. Use type "span" for time ranges (eras, periods, lifetimes) and "point" for discrete events. Nest nodes by setting parent_id to an existing span node.',
    inputSchema: {
      type: 'object',
      properties: {
        parent_id:    { type: ['string', 'null'], description: 'Parent node ID, or null for root level' },
        type:         { type: 'string',  description: '"point" (default) or "span"' },
        title:        { type: 'string',  description: 'Title for the node' },
        start_year:   { type: 'number',  description: 'Start year (required). Uses the active calendar to compute the decimal date.' },
        start_month:  { type: 'number',  description: 'Start month (optional, 1-based)' },
        start_day:    { type: 'number',  description: 'Start day (optional)' },
        end_year:     { type: 'number',  description: 'End year (required for spans)' },
        end_month:    { type: 'number',  description: 'End month (optional)' },
        end_day:      { type: 'number',  description: 'End day (optional)' },
        description:  { type: 'string',  description: 'Description of the node' },
        node_type:    { type: 'string',  description: 'Semantic type: event, milestone, conflict, disaster, cultural, legend, religious, region, kingdom, character, etc.' },
        importance:   { type: 'string',  description: 'critical, major, moderate, or minor' },
        color:        { type: 'string',  description: 'Hex color (e.g. "#7c6bff")' },
      },
      required: ['title', 'start_year'],
    },
  },
  {
    name: 'update_node',
    description: 'Update an existing timeline node. Only provided fields are changed.',
    inputSchema: {
      type: 'object',
      properties: {
        node_id:      { type: 'string' },
        title:        { type: 'string' },
        type:         { type: 'string',  description: '"point" or "span"' },
        start_year:   { type: 'number' },
        start_month:  { type: 'number' },
        start_day:    { type: 'number' },
        end_year:     { type: 'number' },
        end_month:    { type: 'number' },
        end_day:      { type: 'number' },
        description:  { type: 'string' },
        node_type:    { type: 'string' },
        importance:   { type: 'string' },
        color:        { type: 'string' },
        parent_id:    { type: ['string', 'null'], description: 'Move node to a new parent (null for root)' },
      },
      required: ['node_id'],
    },
  },
  {
    name: 'delete_node',
    description: 'Delete a node and all its descendants permanently.',
    inputSchema: {
      type: 'object',
      properties: {
        node_id: { type: 'string', description: 'ID of the node to delete' },
        confirm: { type: 'boolean', description: 'Must be true to confirm deletion' },
      },
      required: ['node_id', 'confirm'],
    },
  },
  {
    name: 'move_node',
    description: 'Move a node to a different parent (or to root level). Children move with it.',
    inputSchema: {
      type: 'object',
      properties: {
        node_id:   { type: 'string', description: 'Node to move' },
        parent_id: { type: ['string', 'null'], description: 'New parent ID, or null for root' },
      },
      required: ['node_id'],
    },
  },
  {
    name: 'get_stats',
    description: 'Get an overview of the timeline tree — total nodes, counts by type/node_type/importance, date range, and tree depth.',
    inputSchema: { type: 'object', properties: {} },
  },

  // ── Settings ──────────────────────────────────────────────────────────────
  {
    name: 'get_settings',
    description: 'Get all timeline settings (world bounds, default view, active calendar info).',
    inputSchema: { type: 'object', properties: {} },
  },
  {
    name: 'update_settings',
    description: 'Update timeline settings. Allowed keys: world_start, world_end, default_view_start, default_view_end, calendar.',
    inputSchema: {
      type: 'object',
      properties: {
        world_start:        { type: 'number',  description: 'World start year (how far back the timeline goes)' },
        world_end:          { type: 'number',  description: 'World end year' },
        default_view_start: { type: ['number', 'null'], description: 'Default view start year (null = fit to data)' },
        default_view_end:   { type: ['number', 'null'], description: 'Default view end year (null = fit to data)' },
      },
    },
  },

  // ── Calendars ─────────────────────────────────────────────────────────────
  {
    name: 'list_calendars',
    description: 'List all calendars.',
    inputSchema: { type: 'object', properties: {} },
  },
  {
    name: 'get_calendar',
    description: 'Get a single calendar by ID.',
    inputSchema: {
      type: 'object',
      properties: { calendar_id: { type: 'string' } },
      required: ['calendar_id'],
    },
  },
  {
    name: 'create_calendar',
    description: 'Create a new calendar with a name and optional config (months, hours_per_day, etc.).',
    inputSchema: {
      type: 'object',
      properties: {
        name:        { type: 'string',  description: 'Calendar name' },
        config:      { type: 'object',  description: 'Calendar config (months array, hours_per_day, etc.). Defaults to Gregorian.' },
        timeline_id: { type: 'string',  description: 'Timeline ID (default: "default")' },
      },
      required: ['name'],
    },
  },
  {
    name: 'update_calendar',
    description: 'Update an existing calendar name and/or config.',
    inputSchema: {
      type: 'object',
      properties: {
        calendar_id: { type: 'string' },
        name:        { type: 'string' },
        config:      { type: 'object', description: 'Calendar config object' },
      },
      required: ['calendar_id'],
    },
  },
  {
    name: 'delete_calendar',
    description: 'Delete a calendar. Cannot delete the active calendar.',
    inputSchema: {
      type: 'object',
      properties: { calendar_id: { type: 'string' } },
      required: ['calendar_id'],
    },
  },
  {
    name: 'activate_calendar',
    description: 'Set a calendar as the active one. Recalculates all node dates to match the new calendar.',
    inputSchema: {
      type: 'object',
      properties: { calendar_id: { type: 'string' } },
      required: ['calendar_id'],
    },
  },

  // ── Documents ─────────────────────────────────────────────────────────────
  {
    name: 'list_docs',
    description: 'List documents, optionally filtering by search text or category.',
    inputSchema: {
      type: 'object',
      properties: {
        search:   { type: 'string', description: 'Search text (matches title, content, and tags)' },
        category: { type: 'string', description: 'Filter by category' },
      },
    },
  },
  {
    name: 'get_doc',
    description: 'Get a single document by ID, including its full content and tags.',
    inputSchema: {
      type: 'object',
      properties: { doc_id: { type: 'string' } },
      required: ['doc_id'],
    },
  },
  {
    name: 'create_doc',
    description: 'Create a new document.',
    inputSchema: {
      type: 'object',
      properties: {
        title:    { type: 'string',  description: 'Document title' },
        category: { type: 'string',  description: 'Category (Overview, People, Places, Factions, Events, Rules, Other)' },
        content:  { type: 'string',  description: 'Document content (markdown)' },
        tags:     { type: 'array', items: { type: 'string' }, description: 'Tags' },
      },
      required: ['title'],
    },
  },
  {
    name: 'update_doc',
    description: 'Update an existing document. Only provided fields are changed.',
    inputSchema: {
      type: 'object',
      properties: {
        doc_id:   { type: 'string' },
        title:    { type: 'string' },
        category: { type: 'string' },
        content:  { type: 'string' },
        tags:     { type: 'array', items: { type: 'string' } },
      },
      required: ['doc_id'],
    },
  },
  {
    name: 'delete_doc',
    description: 'Delete a document permanently.',
    inputSchema: {
      type: 'object',
      properties: {
        doc_id:  { type: 'string' },
        confirm: { type: 'boolean', description: 'Must be true to confirm deletion' },
      },
      required: ['doc_id', 'confirm'],
    },
  },

  // ── Entities ──────────────────────────────────────────────────────────────
  {
    name: 'list_entities',
    description: 'List entities, optionally filtering by search text or entity_type.',
    inputSchema: {
      type: 'object',
      properties: {
        search:      { type: 'string', description: 'Search text (name/description)' },
        entity_type: { type: 'string', description: 'Filter by type: character, faction, location, item, concept, species, other' },
      },
    },
  },
  {
    name: 'get_entity',
    description: 'Get a single entity by ID, including its bound timeline node and linked nodes.',
    inputSchema: {
      type: 'object',
      properties: { entity_id: { type: 'string' } },
      required: ['entity_id'],
    },
  },
  {
    name: 'create_entity',
    description: 'Create a new entity (character, faction, location, etc.).',
    inputSchema: {
      type: 'object',
      properties: {
        name:        { type: 'string',  description: 'Entity name' },
        entity_type: { type: 'string',  description: 'Type: character, faction, location, item, concept, species, other' },
        description: { type: 'string',  description: 'Description' },
        color:       { type: 'string',  description: 'Hex color' },
        node_id:     { type: ['string', 'null'], description: 'Bind to an existing timeline node (1:1)' },
      },
      required: ['name'],
    },
  },
  {
    name: 'update_entity',
    description: 'Update an existing entity. Only provided fields are changed.',
    inputSchema: {
      type: 'object',
      properties: {
        entity_id:   { type: 'string' },
        name:        { type: 'string' },
        entity_type: { type: 'string' },
        description: { type: 'string' },
        color:       { type: 'string' },
      },
      required: ['entity_id'],
    },
  },
  {
    name: 'delete_entity',
    description: 'Delete an entity and its bound timeline node (if any).',
    inputSchema: {
      type: 'object',
      properties: {
        entity_id: { type: 'string' },
        confirm:   { type: 'boolean', description: 'Must be true to confirm deletion' },
      },
      required: ['entity_id', 'confirm'],
    },
  },

  // ── Entity-Node Links ─────────────────────────────────────────────────────
  {
    name: 'link_entity_node',
    description: 'Link an entity to a timeline node (many-to-many relationship, separate from the 1:1 bound node).',
    inputSchema: {
      type: 'object',
      properties: {
        entity_id: { type: 'string' },
        node_id:   { type: 'string' },
        role:      { type: 'string', description: 'Role/relationship label (optional)' },
      },
      required: ['entity_id', 'node_id'],
    },
  },
  {
    name: 'unlink_entity_node',
    description: 'Remove a link between an entity and a timeline node.',
    inputSchema: {
      type: 'object',
      properties: {
        entity_id: { type: 'string' },
        node_id:   { type: 'string' },
      },
      required: ['entity_id', 'node_id'],
    },
  },

  // ── Entity-Doc Links ────────────────────────────────────────────────────
  {
    name: 'link_entity_doc',
    description: 'Link an entity to a document (many-to-many cross-reference).',
    inputSchema: {
      type: 'object',
      properties: {
        entity_id: { type: 'string' },
        doc_id:    { type: 'string' },
        role:      { type: 'string', description: 'Role/relationship label (optional)' },
      },
      required: ['entity_id', 'doc_id'],
    },
  },
  {
    name: 'unlink_entity_doc',
    description: 'Remove a link between an entity and a document.',
    inputSchema: {
      type: 'object',
      properties: {
        entity_id: { type: 'string' },
        doc_id:    { type: 'string' },
      },
      required: ['entity_id', 'doc_id'],
    },
  },

  // ── Doc-Node Links ─────────────────────────────────────────────────────
  {
    name: 'link_doc_node',
    description: 'Link a document to a timeline node (many-to-many cross-reference).',
    inputSchema: {
      type: 'object',
      properties: {
        doc_id:  { type: 'string' },
        node_id: { type: 'string' },
        role:    { type: 'string', description: 'Role/relationship label (optional)' },
      },
      required: ['doc_id', 'node_id'],
    },
  },
  {
    name: 'unlink_doc_node',
    description: 'Remove a link between a document and a timeline node.',
    inputSchema: {
      type: 'object',
      properties: {
        doc_id:  { type: 'string' },
        node_id: { type: 'string' },
      },
      required: ['doc_id', 'node_id'],
    },
  },

  // ── Backlinks ──────────────────────────────────────────────────────────
  {
    name: 'get_backlinks',
    description: 'Get all cross-references pointing to or from a given entity, document, or node.',
    inputSchema: {
      type: 'object',
      properties: {
        type: { type: 'string', enum: ['entity', 'doc', 'node'], description: 'Resource type' },
        id:   { type: 'string', description: 'Resource ID' },
      },
      required: ['type', 'id'],
    },
  },

  // ── Folders ────────────────────────────────────────────────────────────
  {
    name: 'list_folders',
    description: 'List all folders, optionally filtered by type (docs or entities).',
    inputSchema: {
      type: 'object',
      properties: {
        type: { type: 'string', enum: ['docs', 'entities'], description: 'Filter by folder type' },
      },
    },
  },
  {
    name: 'create_folder',
    description: 'Create a new folder for organizing documents or entities.',
    inputSchema: {
      type: 'object',
      properties: {
        name:        { type: 'string' },
        folder_type: { type: 'string', enum: ['docs', 'entities'] },
        parent_id:   { type: 'string', description: 'Parent folder ID for nesting (optional)' },
        color:       { type: 'string', description: 'Hex color (optional)' },
      },
      required: ['name', 'folder_type'],
    },
  },

  // ── Entity Relationships ─────────────────────────────────────────────────
  {
    name: 'add_relationship',
    description: 'Create a relationship between two entities. Types: parent_of, married_to, sibling_of, member_of, leads, serves, ally_of, rival_of, enemy_of, located_in, owns, created_by, custom.',
    inputSchema: {
      type: 'object',
      properties: {
        source_id:     { type: 'string', description: 'Source entity ID' },
        target_id:     { type: 'string', description: 'Target entity ID' },
        relationship:  { type: 'string', description: 'Relationship type' },
        description:   { type: 'string', description: 'Optional description of the relationship' },
        start_node_id: { type: 'string', description: 'Timeline node ID when this relationship began (optional)' },
        end_node_id:   { type: 'string', description: 'Timeline node ID when this relationship ended (optional)' },
      },
      required: ['source_id', 'target_id', 'relationship'],
    },
  },
  {
    name: 'remove_relationship',
    description: 'Remove a relationship by its ID.',
    inputSchema: {
      type: 'object',
      properties: {
        relationship_id: { type: 'string', description: 'Relationship ID to remove' },
      },
      required: ['relationship_id'],
    },
  },
  {
    name: 'get_relationships',
    description: 'Get all relationships for an entity, optionally filtered by type.',
    inputSchema: {
      type: 'object',
      properties: {
        entity_id: { type: 'string', description: 'Entity ID to get relationships for' },
        type:      { type: 'string', description: 'Filter by relationship type (e.g. parent_of, ally_of)' },
      },
      required: ['entity_id'],
    },
  },

  // ── Story Arcs ──────────────────────────────────────────────────────────
  {
    name: 'list_arcs',
    description: 'List all story arcs with summary stats (node count, entity count, status).',
    inputSchema: { type: 'object', properties: {} },
  },
  {
    name: 'get_arc',
    description: 'Get full details of a story arc including its nodes and entity participants.',
    inputSchema: {
      type: 'object',
      properties: { arc_id: { type: 'string', description: 'Story arc ID' } },
      required: ['arc_id'],
    },
  },
  {
    name: 'create_arc',
    description: 'Create a new story arc.',
    inputSchema: {
      type: 'object',
      properties: {
        name:        { type: 'string', description: 'Arc name' },
        description: { type: 'string', description: 'Arc description (markdown)' },
        color:       { type: 'string', description: 'Hex color (default #c97b2a)' },
        status:      { type: 'string', description: 'planned, active, resolved, or abandoned (default: active)' },
      },
      required: ['name'],
    },
  },
  {
    name: 'update_arc',
    description: 'Update a story arc\'s properties.',
    inputSchema: {
      type: 'object',
      properties: {
        arc_id:      { type: 'string', description: 'Story arc ID' },
        name:        { type: 'string' },
        description: { type: 'string' },
        color:       { type: 'string' },
        status:      { type: 'string' },
      },
      required: ['arc_id'],
    },
  },
  {
    name: 'add_arc_event',
    description: 'Add a timeline node to a story arc.',
    inputSchema: {
      type: 'object',
      properties: {
        arc_id:    { type: 'string', description: 'Story arc ID' },
        node_id:   { type: 'string', description: 'Timeline node ID to add' },
        position:  { type: 'number', description: 'Position in the arc sequence (default 0)' },
        arc_label: { type: 'string', description: 'Label (e.g. inciting incident, climax, resolution)' },
      },
      required: ['arc_id', 'node_id'],
    },
  },
  {
    name: 'remove_arc_event',
    description: 'Remove a timeline node from a story arc.',
    inputSchema: {
      type: 'object',
      properties: {
        arc_id:  { type: 'string', description: 'Story arc ID' },
        node_id: { type: 'string', description: 'Timeline node ID to remove' },
      },
      required: ['arc_id', 'node_id'],
    },
  },

  // ── Mentions Search ───────────────────────────────────────────────────────
  {
    name: 'search_mentions',
    description: 'Search for entities and timeline nodes by name/title prefix. Useful for finding items to reference or link.',
    inputSchema: {
      type: 'object',
      properties: {
        query: { type: 'string', description: 'Search prefix' },
        limit: { type: 'number', description: 'Max results (default 10, max 20)' },
      },
      required: ['query'],
    },
  },
];

// ── Tool handlers ─────────────────────────────────────────────────────────────

async function handleListNodes(args) {
  const parentId = args?.parent_id ?? null;
  const nodes = getNodes(db, parentId);

  if (!nodes.length) {
    const label = parentId ? `under node \`${parentId}\`` : 'at root level';
    return { content: [{ type: 'text', text: `No nodes found ${label}.` }] };
  }

  const label = parentId ? `Children of \`${parentId}\`` : 'Root nodes';
  let out = `# ${label} (${nodes.length})\n\n`;
  for (const n of nodes) {
    const childCount = db.prepare('SELECT COUNT(*) AS c FROM timeline_nodes WHERE parent_id = ?').get(n.id).c;
    out += `- ${formatNodeBrief(n)}${childCount > 0 ? ` [${childCount} children]` : ''}\n`;
  }
  return { content: [{ type: 'text', text: out }] };
}

async function handleGetNode(args) {
  const node = getNode(db, args.node_id);
  if (!node) return { content: [{ type: 'text', text: `Node "${args.node_id}" not found.` }] };

  const children = getNodes(db, node.id);
  let out = `## Node Details\n\n${formatNode(node)}`;
  if (node.parent_id) {
    const parent = getNode(db, node.parent_id);
    if (parent) out += `\nParent: **${parent.title}** (\`${parent.id}\`)\n`;
  }
  if (children.length) {
    out += `\n### Children (${children.length})\n`;
    for (const c of children) out += `- ${formatNodeBrief(c)}\n`;
  }
  if (node.metadata && node.metadata !== '{}') out += `\nMetadata: ${node.metadata}\n`;
  return { content: [{ type: 'text', text: out }] };
}

async function handleGetTree(args) {
  const root = getNode(db, args.node_id);
  if (!root) return { content: [{ type: 'text', text: `Node "${args.node_id}" not found.` }] };

  const descendants = getDescendants(db, root.id);
  let out = `# Tree: ${root.title}\n\n${formatNode(root)}\n`;
  out += `**Total descendants:** ${descendants.length}\n\n`;

  function renderLevel(parentId, depth) {
    const children = descendants.filter(n => n.parent_id === parentId);
    for (const c of children) {
      out += formatNode(c, '  '.repeat(depth));
      renderLevel(c.id, depth + 1);
    }
  }
  renderLevel(root.id, 1);

  return { content: [{ type: 'text', text: out }] };
}

async function handleSearchNodes(args) {
  const results = searchNodes(db, args.search, {
    type:       args.type,
    node_type:  args.node_type,
    importance: args.importance,
    date_min:   args.date_min,
    date_max:   args.date_max,
  });

  if (!results.length) return { content: [{ type: 'text', text: 'No nodes found matching those criteria.' }] };

  let out = `Found **${results.length} node(s)**:\n\n`;
  for (const n of results) {
    out += formatNode(n) + '\n';
  }
  return { content: [{ type: 'text', text: out }] };
}

async function handleAddNode(args) {
  const cal = getActiveCalendar();
  const reals = computeReal(cal, args);
  const start_date = reals.start_date;
  const end_date = reals.end_date;

  if (start_date == null) return { content: [{ type: 'text', text: 'start_year is required.' }] };

  const type = args.type || (args.end_year != null ? 'span' : 'point');
  const id = generateUUID();

  db.prepare(`INSERT INTO timeline_nodes
    (id,parent_id,type,title,start_date,end_date,
     start_year,start_month,start_day,start_hour,start_minute,
     end_year,end_month,end_day,end_hour,end_minute,
     description,color,opacity,importance,node_type,sort_order,metadata)
    VALUES (?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,?,?)`)
    .run(id, args.parent_id ?? null, type, args.title, start_date, end_date,
         args.start_year ?? null, args.start_month ?? null, args.start_day ?? null, args.start_hour ?? null, args.start_minute ?? null,
         args.end_year ?? null, args.end_month ?? null, args.end_day ?? null, args.end_hour ?? null, args.end_minute ?? null,
         args.description ?? null, args.color ?? '#5566bb', args.opacity ?? 0.75, args.importance ?? 'moderate',
         args.node_type ?? 'event', args.sort_order ?? 0, args.metadata ?? '{}');

  const node = getNode(db, id);
  return { content: [{ type: 'text', text: `Node added!\n\n${formatNode(node)}\nRefresh the timeline app to see it.` }] };
}

async function handleUpdateNode(args) {
  const existing = getNode(db, args.node_id);
  if (!existing) return { content: [{ type: 'text', text: `Node "${args.node_id}" not found.` }] };

  const hasDateUpdate = args.start_year != null;
  let start_date = null, end_date = undefined;
  if (hasDateUpdate) {
    const cal = getActiveCalendar();
    const reals = computeReal(cal, args);
    start_date = reals.start_date;
    end_date = reals.end_date;
  }

  db.prepare(`UPDATE timeline_nodes SET
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
    .run(args.type, args.title,
         start_date, end_date ?? null,
         args.start_year ?? null, args.start_month ?? null, args.start_day ?? null, args.start_hour ?? null, args.start_minute ?? null,
         args.end_year ?? null, args.end_month ?? null, args.end_day ?? null, args.end_hour ?? null, args.end_minute ?? null,
         args.description, args.color, args.opacity ?? null, args.importance, args.node_type, args.sort_order, args.metadata, args.node_id);

  if (Object.prototype.hasOwnProperty.call(args, 'parent_id')) {
    if (args.node_id === args.parent_id) {
      return { content: [{ type: 'text', text: 'A node cannot be its own parent.' }] };
    }
    const newParent = args.parent_id === '' ? null : (args.parent_id ?? null);
    db.prepare("UPDATE timeline_nodes SET parent_id=?, updated_at=datetime('now') WHERE id=?")
      .run(newParent, args.node_id);
  }

  const updated = getNode(db, args.node_id);
  return { content: [{ type: 'text', text: `Node updated!\n\n${formatNode(updated)}` }] };
}

async function handleDeleteNode(args) {
  if (!args.confirm) return { content: [{ type: 'text', text: 'Deletion not confirmed. Set confirm: true to delete.' }] };
  const node = getNode(db, args.node_id);
  if (!node) return { content: [{ type: 'text', text: `Node "${args.node_id}" not found.` }] };

  const descendants = getDescendants(db, node.id);
  // Clean up entity bindings (matches REST API behavior)
  db.prepare('DELETE FROM entities WHERE node_id = ?').run(args.node_id);
  for (const d of descendants) db.prepare('DELETE FROM entities WHERE node_id = ?').run(d.id);
  db.prepare('DELETE FROM timeline_nodes WHERE id = ?').run(args.node_id);
  return { content: [{ type: 'text', text: `Deleted **${node.title}**${descendants.length ? ` and ${descendants.length} descendant(s)` : ''}.` }] };
}

async function handleMoveNode(args) {
  const node = getNode(db, args.node_id);
  if (!node) return { content: [{ type: 'text', text: `Node "${args.node_id}" not found.` }] };

  if (args.node_id === args.parent_id) {
    return { content: [{ type: 'text', text: 'A node cannot be its own parent.' }] };
  }

  // Prevent moving a node under one of its own descendants
  if (args.parent_id != null) {
    const descendants = getDescendants(db, args.node_id);
    if (descendants.some(d => d.id === args.parent_id)) {
      return { content: [{ type: 'text', text: 'Cannot move a node under one of its own descendants.' }] };
    }
  }

  const newParent = args.parent_id ?? null;
  db.prepare("UPDATE timeline_nodes SET parent_id=?, updated_at=datetime('now') WHERE id=?")
    .run(newParent, args.node_id);

  const dest = newParent ? getNode(db, newParent) : null;
  return { content: [{ type: 'text', text: `Moved **${node.title}** to ${dest ? `under **${dest.title}**` : 'root level'}.` }] };
}

async function handleGetStats() {
  const total = db.prepare('SELECT COUNT(*) AS c FROM timeline_nodes').get().c;
  if (!total) return { content: [{ type: 'text', text: 'No nodes in the timeline yet.' }] };

  const dateRange = db.prepare('SELECT MIN(start_date) AS min, MAX(COALESCE(end_date, start_date)) AS max FROM timeline_nodes').get();
  const byType = db.prepare('SELECT type, COUNT(*) AS c FROM timeline_nodes GROUP BY type').all();
  const byNodeType = db.prepare('SELECT node_type, COUNT(*) AS c FROM timeline_nodes GROUP BY node_type ORDER BY c DESC').all();
  const byImportance = db.prepare('SELECT importance, COUNT(*) AS c FROM timeline_nodes GROUP BY importance').all();
  const rootCount = db.prepare('SELECT COUNT(*) AS c FROM timeline_nodes WHERE parent_id IS NULL').get().c;
  const maxDepth = db.prepare(`
    WITH RECURSIVE d(id, depth) AS (
      SELECT id, 0 FROM timeline_nodes WHERE parent_id IS NULL
      UNION ALL
      SELECT n.id, d.depth + 1 FROM timeline_nodes n JOIN d ON n.parent_id = d.id
    )
    SELECT MAX(depth) AS m FROM d
  `).get().m ?? 0;

  let out = `# Timeline Statistics\n\n`;
  out += `**Total nodes:** ${total}\n`;
  out += `**Root nodes:** ${rootCount}\n`;
  out += `**Max depth:** ${maxDepth}\n`;
  out += `**Date range:** ${dateRange.min} to ${dateRange.max}\n\n`;

  out += `## By Type\n`;
  for (const r of byType) out += `- ${r.type}: ${r.c}\n`;

  out += `\n## By Node Type\n`;
  for (const r of byNodeType) out += `- ${r.node_type}: ${r.c}\n`;

  out += `\n## By Importance\n`;
  for (const level of ['critical', 'major', 'moderate', 'minor']) {
    const row = byImportance.find(r => r.importance === level);
    if (row) out += `- ${level}: ${row.c}\n`;
  }

  return { content: [{ type: 'text', text: out }] };
}

// ── Settings handlers ─────────────────────────────────────────────────────────

const JSON_SETTINGS = new Set(['calendar']);

async function handleGetSettings() {
  const rows = db.prepare('SELECT key, value FROM settings').all();
  const out = {};
  for (const r of rows) {
    if (r.value === null) { out[r.key] = null; continue; }
    if (JSON_SETTINGS.has(r.key)) { try { out[r.key] = JSON.parse(r.value); } catch { out[r.key] = null; } }
    else out[r.key] = Number(r.value);
  }
  const activeCal = db.prepare('SELECT id, name, config FROM calendars WHERE is_active = 1 LIMIT 1').get();
  if (activeCal) {
    out.active_calendar_id = activeCal.id;
    out.active_calendar_name = activeCal.name;
    try { out.calendar = JSON.parse(activeCal.config); } catch {}
  }
  let text = '# Settings\n\n';
  for (const [k, v] of Object.entries(out)) {
    text += `- **${k}:** ${typeof v === 'object' ? JSON.stringify(v) : v}\n`;
  }
  return { content: [{ type: 'text', text }] };
}

async function handleUpdateSettings(args) {
  const allowed = ['world_start', 'world_end', 'default_view_start', 'default_view_end', 'calendar'];
  const upsert = db.prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
  const updated = [];
  const tx = db.transaction(() => {
    for (const key of allowed) {
      if (Object.prototype.hasOwnProperty.call(args, key)) {
        if (args[key] === null) { upsert.run(key, null); }
        else if (JSON_SETTINGS.has(key)) { upsert.run(key, JSON.stringify(args[key])); }
        else { upsert.run(key, String(args[key])); }
        updated.push(key);
      }
    }
  });
  tx();
  if (!updated.length) return { content: [{ type: 'text', text: 'No valid settings provided.' }] };
  return { content: [{ type: 'text', text: `Updated settings: ${updated.join(', ')}.` }] };
}

// ── Calendar handlers ─────────────────────────────────────────────────────────

async function handleListCalendars() {
  const rows = db.prepare('SELECT * FROM calendars ORDER BY name').all();
  if (!rows.length) return { content: [{ type: 'text', text: 'No calendars found.' }] };
  let text = `# Calendars (${rows.length})\n\n`;
  for (const r of rows) {
    let config;
    try { config = JSON.parse(r.config); } catch { config = {}; }
    const monthCount = config.months?.length || 0;
    text += `- **${r.name}** (\`${r.id}\`)${r.is_active ? ' ✓ active' : ''} — ${monthCount} months\n`;
  }
  return { content: [{ type: 'text', text }] };
}

async function handleGetCalendar(args) {
  const row = db.prepare('SELECT * FROM calendars WHERE id = ?').get(args.calendar_id);
  if (!row) return { content: [{ type: 'text', text: `Calendar "${args.calendar_id}" not found.` }] };
  let config;
  try { config = JSON.parse(row.config); } catch { config = {}; }
  let text = `## ${row.name}${row.is_active ? ' (active)' : ''}\n\n`;
  text += `**ID:** \`${row.id}\`\n`;
  text += `**Config:** ${JSON.stringify(config, null, 2)}\n`;
  return { content: [{ type: 'text', text }] };
}

async function handleCreateCalendar(args) {
  const id = 'cal_' + generateUUID().replace(/-/g, '').substring(0, 12);
  db.prepare('INSERT INTO calendars (id, timeline_id, name, is_active, config) VALUES (?,?,?,0,?)')
    .run(id, args.timeline_id ?? 'default', args.name, JSON.stringify(args.config ?? DEFAULT_CALENDAR));
  return { content: [{ type: 'text', text: `Calendar created: **${args.name}** (\`${id}\`).` }] };
}

async function handleUpdateCalendar(args) {
  const sets = [], vals = [];
  if (args.name != null) { sets.push('name=?'); vals.push(args.name); }
  if (args.config != null) { sets.push('config=?'); vals.push(JSON.stringify(args.config)); }
  if (!sets.length) return { content: [{ type: 'text', text: 'Nothing to update.' }] };
  sets.push("updated_at=datetime('now')");
  vals.push(args.calendar_id);
  db.prepare(`UPDATE calendars SET ${sets.join(',')} WHERE id=?`).run(...vals);
  const row = db.prepare('SELECT * FROM calendars WHERE id = ?').get(args.calendar_id);
  if (!row) return { content: [{ type: 'text', text: `Calendar "${args.calendar_id}" not found.` }] };
  // If active calendar config was updated, sync to settings
  if (row.is_active && args.config != null) {
    db.prepare("INSERT INTO settings (key, value) VALUES ('calendar', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")
      .run(JSON.stringify(args.config));
  }
  return { content: [{ type: 'text', text: `Calendar **${row.name}** updated.` }] };
}

async function handleDeleteCalendar(args) {
  const row = db.prepare('SELECT is_active, name FROM calendars WHERE id = ?').get(args.calendar_id);
  if (!row) return { content: [{ type: 'text', text: `Calendar "${args.calendar_id}" not found.` }] };
  if (row.is_active) return { content: [{ type: 'text', text: 'Cannot delete the active calendar.' }] };
  db.prepare('DELETE FROM calendars WHERE id = ?').run(args.calendar_id);
  return { content: [{ type: 'text', text: `Calendar **${row.name}** deleted.` }] };
}

async function handleActivateCalendar(args) {
  const cal = db.prepare('SELECT * FROM calendars WHERE id = ?').get(args.calendar_id);
  if (!cal) return { content: [{ type: 'text', text: `Calendar "${args.calendar_id}" not found.` }] };
  let config;
  try { config = JSON.parse(cal.config); } catch { return { content: [{ type: 'text', text: 'Invalid calendar config.' }] }; }
  if (!config?.months?.length) return { content: [{ type: 'text', text: 'Calendar has no months defined.' }] };

  const tx = db.transaction(() => {
    db.prepare('UPDATE calendars SET is_active = 0 WHERE timeline_id = ?').run(cal.timeline_id);
    db.prepare('UPDATE calendars SET is_active = 1 WHERE id = ?').run(cal.id);
    db.prepare("INSERT INTO settings (key, value) VALUES ('calendar', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value")
      .run(cal.config);

    // Recalculate all node dates
    const mc = config.months.length;
    function clampMonth(m) { return m ? Math.min(m, mc) : m; }
    function clampDay(d, m) {
      if (!d || !m) return d;
      const maxD = config.months[m - 1]?.days ?? 30;
      return Math.min(d, maxD);
    }
    const nodes = db.prepare('SELECT id, start_year, start_month, start_day, start_hour, start_minute, end_year, end_month, end_day, end_hour, end_minute FROM timeline_nodes WHERE start_year IS NOT NULL').all();
    const update = db.prepare('UPDATE timeline_nodes SET start_date=?, end_date=?, start_month=?, start_day=?, end_month=?, end_day=? WHERE id=?');
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

  return { content: [{ type: 'text', text: `Activated calendar **${cal.name}**. All node dates recalculated.` }] };
}

// ── Document handlers ─────────────────────────────────────────────────────────

async function handleListDocs(args) {
  const clauses = [], params = [];
  if (args?.category) { clauses.push('d.category = ?'); params.push(args.category); }
  if (args?.search) {
    const s = `%${args.search.toLowerCase()}%`;
    clauses.push(`(
      LOWER(d.title) LIKE ? OR LOWER(d.content) LIKE ? OR
      EXISTS (SELECT 1 FROM document_tags dt WHERE dt.doc_id = d.id AND LOWER(dt.tag) LIKE ?)
    )`);
    params.push(s, s, s);
  }
  const where = clauses.length ? 'WHERE ' + clauses.join(' AND ') : '';
  const docs = db.prepare(`SELECT * FROM documents d ${where} ORDER BY d.updated_at DESC`).all(params);

  if (!docs.length) return { content: [{ type: 'text', text: 'No documents found.' }] };

  const tagRows = db.prepare('SELECT doc_id, tag FROM document_tags').all();
  const tagMap = {};
  for (const r of tagRows) (tagMap[r.doc_id] ??= []).push(r.tag);

  let text = `# Documents (${docs.length})\n\n`;
  for (const d of docs) {
    const tags = tagMap[d.id] || [];
    const excerpt = (d.content || '').replace(/[#*`]/g, '').slice(0, 80).trim();
    text += `- **${d.title}** (\`${d.id}\`) [${d.category}]${tags.length ? ' #' + tags.join(' #') : ''}\n`;
    if (excerpt) text += `  ${excerpt}…\n`;
  }
  return { content: [{ type: 'text', text }] };
}

async function handleGetDoc(args) {
  const doc = db.prepare('SELECT * FROM documents WHERE id = ?').get(args.doc_id);
  if (!doc) return { content: [{ type: 'text', text: `Document "${args.doc_id}" not found.` }] };
  const tags = db.prepare('SELECT tag FROM document_tags WHERE doc_id = ?').all(args.doc_id).map(r => r.tag);
  let text = `## ${doc.title}\n\n`;
  text += `**ID:** \`${doc.id}\` | **Category:** ${doc.category} | **Tags:** ${tags.join(', ') || '(none)'}\n`;
  text += `**Updated:** ${doc.updated_at || '—'}\n\n`;
  text += `---\n\n${doc.content || '(empty)'}\n`;
  return { content: [{ type: 'text', text }] };
}

async function handleCreateDoc(args) {
  const now = new Date().toISOString();
  const id = 'doc_' + generateUUID().replace(/-/g, '').substring(0, 8);
  const tags = args.tags || [];

  db.transaction(() => {
    db.prepare('INSERT INTO documents (id, timeline_id, title, category, content, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
      .run(id, 'default', args.title, args.category || 'Other', args.content || '', now, now);
    for (const tag of tags)
      db.prepare('INSERT OR IGNORE INTO document_tags (doc_id, tag) VALUES (?, ?)').run(id, tag);
  })();

  return { content: [{ type: 'text', text: `Document created: **${args.title}** (\`${id}\`).` }] };
}

async function handleUpdateDoc(args) {
  const existing = db.prepare('SELECT * FROM documents WHERE id = ?').get(args.doc_id);
  if (!existing) return { content: [{ type: 'text', text: `Document "${args.doc_id}" not found.` }] };

  const now = new Date().toISOString();

  db.transaction(() => {
    db.prepare('UPDATE documents SET title=COALESCE(?,title), category=COALESCE(?,category), content=COALESCE(?,content), updated_at=? WHERE id=?')
      .run(args.title, args.category, args.content, now, args.doc_id);
    if (args.tags !== undefined) {
      db.prepare('DELETE FROM document_tags WHERE doc_id = ?').run(args.doc_id);
      for (const tag of args.tags)
        db.prepare('INSERT OR IGNORE INTO document_tags (doc_id, tag) VALUES (?, ?)').run(args.doc_id, tag);
    }
  })();

  return { content: [{ type: 'text', text: `Document **${args.title || existing.title}** updated.` }] };
}

async function handleDeleteDoc(args) {
  if (!args.confirm) return { content: [{ type: 'text', text: 'Deletion not confirmed. Set confirm: true to delete.' }] };
  const doc = db.prepare('SELECT title FROM documents WHERE id = ?').get(args.doc_id);
  if (!doc) return { content: [{ type: 'text', text: `Document "${args.doc_id}" not found.` }] };
  db.prepare('DELETE FROM documents WHERE id = ?').run(args.doc_id);
  return { content: [{ type: 'text', text: `Deleted document **${doc.title}**.` }] };
}

// ── Entity handlers ───────────────────────────────────────────────────────────

async function handleListEntities(args) {
  const clauses = [], params = [];
  if (args?.entity_type) { clauses.push('e.entity_type = ?'); params.push(args.entity_type); }
  if (args?.search) {
    const s = `%${args.search.toLowerCase()}%`;
    clauses.push("(LOWER(e.name) LIKE ? OR LOWER(COALESCE(e.description,'')) LIKE ?)");
    params.push(s, s);
  }
  const where = clauses.length ? 'WHERE ' + clauses.join(' AND ') : '';
  const orderBy = args?.search
    ? `ORDER BY (CASE WHEN LOWER(e.name) LIKE ? THEN 0 ELSE 1 END), e.name`
    : 'ORDER BY e.name';
  if (args?.search) params.push(`%${args.search.toLowerCase()}%`);
  const entities = db.prepare(`SELECT * FROM entities e ${where} ${orderBy}`).all(params);

  if (!entities.length) return { content: [{ type: 'text', text: 'No entities found.' }] };

  const countStmt = db.prepare('SELECT COUNT(*) as c FROM entity_node_links WHERE entity_id = ?');
  let text = `# Entities (${entities.length})\n\n`;
  for (const e of entities) {
    const linkCount = countStmt.get(e.id).c;
    text += `- **${e.name}** (\`${e.id}\`) [${e.entity_type}]${linkCount ? ` — ${linkCount} linked nodes` : ''}\n`;
  }
  return { content: [{ type: 'text', text }] };
}

async function handleGetEntity(args) {
  const entity = db.prepare('SELECT * FROM entities WHERE id = ?').get(args.entity_id);
  if (!entity) return { content: [{ type: 'text', text: `Entity "${args.entity_id}" not found.` }] };

  let metadata;
  try { metadata = JSON.parse(entity.metadata); } catch { metadata = {}; }

  let text = `## ${entity.name}\n\n`;
  text += `**ID:** \`${entity.id}\` | **Type:** ${entity.entity_type} | **Color:** ${entity.color}\n`;
  if (entity.description) text += `\n${entity.description}\n`;

  // Bound node
  if (entity.node_id) {
    const node = db.prepare('SELECT * FROM timeline_nodes WHERE id = ?').get(entity.node_id);
    if (node) {
      text += `\n### Bound Timeline Node\n`;
      text += formatNode(node);
    }
  }

  // Linked nodes
  const linked = db.prepare(`
    SELECT n.id, n.title, n.type, n.node_type, n.start_date, n.color, l.role
    FROM entity_node_links l
    JOIN timeline_nodes n ON n.id = l.node_id
    WHERE l.entity_id = ? AND n.id != COALESCE(?, '')
    ORDER BY n.start_date`).all(args.entity_id, entity.node_id);

  if (linked.length) {
    text += `\n### Linked Nodes (${linked.length})\n`;
    for (const n of linked) {
      text += `- ${formatNodeBrief(n)}${n.role ? ` [${n.role}]` : ''}\n`;
    }
  }

  if (Object.keys(metadata).length) text += `\n**Metadata:** ${JSON.stringify(metadata)}\n`;

  return { content: [{ type: 'text', text }] };
}

async function handleCreateEntity(args) {
  const id = 'ent_' + generateUUID().replace(/-/g, '').substring(0, 8);
  db.prepare(`INSERT INTO entities (id, timeline_id, name, entity_type, description, color, metadata, node_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)`).run(
    id, 'default', args.name, args.entity_type || 'character',
    args.description || null, args.color || '#7c6bff', JSON.stringify(args.metadata || {}),
    args.node_id || null);
  return { content: [{ type: 'text', text: `Entity created: **${args.name}** (\`${id}\`) [${args.entity_type || 'character'}].` }] };
}

async function handleUpdateEntity(args) {
  db.prepare(`UPDATE entities SET
    name=COALESCE(?,name), entity_type=COALESCE(?,entity_type),
    description=COALESCE(?,description), color=COALESCE(?,color),
    metadata=COALESCE(?,metadata), updated_at=datetime('now')
    WHERE id=?`).run(
    args.name, args.entity_type, args.description, args.color,
    args.metadata != null ? JSON.stringify(args.metadata) : null, args.entity_id);
  const entity = db.prepare('SELECT * FROM entities WHERE id = ?').get(args.entity_id);
  if (!entity) return { content: [{ type: 'text', text: `Entity "${args.entity_id}" not found.` }] };
  return { content: [{ type: 'text', text: `Entity **${entity.name}** updated.` }] };
}

async function handleDeleteEntity(args) {
  if (!args.confirm) return { content: [{ type: 'text', text: 'Deletion not confirmed. Set confirm: true to delete.' }] };
  const ent = db.prepare('SELECT name, node_id FROM entities WHERE id = ?').get(args.entity_id);
  if (!ent) return { content: [{ type: 'text', text: `Entity "${args.entity_id}" not found.` }] };
  // Also delete the bound timeline node (matches REST API behavior)
  if (ent.node_id) db.prepare('DELETE FROM timeline_nodes WHERE id = ?').run(ent.node_id);
  db.prepare('DELETE FROM entities WHERE id = ?').run(args.entity_id);
  return { content: [{ type: 'text', text: `Deleted entity **${ent.name}**${ent.node_id ? ' and its bound timeline node' : ''}.` }] };
}

// ── Entity-Node link handlers ─────────────────────────────────────────────────

async function handleLinkEntityNode(args) {
  db.prepare('INSERT OR IGNORE INTO entity_node_links (entity_id, node_id, role) VALUES (?, ?, ?)')
    .run(args.entity_id, args.node_id, args.role || '');
  const entity = db.prepare('SELECT name FROM entities WHERE id = ?').get(args.entity_id);
  const node = getNode(db, args.node_id);
  return { content: [{ type: 'text', text: `Linked **${entity?.name || args.entity_id}** to **${node?.title || args.node_id}**${args.role ? ` (${args.role})` : ''}.` }] };
}

async function handleUnlinkEntityNode(args) {
  db.prepare('DELETE FROM entity_node_links WHERE entity_id = ? AND node_id = ?')
    .run(args.entity_id, args.node_id);
  return { content: [{ type: 'text', text: `Unlinked entity \`${args.entity_id}\` from node \`${args.node_id}\`.` }] };
}

// ── Entity-Doc link handlers ──────────────────────────────────────────────────

async function handleLinkEntityDoc(args) {
  db.prepare('INSERT OR IGNORE INTO entity_doc_links (entity_id, doc_id, role) VALUES (?, ?, ?)')
    .run(args.entity_id, args.doc_id, args.role || '');
  const entity = db.prepare('SELECT name FROM entities WHERE id = ?').get(args.entity_id);
  const doc = db.prepare('SELECT title FROM documents WHERE id = ?').get(args.doc_id);
  return { content: [{ type: 'text', text: `Linked entity **${entity?.name || args.entity_id}** to doc **${doc?.title || args.doc_id}**${args.role ? ` (${args.role})` : ''}.` }] };
}

async function handleUnlinkEntityDoc(args) {
  db.prepare('DELETE FROM entity_doc_links WHERE entity_id = ? AND doc_id = ?')
    .run(args.entity_id, args.doc_id);
  return { content: [{ type: 'text', text: `Unlinked entity \`${args.entity_id}\` from doc \`${args.doc_id}\`.` }] };
}

// ── Doc-Node link handlers ────────────────────────────────────────────────────

async function handleLinkDocNode(args) {
  db.prepare('INSERT OR IGNORE INTO doc_node_links (doc_id, node_id, role) VALUES (?, ?, ?)')
    .run(args.doc_id, args.node_id, args.role || '');
  const doc = db.prepare('SELECT title FROM documents WHERE id = ?').get(args.doc_id);
  const node = getNode(db, args.node_id);
  return { content: [{ type: 'text', text: `Linked doc **${doc?.title || args.doc_id}** to node **${node?.title || args.node_id}**${args.role ? ` (${args.role})` : ''}.` }] };
}

async function handleUnlinkDocNode(args) {
  db.prepare('DELETE FROM doc_node_links WHERE doc_id = ? AND node_id = ?')
    .run(args.doc_id, args.node_id);
  return { content: [{ type: 'text', text: `Unlinked doc \`${args.doc_id}\` from node \`${args.node_id}\`.` }] };
}

// ── Backlinks handler ─────────────────────────────────────────────────────────

async function handleGetBacklinks(args) {
  const { type, id } = args;
  const links = {};

  if (type === 'entity') {
    links.linked_docs = db.prepare(`SELECT d.id, d.title, l.role FROM entity_doc_links l
      JOIN documents d ON d.id = l.doc_id WHERE l.entity_id = ?`).all(id);
    links.linked_nodes = db.prepare(`SELECT n.id, n.title, l.role FROM entity_node_links l
      JOIN timeline_nodes n ON n.id = l.node_id WHERE l.entity_id = ?`).all(id);
  } else if (type === 'doc') {
    links.linked_entities = db.prepare(`SELECT e.id, e.name, l.role FROM entity_doc_links l
      JOIN entities e ON e.id = l.entity_id WHERE l.doc_id = ?`).all(id);
    links.linked_nodes = db.prepare(`SELECT n.id, n.title, l.role FROM doc_node_links l
      JOIN timeline_nodes n ON n.id = l.node_id WHERE l.doc_id = ?`).all(id);
  } else if (type === 'node') {
    links.linked_entities = db.prepare(`SELECT e.id, e.name, l.role FROM entity_node_links l
      JOIN entities e ON e.id = l.entity_id WHERE l.node_id = ?`).all(id);
    links.linked_docs = db.prepare(`SELECT d.id, d.title, l.role FROM doc_node_links l
      JOIN documents d ON d.id = l.doc_id WHERE l.node_id = ?`).all(id);
  }

  const sections = Object.entries(links).filter(([, arr]) => arr.length);
  if (!sections.length) return { content: [{ type: 'text', text: `No backlinks found for ${type} \`${id}\`.` }] };

  let text = `**Backlinks for ${type} \`${id}\`:**\n\n`;
  for (const [key, arr] of sections) {
    text += `### ${key.replace(/_/g, ' ')}\n`;
    for (const r of arr) {
      const label = r.name || r.title;
      text += `- **${label}** (\`${r.id}\`)${r.role ? ` — ${r.role}` : ''}\n`;
    }
    text += '\n';
  }
  return { content: [{ type: 'text', text }] };
}

// ── Folder handlers ───────────────────────────────────────────────────────────

async function handleListFolders(args) {
  const rows = args?.type
    ? db.prepare('SELECT * FROM folders WHERE folder_type = ? ORDER BY sort_order, name').all(args.type)
    : db.prepare('SELECT * FROM folders ORDER BY folder_type, sort_order, name').all();
  if (!rows.length) return { content: [{ type: 'text', text: 'No folders found.' }] };

  let text = `**${rows.length} folder(s):**\n\n`;
  for (const f of rows) {
    text += `- **${f.name}** (\`${f.id}\`) — ${f.folder_type}${f.parent_id ? `, parent: \`${f.parent_id}\`` : ''}${f.color ? `, color: ${f.color}` : ''}\n`;
  }
  return { content: [{ type: 'text', text }] };
}

async function handleCreateFolder(args) {
  const id = generateUUID();
  db.prepare('INSERT INTO folders (id, name, folder_type, parent_id, color) VALUES (?, ?, ?, ?, ?)')
    .run(id, args.name, args.folder_type, args.parent_id || null, args.color || null);
  return { content: [{ type: 'text', text: `Created ${args.folder_type} folder **${args.name}** (\`${id}\`).` }] };
}

// ── Mentions search handler ───────────────────────────────────────────────────

async function handleSearchMentions(args) {
  const prefix = args.query.replace(/[%_]/g, '') + '%';
  const lim = Math.min(args.limit || 10, 20);
  const halfLim = Math.ceil(lim / 2);

  const entities = db.prepare(`
    SELECT id, name, entity_type AS type_label, color, 'entity' AS _kind
    FROM entities
    WHERE name LIKE ? COLLATE NOCASE
    ORDER BY name
    LIMIT ?`).all(prefix, halfLim);

  const events = db.prepare(`
    SELECT id, title AS name, node_type AS type_label, color, 'event' AS _kind
    FROM timeline_nodes
    WHERE title LIKE ? COLLATE NOCASE
    ORDER BY title
    LIMIT ?`).all(prefix, halfLim);

  const items = [...entities, ...events];
  if (!items.length) return { content: [{ type: 'text', text: 'No matches found.' }] };

  let text = `Found **${items.length}** match(es):\n\n`;
  for (const it of items) {
    text += `- [${it._kind}] **${it.name}** (\`${it.id}\`) — ${it.type_label}\n`;
  }
  return { content: [{ type: 'text', text }] };
}

// ── Relationship handlers ─────────────────────────────────────────────────────

async function handleAddRelationship(args) {
  const { source_id, target_id, relationship, description, start_node_id, end_node_id } = args;
  if (!source_id || !target_id || !relationship) return { content: [{ type: 'text', text: 'source_id, target_id, and relationship are required.' }] };
  if (source_id === target_id) return { content: [{ type: 'text', text: 'Cannot relate an entity to itself.' }] };
  const id = 'rel_' + require('crypto').randomUUID().replace(/-/g, '').slice(0, 12);
  try {
    db.prepare(`INSERT INTO entity_relationships (id, source_id, target_id, relationship, description, start_node_id, end_node_id)
      VALUES (?, ?, ?, ?, ?, ?, ?)`).run(id, source_id, target_id, relationship, description || null, start_node_id || null, end_node_id || null);
  } catch (e) {
    if (e.message?.includes('UNIQUE')) return { content: [{ type: 'text', text: 'Relationship already exists.' }] };
    throw e;
  }
  const src = db.prepare('SELECT name FROM entities WHERE id = ?').get(source_id);
  const tgt = db.prepare('SELECT name FROM entities WHERE id = ?').get(target_id);
  return { content: [{ type: 'text', text: `Created relationship: **${src?.name || source_id}** → *${relationship}* → **${tgt?.name || target_id}** (\`${id}\`)` }] };
}

async function handleRemoveRelationship(args) {
  const info = db.prepare('DELETE FROM entity_relationships WHERE id = ?').run(args.relationship_id);
  return { content: [{ type: 'text', text: info.changes ? 'Relationship removed.' : 'Relationship not found.' }] };
}

async function handleGetRelationships(args) {
  const { entity_id, type } = args;
  let sql = `SELECT r.*, e.name AS other_name, e.entity_type AS other_type
    FROM entity_relationships r
    JOIN entities e ON e.id = CASE WHEN r.source_id = ? THEN r.target_id ELSE r.source_id END
    WHERE (r.source_id = ? OR r.target_id = ?)`;
  const params = [entity_id, entity_id, entity_id];
  if (type) { sql += ' AND r.relationship = ?'; params.push(type); }
  const rows = db.prepare(sql).all(...params);
  if (!rows.length) return { content: [{ type: 'text', text: 'No relationships found.' }] };
  const ent = db.prepare('SELECT name FROM entities WHERE id = ?').get(entity_id);
  let text = `**${ent?.name || entity_id}** has **${rows.length}** relationship(s):\n\n`;
  for (const r of rows) {
    const dir = r.source_id === entity_id ? '→' : '←';
    text += `- ${dir} *${r.relationship}* **${r.other_name}** (${r.other_type}) — \`${r.id}\``;
    if (r.description) text += ` — ${r.description}`;
    text += '\n';
  }
  return { content: [{ type: 'text', text }] };
}

// ── Story Arc handlers ────────────────────────────────────────────────────────

async function handleListArcs() {
  const arcs = db.prepare(`SELECT a.*,
    (SELECT COUNT(*) FROM arc_node_links WHERE arc_id = a.id) AS node_count,
    (SELECT COUNT(*) FROM arc_entity_links WHERE arc_id = a.id) AS entity_count
    FROM story_arcs a ORDER BY a.sort_order, a.name`).all();
  if (!arcs.length) return { content: [{ type: 'text', text: 'No story arcs yet.' }] };
  let text = `**${arcs.length}** story arc(s):\n\n`;
  for (const a of arcs) {
    text += `- **${a.name}** (\`${a.id}\`) — ${a.status} — ${a.node_count} events, ${a.entity_count} participants\n`;
  }
  return { content: [{ type: 'text', text }] };
}

async function handleGetArc(args) {
  const arc = db.prepare('SELECT * FROM story_arcs WHERE id = ?').get(args.arc_id);
  if (!arc) return { content: [{ type: 'text', text: 'Arc not found.' }] };
  const nodes = db.prepare(`SELECT anl.*, n.title FROM arc_node_links anl JOIN timeline_nodes n ON n.id = anl.node_id WHERE anl.arc_id = ? ORDER BY anl.position`).all(args.arc_id);
  const entities = db.prepare(`SELECT ael.*, e.name, e.entity_type FROM arc_entity_links ael JOIN entities e ON e.id = ael.entity_id WHERE ael.arc_id = ?`).all(args.arc_id);
  let text = `## ${arc.name}\n**Status:** ${arc.status} | **Color:** ${arc.color}\n`;
  if (arc.description) text += `\n${arc.description}\n`;
  if (nodes.length) {
    text += `\n### Events (${nodes.length})\n`;
    for (const n of nodes) text += `${n.position ?? '-'}. **${n.title}** (\`${n.node_id}\`)${n.arc_label ? ` — *${n.arc_label}*` : ''}\n`;
  }
  if (entities.length) {
    text += `\n### Participants (${entities.length})\n`;
    for (const e of entities) text += `- **${e.name}** (${e.entity_type})${e.role ? ` — ${e.role}` : ''}\n`;
  }
  return { content: [{ type: 'text', text }] };
}

async function handleCreateArc(args) {
  const { name, description, color, status } = args;
  if (!name) return { content: [{ type: 'text', text: 'name is required.' }] };
  const id = 'arc_' + require('crypto').randomUUID().replace(/-/g, '').slice(0, 12);
  db.prepare(`INSERT INTO story_arcs (id, name, description, color, status) VALUES (?, ?, ?, ?, ?)`)
    .run(id, name, description || null, color || '#c97b2a', status || 'active');
  return { content: [{ type: 'text', text: `Created arc **${name}** (\`${id}\`)` }] };
}

async function handleUpdateArc(args) {
  const { arc_id, name, description, color, status } = args;
  const existing = db.prepare('SELECT * FROM story_arcs WHERE id = ?').get(arc_id);
  if (!existing) return { content: [{ type: 'text', text: 'Arc not found.' }] };
  db.prepare(`UPDATE story_arcs SET name = COALESCE(?, name), description = COALESCE(?, description),
    color = COALESCE(?, color), status = COALESCE(?, status), updated_at = datetime('now') WHERE id = ?`)
    .run(name || null, description !== undefined ? description : null, color || null, status || null, arc_id);
  return { content: [{ type: 'text', text: `Updated arc \`${arc_id}\`.` }] };
}

async function handleAddArcEvent(args) {
  const { arc_id, node_id, position, arc_label } = args;
  if (!arc_id || !node_id) return { content: [{ type: 'text', text: 'arc_id and node_id required.' }] };
  db.prepare(`INSERT OR IGNORE INTO arc_node_links (arc_id, node_id, position, arc_label) VALUES (?, ?, ?, ?)`)
    .run(arc_id, node_id, position ?? 0, arc_label || null);
  const node = db.prepare('SELECT title FROM timeline_nodes WHERE id = ?').get(node_id);
  return { content: [{ type: 'text', text: `Added **${node?.title || node_id}** to arc${arc_label ? ` as *${arc_label}*` : ''}.` }] };
}

async function handleRemoveArcEvent(args) {
  db.prepare('DELETE FROM arc_node_links WHERE arc_id = ? AND node_id = ?').run(args.arc_id, args.node_id);
  return { content: [{ type: 'text', text: 'Removed event from arc.' }] };
}

// ── MCP server setup ──────────────────────────────────────────────────────────

const mcpServer = new Server(
  { name: 'timeline', version: '3.0.0' },
  { capabilities: { tools: {} } }
);

mcpServer.setRequestHandler(ListToolsRequestSchema, async () => ({ tools: TOOL_DEFINITIONS }));

mcpServer.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args } = request.params;
  try {
    switch (name) {
      case 'list_nodes':    return await handleListNodes(args || {});
      case 'get_node':      return await handleGetNode(args);
      case 'get_tree':      return await handleGetTree(args);
      case 'search_nodes':  return await handleSearchNodes(args || {});
      case 'add_node':      return await handleAddNode(args);
      case 'update_node':   return await handleUpdateNode(args);
      case 'delete_node':   return await handleDeleteNode(args);
      case 'move_node':     return await handleMoveNode(args);
      case 'get_stats':     return await handleGetStats();

      // Settings
      case 'get_settings':    return await handleGetSettings();
      case 'update_settings': return await handleUpdateSettings(args || {});

      // Calendars
      case 'list_calendars':    return await handleListCalendars();
      case 'get_calendar':      return await handleGetCalendar(args);
      case 'create_calendar':   return await handleCreateCalendar(args);
      case 'update_calendar':   return await handleUpdateCalendar(args);
      case 'delete_calendar':   return await handleDeleteCalendar(args);
      case 'activate_calendar': return await handleActivateCalendar(args);

      // Documents
      case 'list_docs':   return await handleListDocs(args || {});
      case 'get_doc':     return await handleGetDoc(args);
      case 'create_doc':  return await handleCreateDoc(args);
      case 'update_doc':  return await handleUpdateDoc(args);
      case 'delete_doc':  return await handleDeleteDoc(args);

      // Entities
      case 'list_entities':  return await handleListEntities(args || {});
      case 'get_entity':     return await handleGetEntity(args);
      case 'create_entity':  return await handleCreateEntity(args);
      case 'update_entity':  return await handleUpdateEntity(args);
      case 'delete_entity':  return await handleDeleteEntity(args);

      // Entity-Node links
      case 'link_entity_node':   return await handleLinkEntityNode(args);
      case 'unlink_entity_node': return await handleUnlinkEntityNode(args);

      // Entity-Doc links
      case 'link_entity_doc':   return await handleLinkEntityDoc(args);
      case 'unlink_entity_doc': return await handleUnlinkEntityDoc(args);

      // Doc-Node links
      case 'link_doc_node':   return await handleLinkDocNode(args);
      case 'unlink_doc_node': return await handleUnlinkDocNode(args);

      // Backlinks
      case 'get_backlinks': return await handleGetBacklinks(args);

      // Folders
      case 'list_folders':  return await handleListFolders(args || {});
      case 'create_folder': return await handleCreateFolder(args);

      // Relationships
      case 'add_relationship':    return await handleAddRelationship(args);
      case 'remove_relationship': return await handleRemoveRelationship(args);
      case 'get_relationships':   return await handleGetRelationships(args);

      // Story Arcs
      case 'list_arcs':        return await handleListArcs();
      case 'get_arc':          return await handleGetArc(args);
      case 'create_arc':       return await handleCreateArc(args);
      case 'update_arc':       return await handleUpdateArc(args);
      case 'add_arc_event':    return await handleAddArcEvent(args);
      case 'remove_arc_event': return await handleRemoveArcEvent(args);

      // Mentions
      case 'search_mentions': return await handleSearchMentions(args);

      default:
        return { content: [{ type: 'text', text: `Unknown tool: ${name}` }] };
    }
  } catch (err) {
    return { content: [{ type: 'text', text: `Error executing ${name}: ${err.message}` }] };
  }
});

const transport = new StdioServerTransport();
mcpServer.connect(transport).catch(err => {
  process.stderr.write('MCP server error: ' + err.message + '\n');
  process.exit(1);
});
