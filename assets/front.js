/* global rumbleData */
(function(){
  'use strict';

  var rumbleBusyCount = 0;
  var rumbleBusyTimer = null;

  function ensureBusyOverlay(){
    var overlay = document.querySelector('[data-rumble-busy-overlay]');
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.className = 'rumble-busy-overlay';
    overlay.setAttribute('data-rumble-busy-overlay', '');
    overlay.setAttribute('role', 'status');
    overlay.setAttribute('aria-live', 'polite');
    overlay.setAttribute('aria-label', 'Rumble is working');
    overlay.innerHTML =
      '<div class="rumble-busy-box">' +
        '<span class="rumble-busy-spinner" aria-hidden="true"></span>' +
        '<strong>Working</strong>' +
      '</div>';
    document.body.appendChild(overlay);
    return overlay;
  }

  function setRumbleBusy(active){
    if (active) {
      rumbleBusyCount++;
      if (!rumbleBusyTimer) {
        rumbleBusyTimer = window.setTimeout(function(){
          rumbleBusyTimer = null;
          if (rumbleBusyCount > 0) {
            ensureBusyOverlay();
            document.body.classList.add('rumble-is-busy');
          }
        }, 180);
      }
      return;
    }

    rumbleBusyCount = Math.max(0, rumbleBusyCount - 1);
    if (rumbleBusyCount > 0) return;
    if (rumbleBusyTimer) {
      window.clearTimeout(rumbleBusyTimer);
      rumbleBusyTimer = null;
    }
    document.body.classList.remove('rumble-is-busy');
  }

  function installGlobalBusyFetch(){
    if (!window.fetch || window.fetch.__rumbleBusyWrapped) return;
    var nativeFetch = window.fetch.bind(window);
    var wrappedFetch = function(){
      setRumbleBusy(true);
      return nativeFetch.apply(null, arguments).finally(function(){
        setRumbleBusy(false);
      });
    };
    wrappedFetch.__rumbleBusyWrapped = true;
    window.fetch = wrappedFetch;
  }

  installGlobalBusyFetch();

  document.addEventListener('click', function(event){
    var toggle = event.target.closest('[data-rumble-nav-toggle]');
    var menu = document.querySelector('[data-rumble-nav-menu]');

    if (toggle && menu) {
      var expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      menu.classList.toggle('is-open', !expanded);
      return;
    }

    if (menu && menu.classList.contains('is-open') && !event.target.closest('.rumble-nav')) {
      var activeToggle = document.querySelector('[data-rumble-nav-toggle]');
      if (activeToggle) activeToggle.setAttribute('aria-expanded', 'false');
      menu.classList.remove('is-open');
    }
  });

  function digitsOnly(value){
    return String(value || '').replace(/\D/g, '');
  }

  function decimalOnly(value){
    var cleaned = String(value || '').replace(/[^0-9.]/g, '');
    var firstDot = cleaned.indexOf('.');
    if (firstDot === -1) return cleaned;
    return cleaned.slice(0, firstDot + 1) + cleaned.slice(firstDot + 1).replace(/\./g, '');
  }

  function moneyFromInput(value){
    var cleaned = decimalOnly(value);
    var amount = parseFloat(cleaned);
    return Number.isFinite(amount) ? amount : 0;
  }

  function formatUsd(amount){
    return amount ? '$' + amount.toFixed(2) : '';
  }

  function showRumbleDialog(message, title, details){
    var existing = document.querySelector('.rumble-dialog-backdrop');
    if (existing) existing.remove();
    var detailHtml = '';
    if (Array.isArray(details) && details.length) {
      detailHtml = '<ul>' + details.map(function(item){ return '<li>' + escapeHtml(item) + '</li>'; }).join('') + '</ul>';
    }

    var backdrop = document.createElement('div');
    backdrop.className = 'rumble-dialog-backdrop';
    backdrop.innerHTML =
      '<section class="rumble-dialog" role="dialog" aria-modal="true" aria-labelledby="rumble-dialog-title" tabindex="-1">' +
        '<header><h2 id="rumble-dialog-title">' + escapeHtml(title || 'Rumble') + '</h2></header>' +
        '<p>' + escapeHtml(message || '') + '</p>' +
        detailHtml +
        '<footer><button type="button" data-rumble-dialog-close>OK</button></footer>' +
      '</section>';

    function close(){
      backdrop.remove();
      document.removeEventListener('keydown', onKeydown);
    }

    function onKeydown(event){
      if (event.key === 'Escape' || event.key === 'Enter') close();
    }

    backdrop.addEventListener('click', function(event){
      if (event.target === backdrop || event.target.closest('[data-rumble-dialog-close]')) close();
    });
    document.addEventListener('keydown', onKeydown);
    document.body.appendChild(backdrop);
    var dialog = backdrop.querySelector('.rumble-dialog');
    var button = backdrop.querySelector('[data-rumble-dialog-close]');
    if (dialog) dialog.focus();
    if (button) window.setTimeout(function(){ button.focus(); }, 0);
  }

  function taxRate(){
    var rate = window.rumbleData ? parseFloat(rumbleData.taxRate) : 0;
    return Number.isFinite(rate) ? rate : 0;
  }

  function formatPhone(input){
    var d = digitsOnly(input.value).slice(0, 10);
    if (d.length > 6) input.value = d.slice(0, 3) + '-' + d.slice(3, 6) + '-' + d.slice(6);
    else if (d.length > 3) input.value = d.slice(0, 3) + '-' + d.slice(3);
    else input.value = d;
  }

  function resizeDescription(textarea){
    textarea.style.height = '42px';
    textarea.style.height = Math.max(42, textarea.scrollHeight) + 'px';
  }

  function updateLineTotal(row){
    var sizeInputs = row.querySelectorAll('[data-rumble-size-qty]');
    var qty = 0;
    sizeInputs.forEach(function(input){
      qty += parseInt(digitsOnly(input.value), 10) || 0;
    });

    var qtyInput = row.querySelector('[data-rumble-qty]');
    if (qtyInput) qtyInput.value = qty || '';

    var priceInput = row.querySelector('[data-rumble-price]');
    var totalInput = row.querySelector('[data-rumble-total]');
    if (!totalInput) return;

    var subtotal = qty * moneyFromInput(priceInput ? priceInput.value : '');
    var taxable = !!row.querySelector('[data-rumble-sales-tax]:checked');
    totalInput.value = formatUsd(subtotal + (taxable ? subtotal * taxRate() : 0));
    updateOrderTotals(row.closest('form'));
  }

  function itemRows(form){
    return Array.prototype.slice.call(form.querySelectorAll('.rumble-items-row:not(.rumble-items-head)'));
  }

  function rowHasData(row){
    return Array.prototype.some.call(row.querySelectorAll('input, select, textarea'), function(field){
      if (field.type === 'hidden' || field.matches('[data-rumble-qty], [data-rumble-total]')) return false;
      if (field.type === 'checkbox') return field.checked;
      return String(field.value || '').trim() !== '';
    });
  }

  function renumberItemRows(form){
    itemRows(form).forEach(function(row, index){
      var rowNumber = index + 1;
      row.querySelectorAll('[name]').forEach(function(field){
        field.name = field.name.replace(/^items\[\d+\]/, 'items[' + rowNumber + ']');
      });
      row.querySelectorAll('[aria-label]').forEach(function(field){
        field.setAttribute('aria-label', field.getAttribute('aria-label').replace(/\s+\d+$/, ' ' + rowNumber));
      });
    });
  }

  function resetItemRow(row){
    row.querySelectorAll('input, select, textarea').forEach(function(field){
      if (field.type === 'checkbox') field.checked = false;
      else field.value = '';
    });
    updateLineTotal(row);
    row.querySelectorAll('[data-rumble-description]').forEach(resizeDescription);
  }

  function cloneItemRow(row){
    var clone = row.cloneNode(true);
    resetItemRow(clone);
    return clone;
  }

  function syncDynamicItemRows(form){
    var rows = itemRows(form);
    if (!rows.length) return;
    var last = rows[rows.length - 1];
    if (rowHasData(last)) {
      last.parentElement.appendChild(cloneItemRow(last));
      rows = itemRows(form);
    }
    for (var i = rows.length - 1; i >= 8; i--) {
      if (i === rows.length - 1) continue;
      if (!rowHasData(rows[i])) rows[i].remove();
    }
    renumberItemRows(form);
    updateOrderTotals(form);
  }

  function updateOrderTotals(form){
    if (!form) return;
    var merchandiseTotal = 0;
    var taxTotal = 0;

    form.querySelectorAll('.rumble-items-row:not(.rumble-items-head)').forEach(function(row){
      var qtyInput = row.querySelector('[data-rumble-qty]');
      var priceInput = row.querySelector('[data-rumble-price]');
      var qty = parseInt(digitsOnly(qtyInput ? qtyInput.value : ''), 10) || 0;
      var subtotal = qty * moneyFromInput(priceInput ? priceInput.value : '');
      merchandiseTotal += subtotal;
      if (row.querySelector('[data-rumble-sales-tax]:checked')) taxTotal += subtotal * taxRate();
    });

    var taxInput = form.querySelector('[data-rumble-tax-total]');
    var grandInput = form.querySelector('[data-rumble-grand-total]');
    var artSetup = moneyFromInput((form.querySelector('[name="art_setup_total"]') || {}).value);
    var rushFee = moneyFromInput((form.querySelector('[name="rush_fee"]') || {}).value);
    var grandTotal = merchandiseTotal + artSetup + rushFee + taxTotal;

    if (taxInput) taxInput.value = formatUsd(taxTotal);
    if (grandInput) grandInput.value = formatUsd(grandTotal);
  }

  function syncShippingFromBilling(form){
    var pairs = [
      ['company', 'ship_to'],
      ['billing_street', 'shipping_street'],
      ['billing_city', 'shipping_city'],
      ['billing_state', 'shipping_state'],
      ['billing_zip', 'shipping_zip']
    ];

    pairs.forEach(function(pair){
      var billing = form.querySelector('[name="' + pair[0] + '"]');
      var shipping = form.querySelector('[name="' + pair[1] + '"]');
      if (billing && shipping) shipping.value = billing.value;
    });
  }

  function sameAsBillingIsChecked(form){
    return !!form.querySelector('[name="same_as_billing"]:checked');
  }

  function isBillingField(field){
    return /^(company|billing_)/.test(field.getAttribute('name') || '');
  }

  function isShippingField(field){
    return /^(ship_to|shipping_)/.test(field.getAttribute('name') || '');
  }

  function setShippingReadonly(form, readonly){
    ['ship_to', 'shipping_street', 'shipping_city', 'shipping_state', 'shipping_zip'].forEach(function(name){
      var field = form.querySelector('[name="' + name + '"]');
      if (!field) return;
      if (field.tagName === 'SELECT') field.setAttribute('aria-readonly', readonly ? 'true' : 'false');
      else field.readOnly = readonly;
    });
  }

  function setFieldValue(form, name, value){
    var field = form.querySelector('[name="' + name + '"]');
    if (!field) return;
    field.value = value || '';
    if (field.matches('[data-rumble-phone]')) formatPhone(field);
  }

  function applyCustomerLookupResult(form, customer){
    if (!customer) return;
    [
      'company',
      'buyer_first',
      'buyer_last',
      'billing_street',
      'billing_city',
      'billing_state',
      'billing_zip',
      'phone',
      'email'
    ].forEach(function(name){
      setFieldValue(form, name, customer[name] || '');
    });

    var same = form.querySelector('[name="same_as_billing"]');
    if (same) same.checked = true;
    syncShippingFromBilling(form);
    setShippingReadonly(form, true);
  }

  function customerLookupLabel(customer){
    var name = [customer.buyer_first || '', customer.buyer_last || ''].join(' ').trim();
    var primary = customer.company || name || customer.label || '';
    var secondary = customer.company && name ? name : '';
    if (secondary && customer.order_number) secondary += ' - #' + customer.order_number;
    else if (customer.order_number) secondary = '#' + customer.order_number;
    return {primary: primary, secondary: secondary};
  }

  function setupCustomerLookup(form){
    var fields = Array.prototype.slice.call(form.querySelectorAll('[data-rumble-customer-lookup]'));
    if (!fields.length || !window.rumbleData || !rumbleData.ajax || !rumbleData.nonce) return;

    var box = document.createElement('div');
    box.className = 'rumble-customer-lookup';
    box.hidden = true;
    form.appendChild(box);

    var timer = null;
    var controller = null;
    var activeField = null;

    function close(){
      box.hidden = true;
      box.innerHTML = '';
    }

    function positionBox(field){
      var formRect = form.getBoundingClientRect();
      var rect = field.getBoundingClientRect();
      box.style.left = (rect.left - formRect.left) + 'px';
      box.style.top = (rect.bottom - formRect.top + 4) + 'px';
      box.style.width = rect.width + 'px';
    }

    function draw(results){
      if (!activeField) return;
      if (!results.length) {
        close();
        return;
      }
      box.innerHTML = results.map(function(customer, index){
        var label = customerLookupLabel(customer);
        return '<button type="button" data-index="' + index + '">' +
          '<strong>' + escapeHtml(label.primary) + '</strong>' +
          (label.secondary ? '<span>' + escapeHtml(label.secondary) + '</span>' : '') +
        '</button>';
      }).join('');
      box._rumbleResults = results;
      positionBox(activeField);
      box.hidden = false;
    }

    function search(field){
      var term = field.value.trim();
      if (term.length < 2) {
        close();
        return;
      }
      if (controller) controller.abort();
      controller = new AbortController();
      var url = new URL(rumbleData.ajax);
      url.searchParams.set('action', 'rumble_customer_lookup');
      url.searchParams.set('nonce', rumbleData.nonce);
      url.searchParams.set('term', term);

      fetch(url.toString(), {credentials: 'same-origin', signal: controller.signal})
        .then(function(response){ return response.json(); })
        .then(function(json){
          if (!json || !json.success) throw json;
          draw((json.data && json.data.results) || []);
        })
        .catch(function(error){
          if (error && error.name === 'AbortError') return;
          close();
        });
    }

    fields.forEach(function(field){
      field.addEventListener('input', function(){
        activeField = field;
        window.clearTimeout(timer);
        timer = window.setTimeout(function(){ search(field); }, 220);
      });
      field.addEventListener('focus', function(){
        activeField = field;
        if (field.value.trim().length >= 2) search(field);
      });
      field.addEventListener('keydown', function(event){
        if (event.key === 'Escape') close();
        if (event.key === 'ArrowDown' && !box.hidden) {
          var first = box.querySelector('button');
          if (first) {
            event.preventDefault();
            first.focus();
          }
        }
      });
    });

    box.addEventListener('click', function(event){
      var button = event.target.closest('button[data-index]');
      if (!button) return;
      var result = (box._rumbleResults || [])[parseInt(button.dataset.index, 10)];
      applyCustomerLookupResult(form, result);
      close();
    });

    box.addEventListener('keydown', function(event){
      if (event.key === 'Escape') {
        close();
        if (activeField) activeField.focus();
        return;
      }
      if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
      event.preventDefault();
      var buttons = Array.prototype.slice.call(box.querySelectorAll('button'));
      var index = buttons.indexOf(document.activeElement);
      var next = event.key === 'ArrowDown' ? index + 1 : index - 1;
      if (next < 0) next = buttons.length - 1;
      if (next >= buttons.length) next = 0;
      if (buttons[next]) buttons[next].focus();
    });

    document.addEventListener('click', function(event){
      if (!event.target.closest('.rumble-customer-lookup') && !event.target.closest('[data-rumble-customer-lookup]')) {
        close();
      }
    });

    window.addEventListener('resize', function(){
      if (!box.hidden && activeField) positionBox(activeField);
    });
  }

  function syncVendorName(select){
    var selected = select.options[select.selectedIndex];
    var row = select.closest('.rumble-items-row');
    var vendorName = row ? row.querySelector('[data-rumble-vendor-name]') : null;
    if (vendorName) vendorName.value = selected ? (selected.getAttribute('data-name') || '') : '';
  }

  function setVendorSelectLabels(select, mode){
    Array.prototype.forEach.call(select.options, function(option){
      if (!option.value) return;
      var name = option.getAttribute('data-name') || option.textContent;
      option.textContent = mode === 'names' ? name : option.value;
    });
  }

  function collapseVendorSelect(select){
    if (!select.value) {
      setVendorSelectLabels(select, 'names');
      return;
    }
    setVendorSelectLabels(select, 'names');
    select.options[select.selectedIndex].textContent = select.value;
  }

  function setupNewOrderForm(form){
    var section = form.closest('.rumble-order-screen');
    var orderInput = form.querySelector('[name="woocommerce_order_number"]');

    if (section) {
      section.querySelectorAll('[data-rumble-order-action]').forEach(function(button){
        button.addEventListener('click', function(){
          saveNewOrder(form, button.getAttribute('data-rumble-order-action') || 'auto', button);
        });
      });
    }

    form.addEventListener('input', function(event){
      var target = event.target;
      if (!(target instanceof HTMLInputElement) && !(target instanceof HTMLTextAreaElement)) return;

      if (target.matches('[data-rumble-description]')) {
        resizeDescription(target);
      }

      if (target.matches('[data-rumble-phone]')) {
        formatPhone(target);
        return;
      }

      if (target.matches('[data-rumble-number-only]')) {
        target.value = digitsOnly(target.value);
      }

      if (target.matches('[data-rumble-currency]')) {
        target.value = decimalOnly(target.value);
      }

      if (target.form && sameAsBillingIsChecked(target.form) && isBillingField(target)) {
        syncShippingFromBilling(target.form);
      }

      var row = target.closest('.rumble-items-row');
      if (row && (target.matches('[data-rumble-size-qty]') || target.matches('[data-rumble-qty]') || target.matches('[data-rumble-price]'))) {
        updateLineTotal(row);
      }
      if (row && !row.classList.contains('rumble-items-head')) {
        syncDynamicItemRows(form);
      }

      if (target.matches('[name="art_setup_total"], [name="rush_fee"]')) {
        updateOrderTotals(form);
      }

      if (target.matches('[data-rumble-vendor]')) {
        syncVendorName(target);
        collapseVendorSelect(target);
      }
    });

    form.addEventListener('pointerdown', function(event){
      var target = event.target;
      if (target instanceof HTMLSelectElement && target.matches('[data-rumble-vendor]')) {
        setVendorSelectLabels(target, 'names');
      }
    });

    form.addEventListener('focusin', function(event){
      var target = event.target;
      if (target instanceof HTMLSelectElement && target.matches('[data-rumble-vendor]')) {
        setVendorSelectLabels(target, 'names');
      }
    });

    form.addEventListener('blur', function(event){
      var target = event.target;
      if (!(target instanceof HTMLInputElement)) return;

      if (target.matches('[data-rumble-phone]') && target.value && !/^\d{3}-\d{3}-\d{4}$/.test(target.value)) {
        target.setCustomValidity('Use xxx-xxx-xxxx format.');
        target.reportValidity();
      } else if (target.matches('[data-rumble-phone]')) {
        target.setCustomValidity('');
      }

      if (target.matches('[data-rumble-currency]')) {
        target.value = formatUsd(moneyFromInput(target.value));
        var row = target.closest('.rumble-items-row');
        if (row) updateLineTotal(row);
        else updateOrderTotals(form);
      }
    }, true);

    form.addEventListener('change', function(event){
      var target = event.target;
      if (!(target instanceof HTMLElement)) return;

      if (target.matches('[name="same_as_billing"]')) {
        if (target.checked) syncShippingFromBilling(form);
        setShippingReadonly(form, target.checked);
      }

      if (target.form && sameAsBillingIsChecked(target.form) && isBillingField(target)) {
        syncShippingFromBilling(target.form);
      }

      if (target.form && sameAsBillingIsChecked(target.form) && isShippingField(target)) {
        syncShippingFromBilling(target.form);
      }

      if (target.matches('[data-rumble-sales-tax]')) {
        var row = target.closest('.rumble-items-row');
        if (row) updateLineTotal(row);
      }

      if (target.matches('[data-rumble-vendor]')) {
        syncVendorName(target);
        collapseVendorSelect(target);
      }
      var row = target.closest('.rumble-items-row');
      if (row && !row.classList.contains('rumble-items-head')) {
        syncDynamicItemRows(form);
      }
    });

    form.addEventListener('focusout', function(event){
      var target = event.target;
      if (target instanceof HTMLSelectElement && target.matches('[data-rumble-vendor]')) {
        collapseVendorSelect(target);
      }
    });

    form.addEventListener('submit', function(event){
      event.preventDefault();
      saveNewOrder(form, 'auto', null);
    });

    if (orderInput) orderInput.readOnly = true;
    ['tax', 'grand_total'].forEach(function(name){
      var input = form.querySelector('[name="' + name + '"]');
      if (input) input.readOnly = true;
    });
    form.querySelectorAll('[data-rumble-size-qty], [data-rumble-price]').forEach(function(input){
      input.readOnly = false;
      input.disabled = false;
      input.removeAttribute('readonly');
      input.removeAttribute('disabled');
    });
    form.querySelectorAll('[data-rumble-vendor]').forEach(collapseVendorSelect);
    form.querySelectorAll('[data-rumble-description]').forEach(resizeDescription);
    setupCustomerLookup(form);
    syncDynamicItemRows(form);
    updateOrderTotals(form);
  }

  function collectOrderData(form, intent){
    var data = { intent: intent, items: {} };
    var formData = new FormData(form);
    formData.forEach(function(value, key){
      var itemMatch = key.match(/^items\[(\d+)\]\[([^\]]+)\]$/);
      if (itemMatch) {
        if (!data.items[itemMatch[1]]) data.items[itemMatch[1]] = {};
        data.items[itemMatch[1]][itemMatch[2]] = value;
      } else {
        data[key] = value;
      }
    });
    form.querySelectorAll('input[type="checkbox"]').forEach(function(input){
      if (/^items\[\d+\]\[[^\]]+\]$/.test(input.name)) return;
      data[input.name] = input.checked ? '1' : '';
    });
    data.items = Object.keys(data.items).sort(function(a, b){ return parseInt(a, 10) - parseInt(b, 10); }).map(function(key){
      return data.items[key];
    });
    return data;
  }

  function setActionBusy(button, busy){
    if (!button) return;
    if (busy) {
      button.dataset.rumbleOriginalText = button.textContent;
      button.textContent = 'Saving...';
      button.disabled = true;
    } else {
      button.textContent = button.dataset.rumbleOriginalText || button.textContent;
      button.disabled = false;
    }
  }

  function saveNewOrder(form, intent, button){
    if (!window.rumbleData || !rumbleData.ajax || !rumbleData.nonce) {
      showRumbleDialog('Rumble order saving is not available on this page.', 'Unable to Save');
      return;
    }
    if (!form.checkValidity() && intent !== 'draft') intent = 'draft';

    setActionBusy(button, true);
    var body = new URLSearchParams();
    body.set('action', 'rumble_save_new_order');
    body.set('nonce', rumbleData.nonce);
    body.set('payload', JSON.stringify(collectOrderData(form, intent)));

    fetch(rumbleData.ajax, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    })
      .then(function(response){
        return response.json().then(function(json){
          if (!response.ok || !json.success) throw json;
          return json;
        });
      })
      .then(function(json){
        var orderInput = form.querySelector('[name="woocommerce_order_number"]');
        if (orderInput && json.data && json.data.order_id) orderInput.value = json.data.order_id;
        var dateInput = form.querySelector('[name="order_date"]');
        if (dateInput && json.data && json.data.order_date && !dateInput.value) dateInput.value = json.data.order_date;
        if (json.data && json.data.status && json.data.status !== 'rumble-draft') {
          window.location.href = '/rumble/';
          return;
        }
        showRumbleDialog((json.data && json.data.message) || 'Order saved.', 'Saved', json.data && json.data.missing_requirements);
      })
      .catch(function(error){
        showRumbleDialog((error && error.data && error.data.message) || 'Error saving order.', 'Save Failed');
      })
      .finally(function(){
        setActionBusy(button, false);
      });
  }

  function setupSearch(input){
    var box = document.getElementById(input.getAttribute('aria-controls') || '');
    if (!box) return;
    var tbody = box.querySelector('tbody');
    var timer = null;
    var controller = null;

    function setOpen(open){
      box.hidden = !open;
      input.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function clearResults(){
      if (tbody) tbody.innerHTML = '';
      setOpen(false);
    }

    function drawResults(results){
      if (!tbody) return;
      tbody.innerHTML = '';
      if (!results.length) {
        var empty = document.createElement('tr');
        empty.className = 'is-empty';
        empty.innerHTML = '<td colspan="5">No matching orders</td>';
        tbody.appendChild(empty);
        setOpen(true);
        return;
      }

      results.forEach(function(item){
        var row = document.createElement('tr');
        row.tabIndex = 0;
        row.dataset.url = item.url || '';
        row.innerHTML =
          '<td>' + escapeHtml(item.type || '') + (item.order_number ? ' #' + escapeHtml(item.order_number) : '') + '</td>' +
          '<td>' + escapeHtml(item.customer || '') + '</td>' +
          '<td>' + escapeHtml(item.title || '') + '</td>' +
          '<td>' + escapeHtml(item.status || '') + '</td>' +
          '<td>' + escapeHtml(item.due_date || '') + '</td>';
        tbody.appendChild(row);
      });
      setOpen(true);
    }

    function runSearch(){
      var term = input.value.trim();
      if (term.length < 2) {
        clearResults();
        return;
      }
      if (!window.rumbleData || !rumbleData.ajax || !rumbleData.nonce) return;
      if (controller) controller.abort();
      controller = new AbortController();
      var url = new URL(rumbleData.ajax);
      url.searchParams.set('action', 'rumble_search');
      url.searchParams.set('nonce', rumbleData.nonce);
      url.searchParams.set('term', term);

      fetch(url.toString(), {credentials: 'same-origin', signal: controller.signal})
        .then(function(response){ return response.json(); })
        .then(function(json){
          if (!json || !json.success) throw json;
          drawResults((json.data && json.data.results) || []);
        })
        .catch(function(error){
          if (error && error.name === 'AbortError') return;
          clearResults();
        });
    }

    input.addEventListener('input', function(){
      window.clearTimeout(timer);
      timer = window.setTimeout(runSearch, 180);
    });

    input.addEventListener('keydown', function(event){
      if (event.key === 'Escape') clearResults();
      if (event.key === 'ArrowDown' && tbody && tbody.querySelector('tr[data-url]')) {
        event.preventDefault();
        tbody.querySelector('tr[data-url]').focus();
      }
    });

    box.addEventListener('click', function(event){
      var row = event.target.closest('tr[data-url]');
      if (row && row.dataset.url) window.location.href = row.dataset.url;
    });

    box.addEventListener('keydown', function(event){
      var row = event.target.closest('tr[data-url]');
      if (!row) return;
      if (event.key === 'Enter' && row.dataset.url) window.location.href = row.dataset.url;
      if (event.key === 'Escape') {
        clearResults();
        input.focus();
      }
    });

    document.addEventListener('click', function(event){
      if (!event.target.closest('.rumble-search')) clearResults();
    });
  }

  function escapeHtml(value){
    return String(value).replace(/[&<>"']/g, function(char){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
    });
  }

  function setupNoteForm(form){
    form.addEventListener('submit', function(event){
      event.preventDefault();
      if (!window.rumbleData || !rumbleData.ajax || !rumbleData.nonce) return;

      var textarea = form.querySelector('[name="note"]');
      var button = form.querySelector('button[type="submit"]');
      var note = textarea ? textarea.value.trim() : '';
      if (!note) {
        if (textarea) textarea.focus();
        return;
      }

      if (button) button.disabled = true;
      var body = new URLSearchParams();
      body.set('action', 'rumble_add_order_note');
      body.set('nonce', rumbleData.nonce);
      body.set('order_id', form.dataset.rumbleOrderId || '');
      body.set('note', note);
      body.set('note_type', (form.querySelector('[name="note_type"]') || {}).value || 'private');

      fetch(rumbleData.ajax, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body: body.toString()
      })
        .then(function(response){
          return response.json().then(function(json){
            if (!response.ok || !json.success) throw json;
            return json;
          });
        })
        .then(function(json){
          if (textarea) textarea.value = '';
          prependNote(form, json.data && json.data.note);
        })
        .catch(function(error){
          showRumbleDialog((error && error.data && error.data.message) || 'Error adding note.', 'Note Failed');
        })
        .finally(function(){
          if (button) button.disabled = false;
        });
    });
  }

  function prependNote(form, note){
    if (!note) return;
    var list = form.parentElement ? form.parentElement.querySelector('[data-rumble-note-list]') : null;
    if (!list) return;
    var empty = list.querySelector('.rumble-note-empty');
    if (empty) empty.remove();

    var article = document.createElement('article');
    article.className = 'rumble-note ' + (note.customer_note ? 'is-customer' : 'is-private');
    article.innerHTML =
      '<header><strong>' + escapeHtml(note.type || 'Private note') + '</strong><span>' + escapeHtml(note.date || '') + '</span></header>' +
      '<p>' + escapeHtml(note.content || '').replace(/\n/g, '<br>') + '</p>';
    list.insertBefore(article, list.firstChild);
  }

  function saveStatusForm(form, button, notify){
    if (!window.rumbleData || !rumbleData.ajax || !rumbleData.nonce) return;

    var status = (form.querySelector('[name="status"]') || {}).value || '';
    var designInput = form.querySelector('[name="needs_design"]');

    if (button) button.disabled = true;
    var body = new URLSearchParams();
    body.set('action', 'rumble_update_order_status');
    body.set('nonce', rumbleData.nonce);
    body.set('order_id', form.dataset.rumbleOrderId || '');
    body.set('status', status);
    body.set('needs_design', designInput && designInput.checked ? '1' : '');

    fetch(rumbleData.ajax, {
      method: 'POST',
      credentials: 'same-origin',
      keepalive: true,
      headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    })
      .then(function(response){
        return response.json().then(function(json){
          if (!response.ok || !json.success) throw json;
          return json;
        });
      })
      .then(function(json){
        if (designInput) designInput.checked = !!(json.data && json.data.needs_design);
        var heading = form.closest('.rumble-section-head');
        var title = heading ? heading.querySelector('h2') : null;
        if (title && json.data && json.data.status_label) title.textContent = json.data.status_label;
        if (notify) showRumbleDialog((json.data && json.data.message) || 'Status updated.', 'Updated');
      })
      .catch(function(error){
        showRumbleDialog((error && error.data && error.data.message) || 'Error updating status.', 'Update Failed');
      })
      .finally(function(){
        if (button) button.disabled = false;
      });
  }

  function setupStatusForm(form){
    form.addEventListener('change', function(event){
      if (event.target && event.target.matches('[name="needs_design"]')) {
        saveStatusForm(form, form.querySelector('button[type="submit"]'), false);
      }
    });

    form.addEventListener('submit', function(event){
      event.preventDefault();
      saveStatusForm(form, form.querySelector('button[type="submit"]'), true);
    });
  }

  function setupTimelineForm(form){
    form.addEventListener('submit', function(event){
      event.preventDefault();
      if (!window.rumbleData || !rumbleData.ajax || !rumbleData.nonce) return;

      var button = form.querySelector('button[type="submit"]');
      if (button) {
        button.dataset.rumbleOriginalText = button.textContent;
        button.textContent = 'Saving...';
        button.disabled = true;
      }

      var body = new URLSearchParams();
      body.set('action', 'rumble_update_order_timeline');
      body.set('nonce', rumbleData.nonce);
      body.set('order_id', form.dataset.rumbleOrderId || '');
      ['production_date', 'needed_by', 'event_date'].forEach(function(name){
        body.set(name, (form.querySelector('[name="' + name + '"]') || {}).value || '');
      });

      fetch(rumbleData.ajax, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body: body.toString()
      })
        .then(function(response){
          return response.json().then(function(json){
            if (!response.ok || !json.success) throw json;
            return json;
          });
        })
        .then(function(json){
          showRumbleDialog((json.data && json.data.message) || 'Timeline updated.', 'Updated');
        })
        .catch(function(error){
          showRumbleDialog((error && error.data && error.data.message) || 'Error updating timeline.', 'Timeline Failed');
        })
        .finally(function(){
          if (button) {
            button.textContent = button.dataset.rumbleOriginalText || 'Update Timeline';
            button.disabled = false;
          }
        });
    });
  }

  function setupFreshBooksButtons(){
    document.querySelectorAll('[data-rumble-freshbooks-invoice]').forEach(function(button){
      button.addEventListener('click', function(){
        if (!window.rumbleData || !rumbleData.ajax || !rumbleData.nonce) return;
        if (button.disabled) return;

        var original = button.textContent;
        button.disabled = true;
        button.textContent = 'Creating...';

        var body = new URLSearchParams();
        body.set('action', 'rumble_create_freshbooks_invoice');
        body.set('nonce', rumbleData.nonce);
        body.set('order_id', button.getAttribute('data-rumble-order-id') || '');

        fetch(rumbleData.ajax, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
          body: body.toString()
        })
          .then(function(response){
            return response.json().then(function(json){
              if (!response.ok || !json.success) throw json;
              return json;
            });
          })
          .then(function(json){
            showRumbleDialog((json.data && json.data.message) || 'FreshBooks invoice created.', 'Invoice Created');
            window.setTimeout(function(){ window.location.reload(); }, 700);
          })
          .catch(function(error){
            button.disabled = false;
            button.textContent = original;
            showRumbleDialog(
              (error && error.data && error.data.message) || 'FreshBooks invoice failed.',
              'Invoice Failed',
              error && error.data && Array.isArray(error.data.details) ? error.data.details : []
            );
          });
      });
    });
  }

  function setupBusyForms(){
    document.querySelectorAll('.rumble-workorder-card').forEach(function(form){
      form.addEventListener('submit', function(){
        setRumbleBusy(true);
        window.setTimeout(function(){
          setRumbleBusy(false);
        }, 1400);
      });
    });
  }

  function setupStageLinks(){
    document.querySelectorAll('[data-rumble-stage-url]').forEach(function(stage){
      stage.addEventListener('click', function(event){
        if (event.target.closest('a, button, input, select, textarea')) return;
        var url = stage.getAttribute('data-rumble-stage-url');
        if (url) window.location.href = url;
      });
    });
  }

  function setupClickableRows(){
    document.querySelectorAll('[data-rumble-row-url]').forEach(function(row){
      row.addEventListener('click', function(event){
        if (event.target.closest('a, button, input, select, textarea')) return;
        if (row.dataset.rumbleRowUrl) window.location.href = row.dataset.rumbleRowUrl;
      });
      row.addEventListener('keydown', function(event){
        if ((event.key === 'Enter' || event.key === ' ') && row.dataset.rumbleRowUrl) {
          event.preventDefault();
          window.location.href = row.dataset.rumbleRowUrl;
        }
      });
    });
  }

  function setupOrderCards(){
    document.querySelectorAll('.rumble-job-card, .rumble-work-item, .rumble-attention-item, .rumble-calendar-item').forEach(function(card){
      card.addEventListener('click', function(event){
        if (event.target.closest('a, button, input, select, textarea') && event.target.closest('a') !== card) return;
        if (card.href) window.location.href = card.href;
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.rumble-order-form').forEach(setupNewOrderForm);
    document.querySelectorAll('[data-rumble-search]').forEach(setupSearch);
    document.querySelectorAll('.rumble-note-form').forEach(setupNoteForm);
    document.querySelectorAll('.rumble-status-form').forEach(setupStatusForm);
    document.querySelectorAll('.rumble-timeline-form').forEach(setupTimelineForm);
    setupFreshBooksButtons();
    setupBusyForms();
    setupStageLinks();
    setupClickableRows();
    setupOrderCards();
  });
})();
