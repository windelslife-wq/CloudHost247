/**
 * CloudHost247 Marketing — email builder.
 *
 * Vanilla ES5-compatible JavaScript. No framework, no build step, no CDN:
 * this runs inside the WHMCS admin area, which already loads jQuery and
 * Bootstrap 3, and adding a toolchain to a WHMCS addon is a maintenance debt
 * nobody wants to inherit.
 *
 * Responsibilities
 *   - drag blocks and layouts from the palette into the canvas
 *   - reorder and delete rows and blocks
 *   - edit block props through a form generated from the server manifest
 *   - keep a single JSON design document that the PHP renderer compiles
 *
 * Deliberately NOT responsible for producing the final email HTML. The canvas
 * is an approximation for editing; the real output comes from the PHP
 * renderer, which is the only thing that knows about Outlook ghost tables and
 * inlined styles. Two renderers would drift within a week.
 */
(function () {
  'use strict';

  var root = document.querySelector('.ch247m-builder');
  if (!root || root.getAttribute('data-ready') === '1') {
    return;
  }
  root.setAttribute('data-ready', '1');

  /* ------------------------------------------------------------ state -- */

  var manifest = parseJson(root.getAttribute('data-manifest'), { blocks: {}, layouts: {}, designDefaults: {}, rowDefaults: {} });
  var design = normaliseDesign(parseJson(root.getAttribute('data-design'), null));

  var canvas = root.querySelector('[data-role="canvas"]');
  var inspector = root.querySelector('[data-role="inspector"]');
  var statusEl = root.querySelector('[data-role="status"]');
  var designInput = root.querySelector('[data-role="design-input"]');
  var saveForm = root.querySelector('[data-role="save-form"]');

  var selection = null;      // {rowId, cellIndex, blockId} or {rowId:…} for a row
  var dragPayload = null;    // {kind:'new-block'|'new-layout'|'move-block', …}
  var history = [];
  var dirty = false;
  var previewMode = false;

  /* ------------------------------------------------------------- init -- */

  render();
  bindPalette();
  bindToolbar();
  bindShortcuts();
  renderDesignSettings();
  setStatus('Ready');

  window.addEventListener('beforeunload', function (e) {
    if (!dirty) { return undefined; }
    e.preventDefault();
    e.returnValue = 'You have unsaved changes to this email.';
    return e.returnValue;
  });

  /* -------------------------------------------------------- utilities -- */

  function parseJson(raw, fallback) {
    if (!raw) { return fallback; }
    try {
      var value = JSON.parse(raw);
      return value === null ? fallback : value;
    } catch (err) {
      return fallback;
    }
  }

  function uid(prefix) {
    return (prefix || 'x') + Math.random().toString(36).slice(2, 9);
  }

  function clone(value) {
    return JSON.parse(JSON.stringify(value));
  }

  function esc(value) {
    return String(value === null || value === undefined ? '' : value)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function normaliseDesign(input) {
    var out = { settings: {}, rows: [] };
    var defaults = manifest.designDefaults || {};
    var key;
    for (key in defaults) {
      if (Object.prototype.hasOwnProperty.call(defaults, key)) {
        out.settings[key] = defaults[key];
      }
    }
    if (input && input.settings) {
      for (key in input.settings) {
        if (Object.prototype.hasOwnProperty.call(out.settings, key)) {
          out.settings[key] = input.settings[key];
        }
      }
    }
    if (input && Object.prototype.toString.call(input.rows) === '[object Array]') {
      for (var i = 0; i < input.rows.length; i++) {
        var row = adoptRow(input.rows[i]);
        if (row) { out.rows.push(row); }
      }
    }
    return out;
  }

  function adoptRow(raw) {
    if (!raw) { return null; }
    var layoutKey = raw.layout && manifest.layouts[raw.layout] ? raw.layout : 'col-1';
    var layout = manifest.layouts[layoutKey];
    var widths = (Object.prototype.toString.call(raw.widths) === '[object Array]' && raw.widths.length)
      ? raw.widths.slice(0)
      : layout.widths.slice(0);

    var row = {
      id: typeof raw.id === 'string' ? raw.id : uid('r'),
      layout: layoutKey,
      widths: widths,
      props: mergeProps(manifest.rowDefaults, layout.props || {}, raw.props || {}),
      cells: []
    };
    for (var c = 0; c < widths.length; c++) {
      var cell = [];
      var source = (raw.cells && raw.cells[c]) || [];
      for (var b = 0; b < source.length; b++) {
        var block = adoptBlock(source[b]);
        if (block) { cell.push(block); }
      }
      row.cells.push(cell);
    }
    return row;
  }

  function adoptBlock(raw) {
    if (!raw || !raw.type || !manifest.blocks[raw.type]) { return null; }
    return {
      id: typeof raw.id === 'string' ? raw.id : uid('b'),
      type: raw.type,
      props: mergeProps(manifest.blocks[raw.type].props, {}, raw.props || {})
    };
  }

  function mergeProps() {
    var out = {};
    for (var i = 0; i < arguments.length; i++) {
      var src = arguments[i] || {};
      for (var key in src) {
        if (Object.prototype.hasOwnProperty.call(src, key)) {
          if (i === 0 || Object.prototype.hasOwnProperty.call(out, key)) {
            out[key] = src[key];
          }
        }
      }
    }
    return out;
  }

  function findRow(rowId) {
    for (var i = 0; i < design.rows.length; i++) {
      if (design.rows[i].id === rowId) { return design.rows[i]; }
    }
    return null;
  }

  function findBlock(ref) {
    if (!ref || !ref.blockId) { return null; }
    var row = findRow(ref.rowId);
    if (!row) { return null; }
    var cell = row.cells[ref.cellIndex];
    if (!cell) { return null; }
    for (var i = 0; i < cell.length; i++) {
      if (cell[i].id === ref.blockId) { return cell[i]; }
    }
    return null;
  }

  function pushHistory() {
    history.push(JSON.stringify(design));
    if (history.length > 40) { history.shift(); }
  }

  function markDirty() {
    dirty = true;
    setStatus('Unsaved changes');
  }

  function setStatus(text, tone) {
    if (!statusEl) { return; }
    statusEl.textContent = text;
    statusEl.className = 'ch247m-builder-status' + (tone ? ' is-' + tone : '');
  }

  function blockCount() {
    var n = 0;
    for (var i = 0; i < design.rows.length; i++) {
      for (var c = 0; c < design.rows[i].cells.length; c++) {
        n += design.rows[i].cells[c].length;
      }
    }
    return n;
  }

  /* ---------------------------------------------------------- palette -- */

  function bindPalette() {
    var tabs = root.querySelectorAll('.ch247m-palette-tabs button');
    for (var i = 0; i < tabs.length; i++) {
      tabs[i].addEventListener('click', function () {
        var target = this.getAttribute('data-palette');
        var all = root.querySelectorAll('.ch247m-palette-tabs button');
        for (var j = 0; j < all.length; j++) { all[j].classList.remove('active'); }
        this.classList.add('active');
        var panes = root.querySelectorAll('.ch247m-palette-pane');
        for (var k = 0; k < panes.length; k++) {
          panes[k].classList.toggle('active', panes[k].getAttribute('data-pane') === target);
        }
      });
    }

    var chips = root.querySelectorAll('.ch247m-chip');
    for (var c = 0; c < chips.length; c++) {
      (function (chip) {
        chip.addEventListener('dragstart', function (e) {
          dragPayload = chip.hasAttribute('data-layout')
            ? { kind: 'new-layout', layout: chip.getAttribute('data-layout') }
            : { kind: 'new-block', type: chip.getAttribute('data-block') };
          try { e.dataTransfer.setData('text/plain', 'ch247m'); } catch (err) { /* IE */ }
          e.dataTransfer.effectAllowed = 'copy';
          root.classList.add('is-dragging');
        });
        chip.addEventListener('dragend', function () {
          dragPayload = null;
          root.classList.remove('is-dragging');
          clearDropHighlights();
        });
        // Click-to-append, because dragging is painful on a laptop trackpad.
        chip.addEventListener('click', function () {
          if (chip.hasAttribute('data-layout')) {
            addRow(chip.getAttribute('data-layout'), design.rows.length);
          } else {
            appendBlockToLastCell(chip.getAttribute('data-block'));
          }
        });
      }(chips[c]));
    }
  }

  /* ---------------------------------------------------------- toolbar -- */

  function bindToolbar() {
    var saveBtn = root.querySelector('[data-role="save"]');
    if (saveBtn) {
      saveBtn.addEventListener('click', function () { save(); });
    }
    var undoBtn = root.querySelector('[data-role="undo"]');
    if (undoBtn) {
      undoBtn.addEventListener('click', function () { undo(); });
    }
    var previewBtn = root.querySelector('[data-role="toggle-preview"]');
    if (previewBtn) {
      previewBtn.addEventListener('click', function () {
        previewMode = !previewMode;
        root.classList.toggle('is-preview', previewMode);
        previewBtn.textContent = previewMode ? 'Edit mode' : 'Preview mode';
        if (previewMode) { selection = null; renderInspector(); }
        render();
      });
    }
  }

  function bindShortcuts() {
    document.addEventListener('keydown', function (e) {
      if (!root.offsetParent) { return; }
      var tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
      var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || (e.target && e.target.isContentEditable);
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        save();
        return;
      }
      if ((e.ctrlKey || e.metaKey) && e.key === 'z' && !typing) {
        e.preventDefault();
        undo();
        return;
      }
      if ((e.key === 'Delete' || e.key === 'Backspace') && !typing && selection && selection.blockId) {
        e.preventDefault();
        deleteBlock(selection);
      }
    });
  }

  function undo() {
    if (!history.length) {
      setStatus('Nothing to undo');
      return;
    }
    design = normaliseDesign(JSON.parse(history.pop()));
    selection = null;
    render();
    renderInspector();
    renderDesignSettings();
    markDirty();
    setStatus('Undone');
  }

  function save() {
    if (!saveForm || !designInput) { return; }
    designInput.value = JSON.stringify(design);
    dirty = false;
    setStatus('Saving…');
    saveForm.submit();
  }

  /* ----------------------------------------------------------- canvas -- */

  function render() {
    if (!canvas) { return; }
    canvas.innerHTML = '';
    canvas.style.backgroundColor = design.settings.backgroundColor || '#f4f5f7';

    if (!design.rows.length) {
      var empty = document.createElement('div');
      empty.className = 'ch247m-empty';
      empty.textContent = 'Drag a layout or a content block here to begin.';
      canvas.appendChild(empty);
      makeRowDropZone(empty, 0);
      return;
    }

    canvas.appendChild(rowGap(0));
    for (var i = 0; i < design.rows.length; i++) {
      canvas.appendChild(renderRow(design.rows[i], i));
      canvas.appendChild(rowGap(i + 1));
    }
  }

  function rowGap(index) {
    var gap = document.createElement('div');
    gap.className = 'ch247m-rowgap';
    makeRowDropZone(gap, index);
    return gap;
  }

  function renderRow(row, index) {
    var el = document.createElement('div');
    el.className = 'ch247m-row';
    el.setAttribute('data-row', row.id);
    if (selection && selection.rowId === row.id && !selection.blockId) {
      el.classList.add('is-selected');
    }

    var inner = document.createElement('div');
    inner.className = 'ch247m-row-inner';
    inner.style.backgroundColor = row.props.backgroundColor || '#ffffff';
    inner.style.paddingTop = (row.props.paddingTop || 0) + 'px';
    inner.style.paddingBottom = (row.props.paddingBottom || 0) + 'px';
    inner.style.paddingLeft = (row.props.paddingLeft || 0) + 'px';
    inner.style.paddingRight = (row.props.paddingRight || 0) + 'px';
    inner.style.maxWidth = row.props.fullWidth ? 'none' : ((design.settings.contentWidth || 600) + 'px');

    var total = 0;
    for (var w = 0; w < row.widths.length; w++) { total += Number(row.widths[w]) || 1; }

    for (var c = 0; c < row.cells.length; c++) {
      var cell = document.createElement('div');
      cell.className = 'ch247m-cell';
      cell.style.flex = (Number(row.widths[c]) || 1) + ' 1 0';
      cell.setAttribute('data-cell', String(c));
      makeCellDropZone(cell, row.id, c);

      var blocks = row.cells[c];
      if (!blocks.length) {
        var ph = document.createElement('div');
        ph.className = 'ch247m-cell-empty';
        ph.textContent = 'Drop content here';
        cell.appendChild(ph);
      }
      for (var b = 0; b < blocks.length; b++) {
        cell.appendChild(renderBlock(row, c, blocks[b], b));
      }
      inner.appendChild(cell);
    }

    el.appendChild(rowToolbar(row, index));
    el.appendChild(inner);

    el.addEventListener('click', function (e) {
      if (previewMode) { return; }
      if (e.target === el || e.target === inner) {
        selection = { rowId: row.id };
        render();
        renderInspector();
      }
    });
    return el;
  }

  function rowToolbar(row, index) {
    var bar = document.createElement('div');
    bar.className = 'ch247m-row-tools';
    bar.appendChild(toolButton('▲', 'Move up', function () { moveRow(index, index - 1); }));
    bar.appendChild(toolButton('▼', 'Move down', function () { moveRow(index, index + 1); }));
    bar.appendChild(toolButton('⧉', 'Duplicate row', function () { duplicateRow(index); }));
    bar.appendChild(toolButton('✎', 'Row settings', function () {
      selection = { rowId: row.id };
      render();
      renderInspector();
    }));
    bar.appendChild(toolButton('✕', 'Delete row', function () { deleteRow(index); }, 'danger'));
    return bar;
  }

  function renderBlock(row, cellIndex, block, position) {
    var el = document.createElement('div');
    el.className = 'ch247m-block ch247m-block-' + block.type;
    el.setAttribute('data-block-id', block.id);
    el.setAttribute('draggable', previewMode ? 'false' : 'true');
    if (selection && selection.blockId === block.id) {
      el.classList.add('is-selected');
    }

    var body = document.createElement('div');
    body.className = 'ch247m-block-body';
    body.innerHTML = previewBlock(block);
    el.appendChild(body);

    if (!previewMode) {
      var tools = document.createElement('div');
      tools.className = 'ch247m-block-tools';
      tools.appendChild(toolButton('▲', 'Move up', function () { moveBlock(row.id, cellIndex, position, position - 1); }));
      tools.appendChild(toolButton('▼', 'Move down', function () { moveBlock(row.id, cellIndex, position, position + 1); }));
      tools.appendChild(toolButton('⧉', 'Duplicate', function () { duplicateBlock(row.id, cellIndex, position); }));
      tools.appendChild(toolButton('✕', 'Delete', function () {
        deleteBlock({ rowId: row.id, cellIndex: cellIndex, blockId: block.id });
      }, 'danger'));
      el.appendChild(tools);

      var label = document.createElement('span');
      label.className = 'ch247m-block-label';
      label.textContent = manifest.blocks[block.type] ? manifest.blocks[block.type].label : block.type;
      el.appendChild(label);
    }

    el.addEventListener('click', function (e) {
      if (previewMode) { return; }
      e.stopPropagation();
      selection = { rowId: row.id, cellIndex: cellIndex, blockId: block.id };
      render();
      renderInspector();
    });

    el.addEventListener('dragstart', function (e) {
      if (previewMode) { return; }
      e.stopPropagation();
      dragPayload = { kind: 'move-block', rowId: row.id, cellIndex: cellIndex, blockId: block.id };
      try { e.dataTransfer.setData('text/plain', 'ch247m'); } catch (err) { /* IE */ }
      e.dataTransfer.effectAllowed = 'move';
      root.classList.add('is-dragging');
    });
    el.addEventListener('dragend', function () {
      dragPayload = null;
      root.classList.remove('is-dragging');
      clearDropHighlights();
    });

    return el;
  }

  function toolButton(glyph, title, handler, tone) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.title = title;
    btn.innerHTML = glyph;
    btn.className = 'ch247m-tool' + (tone ? ' ch247m-tool-' + tone : '');
    btn.addEventListener('click', function (e) {
      e.stopPropagation();
      handler();
    });
    return btn;
  }

  /* ------------------------------------------------------- drop zones -- */

  function makeRowDropZone(el, index) {
    el.addEventListener('dragover', function (e) {
      if (!dragPayload) { return; }
      e.preventDefault();
      e.dataTransfer.dropEffect = 'copy';
      el.classList.add('is-over');
    });
    el.addEventListener('dragleave', function () { el.classList.remove('is-over'); });
    el.addEventListener('drop', function (e) {
      e.preventDefault();
      e.stopPropagation();
      el.classList.remove('is-over');
      if (!dragPayload) { return; }
      if (dragPayload.kind === 'new-layout') {
        addRow(dragPayload.layout, index);
      } else if (dragPayload.kind === 'new-block') {
        // Dropping a bare block between rows creates a single-column row.
        var row = addRow('col-1', index, true);
        insertBlock(row.id, 0, 0, makeBlock(dragPayload.type));
      }
      dragPayload = null;
    });
  }

  function makeCellDropZone(el, rowId, cellIndex) {
    el.addEventListener('dragover', function (e) {
      if (!dragPayload || dragPayload.kind === 'new-layout') { return; }
      e.preventDefault();
      e.stopPropagation();
      e.dataTransfer.dropEffect = dragPayload.kind === 'move-block' ? 'move' : 'copy';
      el.classList.add('is-over');
    });
    el.addEventListener('dragleave', function () { el.classList.remove('is-over'); });
    el.addEventListener('drop', function (e) {
      e.preventDefault();
      e.stopPropagation();
      el.classList.remove('is-over');
      if (!dragPayload) { return; }
      var target = positionWithinCell(el, e.clientY);
      if (dragPayload.kind === 'new-block') {
        insertBlock(rowId, cellIndex, target, makeBlock(dragPayload.type));
      } else if (dragPayload.kind === 'move-block') {
        relocateBlock(dragPayload, rowId, cellIndex, target);
      }
      dragPayload = null;
    });
  }

  /** Index to insert at, based on where the pointer is relative to siblings. */
  function positionWithinCell(cellEl, clientY) {
    var blocks = cellEl.querySelectorAll('.ch247m-block');
    for (var i = 0; i < blocks.length; i++) {
      var box = blocks[i].getBoundingClientRect();
      if (clientY < box.top + box.height / 2) { return i; }
    }
    return blocks.length;
  }

  function clearDropHighlights() {
    var over = root.querySelectorAll('.is-over');
    for (var i = 0; i < over.length; i++) { over[i].classList.remove('is-over'); }
  }

  /* --------------------------------------------------------- mutation -- */

  function makeBlock(type) {
    var spec = manifest.blocks[type];
    if (!spec) { return null; }
    return { id: uid('b'), type: type, props: clone(spec.props) };
  }

  function addRow(layoutKey, index, silent) {
    if (design.rows.length >= 100) {
      setStatus('A design is limited to 100 rows', 'warn');
      return null;
    }
    var layout = manifest.layouts[layoutKey] || manifest.layouts['col-1'];
    pushHistory();
    var row = adoptRow({ layout: layoutKey, widths: layout.widths.slice(0), props: layout.props || {} });
    design.rows.splice(Math.max(0, Math.min(index, design.rows.length)), 0, row);
    selection = { rowId: row.id };
    render();
    if (!silent) { renderInspector(); }
    markDirty();
    return row;
  }

  function duplicateRow(index) {
    var row = design.rows[index];
    if (!row) { return; }
    pushHistory();
    var copy = clone(row);
    copy.id = uid('r');
    for (var c = 0; c < copy.cells.length; c++) {
      for (var b = 0; b < copy.cells[c].length; b++) {
        copy.cells[c][b].id = uid('b');
      }
    }
    design.rows.splice(index + 1, 0, copy);
    render();
    markDirty();
  }

  function deleteRow(index) {
    var row = design.rows[index];
    if (!row) { return; }
    var hasContent = false;
    for (var c = 0; c < row.cells.length; c++) {
      if (row.cells[c].length) { hasContent = true; }
    }
    if (hasContent && !window.confirm('Delete this row and everything in it?')) { return; }
    pushHistory();
    design.rows.splice(index, 1);
    selection = null;
    render();
    renderInspector();
    markDirty();
  }

  function moveRow(from, to) {
    if (to < 0 || to >= design.rows.length) { return; }
    pushHistory();
    var row = design.rows.splice(from, 1)[0];
    design.rows.splice(to, 0, row);
    render();
    markDirty();
  }

  function insertBlock(rowId, cellIndex, position, block) {
    if (!block) { return; }
    var row = findRow(rowId);
    if (!row || !row.cells[cellIndex]) { return; }
    if (row.cells[cellIndex].length >= 50) {
      setStatus('A column is limited to 50 blocks', 'warn');
      return;
    }
    pushHistory();
    row.cells[cellIndex].splice(position, 0, block);
    selection = { rowId: rowId, cellIndex: cellIndex, blockId: block.id };
    render();
    renderInspector();
    markDirty();
  }

  function relocateBlock(source, rowId, cellIndex, position) {
    var fromRow = findRow(source.rowId);
    if (!fromRow) { return; }
    var fromCell = fromRow.cells[source.cellIndex];
    if (!fromCell) { return; }
    var fromIndex = -1;
    for (var i = 0; i < fromCell.length; i++) {
      if (fromCell[i].id === source.blockId) { fromIndex = i; }
    }
    if (fromIndex < 0) { return; }

    pushHistory();
    var block = fromCell.splice(fromIndex, 1)[0];
    var toRow = findRow(rowId);
    if (!toRow || !toRow.cells[cellIndex]) {
      fromCell.splice(fromIndex, 0, block); // put it back
      return;
    }
    // Removing from the same cell shifts later indices down by one.
    if (source.rowId === rowId && source.cellIndex === cellIndex && position > fromIndex) {
      position -= 1;
    }
    toRow.cells[cellIndex].splice(position, 0, block);
    selection = { rowId: rowId, cellIndex: cellIndex, blockId: block.id };
    render();
    renderInspector();
    markDirty();
  }

  function moveBlock(rowId, cellIndex, from, to) {
    var row = findRow(rowId);
    if (!row) { return; }
    var cell = row.cells[cellIndex];
    if (!cell || to < 0 || to >= cell.length) { return; }
    pushHistory();
    var block = cell.splice(from, 1)[0];
    cell.splice(to, 0, block);
    render();
    markDirty();
  }

  function duplicateBlock(rowId, cellIndex, position) {
    var row = findRow(rowId);
    if (!row || !row.cells[cellIndex]) { return; }
    pushHistory();
    var copy = clone(row.cells[cellIndex][position]);
    copy.id = uid('b');
    row.cells[cellIndex].splice(position + 1, 0, copy);
    render();
    markDirty();
  }

  function deleteBlock(ref) {
    var row = findRow(ref.rowId);
    if (!row) { return; }
    var cell = row.cells[ref.cellIndex];
    if (!cell) { return; }
    for (var i = 0; i < cell.length; i++) {
      if (cell[i].id === ref.blockId) {
        pushHistory();
        cell.splice(i, 1);
        selection = null;
        render();
        renderInspector();
        markDirty();
        return;
      }
    }
  }

  function appendBlockToLastCell(type) {
    var block = makeBlock(type);
    if (!block) { return; }
    if (!design.rows.length) {
      var row = addRow('col-1', 0, true);
      insertBlock(row.id, 0, 0, block);
      return;
    }
    var last = design.rows[design.rows.length - 1];
    insertBlock(last.id, 0, last.cells[0].length, block);
  }

  /* -------------------------------------------------------- inspector -- */

  function renderInspector() {
    if (!inspector) { return; }
    inspector.innerHTML = '';

    if (!selection) {
      var hint = document.createElement('div');
      hint.className = 'ch247m-inspector-empty';
      hint.textContent = 'Select a block to edit its content and styling.';
      inspector.appendChild(hint);
      return;
    }

    var block = findBlock(selection);
    if (block) {
      inspector.appendChild(sectionTitle(manifest.blocks[block.type].label, 'Block'));
      inspector.appendChild(propForm(block.props, manifest.blocks[block.type].props, function () {
        render();
        markDirty();
      }, block.type));
      return;
    }

    var row = findRow(selection.rowId);
    if (row) {
      inspector.appendChild(sectionTitle('Row', manifest.layouts[row.layout].label));
      inspector.appendChild(propForm(row.props, manifest.rowDefaults, function () {
        render();
        markDirty();
      }, '__row'));
      if (row.widths.length > 1) {
        inspector.appendChild(widthEditor(row));
      }
    }
  }

  function renderDesignSettings() {
    var host = root.querySelector('[data-role="design-settings"]');
    if (!host) { return; }
    host.innerHTML = '';
    host.appendChild(sectionTitle('Email styling', 'Applies everywhere'));
    host.appendChild(propForm(design.settings, manifest.designDefaults, function () {
      render();
      markDirty();
    }, '__design'));
  }

  function sectionTitle(title, sub) {
    var el = document.createElement('div');
    el.className = 'ch247m-inspector-title';
    el.innerHTML = '<strong>' + esc(title) + '</strong>' + (sub ? '<span>' + esc(sub) + '</span>' : '');
    return el;
  }

  function widthEditor(row) {
    var wrap = document.createElement('div');
    wrap.className = 'ch247m-field';
    wrap.innerHTML = '<label>Column widths</label>';
    var line = document.createElement('div');
    line.className = 'ch247m-width-row';
    for (var i = 0; i < row.widths.length; i++) {
      (function (index) {
        var input = document.createElement('input');
        input.type = 'number';
        input.min = '1';
        input.max = '12';
        input.value = row.widths[index];
        input.addEventListener('change', function () {
          pushHistory();
          row.widths[index] = Math.max(1, Math.min(12, parseInt(input.value, 10) || 1));
          render();
          markDirty();
        });
        line.appendChild(input);
      }(i));
    }
    wrap.appendChild(line);
    var help = document.createElement('p');
    help.className = 'ch247m-help';
    help.textContent = 'Relative weights — 1 and 2 give a one-third / two-thirds split.';
    wrap.appendChild(help);
    return wrap;
  }

  /** Field metadata that cannot be inferred from the default value alone. */
  var FIELD_HINTS = {
    html: { control: 'textarea', rows: 8 },
    extraHtml: { control: 'textarea', rows: 4 },
    body: { control: 'textarea', rows: 4 },
    features: { control: 'textarea', rows: 4, help: 'One feature per line.' },
    address: { control: 'textarea', rows: 3, help: 'Leave blank to use the address from Settings.' },
    align: { control: 'select', options: ['left', 'center', 'right'] },
    level: { control: 'select', options: ['h1', 'h2', 'h3', 'h4'] },
    style: { control: 'select', options: ['solid', 'dashed', 'dotted'] },
    fontFamily: {
      control: 'select',
      options: [
        'Arial, Helvetica, sans-serif',
        'Georgia, "Times New Roman", serif',
        '"Helvetica Neue", Helvetica, Arial, sans-serif',
        'Tahoma, Geneva, sans-serif',
        '"Trebuchet MS", Helvetica, sans-serif',
        'Verdana, Geneva, sans-serif'
      ]
    },
    href: { control: 'url', help: 'Merge tags such as {{webview_url}} are allowed.' },
    src: { control: 'url' },
    image: { control: 'url' },
    thumbnail: { control: 'url', help: 'Email clients cannot play video. This image links out to it.' },
    alt: { help: 'Shown when images are blocked — most inboxes block them by default.' },
    code: { help: 'The coupon code recipients type at checkout.' }
  };

  var COLOUR_KEYS = /color$/i;

  function propForm(target, defaults, onChange, context) {
    var form = document.createElement('div');
    form.className = 'ch247m-propform';

    for (var key in defaults) {
      if (!Object.prototype.hasOwnProperty.call(defaults, key)) { continue; }
      (function (name) {
        var def = defaults[name];
        var hint = FIELD_HINTS[name] || {};
        var field = document.createElement('div');
        field.className = 'ch247m-field';

        if (name === 'links' && context === 'social') {
          field.appendChild(socialEditor(target, onChange));
          form.appendChild(field);
          return;
        }
        if (Object.prototype.toString.call(def) === '[object Array]') {
          return; // handled by a dedicated editor or not editable
        }

        var label = document.createElement('label');
        label.textContent = humanise(name);
        field.appendChild(label);

        var input;
        if (typeof def === 'boolean') {
          input = document.createElement('input');
          input.type = 'checkbox';
          input.checked = !!target[name];
          field.classList.add('ch247m-field-check');
          field.insertBefore(input, label);
          input.addEventListener('change', function () {
            target[name] = input.checked;
            onChange();
          });
        } else if (hint.control === 'select') {
          input = document.createElement('select');
          for (var o = 0; o < hint.options.length; o++) {
            var opt = document.createElement('option');
            opt.value = hint.options[o];
            opt.textContent = hint.options[o];
            if (String(target[name]) === hint.options[o]) { opt.selected = true; }
            input.appendChild(opt);
          }
          input.addEventListener('change', function () {
            target[name] = input.value;
            onChange();
          });
          field.appendChild(input);
        } else if (hint.control === 'textarea') {
          input = document.createElement('textarea');
          input.rows = hint.rows || 4;
          input.value = target[name] === undefined ? '' : target[name];
          input.addEventListener('input', debounce(function () {
            target[name] = input.value;
            onChange();
          }, 350));
          field.appendChild(input);
        } else if (COLOUR_KEYS.test(name)) {
          var group = document.createElement('div');
          group.className = 'ch247m-colour';
          var swatch = document.createElement('input');
          swatch.type = 'color';
          swatch.value = toHex(target[name]) || '#000000';
          input = document.createElement('input');
          input.type = 'text';
          input.placeholder = 'inherit';
          input.value = target[name] === undefined ? '' : target[name];
          swatch.addEventListener('input', function () {
            input.value = swatch.value;
            target[name] = swatch.value;
            onChange();
          });
          input.addEventListener('input', debounce(function () {
            target[name] = input.value;
            if (toHex(input.value)) { swatch.value = toHex(input.value); }
            onChange();
          }, 350));
          group.appendChild(swatch);
          group.appendChild(input);
          field.appendChild(group);
        } else if (typeof def === 'number') {
          input = document.createElement('input');
          input.type = 'number';
          input.step = (def % 1 === 0) ? '1' : '0.1';
          input.value = target[name];
          input.addEventListener('input', debounce(function () {
            target[name] = (def % 1 === 0) ? (parseInt(input.value, 10) || 0) : (parseFloat(input.value) || 0);
            onChange();
          }, 300));
          field.appendChild(input);
        } else {
          input = document.createElement('input');
          input.type = 'text';
          input.value = target[name] === undefined ? '' : target[name];
          input.addEventListener('input', debounce(function () {
            target[name] = input.value;
            onChange();
          }, 350));
          field.appendChild(input);
        }

        if (hint.help) {
          var help = document.createElement('p');
          help.className = 'ch247m-help';
          help.textContent = hint.help;
          field.appendChild(help);
        }
        form.appendChild(field);
      }(key));
    }
    return form;
  }

  function socialEditor(target, onChange) {
    var wrap = document.createElement('div');
    wrap.innerHTML = '<label>Social links</label>';
    var list = target.links || [];
    for (var i = 0; i < list.length; i++) {
      (function (index) {
        var line = document.createElement('div');
        line.className = 'ch247m-social-row';
        var name = document.createElement('span');
        name.textContent = list[index].network;
        var input = document.createElement('input');
        input.type = 'text';
        input.placeholder = 'https://…  (leave blank to hide)';
        input.value = list[index].href || '';
        input.addEventListener('input', debounce(function () {
          list[index].href = input.value;
          onChange();
        }, 350));
        line.appendChild(name);
        line.appendChild(input);
        wrap.appendChild(line);
      }(i));
    }
    return wrap;
  }

  function humanise(key) {
    return key
      .replace(/([A-Z])/g, ' $1')
      .replace(/^./, function (m) { return m.toUpperCase(); })
      .replace(/\bUrl\b/, 'URL')
      .replace(/\bHtml\b/, 'HTML');
  }

  function toHex(value) {
    if (typeof value !== 'string') { return ''; }
    var v = value.trim();
    return /^#[0-9a-f]{6}$/i.test(v) ? v : (/^#[0-9a-f]{3}$/i.test(v)
      ? '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3]
      : '');
  }

  function debounce(fn, wait) {
    var timer = null;
    return function () {
      var args = arguments;
      var self = this;
      window.clearTimeout(timer);
      timer = window.setTimeout(function () { fn.apply(self, args); }, wait);
    };
  }

  /* ---------------------------------------------------- canvas preview -- */

  /**
   * An editing approximation of each block — close enough to judge layout
   * and copy, intentionally not a replica of the sent email.
   */
  function previewBlock(block) {
    var p = block.props;
    var base = 'font-family:' + esc(design.settings.fontFamily) + ';color:' + esc(design.settings.textColor) + ';';

    switch (block.type) {
      case 'heading':
        return '<' + (p.level || 'h2') + ' style="margin:0;text-align:' + esc(p.align) + ';font-size:' + Number(p.fontSize) + 'px;'
          + (p.color ? 'color:' + esc(p.color) + ';' : '') + base + '">' + esc(p.text) + '</' + (p.level || 'h2') + '>';

      case 'text':
        return '<div style="text-align:' + esc(p.align) + ';' + base + (p.color ? 'color:' + esc(p.color) + ';' : '') + '">'
          + sanitisePreview(p.html) + '</div>';

      case 'image':
      case 'logo':
        if (!p.src) {
          return placeholder(block.type === 'logo' ? 'Logo — set an image URL' : 'Image — set an image URL');
        }
        return '<div style="text-align:' + esc(p.align) + '"><img src="' + esc(p.src) + '" alt="' + esc(p.alt) + '"'
          + (p.width ? ' width="' + Number(p.width) + '"' : '') + ' style="max-width:100%;height:auto;"></div>';

      case 'button':
        return '<div style="text-align:' + esc(p.align) + '"><span style="display:inline-block;background:' + esc(p.backgroundColor)
          + ';color:' + esc(p.textColor) + ';border-radius:' + Number(p.radius) + 'px;padding:' + Number(p.paddingY) + 'px '
          + Number(p.paddingX) + 'px;font-size:' + Number(p.fontSize) + 'px;font-family:' + esc(design.settings.fontFamily) + ';">'
          + esc(p.text) + '</span></div>'
          + (p.href ? '' : '<p class="ch247m-warn">No link set — the button goes nowhere.</p>');

      case 'divider':
        return '<div style="padding:4px 0;"><hr style="border:0;border-top:' + Number(p.thickness) + 'px ' + esc(p.style) + ' '
          + esc(p.color) + ';width:' + Number(p.width) + '%;margin:0 auto;"></div>';

      case 'spacer':
        return '<div class="ch247m-spacer" style="height:' + Number(p.height) + 'px;"><span>' + Number(p.height) + 'px</span></div>';

      case 'social':
        var icons = '';
        var links = p.links || [];
        for (var i = 0; i < links.length; i++) {
          if (!links[i].href) { continue; }
          icons += '<span class="ch247m-social-chip">' + esc(links[i].network) + '</span> ';
        }
        return '<div style="text-align:' + esc(p.align) + '">' + (icons || placeholder('Social links — add at least one URL')) + '</div>';

      case 'video':
        return p.thumbnail
          ? '<div style="text-align:' + esc(p.align) + ';position:relative;display:inline-block;"><img src="' + esc(p.thumbnail)
            + '" alt="' + esc(p.alt) + '" style="max-width:100%;"><span class="ch247m-play">▶</span></div>'
          : placeholder('Video — set a thumbnail image and a link');

      case 'html':
        return '<div class="ch247m-rawhtml"><span>Custom HTML</span><code>' + esc(String(p.html).slice(0, 400)) + '</code></div>';

      case 'product':
        return '<div class="ch247m-card" style="border:1px solid ' + esc(p.borderColor) + ';">'
          + (p.image ? '<img src="' + esc(p.image) + '" alt="" style="max-width:100%;">' : '')
          + '<h4 style="' + base + '">' + esc(p.title) + '</h4>'
          + '<p style="' + base + '">' + esc(p.body) + '</p>'
          + (p.price ? '<p class="ch247m-price">' + esc(p.price) + '</p>' : '')
          + '<span class="ch247m-fakebtn">' + esc(p.buttonText) + '</span></div>';

      case 'coupon':
        return '<div class="ch247m-coupon" style="background:' + esc(p.backgroundColor) + ';border:2px dashed ' + esc(p.borderColor)
          + ';color:' + esc(p.textColor) + ';"><small>' + esc(p.headline) + '</small><strong>' + esc(p.code) + '</strong>'
          + '<small>' + esc(p.subtext) + '</small></div>';

      case 'pricing':
        var features = String(p.features || '').split('\n');
        var li = '';
        for (var f = 0; f < features.length; f++) {
          if (features[f].trim()) { li += '<li>' + esc(features[f]) + '</li>'; }
        }
        return '<div class="ch247m-card" style="border-top:3px solid ' + esc(p.accent) + ';">'
          + '<h4 style="' + base + '">' + esc(p.plan) + '</h4>'
          + '<p class="ch247m-price">' + esc(p.price) + '<small>' + esc(p.period) + '</small></p>'
          + '<ul>' + li + '</ul><span class="ch247m-fakebtn" style="background:' + esc(p.accent) + '">' + esc(p.buttonText) + '</span></div>';

      case 'footer':
        return '<div style="text-align:' + esc(p.align) + ';font-size:' + Number(p.fontSize) + 'px;color:' + esc(p.color) + ';">'
          + esc(p.companyName || '{{company_name}}') + '<br>'
          + esc(p.address || '{{physical_address}}').replace(/\n/g, '<br>')
          + (p.showUnsubscribe ? '<br><u>Unsubscribe</u> · <u>Email preferences</u>' : '<br><span class="ch247m-warn">Unsubscribe link is switched off</span>')
          + '</div>';

      default:
        return placeholder(block.type);
    }
  }

  function placeholder(text) {
    return '<div class="ch247m-placeholder">' + esc(text) + '</div>';
  }

  /**
   * Strip scripts and event handlers from the editing preview.
   *
   * This is a convenience for the person editing, not a security control —
   * the authoritative sanitisation happens server-side in Renderer, which is
   * the only copy an attacker cannot skip.
   */
  function sanitisePreview(html) {
    var div = document.createElement('div');
    div.innerHTML = String(html || '');
    var scripts = div.querySelectorAll('script, iframe, object, embed, style, link');
    for (var i = scripts.length - 1; i >= 0; i--) {
      scripts[i].parentNode.removeChild(scripts[i]);
    }
    var all = div.querySelectorAll('*');
    for (var j = 0; j < all.length; j++) {
      var attrs = all[j].attributes;
      for (var k = attrs.length - 1; k >= 0; k--) {
        var attrName = attrs[k].name.toLowerCase();
        if (attrName.indexOf('on') === 0 || (attrName === 'href' && /^\s*javascript:/i.test(attrs[k].value))) {
          all[j].removeAttribute(attrs[k].name);
        }
      }
    }
    return div.innerHTML;
  }
}());
