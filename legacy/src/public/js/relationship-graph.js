'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Relationship Graph — Hybrid SVG + DOM visualization
// ══════════════════════════════════════════════════════════════════════════════

const RELATIONSHIP_REGISTRY = {
  parent_of:   { category: 'family',     symmetric: false, inverse: 'child of',    icon: '↓' },
  married_to:  { category: 'family',     symmetric: true,  inverse: 'married to',  icon: '♥' },
  sibling_of:  { category: 'family',     symmetric: true,  inverse: 'sibling of',  icon: '↔' },
  member_of:   { category: 'org',        symmetric: false, inverse: 'has member',  icon: '⊂' },
  leads:       { category: 'org',        symmetric: false, inverse: 'led by',      icon: '★' },
  serves:      { category: 'org',        symmetric: false, inverse: 'served by',   icon: '→' },
  ally_of:     { category: 'political',  symmetric: true,  inverse: 'ally of',     icon: '⊕' },
  rival_of:    { category: 'political',  symmetric: true,  inverse: 'rival of',    icon: '⊘' },
  enemy_of:    { category: 'political',  symmetric: true,  inverse: 'enemy of',    icon: '✕' },
  located_in:  { category: 'spatial',    symmetric: false, inverse: 'contains',    icon: '⊃' },
  owns:        { category: 'possession', symmetric: false, inverse: 'owned by',    icon: '◆' },
  created_by:  { category: 'creation',   symmetric: false, inverse: 'creator of',  icon: '✦' },
  custom:      { category: 'custom',     symmetric: false, inverse: null,          icon: '🔗' },
};

const CATEGORY_COLORS = {
  family: '#c97b2a', org: '#5566bb', political: '#885544',
  spatial: '#558866', possession: '#997755', creation: '#664488', custom: '#776644',
};

// ── Graph data fetching ──────────────────────────────────────────────────────

async function fetchGraphData(entityId, depth, mode) {
  const params = new URLSearchParams({
    entity_ids: entityId,
    depth: depth,
    mode: mode || 'general',
  });
  return apiFetch(`${API}/relationship-graph?${params}`).then(r => r.json());
}

// ── Render entry point (called from entities-module.js) ──────────────────────

async function renderRelGraph(entityId) {
  const container = document.getElementById('rel-graph-container');
  container.innerHTML = '';

  const depth = parseInt(document.getElementById('rel-depth-slider')?.value) || 2;
  const mode = document.getElementById('rel-mode-select')?.value || 'general';
  const data = await fetchGraphData(entityId, depth, mode);

  if (!data.nodes?.length) {
    container.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text-dim);">No relationships found.</div>';
    return;
  }

  const layout = mode === 'family'
    ? layoutFamilyTree(data, entityId)
    : layoutForceDirected(data, entityId);

  renderGraphDOM(container, layout, data, entityId);
}

// ══════════════════════════════════════════════════════════════════════════════
// Family Tree Layout (Simplified Sugiyama)
// ══════════════════════════════════════════════════════════════════════════════

function layoutFamilyTree(data, rootId) {
  const { nodes, edges } = data;
  const nodeMap = new Map(nodes.map(n => [n.id, n]));

  // Build parent→children adjacency (only parent_of edges)
  const children = new Map();  // parentId → [childId]
  const parents  = new Map();  // childId → [parentId]
  const marriages = new Map(); // entityId → partnerId

  for (const e of edges) {
    if (e.relationship === 'parent_of') {
      if (!children.has(e.source_id)) children.set(e.source_id, []);
      children.get(e.source_id).push(e.target_id);
      if (!parents.has(e.target_id)) parents.set(e.target_id, []);
      parents.get(e.target_id).push(e.source_id);
    } else if (e.relationship === 'married_to') {
      marriages.set(e.source_id, e.target_id);
      marriages.set(e.target_id, e.source_id);
    }
  }

  // Assign layers via BFS from root (up for ancestors, down for descendants)
  const layers = new Map(); // entityId → layer number
  layers.set(rootId, 0);

  // Traverse ancestors (negative layers)
  const upQueue = [rootId];
  while (upQueue.length) {
    const id = upQueue.shift();
    const pars = parents.get(id) || [];
    for (const p of pars) {
      if (!layers.has(p)) {
        layers.set(p, layers.get(id) - 1);
        upQueue.push(p);
      }
    }
  }

  // Traverse descendants (positive layers)
  const downQueue = [rootId];
  while (downQueue.length) {
    const id = downQueue.shift();
    const kids = children.get(id) || [];
    for (const k of kids) {
      if (!layers.has(k)) {
        layers.set(k, layers.get(id) + 1);
        downQueue.push(k);
      }
    }
  }

  // Assign remaining nodes (non-family) to layer 0
  for (const n of nodes) {
    if (!layers.has(n.id)) layers.set(n.id, 0);
  }

  // Group by layer
  const layerGroups = new Map();
  for (const [id, layer] of layers) {
    if (!layerGroups.has(layer)) layerGroups.set(layer, []);
    layerGroups.get(layer).push(id);
  }

  // Sort layers numerically
  const sortedLayers = [...layerGroups.keys()].sort((a, b) => a - b);

  // Barycenter ordering: order nodes within each layer by avg X of connected parent nodes
  const xPos = new Map();
  const NODE_W = 140, NODE_H = 48, GAP_X = 30, GAP_Y = 80;
  const MARRIAGE_GAP = 8;

  // First pass: initial X based on order
  for (const layer of sortedLayers) {
    const group = layerGroups.get(layer);
    // Place married pairs adjacent
    const placed = new Set();
    const ordered = [];
    for (const id of group) {
      if (placed.has(id)) continue;
      placed.add(id);
      ordered.push(id);
      const partner = marriages.get(id);
      if (partner && group.includes(partner) && !placed.has(partner)) {
        placed.add(partner);
        ordered.push(partner);
      }
    }
    layerGroups.set(layer, ordered);
  }

  // Barycenter refinement (2 passes)
  for (let pass = 0; pass < 2; pass++) {
    for (const layer of sortedLayers) {
      const group = layerGroups.get(layer);
      const bary = new Map();
      for (const id of group) {
        const pars = parents.get(id) || [];
        const connectedX = pars.map(p => xPos.get(p) ?? 0).filter(v => v !== undefined);
        bary.set(id, connectedX.length ? connectedX.reduce((a, b) => a + b, 0) / connectedX.length : (xPos.get(id) ?? 0));
      }
      group.sort((a, b) => (bary.get(a) || 0) - (bary.get(b) || 0));
      // Re-assign X
      let x = 0;
      for (const id of group) {
        xPos.set(id, x);
        const partner = marriages.get(id);
        x += NODE_W + (partner && group.indexOf(partner) === group.indexOf(id) + 1 ? MARRIAGE_GAP : GAP_X);
      }
    }
  }

  // Center each layer
  for (const layer of sortedLayers) {
    const group = layerGroups.get(layer);
    const maxX = Math.max(...group.map(id => xPos.get(id)));
    const offset = -maxX / 2;
    for (const id of group) xPos.set(id, xPos.get(id) + offset);
  }

  // Normalize: shift so min is 40
  const minLayer = sortedLayers[0];
  const allX = [...xPos.values()];
  const shiftX = 40 - Math.min(...allX);
  const shiftY = 40 - minLayer * GAP_Y;

  const positions = new Map();
  for (const [id, x] of xPos) {
    positions.set(id, {
      x: x + shiftX,
      y: (layers.get(id) ?? 0) * GAP_Y + shiftY,
      w: NODE_W,
      h: NODE_H,
    });
  }

  // Compute total size
  const allPos = [...positions.values()];
  const totalW = Math.max(...allPos.map(p => p.x + p.w)) + 40;
  const totalH = Math.max(...allPos.map(p => p.y + p.h)) + 40;

  return { positions, marriages, totalW, totalH };
}

// ══════════════════════════════════════════════════════════════════════════════
// Force-Directed Layout
// ══════════════════════════════════════════════════════════════════════════════

function layoutForceDirected(data, centerId) {
  const { nodes, edges } = data;
  const NODE_W = 140, NODE_H = 48;

  // Initialize positions in a circle around center
  const cx = 400, cy = 300;
  const posArr = nodes.map((n, i) => {
    if (n.id === centerId) return { x: cx, y: cy, vx: 0, vy: 0 };
    const angle = (i / nodes.length) * Math.PI * 2;
    const r = 150 + Math.random() * 50;
    return { x: cx + Math.cos(angle) * r, y: cy + Math.sin(angle) * r, vx: 0, vy: 0 };
  });

  const nodeIndex = new Map(nodes.map((n, i) => [n.id, i]));
  const edgeIndices = edges.map(e => [nodeIndex.get(e.source_id), nodeIndex.get(e.target_id)]).filter(([a, b]) => a != null && b != null);

  const REPULSION = 8000;
  const ATTRACTION = 0.005;
  const DAMPING = 0.85;
  const ITERATIONS = 80;

  for (let iter = 0; iter < ITERATIONS; iter++) {
    const temp = 1 - iter / ITERATIONS;

    // Repulsion between all pairs
    for (let i = 0; i < posArr.length; i++) {
      for (let j = i + 1; j < posArr.length; j++) {
        const dx = posArr[i].x - posArr[j].x;
        const dy = posArr[i].y - posArr[j].y;
        const d2 = dx * dx + dy * dy + 1;
        const f = REPULSION / d2;
        const fx = dx / Math.sqrt(d2) * f * temp;
        const fy = dy / Math.sqrt(d2) * f * temp;
        posArr[i].vx += fx; posArr[i].vy += fy;
        posArr[j].vx -= fx; posArr[j].vy -= fy;
      }
    }

    // Attraction along edges
    for (const [i, j] of edgeIndices) {
      const dx = posArr[j].x - posArr[i].x;
      const dy = posArr[j].y - posArr[i].y;
      const d = Math.sqrt(dx * dx + dy * dy) + 1;
      const f = d * ATTRACTION * temp;
      posArr[i].vx += dx / d * f; posArr[i].vy += dy / d * f;
      posArr[j].vx -= dx / d * f; posArr[j].vy -= dy / d * f;
    }

    // Centering
    for (const p of posArr) {
      p.vx += (cx - p.x) * 0.001;
      p.vy += (cy - p.y) * 0.001;
    }

    // Apply velocities with damping
    for (const p of posArr) {
      p.x += p.vx; p.y += p.vy;
      p.vx *= DAMPING; p.vy *= DAMPING;
    }
  }

  // Normalize: shift so min is 40
  const minX = Math.min(...posArr.map(p => p.x));
  const minY = Math.min(...posArr.map(p => p.y));
  const shiftX = 40 - minX;
  const shiftY = 40 - minY;

  const positions = new Map();
  for (let i = 0; i < nodes.length; i++) {
    positions.set(nodes[i].id, {
      x: posArr[i].x + shiftX,
      y: posArr[i].y + shiftY,
      w: NODE_W,
      h: NODE_H,
    });
  }

  const totalW = Math.max(...[...positions.values()].map(p => p.x + p.w)) + 40;
  const totalH = Math.max(...[...positions.values()].map(p => p.y + p.h)) + 40;

  return { positions, marriages: new Map(), totalW, totalH };
}

// ══════════════════════════════════════════════════════════════════════════════
// DOM + SVG Rendering
// ══════════════════════════════════════════════════════════════════════════════

function renderGraphDOM(container, layout, data, focusId) {
  const { positions, marriages, totalW, totalH } = layout;
  const { nodes, edges, truncated_edges } = data;
  const nodeMap = new Map(nodes.map(n => [n.id, n]));

  container.style.width  = totalW + 'px';
  container.style.height = totalH + 'px';

  // SVG layer for edges
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('width', totalW);
  svg.setAttribute('height', totalH);
  svg.style.position = 'absolute';
  svg.style.top = '0';
  svg.style.left = '0';
  svg.style.pointerEvents = 'none';
  container.appendChild(svg);

  // Defs for arrowheads
  const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
  defs.innerHTML = `<marker id="arrow" viewBox="0 0 10 6" refX="10" refY="3"
    markerWidth="8" markerHeight="6" orient="auto-start-reverse">
    <path d="M 0 0 L 10 3 L 0 6 z" fill="var(--text-dim)" />
  </marker>`;
  svg.appendChild(defs);

  // Draw edges
  for (const edge of edges) {
    const sPos = positions.get(edge.source_id);
    const tPos = positions.get(edge.target_id);
    if (!sPos || !tPos) continue;

    const reg = RELATIONSHIP_REGISTRY[edge.relationship] || RELATIONSHIP_REGISTRY.custom;
    const color = CATEGORY_COLORS[reg.category] || '#776644';
    const ended = !!edge.end_node_id;

    const x1 = sPos.x + sPos.w / 2, y1 = sPos.y + sPos.h / 2;
    const x2 = tPos.x + tPos.w / 2, y2 = tPos.y + tPos.h / 2;

    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    const midX = (x1 + x2) / 2, midY = (y1 + y2) / 2;
    const dx = x2 - x1, dy = y2 - y1;
    // Bezier with slight curve
    const cx1 = x1 + dx * 0.3 - dy * 0.1;
    const cy1 = y1 + dy * 0.3 + dx * 0.1;
    const cx2 = x2 - dx * 0.3 - dy * 0.1;
    const cy2 = y2 - dy * 0.3 + dx * 0.1;
    path.setAttribute('d', `M ${x1} ${y1} C ${cx1} ${cy1}, ${cx2} ${cy2}, ${x2} ${y2}`);
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke', color);
    path.setAttribute('stroke-width', '2');
    path.setAttribute('opacity', ended ? '0.35' : '0.7');
    if (ended) path.setAttribute('stroke-dasharray', '6 4');
    if (!reg.symmetric) path.setAttribute('marker-end', 'url(#arrow)');
    svg.appendChild(path);

    // Edge label at midpoint
    const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    label.setAttribute('x', midX);
    label.setAttribute('y', midY - 6);
    label.setAttribute('text-anchor', 'middle');
    label.setAttribute('fill', color);
    label.setAttribute('font-size', '10');
    label.setAttribute('opacity', '0.8');
    label.textContent = edge.relationship.replace(/_/g, ' ');
    svg.appendChild(label);
  }

  // Draw marriage lines
  for (const [id1, id2] of marriages) {
    if (id1 > id2) continue; // draw once
    const p1 = positions.get(id1), p2 = positions.get(id2);
    if (!p1 || !p2) continue;
    const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    line.setAttribute('x1', p1.x + p1.w); line.setAttribute('y1', p1.y + p1.h / 2);
    line.setAttribute('x2', p2.x);        line.setAttribute('y2', p2.y + p2.h / 2);
    line.setAttribute('stroke', CATEGORY_COLORS.family);
    line.setAttribute('stroke-width', '2');
    line.setAttribute('opacity', '0.6');
    svg.appendChild(line);
  }

  // Draw truncated edge indicators (fading stubs)
  for (const trunc of (truncated_edges || [])) {
    const pos = positions.get(trunc.node_id);
    if (!pos) continue;
    const cx = pos.x + pos.w / 2;
    const cy = pos.y + pos.h + 8;

    // Fading line downward
    const grad = document.createElementNS('http://www.w3.org/2000/svg', 'linearGradient');
    const gradId = 'fade-' + trunc.node_id.replace(/[^a-z0-9]/gi, '');
    grad.setAttribute('id', gradId);
    grad.setAttribute('x1', '0'); grad.setAttribute('y1', '0');
    grad.setAttribute('x2', '0'); grad.setAttribute('y2', '1');
    grad.innerHTML = '<stop offset="0%" stop-color="var(--text-dim)" stop-opacity="0.6"/><stop offset="100%" stop-color="var(--text-dim)" stop-opacity="0"/>';
    defs.appendChild(grad);

    const fadeLine = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    fadeLine.setAttribute('x1', cx); fadeLine.setAttribute('y1', cy);
    fadeLine.setAttribute('x2', cx); fadeLine.setAttribute('y2', cy + 30);
    fadeLine.setAttribute('stroke', `url(#${gradId})`);
    fadeLine.setAttribute('stroke-width', '2');
    svg.appendChild(fadeLine);

    // "+N" button (DOM, not SVG)
    const btn = document.createElement('div');
    btn.className = 'graph-expand-btn';
    btn.style.left = (cx - 11) + 'px';
    btn.style.top  = (cy + 30) + 'px';
    btn.textContent = '+' + trunc.count;
    btn.title = `${trunc.count} more connection(s): ${trunc.relationship_types.join(', ')}`;
    btn.addEventListener('click', () => {
      // Increase depth and re-render
      const slider = document.getElementById('rel-depth-slider');
      if (slider) {
        slider.value = Math.min(5, parseInt(slider.value) + 1);
        document.getElementById('rel-depth-val').textContent = slider.value;
      }
      renderRelGraph(focusId);
    });
    container.appendChild(btn);
  }

  // DOM nodes
  for (const node of nodes) {
    const pos = positions.get(node.id);
    if (!pos) continue;
    const div = document.createElement('div');
    div.className = 'graph-node';
    div.style.left   = pos.x + 'px';
    div.style.top    = pos.y + 'px';
    div.style.width  = pos.w + 'px';
    div.style.height = pos.h + 'px';
    div.style.borderColor = node.color || '#7c6bff';
    if (node.id === focusId) div.style.boxShadow = `0 0 12px ${node.color || '#7c6bff'}44`;
    div.innerHTML = `<span class="gn-name">${node.name}</span><span class="gn-type">${node.entity_type}</span>`;
    div.addEventListener('click', () => {
      document.getElementById('ent-rel-graph-wrap').style.display = 'none';
      document.getElementById('ent-viewer').style.display = 'block';
      openEntity(node.id);
    });
    container.appendChild(div);
  }
}
