/*
 * File: assets/js/admin.js
 * Description: Lightweight admin interactions for creating and deleting POs.
 */
(function($){
  function post(action, data){
    data = data || {};
    data.action = action;
    var backend = window.TRAXS_BACKEND || {};
    data._wpnonce = backend.nonce ? backend.nonce : '';
    var url = backend.ajax_url ? backend.ajax_url : ajaxurl;
    return $.post(url, data);
  }

  function setBusy($btn, on){
    var $sp = $('.eject-spinner');
    if(on){
      $btn.prop('disabled', true).addClass('is-busy');
      $sp.addClass('is-active');
    } else {
      $btn.prop('disabled', false).removeClass('is-busy');
      $sp.removeClass('is-active');
    }
  }

  function renderResult(resp){
    var $wrap = $('#eject-generation-result').empty();
    if(!resp || !resp.success){ $wrap.text('Unable to build POs.'); return; }
    var data = resp.data || {};

    if(data.created && data.created.length){
      var $list = $('<ul class="eject-result-list"></ul>');
      data.created.forEach(function(r){
        var txt = 'PO ' + r.po_number + ' for vendor ' + r.vendor + ' (' + r.order_ids.length + ' orders, ' + r.items.length + ' item groups)';
        $list.append('<li>'+txt+'</li>');
      });
      $wrap.append('<p><strong>Created:</strong></p>').append($list);
    } else {
      $wrap.append('<p>No new POs were created.</p>');
    }

    if(data.skipped && data.skipped.length){
      var $skip = $('<ul class="eject-result-list eject-skipped"></ul>');
      data.skipped.forEach(function(s){
        var label = s.order_id ? ('Order #' + s.order_id) : 'Item';
        $skip.append('<li>'+label+': '+(s.reason || 'Skipped')+'</li>');
      });
      $wrap.append('<p><strong>Skipped:</strong></p>').append($skip);
    }
  }

  $(document).on('click', '#eject-generate-pos', function(e){
    e.preventDefault();
    var $btn = $(this);
    setBusy($btn, true);
    post('eject_generate_pos', {}).done(function(resp){
      renderResult(resp);
      if(resp && resp.success){
        setTimeout(function(){ location.reload(); }, 800);
      }
    }).fail(function(){
      alert('Generation failed. Check the console for details.');
    }).always(function(){ setBusy($btn, false); });
  });

  function bindTap(selector, handler){
    // Robust mobile activation: handle touch/pointer/click and dedupe rapid duplicates.
    function invokeOnce(el, e){
      var $el = $(el);
      var now = Date.now();
      var last = parseInt($el.data('tap-last-fired') || 0, 10);
      if(last && (now - last) < 700){
        return;
      }
      $el.data('tap-last-fired', now);
      handler.call(el, e);
    }

    $(document).on('pointerup', selector, function(e){
      var oe = e.originalEvent;
      if(oe && oe.pointerType === 'mouse'){
        return;
      }
      invokeOnce(this, e);
    });

    $(document).on('touchend', selector, function(e){
      invokeOnce(this, e);
    });

    $(document).on('click', selector, function(e){
      invokeOnce(this, e);
    });
  }

  function confirmAction(message, onConfirm){
    var $overlay = $('<div class="eject-confirm-overlay" role="dialog" aria-modal="true"></div>');
    var $card = $('<div class="eject-confirm-card"></div>');
    var $msg = $('<p class="eject-confirm-message"></p>').text(message || 'Are you sure?');
    var $actions = $('<div class="eject-confirm-actions"></div>');
    var $cancel = $('<button type="button" class="button">Cancel</button>');
    var $ok = $('<button type="button" class="button button-primary">Continue</button>');
    var done = false;

    function close(runConfirm){
      if(done) return;
      done = true;
      $(document).off('keydown.ejectConfirm');
      $overlay.remove();
      if(runConfirm && typeof onConfirm === 'function'){
        onConfirm();
      }
    }

    $cancel.on('click', function(e){
      e.preventDefault();
      close(false);
    });
    $ok.on('click', function(e){
      e.preventDefault();
      close(true);
    });
    $overlay.on('click', function(e){
      if(e.target === this){
        close(false);
      }
    });
    $(document).on('keydown.ejectConfirm', function(e){
      if(e.key === 'Escape'){
        close(false);
      }
    });

    $actions.append($cancel, $ok);
    $card.append($msg, $actions);
    $overlay.append($card);
    $('body').append($overlay);
    $ok.trigger('focus');
  }

  bindTap('.eject-delete-po', function(e){
    e.preventDefault();
    var $btn = $(this), po = $btn.data('po');
    if(!po){
      alert('Missing PO ID.');
      return;
    }
    if($btn.prop('disabled')) return;
    confirmAction('Delete this PO?', function(){
      $btn.prop('disabled', true);
      post('eject_delete_po', {po_id: po}).done(function(resp){
        if(resp && resp.success){
          $btn.closest('tr').fadeOut(120, function(){ $(this).remove(); });
        } else {
          alert(resp && resp.data && resp.data.message ? resp.data.message : 'Delete failed.');
        }
      }).fail(function(xhr){
        var msg = 'Delete failed.';
        if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
          msg = xhr.responseJSON.data.message;
        }
        alert(msg);
      }).always(function(){ $btn.prop('disabled', false); });
    });
  });

  bindTap('.eject-po-ordered', function(e){
    e.preventDefault();
    var $btn = $(this), $row = $btn.closest('tr');
    if($btn.prop('disabled')) return;
    var $checks = $row.find('.eject-size-checkbox');
    var hasUnordered = false;
    var keepItems = [];
    if($checks.length){
      var total = $checks.length;
      var checked = $checks.filter(':checked').length;
      hasUnordered = checked < total;
      if(checked === 0){
        return; // should be disabled already, just guard.
      }
      $checks.each(function(){
        if($(this).prop('checked')){
          keepItems.push({
            code: $(this).data('code')||'',
            color: $(this).data('color')||'',
            size: $(this).data('size')||''
          });
        }
      });
    }
    $btn.prop('disabled', true).text('Ordering...');
    post('eject_mark_po_ordered', {po_id: $btn.data('po'), has_unordered: hasUnordered, keep_items: keepItems})
      .done(function(resp){
        if(resp && resp.success){
          location.reload();
        } else {
          alert(resp && resp.data && resp.data.message ? resp.data.message : 'Failed to mark ordered.');
        }
      })
      .fail(function(){ alert('Failed to mark ordered.'); })
      .always(function(){ $btn.prop('disabled', false).text('Order PO'); });
  });

  // Enable Order PO when at least one item is checked; flag if any remain unchecked
  function updateOrderedButton($row){
    var $btn=$row.find('.eject-po-ordered');
    var $checks=$row.find('.eject-size-checkbox');
    if(!$btn.length){ return; }
    if(!$checks.length){
      $btn.prop('disabled', false).data('has-unordered', false);
      return;
    }
    var checked = $checks.filter(':checked').length;
    var hasUnordered = checked < $checks.length;
    $btn.prop('disabled', checked === 0);
    $btn.data('has-unordered', hasUnordered);
  }
  $(document).on('change', '.eject-size-checkbox', function(){
    updateOrderedButton($(this).closest('tr'));
  });
  $(function(){
    $('.eject-table tbody tr').each(function(){ updateOrderedButton($(this)); });
  });

  // Remove selected items from a PO
  bindTap('.eject-prune-po', function(e){
    e.preventDefault();
    var $btn=$(this), $row=$btn.closest('tr');
    var po=$btn.data('po');
    if(!po) return;
    var selected=[];
    $row.find('.eject-size-checkbox').each(function(){
      if($(this).prop('checked')){
        selected.push({
          code: $(this).data('code')||'',
          color: $(this).data('color')||'',
          size: $(this).data('size')||''
        });
      }
    });
    if(selected.length===0){
      alert('No items selected to remove.');
      return;
    }
    confirmAction('Remove '+selected.length+' selected item(s) from this PO and return their orders to On Hold?', function(){
      $btn.prop('disabled',true).text('Removing...');
      post('eject_prune_po',{po_id:po, items: selected}).done(function(resp){
        if(resp && resp.success){
          location.reload();
        }else{
          alert(resp && resp.data && resp.data.message ? resp.data.message : 'Failed to remove.');
        }
      }).fail(function(){ alert('Failed to remove.'); })
        .always(function(){ $btn.prop('disabled',false).text('Remove selected'); });
    });
  });

  // Force correct label in case of cached markup
  $(function(){
    $('.eject-prune-po').text('Remove selected');
    // Copy debug block
    $(document).on('click', '.eject-copy-debug', function(){
      var txt = $('#eject-debug-block').text() || '';
      if(!txt){ alert('No debug text to copy'); return; }
      if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(txt).then(function(){ alert('Debug copied'); }).catch(function(){
          if(window.prompt){ window.prompt('Copy debug data:', txt); }
        });
      } else {
        if(window.prompt){ window.prompt('Copy debug data:', txt); }
      }
    });
    // Emergency cleanup
    $(document).on('click', '.eject-emergency-cleanup', function(){
      var $btn = $(this);
      if($btn.prop('disabled')) return;
      confirmAction('Emergency cleanup will delete duplicate/empty POs and reset their orders back to On Hold. Continue?', function(){
        $btn.prop('disabled', true).text('Cleaning...');
        post('eject_emergency_cleanup', {}).done(function(resp){
          if(resp && resp.success){
            alert(resp.data && resp.data.message ? resp.data.message : 'Cleanup complete.');
            location.reload();
          } else {
            alert(resp && resp.data && resp.data.message ? resp.data.message : 'Cleanup failed.');
          }
        }).fail(function(){
          alert('Cleanup failed.');
        }).always(function(){
          $btn.prop('disabled', false).text('Emergency cleanup');
        });
      });
    });
  });

  // ----- Ordered table accordion, search, pagination -----
  $(function(){
    var $table = $('.eject-ordered-table');
    if(!$table.length) return;

    var PAGE_SIZE = 10;
    var $rows = $table.find('tbody tr.eject-ordered-summary');

    function pair($summary){
      var id = $summary.data('po');
      return {
        summary: $summary,
        detail: $table.find('tr.eject-ordered-detail[data-po="'+id+'"]')
      };
    }

    function hideAllDetails(){
      $table.find('.eject-ordered-detail').hide();
      $table.find('.eject-accordion-toggle').attr('aria-expanded', 'false').text('Details');
    }

    function toggleRow(id){
      var $summary = $table.find('tr.eject-ordered-summary[data-po="'+id+'"]');
      var $detail = $table.find('tr.eject-ordered-detail[data-po="'+id+'"]');
      if(!$summary.length || !$detail.length) return;
      var expanded = $detail.is(':visible');
      hideAllDetails();
      if(!expanded){
        $detail.show();
        $summary.find('.eject-accordion-toggle').attr('aria-expanded','true').text('Hide');
      }
    }

    function applySearch(){
      var q = ($('#eject-ordered-search').val() || '').trim().toLowerCase();
      var visible = [];
      $rows.each(function(){
        var $s = $(this);
        var blob = ($s.data('search') || '').toString().toLowerCase();
        var match = !q || blob.indexOf(q) !== -1;
        var p = pair($s);
        if(match){
          visible.push($s);
          $s.show();
          p.detail.hide(); // keep collapsed until explicitly expanded
        }else{
          $s.hide();
          p.detail.hide();
        }
      });
      buildPagination(visible);
    }

    function buildPagination(visibleSummaries){
      var $pager = $('#eject-ordered-pagination');
      $pager.empty();
      hideAllDetails();
      var total = visibleSummaries.length;
      if(total <= PAGE_SIZE){
        // show all visible rows
        visibleSummaries.forEach(function($s){
          $s.show();
          pair($s).detail.hide();
        });
        return;
      }
      var pages = Math.ceil(total / PAGE_SIZE);
      var current = 1;
      function renderPage(page){
        current = page;
        visibleSummaries.forEach(function($s, idx){
          var inPage = Math.floor(idx / PAGE_SIZE) + 1 === page;
          var p = pair($s);
          $s.toggle(inPage);
          p.detail.hide();
        });
        renderControls();
      }
      function renderControls(){
        $pager.empty();
        for(var i=1;i<=pages;i++){
          var $b=$('<button type="button" class="button button-small eject-page-btn"></button>');
          $b.text(i);
          if(i===current) $b.addClass('button-primary');
          (function(page){ $b.on('click', function(){ renderPage(page); }); })(i);
          $pager.append($b);
        }
      }
      renderPage(1);
    }

    // Init
    hideAllDetails();
    applySearch();

    $(document).on('click', '.eject-accordion-toggle', function(){
      var id = $(this).closest('tr').data('po');
      toggleRow(id);
    });

    $('#eject-ordered-search').on('input', function(){
      hideAllDetails();
      applySearch();
    });
  });

  var $orderPopover = null;
  var orderPopoverHandler = null;
  function closeOrderPopover(){
    if($orderPopover){
      $orderPopover.remove();
      $orderPopover = null;
    }
    if(orderPopoverHandler){
      $(document).off('mousedown', orderPopoverHandler);
      orderPopoverHandler = null;
    }
  }
  function showOrderPopover($link, orderLinks, orderIds, orderLabels){
    closeOrderPopover();
    if(!orderLinks || !orderLinks.length){
      return;
    }
    var popover = $('<div class="eject-order-popover"></div>');
    orderLinks.forEach(function(url, idx){
      if(!url) return;
      var identifier = orderLabels && orderLabels[idx]
        ? orderLabels[idx]
        : (orderIds && orderIds[idx] ? '#'+orderIds[idx] : '');
      var label = identifier ? identifier : 'Order '+(idx+1);
      var $item = $('<a class="eject-order-popover__item" target="_blank" rel="noopener noreferrer"></a>');
      $item.attr('href', url).text(label);
      $item.on('click', function(){
        closeOrderPopover();
      });
      popover.append($item);
    });
    var $close = $('<span class="eject-order-popover__close">Close</span>');
    $close.on('click', closeOrderPopover);
    popover.append($close);
    $('body').append(popover);
    var rect = $link[0].getBoundingClientRect();
    var top = rect.bottom + window.scrollY + 6;
    var left = rect.left + window.scrollX;
    popover.css({ top: top + 'px', left: left + 'px' });
    orderPopoverHandler = function(event){
      if(!$orderPopover) return;
      if($(event.target).closest('.eject-order-popover').length) return;
      closeOrderPopover();
    };
    $(document).on('mousedown', orderPopoverHandler);
    $orderPopover = popover;
  }
  bindTap('.eject-size-link', function(e){
    e.preventDefault();
    e.stopPropagation();
    var $link = $(this);
    var orderLinks = $link.data('orderLinks');
    if(!$.isArray(orderLinks)){
      var raw = $link.attr('data-order-links');
      if(raw){
        try {
          orderLinks = JSON.parse(raw);
        } catch (err) {
          orderLinks = [];
        }
      }
    }
    var orderIds = $link.data('orderIds');
    if(!$.isArray(orderIds)){
      var idsRaw = $link.attr('data-order-ids');
      if(idsRaw){
        try {
          orderIds = JSON.parse(idsRaw);
        } catch (err) {
          orderIds = [];
        }
      }
    }
    var orderNumbers = $link.data('orderNumbers');
    if(!$.isArray(orderNumbers)){
      var numbersRaw = $link.attr('data-order-numbers');
      if(numbersRaw){
        try {
          orderNumbers = JSON.parse(numbersRaw);
        } catch (err) {
          orderNumbers = [];
        }
      }
    }
    var orderLabels = $link.data('orderLabels');
    if(!$.isArray(orderLabels)){
      var labelsRaw = $link.attr('data-order-labels');
      if(labelsRaw){
        try {
          orderLabels = JSON.parse(labelsRaw);
        } catch (err) {
          orderLabels = [];
        }
      } else {
        orderLabels = [];
      }
    }
    if(!$.isArray(orderLinks) || !orderLinks.length){
      var href = $link.attr('href');
      if(href){
        window.open(href, '_blank');
      }
      return;
    }
    showOrderPopover($link, orderLinks, orderIds, orderLabels);
  });

})(jQuery);
