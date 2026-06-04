/* global rumbleData, jQuery */
jQuery(function($){
  const form=$('#rumble-form'), itemsWrap=$('#rumble-items');
  if(!rumbleData.bumblebee) addItem();
  toggleShipFields();
  $('#rumble-add').on('click', addItem);
  itemsWrap.on('click','.rumble-remove',function(){ $(this).closest('.rumble-item').remove(); });
  $('#ship_same').on('change', ()=>{ syncShip(); toggleShipFields(); });
  form.on('input','input[name^=\"bill_\"]', syncShip);
  form.on('submit', function(e){
    e.preventDefault(); $('.spinner').addClass('is-active');
    const payload=collectData();
    $.post(rumbleData.ajax,{action:'rumble_save_quote',nonce:rumbleData.nonce,payload:JSON.stringify(payload)})
      .done(res=>{ alert(res.message||'Quote sent.'); if(res.edit_url) location.href=res.edit_url; })
      .fail(err=>{ alert(err.responseJSON?.message||'Error saving quote.'); })
      .always(()=>$('.spinner').removeClass('is-active'));
  });
  function addItem(){ itemsWrap.append($('#rumble-item-template').html()); }
  function syncShip(){
    const same=$('#ship_same').is(':checked');
    ['1','2','city','state','zip'].forEach(k=>{
      const bill=form.find(`[name=\"bill_${k}\"]`).val();
      const ship=form.find(`[name=\"ship_${k}\"]`);
      if(same) ship.val(bill).prop('readonly',true); else ship.prop('readonly',false);
    });
  }
  function toggleShipFields(){
    const same=$('#ship_same').is(':checked'), box=$('.rumble-shipping .rumble-ship-fields');
    if(same) box.hide().attr('aria-hidden','true'); else box.show().attr('aria-hidden','false');
  }
  function collectData(){
    const data={customer:{first_name:form.find('[name=\"first_name\"]').val(),last_name:form.find('[name=\"last_name\"]').val(),phone:form.find('[name=\"phone\"]').val(),email:form.find('[name=\"email\"]').val(),company:form.find('[name=\"company\"]').val()},billing:{line1:form.find('[name=\"bill1\"]').val(),line2:form.find('[name=\"bill2\"]').val(),city:form.find('[name=\"bill_city\"]').val(),state:form.find('[name=\"bill_state\"]').val(),zip:form.find('[name=\"bill_zip\"]').val()},shipping:{line1:form.find('[name=\"ship1\"]').val(),line2:form.find('[name=\"ship2\"]').val(),city:form.find('[name=\"ship_city\"]').val(),state:form.find('[name=\"ship_state\"]').val(),zip:form.find('[name=\"ship_zip\"]').val()},items:[]};
    itemsWrap.find('.rumble-item').each(function(){
      const box=$(this);
      if(box.hasClass('rumble-bb')){
        data.items.push({product_id:box.data('product'),title:box.data('title'),price:box.data('price'),taxable:box.data('taxable')?'taxable':'none'});
      } else {
        data.items.push({title:box.find('[name=\"title\"]').val(),price:box.find('[name=\"price\"]').val(),taxable:box.find('[name=\"taxable\"]').val(),colors:box.find('[name=\"colors\"]').val(),sizes:box.find('[name=\"sizes\"]').val(),quantities:box.find('[name=\"quantities\"]').val(),vendor_codes:box.find('[name=\"vendor_codes\"]').val(),production:box.find('[name=\"production\"]').val(),locations:box.find('[name=\"locations\"]').val(),notes:box.find('[name=\"notes\"]').val()});
      }
    });
    return data;
  }
  window.rumbleAddItemFromBumblebee=function(item){
    const row=$('<div class=\"rumble-item rumble-bb\"></div>');
    row.data('product',item.product_id).data('title',item.title).data('price',item.price).data('taxable',item.taxable);
    row.append('<div class=\"rumble-bb-pill\">Bumblebee Product</div>');
    row.append('<div class=\"rumble-bb-line\"><strong>'+item.title+'</strong> — $'+parseFloat(item.price).toFixed(2)+'</div>');
    row.append('<button type=\"button\" class=\"rumble-remove\">Remove</button>');
    itemsWrap.append(row);
  };
});
