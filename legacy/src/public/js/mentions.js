'use strict';

// ══════════════════════════════════════════════════════════════════════════════
// @mention parsing (entities + events)
// ══════════════════════════════════════════════════════════════════════════════

// Matches @Word, @[Multi Word Entity], @{Event Title}, @(Doc Title) in rendered HTML (outside tags).
// Entity mentions → .entity-mention, Event mentions → .event-mention, Doc mentions → .doc-mention
function parseMentions(html) {
  // Process text nodes only (skip inside HTML tags)
  return html.replace(/(>|^)([^<]*?)(<|$)/g, (full, open, text, close) => {
    const parsed = text.replace(/@\(([^)]+)\)|@\{([^}]+)\}|@\[([^\]]+)\]|@(\w+)/g, (m, doc, event, bracketed, single) => {
      if (doc) {
        return `<span class="doc-mention" data-doc="${doc}" title="Document: ${doc}">${doc}</span>`;
      }
      if (event) {
        return `<span class="event-mention" data-event="${event}" title="Event: ${event}">${event}</span>`;
      }
      const name = bracketed || single;
      return `<span class="entity-mention" data-entity="${name}" title="Entity: ${name}">${name}</span>`;
    });
    return open + parsed + close;
  });
}

// Backward compat alias
function parseEntityMentions(html) { return parseMentions(html); }

// Render markdown with @mention support
function renderMarkdown(text) {
  if (!text) return '';
  const html = typeof marked !== 'undefined' ? marked.parse(text) : text.replace(/\n/g, '<br>');
  return parseMentions(html);
}

// Handle mention clicks (delegated)
document.addEventListener('click', async e => {
  // Doc mentions
  const docMention = e.target.closest('.doc-mention');
  if (docMention) {
    const title = docMention.dataset.doc;
    if (!title) return;
    try {
      const res = await apiFetch(`${API}/docs/by-title/${encodeURIComponent(title)}`);
      if (res.ok) {
        const doc = await res.json();
        openSecondary('docs');
        await loadDocsList();
        openDoc(doc.id);
      } else {
        alert(`Document "${title}" not found.`);
      }
    } catch (err) {
      console.error('Document lookup failed:', err);
    }
    return;
  }

  // Entity mentions
  const entityMention = e.target.closest('.entity-mention');
  if (entityMention) {
    const name = entityMention.dataset.entity;
    if (!name) return;
    try {
      const res = await apiFetch(`${API}/entities/by-name/${encodeURIComponent(name)}`);
      if (res.ok) {
        const ent = await res.json();
        openSecondary('entities');
        await loadEntitiesList();
        openEntity(ent.id);
      } else {
        if (confirm(`Entity "${name}" doesn't exist yet. Create it?`)) {
          const created = await apiFetch(`${API}/entities`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name }),
          }).then(r => r.json());
          openSecondary('entities');
          await loadEntitiesList();
          openEntity(created.id);
        }
      }
    } catch (err) {
      console.error('Entity lookup failed:', err);
    }
    return;
  }

  // Event mentions
  const eventMention = e.target.closest('.event-mention');
  if (eventMention) {
    const title = eventMention.dataset.event;
    if (!title) return;
    try {
      const res = await apiFetch(`${API}/nodes/by-title/${encodeURIComponent(title)}`);
      if (res.ok) {
        const node = await res.json();
        navigateToNode(node.id);
      } else {
        alert(`Event "${title}" not found.`);
      }
    } catch (err) {
      console.error('Event lookup failed:', err);
    }
  }
});

// ══════════════════════════════════════════════════════════════════════════════
// @mention autocomplete (entities + events)
// ══════════════════════════════════════════════════════════════════════════════

const Mention = {
  active: false,
  textarea: null,       // the textarea being typed in
  startPos: 0,          // caret position of the '@'
  selectedIdx: 0,       // currently highlighted suggestion
  items: [],            // filtered results (entities + events)
  fetchId: 0,           // debounce: only apply results from latest request
};

async function mentionSearch(query) {
  const id = ++Mention.fetchId;
  try {
    const res = await apiFetch(`${API}/mentions/search?q=${encodeURIComponent(query)}&limit=10`);
    if (id !== Mention.fetchId) return null; // stale request
    const data = await res.json();
    return data.items || [];
  } catch { return null; }
}

function mentionGetQuery(textarea) {
  const val = textarea.value;
  const caret = textarea.selectionStart;
  // Walk backwards from caret to find '@'
  let i = caret - 1;
  while (i >= 0) {
    const ch = val[i];
    if (ch === '@') return { start: i, query: val.slice(i + 1, caret) };
    if (ch === '\n' || ch === '\r') return null;  // don't cross line boundaries
    i--;
  }
  return null;
}

function mentionHighlight(name, query) {
  if (!query) return name;
  const idx = name.toLowerCase().indexOf(query.toLowerCase());
  if (idx < 0) return name;
  return name.slice(0, idx) + '<mark>' + name.slice(idx, idx + query.length) + '</mark>' + name.slice(idx + query.length);
}

async function mentionShow(textarea) {
  const dropdown = document.getElementById('mention-dropdown');
  const info = mentionGetQuery(textarea);
  if (!info) { mentionHide(); return; }

  Mention.startPos = info.start;
  Mention.textarea = textarea;
  Mention.selectedIdx = 0;

  if (!info.query) {
    Mention.items = [];
    dropdown.innerHTML = '<div class="mention-hint">Type to search entities, events &amp; docs\u2026</div>';
    dropdown.classList.remove('hidden');
    mentionPosition(textarea, dropdown);
    Mention.active = true;
    return;
  }

  const items = await mentionSearch(info.query);
  if (items === null) return; // stale request, ignore

  Mention.items = items;

  if (!items.length) {
    dropdown.innerHTML = '<div class="mention-hint">No matches</div>';
    dropdown.classList.remove('hidden');
    mentionPosition(textarea, dropdown);
    Mention.active = true;
    return;
  }

  dropdown.innerHTML = items.map((e, i) => {
    const isEvent = e._kind === 'event';
    const isDoc = e._kind === 'doc';
    const typeLabel = e.type_label || (isDoc ? 'doc' : isEvent ? 'event' : 'entity');
    const colorDot = e.color || (isDoc ? '#c9963a' : isEvent ? '#558899' : '#7c6bff');
    const icon = isDoc ? '📄' : isEvent ? '⟡' : '●';
    const kindClass = isDoc ? ' mention-doc' : isEvent ? ' mention-event' : '';
    return `
    <div class="mention-item${i === 0 ? ' selected' : ''}${kindClass}" data-idx="${i}">
      <div class="mi-color" style="background:${colorDot}"></div>
      <span class="mi-icon">${icon}</span>
      <span class="mi-name">${mentionHighlight(e.name, info.query)}</span>
      <span class="mi-type">${typeLabel}</span>
    </div>`;
  }).join('');

  dropdown.classList.remove('hidden');
  mentionPosition(textarea, dropdown);
  Mention.active = true;

  // Click handlers on items
  dropdown.querySelectorAll('.mention-item').forEach(el => {
    el.addEventListener('mousedown', ev => {
      ev.preventDefault(); // prevent textarea blur
      mentionComplete(parseInt(el.dataset.idx));
    });
  });
}

function mentionPosition(textarea, dropdown) {
  const taRect = textarea.getBoundingClientRect();
  const style = getComputedStyle(textarea);
  const lh = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.4 || 18;

  // Mirror div replicates textarea text layout to find caret coordinates
  const mirror = document.createElement('div');
  const copyProps = [
    'fontFamily','fontSize','fontWeight','fontStyle','letterSpacing','wordSpacing',
    'textIndent','lineHeight','paddingTop','paddingRight','paddingBottom','paddingLeft',
    'borderTopWidth','borderRightWidth','borderBottomWidth','borderLeftWidth','boxSizing',
  ];
  copyProps.forEach(p => { mirror.style[p] = style[p]; });
  mirror.style.position = 'absolute';
  mirror.style.top = '0';
  mirror.style.left = '-9999px';
  mirror.style.width = taRect.width + 'px';
  mirror.style.whiteSpace = 'pre-wrap';
  mirror.style.wordWrap = 'break-word';
  mirror.style.overflow = 'hidden';
  mirror.style.visibility = 'hidden';
  mirror.style.height = 'auto';

  // Insert text before caret, then a marker span
  const text = textarea.value.substring(0, textarea.selectionStart);
  mirror.textContent = text;
  const marker = document.createElement('span');
  marker.textContent = '\u200b';
  mirror.appendChild(marker);

  document.body.appendChild(mirror);
  const markerTop = marker.offsetTop;
  const markerLeft = marker.offsetLeft;
  document.body.removeChild(mirror);

  // Translate mirror-relative offset to viewport coordinates
  const borderTop = parseFloat(style.borderTopWidth) || 0;
  const borderLeft = parseFloat(style.borderLeftWidth) || 0;

  let top = taRect.top + borderTop + markerTop - textarea.scrollTop + lh + 2;
  let left = taRect.left + borderLeft + markerLeft;

  // Keep within viewport
  if (top + 200 > window.innerHeight) top = top - 200 - lh - 4;
  if (left + 300 > window.innerWidth) left = window.innerWidth - 310;
  if (left < 4) left = 4;

  dropdown.style.top = top + 'px';
  dropdown.style.left = left + 'px';
}

function mentionHide() {
  Mention.active = false;
  Mention.textarea = null;
  Mention.items = [];
  document.getElementById('mention-dropdown').classList.add('hidden');
}

function mentionComplete(idx) {
  const item = Mention.items[idx];
  if (!item || !Mention.textarea) return;

  const ta = Mention.textarea;
  const before = ta.value.slice(0, Mention.startPos);
  const after = ta.value.slice(ta.selectionStart);

  let insert;
  if (item._kind === 'doc') {
    // Documents always use @(Title) (parentheses)
    insert = `@(${item.name})`;
  } else if (item._kind === 'event') {
    // Events always use @{Title} (curly braces)
    insert = `@{${item.name}}`;
  } else {
    // Entities: @[Name] for multi-word, @Name for single-word
    const hasSpace = item.name.includes(' ');
    insert = hasSpace ? `@[${item.name}]` : `@${item.name}`;
  }

  ta.value = before + insert + ' ' + after;
  const newPos = before.length + insert.length + 1;
  ta.selectionStart = ta.selectionEnd = newPos;
  ta.focus();

  mentionHide();
}

function mentionKeyHandler(e) {
  if (!Mention.active || !Mention.items.length) {
    if (Mention.active && e.key === 'Escape') { mentionHide(); e.preventDefault(); return; }
    return;
  }

  if (e.key === 'ArrowDown') {
    e.preventDefault();
    Mention.selectedIdx = (Mention.selectedIdx + 1) % Mention.items.length;
    mentionUpdateSelection();
  } else if (e.key === 'ArrowUp') {
    e.preventDefault();
    Mention.selectedIdx = (Mention.selectedIdx - 1 + Mention.items.length) % Mention.items.length;
    mentionUpdateSelection();
  } else if (e.key === 'Tab' || e.key === 'Enter') {
    e.preventDefault();
    mentionComplete(Mention.selectedIdx);
  } else if (e.key === 'Escape') {
    e.preventDefault();
    mentionHide();
  }
}

function mentionUpdateSelection() {
  const dropdown = document.getElementById('mention-dropdown');
  dropdown.querySelectorAll('.mention-item').forEach((el, i) => {
    el.classList.toggle('selected', i === Mention.selectedIdx);
    if (i === Mention.selectedIdx) el.scrollIntoView({ block: 'nearest' });
  });
}

// Attach to all textareas via event delegation
function initMentionAutocomplete() {
  document.addEventListener('input', async e => {
    if (e.target.tagName !== 'TEXTAREA') return;
    const info = mentionGetQuery(e.target);
    if (info) {
      mentionShow(e.target);
    } else if (Mention.active) {
      mentionHide();
    }
  });

  document.addEventListener('keydown', e => {
    if (e.target.tagName !== 'TEXTAREA' || !Mention.active) return;
    mentionKeyHandler(e);
  });

  document.addEventListener('focusout', e => {
    if (e.target.tagName !== 'TEXTAREA' || !Mention.active) return;
    setTimeout(mentionHide, 200);
  });
}
