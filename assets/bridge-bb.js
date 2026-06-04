/* global BumblebeeCreate, rumbleData, jQuery */
(function($){
  if (!window.BumblebeeCreate || !rumbleData || !rumbleData.bumblebee) return;

  $(function(){
    const btn = $('#bb_create_btn');
    if (!btn.length) return;
    btn.off('click').text('Add to Quote').on('click', function(e){
      e.preventDefault();
      submitBb();
    });
  });

  function submitBb(){
    const btn = $('#bb_create_btn'); const spin = btn.parent().find('.spinner');
    btn.prop('disabled', true); spin.addClass('is-active');
    const fd = new FormData();
    fd.append('action','rumble_bumblebee_create');
    fd.append('nonce', BumblebeeCreate.nonce || '');
    fd.append('title', $('#bb_title').val() || '');
    fd.append('price', $('#bb_price').val() || '');
    fd.append('tax_status', $('input[name="bb_taxable"]:checked').val() || 'taxable');
    fd.append('image_id', $('#bb_image_id').val() || '');
    fd.append('color_data', JSON.stringify(readColors()));
    fd.append('sizes', $('#bb_sizes').val() || '');
    fd.append('vendor_data', JSON.stringify(readVendors()));
    fd.append('production', $('#bb_production').val() || '');
    fd.append('print_location', readLocations().join(', '));
    fd.append('image_url', ($('#bb_image_preview img').attr('src') || '').trim());

    fetch(rumbleData.ajax, {method:'POST', body:fd, credentials:'same-origin'})
      .then(r => r.json())
      .then(d => {
        btn.prop('disabled', false); spin.removeClass('is-active');
        if(d && d.success && d.data){ window.rumbleAddItemFromBumblebee && window.rumbleAddItemFromBumblebee(d.data); }
        else { alert((d && d.data && d.data.message) ? d.data.message : 'Unable to add product.'); }
      })
      .catch(err => { alert('Error: '+err); btn.prop('disabled', false); spin.removeClass('is-active'); });
  }

  function readColors(){
    const count = parseInt($('#bb_color_count').val(),10) || 0;
    const colors=[];
    for(let i=0;i<count;i++){
      colors.push({
        name: ($('#bb_color_name_'+i).val() || '').trim(),
        image_id: parseInt($('#bb_color_image_'+i).val(),10) || 0
      });
    }
    return {selected: $('#bb_color_count').val()!=='' , count, colors};
  }
  function readVendors(){
    const count = parseInt($('#bb_vendor_count').val(),10) || 0;
    const vendors=[];
    for(let i=0;i<count;i++){
      vendors.push({
        name: ($('#bb_vendor_name_'+i).val() || '').trim(),
        item: ($('#bb_vendor_item_'+i).val() || '').trim()
      });
    }
    return {selected: $('#bb_vendor_count').val()!=='', count, vendors};
  }
  function readLocations(){
    const locs=[];
    $('#bb-print-locations .bb-location-checkbox:checked').each(function(){
      const name=$(this).data('name')||$(this).attr('id')||'';
      if(name) locs.push(name);
    });
    return locs;
  }
})(jQuery);
