'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// Module switching (split-screen aware)
// ══════════════════════════════════════════════════════════════════════════════

function switchModule(name) {
  if (name === 'timeline') {
    // Close the secondary pane → full-screen timeline
    closeSecondary();
  } else if (SplitPane.secondaryModule === name) {
    // Toggle off: clicking the already-open secondary module closes it
    closeSecondary();
  } else {
    // Open (or switch) the secondary pane
    openSecondary(name);
  }
}

function openSecondary(name) {
  SplitPane.secondaryModule = name;
  localStorage.setItem('tl-split-module', name);

  // Activate the correct module panel inside secondary pane
  document.querySelectorAll('#pane-secondary .module-panel').forEach(el =>
    el.classList.toggle('active', el.dataset.module === name));

  // Update nav button highlights: timeline stays active, plus secondary
  document.querySelectorAll('.nav-btn[data-module]').forEach(btn =>
    btn.classList.toggle('active',
      btn.dataset.module === 'timeline' || btn.dataset.module === name));

  // Timeline controls always visible (timeline is always showing)
  const tlControls = document.getElementById('timeline-controls');
  const zoomCtl    = document.getElementById('zoom-controls');
  if (tlControls) tlControls.style.display = 'flex';
  if (zoomCtl)    zoomCtl.style.display    = 'flex';

  applySplitLayout();

  // Module-specific init
  if (name === 'docs') loadDocsList();
  if (name === 'entities') loadEntitiesList();
  if (name === 'arcs') loadArcsList();

  // Clear arc highlight when switching away from arcs
  if (name !== 'arcs' && TL.selectedArc) { TL.selectedArc = null; }

  // Re-render timeline since viewport width changed
  requestAnimationFrame(() => requestAnimationFrame(renderWorld));
}

function closeSecondary() {
  SplitPane.secondaryModule = null;
  localStorage.removeItem('tl-split-module');

  // Clear arc highlight
  if (TL.selectedArc) { TL.selectedArc = null; }

  // Deactivate all secondary panels
  document.querySelectorAll('#pane-secondary .module-panel').forEach(el =>
    el.classList.remove('active'));

  // Only timeline nav button is active
  document.querySelectorAll('.nav-btn[data-module]').forEach(btn =>
    btn.classList.toggle('active', btn.dataset.module === 'timeline'));

  // Timeline controls visible
  const tlControls = document.getElementById('timeline-controls');
  const zoomCtl    = document.getElementById('zoom-controls');
  if (tlControls) tlControls.style.display = 'flex';
  if (zoomCtl)    zoomCtl.style.display    = 'flex';

  applySplitLayout();

  // Re-render timeline since viewport width changed
  requestAnimationFrame(() => requestAnimationFrame(renderWorld));
}

function applySplitLayout() {
  const container = document.getElementById('split-container');
  const primary   = document.getElementById('pane-primary');
  const secondary = document.getElementById('pane-secondary');
  const divider   = document.getElementById('split-divider');
  const isOpen    = SplitPane.secondaryModule !== null;

  if (!isOpen) {
    secondary.classList.add('hidden');
    divider.classList.add('hidden');
    container.classList.remove('split-h', 'split-v');
    primary.style.flex = '1';
    return;
  }

  secondary.classList.remove('hidden');
  divider.classList.remove('hidden');

  const isH = (SplitPane.position === 'left' || SplitPane.position === 'right');
  container.classList.toggle('split-h', isH);
  container.classList.toggle('split-v', !isH);
  container.style.flexDirection = isH ? 'row' : 'column';

  // DOM order: timeline (primary) comes first when secondary is on right/bottom.
  // Skip reordering during drag — appendChild detaches the divider, which causes
  // the browser to fire lostpointercapture and kills the drag operation.
  if (!SplitPane.isDragging) {
    const timelineFirst = (SplitPane.position === 'right' || SplitPane.position === 'bottom');
    if (timelineFirst) {
      container.appendChild(primary);
      container.appendChild(divider);
      container.appendChild(secondary);
    } else {
      container.appendChild(secondary);
      container.appendChild(divider);
      container.appendChild(primary);
    }
  }

  // Apply sizes
  const r = SplitPane.ratio;
  const secondaryPct = (r * 100).toFixed(2) + '%';
  const primaryPct   = ((1 - r) * 100).toFixed(2) + '%';

  primary.style.flex   = `0 0 calc(${primaryPct} - 3px)`;
  secondary.style.flex = `0 0 calc(${secondaryPct} - 3px)`;
}

// ── Divider drag-to-resize ──────────────────────────────────────────────────

function initDividerDrag() {
  const divider   = document.getElementById('split-divider');
  const container = document.getElementById('split-container');

  let startPos, startRatio, containerSize;

  divider.addEventListener('pointerdown', e => {
    e.preventDefault();
    SplitPane.isDragging = true;
    divider.classList.add('dragging');
    divider.setPointerCapture(e.pointerId);

    const isH = (SplitPane.position === 'left' || SplitPane.position === 'right');
    const rect = container.getBoundingClientRect();
    containerSize = isH ? rect.width : rect.height;
    startPos = isH ? e.clientX : e.clientY;
    startRatio = SplitPane.ratio;

    document.body.style.userSelect = 'none';
    document.body.style.cursor = isH ? 'col-resize' : 'row-resize';
  });

  divider.addEventListener('pointermove', e => {
    if (!SplitPane.isDragging) return;

    const isH = (SplitPane.position === 'left' || SplitPane.position === 'right');
    const currentPos = isH ? e.clientX : e.clientY;
    const delta = currentPos - startPos;

    // When timeline is first (secondary on right/bottom), dragging right/down
    // means secondary shrinks. When secondary is first (left/top), dragging
    // right/down means secondary grows.
    const timelineFirst = (SplitPane.position === 'right' || SplitPane.position === 'bottom');
    const sign = timelineFirst ? -1 : 1;

    const deltaRatio = (delta / containerSize) * sign;
    SplitPane.ratio = Math.min(SplitPane.maxRatio,
      Math.max(SplitPane.minRatio, startRatio + deltaRatio));

    applySplitLayout();
  });

  function endDrag() {
    if (!SplitPane.isDragging) return;
    SplitPane.isDragging = false;
    divider.classList.remove('dragging');
    document.body.style.userSelect = '';
    document.body.style.cursor = '';
    localStorage.setItem('tl-split-ratio', SplitPane.ratio.toString());
    renderWorld();
  }

  divider.addEventListener('pointerup', e => {
    divider.releasePointerCapture(e.pointerId);
    endDrag();
  });

  divider.addEventListener('lostpointercapture', endDrag);
}

// ── Split position config ───────────────────────────────────────────────────

function initSplitPositionPicker() {
  const picker = document.getElementById('split-pos-picker');
  if (!picker) return;

  function updateActive() {
    picker.querySelectorAll('.split-pos-btn').forEach(btn =>
      btn.classList.toggle('active', btn.dataset.pos === SplitPane.position));
  }
  updateActive();

  picker.addEventListener('click', e => {
    const btn = e.target.closest('.split-pos-btn');
    if (!btn) return;
    SplitPane.position = btn.dataset.pos;
    localStorage.setItem('tl-split-position', SplitPane.position);
    updateActive();
    if (SplitPane.secondaryModule) {
      applySplitLayout();
      requestAnimationFrame(() => requestAnimationFrame(renderWorld));
    }
  });
}
