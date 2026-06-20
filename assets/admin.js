
    // Bulk Import (Supplier Sales)
    function bulkImportRows(btn){
      var table = document.getElementById('cofs-supplier-missing-table');
      if (!table) return;
      var rows = Array.prototype.slice.call(table.querySelectorAll('tbody tr'));
      // only rows with a filled URL
      rows = rows.filter(function(r){
        var inp = r.querySelector('.cofs-source-url');
        return inp && inp.value && inp.value.trim() !== '';
      });
      if (!rows.length) {
        alert('No rows with a URL filled in.');
        return;
      }

      btn.disabled = true;
      var done = 0;

      function runNext(){
        if (!rows.length) {
          btn.disabled = false;
          btn.textContent = 'Bulk import all with URL';
          return;
        }
        var row = rows.shift();
        var clickBtn = row.querySelector('.cofs-scrape-btn');
        if (!clickBtn) {
          runNext();
          return;
        }
        btn.textContent = 'Importing… (' + done + ')';
        // trigger existing handler by calling click
        clickBtn.click();
        done++;

        // wait until row button is re-enabled (import finished), then continue
        var tries = 0;
        (function poll(){
          tries++;
          if (tries > 200) { // ~20s
            runNext();
            return;
          }
          if (!clickBtn.disabled) {
            runNext();
            return;
          }
          setTimeout(poll, 100);
        })();
      }

      runNext();
    }

    document.addEventListener('click', function(e){
      var t = e.target;
      if (!t) return;
      if (t.id === 'cofs-bulk-import') {
        e.preventDefault();
        bulkImportRows(t);
      }
    });
/* global COFS, jQuery */
(function($){
  // -----------------------------
  // Helpers
  // -----------------------------
  function status(msg){ $('#cofs-status').text(msg); }
  function setProgress(pct){
    var p = Math.max(0, Math.min(100, Number(pct) || 0));
    $('.cofs-progress .bar').css('width', p + '%');
  }
  function lockRunUI(locked){
    $('#cofs-prepare,#cofs-force,#cofs-run').prop('disabled', !!locked);
    $('#cofs-cancel').prop('disabled', !locked);
  }
  function safeMsg(res, fallback){
    return (res && res.data && res.data.message) ? res.data.message : (fallback || 'unbekannt');
  }
  function failDetail(xhr){
    if (!xhr) return '';
    var text = (xhr.responseText || '').replace(/<[^>]*>/g,'').trim();
    return text ? (' Details: ' + text.substring(0, 500)) : '';
  }

  // -----------------------------
  // State
  // -----------------------------
  var prepared = null;
  var cancelled = false;
  var running = false;

  // -----------------------------
  // Prepare Feed (shared helper)
  // -----------------------------
  function prepareFeed(onPrepared){
    if (!window.COFS || !COFS.ajax || !COFS.nonce){
      status('Ajax-Kontext fehlt (COFS).');
      return;
    }

    lockRunUI(true);
    setProgress(0);
    status('Lade und parse Feed ...');

    var force = $('#cofs-force').is(':checked') ? 1 : 0;

    $.post(COFS.ajax, {
      action: 'cofs_prepare_feed',
      nonce: COFS.nonce,
      force: force
    }, function(res){
      if(!res || !res.success){
        status('Fehler: ' + safeMsg(res));
        running = false;
        return;
      }
      prepared = res.data || {};

      var total   = prepared.total || 0;
      var maxRows = (typeof prepared.max_rows !== 'undefined') ? prepared.max_rows : '0';
      var sizeKB  = Math.round((prepared.size || 0) / 1024);

      status('Bereit. Zeilen: ' + total + ' (max_rows=' + maxRows + '), Cache ~ ' + sizeKB + ' KB');

      if (typeof onPrepared === 'function') {
        onPrepared(prepared);
      }
    }).fail(function(xhr){
      running = false;
      status('Fehler beim Vorbereiten (' + xhr.status + ').' + failDetail(xhr));
    }).always(function(){
      if(!running){
        lockRunUI(false);
      }
    });
  }

  $(document).on('click', '#cofs-prepare', function(e){
    e.preventDefault();
    if (running) {
      return;
    }
    prepareFeed();
  });

  // -----------------------------
  // Cancel
  // -----------------------------
  $(document).on('click', '#cofs-cancel', function(e){
    e.preventDefault();
    cancelled = true;
    running = false;
    lockRunUI(false);
    status('Abgebrochen.');
  });

  // -----------------------------
  // Run
  // -----------------------------
  $(document).on('click', '#cofs-run', function(e){
    e.preventDefault();

    if (running) {
      return;
    }

    $('#cofs-report tbody').empty();
    setProgress(0);

    cancelled = false;
    running = true;
    prepareFeed(function(){
      if (cancelled) {
        running = false;
        lockRunUI(false);
        status('Abgebrochen.');
        return;
      }
      status('Feed bereit. Starte Sync ...');
      step(0);
    });
  });

  // -----------------------------
  // Step executor (batch loop)
  // -----------------------------
  function step(offset){
    if(cancelled){
      running = false;
      lockRunUI(false);
      return;
    }

    status('Verarbeite ab Zeile ' + offset + ' ...');

    $.post(COFS.ajax, {
      action: 'cofs_sync_step',
      nonce: COFS.nonce,
      offset: offset
    }, function(res){
      if(!res || !res.success){
        status('Fehler: ' + safeMsg(res));
        running = false;
        lockRunUI(false);
        return;
      }

      var d = res.data || {};
      var total = Number(d.total) || 0;
      var next  = Number(d.next)  || 0;
      var pct   = total ? Math.round((next / total) * 100) : 100;

      setProgress(pct);

      if ($.isArray(d.changes) && d.changes.length) {
        $('#cofs-report tbody').append( $.map(d.changes, rowHtml).join('') );
      }

      if(!d.finished && !cancelled){
        setTimeout(function(){ step(next); }, 120);
      } else {
        running = false;
        lockRunUI(false);
        status('Fertig. Änderungen (letzter Schritt): ' + (d.count || 0) + '.');
      }
    }).fail(function(xhr){
      running = false;
      lockRunUI(false);
      status('Fehler beim Sync (' + xhr.status + ').' + failDetail(xhr));
    });
  }

  // -----------------------------
  // Row renderer (Sync-Report)
  // -----------------------------
  function rowHtml(change){
    var items = [];

    if (change.vendor_sku) {
      items.push(
        'Vendor SKU: ' +
        (change.vendor_sku.old || '—') +
        ' → ' +
        change.vendor_sku.new
      );
    }

    if (change.stock) {
      items.push(
        'Stock: ' +
        (change.stock.old === null ? '—' : change.stock.old) +
        ' → ' +
        change.stock.new
      );
    }

    if (change.purchase_price) {
      items.push(
        'Purchase Price: ' +
        (change.purchase_price.old || '—') +
        ' → ' +
        change.purchase_price.new
      );
    }

    var link = change.product_admin
      ? ' <a href="' + change.product_admin + '" target="_blank" rel="noopener">Bearbeiten</a>'
      : '';

    var idLabel = '';
    if (change.feed_sku) {
      idLabel = 'Feed/Vendor SKU: ' + change.feed_sku;
    } else if (change.gtin) {
      idLabel = 'Feed/Vendor SKU: ' + change.gtin;
    } else {
      idLabel = 'Produkt';
    }

    return '<tr>' +
      '<td>' + idLabel + link + '</td>' +
      '<td>' + (items.length ? items.join('<br>') : '—') + '</td>' +
      '</tr>';
  }
})(jQuery);


// -----------------------------
// Missing Products: Scan + Filter + Sort + Export + Scraper
// -----------------------------
(function(){
  function esc(s){
    if (s === null || s === undefined) return '';
    s = String(s);
    return s
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;')
      .replace(/'/g,'&#039;');
  }

  function getMissingTableColCount(tableBodyEl) {
    if (!tableBodyEl) return 1;
    var table = tableBodyEl.closest ? tableBodyEl.closest('table') : null;
    if (!table) return 1;
    var headers = table.querySelectorAll('thead th');
    return headers && headers.length ? headers.length : 1;
  }

  function toNumber(value) {
    if (value === null || value === undefined || value === '') return null;

    var normalized = String(value)
      .replace(/\s+/g, '')
      .replace(/CHF/ig, '')
      .replace(/'/g, '');

    var comma = normalized.lastIndexOf(',');
    var dot = normalized.lastIndexOf('.');

    if (comma !== -1 && dot !== -1) {
      if (comma > dot) {
        normalized = normalized.replace(/\./g, '').replace(',', '.');
      } else {
        normalized = normalized.replace(/,/g, '');
      }
    } else if (comma !== -1) {
      normalized = normalized.replace(',', '.');
    }

    normalized = normalized.replace(/[^0-9.-]/g, '');
    if (!normalized || normalized === '-' || normalized === '.' || normalized === '-.') {
      return null;
    }

    var parsed = Number(normalized);
    return isNaN(parsed) ? null : parsed;
  }

  function formatDecimal(value, digits) {
    if (value === null || value === undefined || isNaN(value)) return '—';
    return Number(value).toFixed(typeof digits === 'number' ? digits : 2);
  }

  function getMarginDisplay(item) {
    var marginValue = toNumber(item.margin_value);
    var marginPercent = toNumber(item.margin_percent);

    if (marginValue === null) {
      return '—';
    }
    if (marginPercent === null) {
      return formatDecimal(marginValue, 2);
    }

    return formatDecimal(marginValue, 2) + ' (' + formatDecimal(marginPercent, 1) + '%)';
  }

  function compareNullableNumbers(a, b, desc) {
    if (a === null && b === null) return 0;
    if (a === null) return 1;
    if (b === null) return -1;
    if (a === b) return 0;
    if (desc) return a > b ? -1 : 1;
    return a < b ? -1 : 1;
  }

  // Zeilenrenderer für "Fehlende Produkte" inkl. Scraper-UI
  function rowHtml(item) {
    var key        = item.key || '';
    var name       = item.name || '';
    var vendorSku  = item.vendor_sku || '';
    var stock      = (typeof item.stock !== 'undefined') ? item.stock : '';
    var supplierSales = (typeof item.supplier_sales !== 'undefined') ? item.supplier_sales : 0;
    var purchase   = item.purchase || '';
    var uvp        = item.uvp || '';
    var marginValue = (typeof item.margin_value !== 'undefined') ? item.margin_value : '';
    var marginPercent = (typeof item.margin_percent !== 'undefined') ? item.margin_percent : '';
    var note       = item.note || '';
    var marginDisplay = getMarginDisplay({
      margin_value: marginValue,
      margin_percent: marginPercent
    });

    var html  = '<tr';
    html += ' data-key="' + esc(key) + '"';
    html += ' data-name="' + esc(name.toLowerCase()) + '"';
    html += ' data-vendor-sku="' + esc(vendorSku) + '"';
    if (item.stock !== undefined && item.stock !== null) {
      html += ' data-stock="' + esc(String(item.stock)) + '"';
    }
    html += ' data-supplier-sales="' + esc(String(supplierSales)) + '"';
    if (purchase !== '') {
      html += ' data-purchase-price="' + esc(String(purchase)) + '"';
    }
    if (uvp !== '') {
      html += ' data-uvp="' + esc(String(uvp)) + '"';
    }
    if (marginValue !== '' && marginValue !== null) {
      html += ' data-margin-value="' + esc(String(marginValue)) + '"';
    }
    if (marginPercent !== '' && marginPercent !== null) {
      html += ' data-margin-percent="' + esc(String(marginPercent)) + '"';
    }
    html += '>';
    html += '<td><code>' + esc(key) + '</code></td>';
    html += '<td class="cofs-missing-name">' + esc(name) + '</td>';
    html += '<td>' + esc(vendorSku) + '</td>';
    html += '<td>' + esc(stock) + '</td>';
    html += '<td>' + esc(supplierSales) + '</td>';
    html += '<td>' + esc(purchase) + '</td>';
    html += '<td>' + esc(uvp) + '</td>';
    html += '<td>' + esc(marginDisplay) + '</td>';
    html += '<td>' + esc(note) + '</td>';

    // Scraper-Spalte: URL + Button + Status
    html += '<td class="cofs-scrape-cell">';
    html +=   '<input type="url" class="cofs-source-url" ';
    html +=          'placeholder="https://caffeonline.ch/produkt/..." ';
    html +=          'style="width:100%;max-width:260px;margin-bottom:4px;">';
    html +=   '<button type="button" class="button button-small cofs-scrape-btn">';
    html +=       'Importieren';
    html +=   '</button>';
    html +=   '<div class="cofs-scrape-status" style="margin-top:2px;font-size:11px;"></div>';
    html += '</td>';

    html += '</tr>';
    return html;
  }

  
  // Live-Filter (Produktname). searchIn/tableBody werden als Parameter übergeben,
  // weil diese Variablen innerhalb von onDomReady() pro Seite initialisiert werden.
  function applyNameFilter(searchInEl, tableBodyEl) {
    if (!searchInEl || !tableBodyEl) return;

    var q = (searchInEl.value || '').toLowerCase().trim();

    // Remove prior "no matches" helper row if present
    var oldNo = tableBodyEl.querySelector('tr.cofs-no-matches');
    if (oldNo) oldNo.parentNode.removeChild(oldNo);

    var trs = tableBodyEl.querySelectorAll('tr');
    var anyVisible = false;

    for (var i = 0; i < trs.length; i++) {
      var tr = trs[i];

      // Keep placeholder rows only when no query is active
      if (tr.classList && tr.classList.contains('cofs-placeholder')) {
        tr.style.display = (q === '') ? '' : 'none';
        continue;
      }

      var nameCell = tr.querySelector('.cofs-missing-name');
      var name = nameCell ? (nameCell.textContent || '').toLowerCase() : '';

      var show = (q === '' || name.indexOf(q) !== -1);
      tr.style.display = show ? '' : 'none';
      if (show) anyVisible = true;
    }

    // If query active and nothing matches, show a single helper row
    if (q !== '' && !anyVisible) {
      var trNo = document.createElement('tr');
      trNo.className = 'cofs-placeholder cofs-no-matches';
      var td = document.createElement('td');
      td.colSpan = getMissingTableColCount(tableBodyEl);
      td.textContent = '— keine Treffer für diese Suche —';
      trNo.appendChild(td);
      tableBodyEl.appendChild(trNo);
    }
  }

  function applySort(rows, mode) {
    if (!mode || mode === 'none') return rows;
    var copy = rows.slice(0);
    copy.sort(function(a,b){
      var A = (a.name || '').toLowerCase();
      var B = (b.name || '').toLowerCase();

      if (mode === 'name_asc' || mode === 'name_desc') {
        if (A < B) return (mode === 'name_asc') ? -1 : 1;
        if (A > B) return (mode === 'name_asc') ? 1 : -1;
        return 0;
      }

      if (mode === 'margin_desc' || mode === 'margin_asc') {
        var marginCmp = compareNullableNumbers(
          toNumber(a.margin_percent),
          toNumber(b.margin_percent),
          mode === 'margin_desc'
        );
        if (marginCmp !== 0) return marginCmp;

        var valueCmp = compareNullableNumbers(
          toNumber(a.margin_value),
          toNumber(b.margin_value),
          mode === 'margin_desc'
        );
        if (valueCmp !== 0) return valueCmp;

        if (A < B) return -1;
        if (A > B) return 1;
      }

      return 0;
    });
    return copy;
  }

  function sortExistingTableRows(tableBody, mode) {
    if (!tableBody || !mode || mode === 'none') return;

    var rows = Array.prototype.slice.call(tableBody.querySelectorAll('tr')).filter(function(tr){
      return !(tr.classList && tr.classList.contains('cofs-placeholder'));
    });

    if (!rows.length) return;

    rows.sort(function(a, b) {
      var nameA = (a.getAttribute('data-name') || '').toLowerCase();
      var nameB = (b.getAttribute('data-name') || '').toLowerCase();

      if (mode === 'name_asc' || mode === 'name_desc') {
        if (nameA < nameB) return (mode === 'name_asc') ? -1 : 1;
        if (nameA > nameB) return (mode === 'name_asc') ? 1 : -1;
        return 0;
      }

      if (mode === 'margin_desc' || mode === 'margin_asc') {
        var marginCmp = compareNullableNumbers(
          toNumber(a.getAttribute('data-margin-percent')),
          toNumber(b.getAttribute('data-margin-percent')),
          mode === 'margin_desc'
        );
        if (marginCmp !== 0) return marginCmp;

        var valueCmp = compareNullableNumbers(
          toNumber(a.getAttribute('data-margin-value')),
          toNumber(b.getAttribute('data-margin-value')),
          mode === 'margin_desc'
        );
        if (valueCmp !== 0) return valueCmp;

        if (nameA < nameB) return -1;
        if (nameA > nameB) return 1;
      }

      return 0;
    });

    for (var i = 0; i < rows.length; i++) {
      tableBody.appendChild(rows[i]);
    }
  }

  function renderMissingRows(tableBody, rows, mode) {
    if (!tableBody) return;

    var sorted = applySort(rows || [], mode || 'none');
    if (sorted.length) {
      var html = '';
      for (var i = 0; i < sorted.length; i++) {
        html += rowHtml(sorted[i]);
      }
      tableBody.innerHTML = html;
      return;
    }

    tableBody.innerHTML =
      '<tr class="cofs-placeholder"><td colspan="' +
      getMissingTableColCount(tableBody) +
      '">- keine fehlenden Produkte in der Vorschau -</td></tr>';
  }

  function setHrefParam(href, key, value) {
    var pattern = new RegExp('([?&]' + key + '=)[^&#]*');
    if (pattern.test(href)) {
      return href.replace(pattern, '$1' + encodeURIComponent(value));
    }

    return href + (href.indexOf('?') === -1 ? '?' : '&') + key + '=' + encodeURIComponent(value);
  }

  function setExportHref(exportBtn, stockMinInput, forceCheckbox) {
    if (!exportBtn) return;
    try {
      var href = exportBtn.getAttribute('href') || '';
      var stockVal = (stockMinInput && stockMinInput.value) ? stockMinInput.value : '0';
      var forceVal = (forceCheckbox && forceCheckbox.checked) ? '1' : '0';

      href = setHrefParam(href, 'stock_min', stockVal);
      href = setHrefParam(href, 'force', forceVal);
      exportBtn.setAttribute('href', href);
    } catch(e) {}
  }

  function onDomReady(fn){
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onDomReady(function(){
    var scanBtn   = document.getElementById('cofs-missing-scan');
    var forceChk  = document.getElementById('cofs-missing-force');
    var limitIn   = document.getElementById('cofs-missing-limit');
    var stockMin  = document.getElementById('cofs-missing-stockmin');
    var sortSel   = document.getElementById('cofs-missing-sort');
    var searchIn = document.getElementById('cofs-missing-search');
    var statusEl  = document.getElementById('cofs-missing-status');
    var tableBody = document.querySelector('#cofs-missing-table tbody');
    var exportBtn = document.getElementById('cofs-missing-export');

    // Diese Datei wird auf mehreren COFS-Unterseiten geladen (Dashboard, Fehlende Produkte,
    // Supplier Sales). Die Scan-/Sort-/Export-Controls gibt es nur auf der Seite
    // "Fehlende Produkte" – die Scraper-Buttons existieren aber auch auf "Supplier Sales".
    // Daher NICHT frühzeitig returnen, sondern feature-spezifisch guards verwenden.

    // Export-URL an Stock-Filter koppeln (nur wenn Elemente vorhanden)
    if (stockMin && exportBtn) {
      stockMin.addEventListener('change', function(){
        setExportHref(exportBtn, stockMin, forceChk);
      });
      stockMin.addEventListener('input', function(){
        setExportHref(exportBtn, stockMin, forceChk);
      });
      if (forceChk) {
        forceChk.addEventListener('change', function(){
          setExportHref(exportBtn, stockMin, forceChk);
        });
      }
      setExportHref(exportBtn, stockMin, forceChk);
    }

    if (searchIn && tableBody) {
      searchIn.addEventListener('input', function(){
        applyNameFilter(searchIn, tableBody);
      });
    }

    // Clientseitige Sortierung (nur Fehlende-Produkte-Seite)
    if (sortSel && tableBody) {
      sortSel.addEventListener('change', function(){
        sortExistingTableRows(tableBody, sortSel.value);
        applyNameFilter(searchIn, tableBody);
      });
    }

    // Scan starten (AJAX) (nur Fehlende-Produkte-Seite)
    if (scanBtn && tableBody) scanBtn.addEventListener('click', function(){
      if (!window.COFS || !COFS.ajax || !COFS.nonce) {
        alert('COFS Ajax-Kontext fehlt.');
        return;
      }
      if (statusEl) statusEl.textContent = 'Scanning...';
      tableBody.innerHTML = '';

      var form = new FormData();
      form.append('action', 'cofs_missing_scan');
      form.append('nonce', COFS.nonce);
      form.append('force', (forceChk && forceChk.checked) ? '1' : '0');
      form.append('limit', (limitIn && limitIn.value) ? limitIn.value : '200');
      form.append('stock_min', (stockMin && stockMin.value) ? stockMin.value : '0');
      form.append('sort', (sortSel && sortSel.value) ? sortSel.value : 'none');

      fetch(COFS.ajax, { method: 'POST', body: form, credentials: 'same-origin' })
        .then(function(res){ return res.json(); })
        .then(function(json){
          if (!json || !json.success) {
            throw new Error(json && json.data && json.data.message ? json.data.message : 'Request failed');
          }

          var rows = (json.data && json.data.rows) ? json.data.rows : [];
          var totalCount = (json.data && typeof json.data.total_count !== 'undefined')
            ? Number(json.data.total_count) || 0
            : rows.length;
          var displayedCount = (json.data && typeof json.data.displayed_count !== 'undefined')
            ? Number(json.data.displayed_count) || 0
            : rows.length;
          var limited = !!(json.data && json.data.limited);

          if (statusEl) {
            statusEl.textContent = limited
              ? 'Gefunden: ' + totalCount + ' fehlende Produkte. Vorschau zeigt ' + displayedCount + '.'
              : 'Gefunden: ' + totalCount + ' fehlende Produkte.';
          }

          renderMissingRows(tableBody, rows, sortSel ? sortSel.value : 'none');
          applyNameFilter(searchIn, tableBody);
        })
        .catch(function(e){
          if (statusEl) {
            statusEl.textContent = 'Fehler: ' + (e && e.message ? e.message : e);
          }
          tableBody.innerHTML =
            '<tr class="cofs-placeholder"><td colspan="' +
            getMissingTableColCount(tableBody) +
            '">Fehler beim Laden der fehlenden Produkte.</td></tr>';
        });
    });

    // -----------------------------------
    // Scraper: Button-Klick (delegiert)
    // (wird sowohl auf der "Fehlende Produkte" Seite als auch auf "Supplier Sales" genutzt)
    // -----------------------------------
    function onScrapeClick(e){
      var target = e.target || e.srcElement;
      if (!target || !target.classList.contains('cofs-scrape-btn')) return;

      if (!window.COFS || !COFS.ajax || !COFS.nonce) {
        alert('COFS Ajax-Kontext fehlt.');
        return;
      }

      var row    = target.closest('tr');
      if (!row) return;

      var urlInput = row.querySelector('.cofs-source-url');
      var statusElCell = row.querySelector('.cofs-scrape-status');

      var url = urlInput ? urlInput.value.trim() : '';
      var key = row.getAttribute('data-key') || '';
      var vendorSku = row.getAttribute('data-vendor-sku') || '';
      var stock = row.getAttribute('data-stock') || '';
      var purchasePrice = row.getAttribute('data-purchase-price') || row.getAttribute('data-purchase') || '';

      var invalidMsg = (COFS.scrape && COFS.scrape.invalidUrl)
        ? COFS.scrape.invalidUrl
        : 'Bitte eine gültige caffeonline.ch Produkt-URL einfügen.';

      // allow any caffeonline.ch product URL (some installs may not use /produkt/...)
      if (!url || url.indexOf('caffeonline.ch') === -1) {
        if (statusElCell) statusElCell.textContent = invalidMsg;
        if (statusElCell) statusElCell.style.color = '#cc0000';
        return;
      }

      var workingMsg = (COFS.scrape && COFS.scrape.working)
        ? COFS.scrape.working
        : 'Lade Produktdaten & erstelle Produkt …';

      target.disabled = true;
      if (statusElCell) {
        statusElCell.textContent = workingMsg;
        statusElCell.style.color = '#555';
      }

      var form = new FormData();
      form.append('action', 'cofs_scrape_product');
      form.append('nonce', COFS.nonce);
      form.append('url', url);
      form.append('key', key);
      form.append('vendor_sku', vendorSku);
      if (stock !== '') {
        form.append('stock', stock);
      }
      if (purchasePrice !== '') {
        form.append('purchase_price', purchasePrice);
      }

      fetch(COFS.ajax, {
        method: 'POST',
        body: form,
        credentials: 'same-origin'
      })
        .then(function(res){ return res.json(); })
        .then(function(json){
          target.disabled = false;

          if (!json || !json.success) {
            var msg = (json && json.data && json.data.message) ? json.data.message : null;
            var errorMsg = msg || (COFS.scrape && COFS.scrape.error) || 'Fehler beim Scrapen der Produktseite.';
            if (statusElCell) {
              statusElCell.textContent = errorMsg;
              statusElCell.style.color = '#cc0000';
            }
            return;
          }

          var okMsg = (COFS.scrape && COFS.scrape.ok)
            ? COFS.scrape.ok
            : 'Produkt wurde angelegt.';

          if (statusElCell) {
            statusElCell.style.color = '#008000';
            var data = json.data || {};
            var link = data.edit_link ? '<a href="' + data.edit_link + '" target="_blank" rel="noopener">Bearbeiten</a>' : '';
            statusElCell.innerHTML = esc(okMsg) + (link ? ' ' + link : '');
          }

          // Optional: Zeile aus Tabelle entfernen
          // row.parentNode.removeChild(row);
        })
        .catch(function(err){
          target.disabled = false;
          var errorMsg = (COFS.scrape && COFS.scrape.error)
            ? COFS.scrape.error
            : 'Fehler beim Scrapen der Produktseite.';
          if (statusElCell) {
            statusElCell.textContent = errorMsg + (err && err.message ? ' (' + err.message + ')' : '');
            statusElCell.style.color = '#cc0000';
          }
        });
    }

    // Scraper-Buttons sowohl auf "Fehlende Produkte" als auch auf "Supplier Sales" binden
    if (tableBody) tableBody.addEventListener('click', onScrapeClick);

    var supplierBody = document.querySelector('#cofs-supplier-missing-table tbody');
    if (supplierBody) supplierBody.addEventListener('click', onScrapeClick);
  });
})();
