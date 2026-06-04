<?php
if (!defined('ABSPATH')) exit;

function rumble_render_form_markup($context='admin'){
  $logo_url = rumble_logo_url();
  $has_bb = rumble_bumblebee_available();
  ?>
  <div class="wrap rumble-wrap <?php echo $context==='front'?'rumble-shell':''; ?>">
    <div class="rumble-header">
      <h1>Rumble</h1>
      <?php if ($logo_url): ?>
        <img class="rumble-logo" src="<?php echo esc_url($logo_url); ?>" alt="The Bear Traxs">
      <?php endif; ?>
    </div>
    <form id="rumble-form">
      <section class="rumble-grid">
        <div><h2>Customer</h2>
          <label>First Name<input name="first_name" required></label>
          <label>Last Name<input name="last_name" required></label>
          <label>Phone<input name="phone" required></label>
          <label>Email<input type="email" name="email" required></label>
          <label>Company<input name="company"></label>
        </div>
        <div><h2>Billing</h2>
          <label>Address 1<input name="bill1" required></label>
          <label>Address 2<input name="bill2"></label>
          <label>City<input name="bill_city" required></label>
          <label>State<input name="bill_state" required></label>
          <label>Zip<input name="bill_zip" required></label>
        </div>
        <div class="rumble-shipping"><h2>Shipping</h2>
          <label><input type="checkbox" id="ship_same" checked> Same as billing</label>
          <div class="rumble-ship-fields">
            <label>Address 1<input name="ship1"></label>
            <label>Address 2<input name="ship2"></label>
            <label>City<input name="ship_city"></label>
            <label>State<input name="ship_state"></label>
            <label>Zip<input name="ship_zip"></label>
          </div>
        </div>
      </section>
      <h2>Line Items</h2>
      <?php if ($has_bb): ?>
        <div class="rumble-bb-box"><?php rumble_render_bumblebee_form(); ?></div>
        <div class="rumble-bb-note">Use Bumblebee above, then add items to this quote.</div>
      <?php endif; ?>
      <div id="rumble-items"></div>
      <?php if (!$has_bb): ?>
        <button type="button" class="button" id="rumble-add">Add line item</button>
      <?php endif; ?>
      <p><button class="button button-primary" id="rumble-submit">Save &amp; Send Quote</button><span class="spinner"></span></p>
    </form>
    <template id="rumble-item-template">
      <div class="rumble-item">
        <button type="button" class="rumble-remove">Remove</button>
        <label>Title<input name="title" required></label>
        <label>Price (USD)<input type="number" name="price" min="0" step="0.01" required></label>
        <label>Taxable<select name="taxable"><option value="taxable">Yes</option><option value="none">No</option></select></label>
        <label>Colors (comma list)<input name="colors"></label>
        <label>Sizes (comma list)<input name="sizes" required></label>
        <label>Quantities (Size:Qty comma list)<input name="quantities" placeholder="S:10,M:12"></label>
        <label>Vendor Codes (one per line)<textarea name="vendor_codes" rows="2"></textarea></label>
        <label>Production<select name="production"><option>Screen Print</option><option>DF</option><option>Embroidery</option></select></label>
        <label>Print Locations<input name="locations" placeholder="Front,Back"></label>
        <label>Special Instructions<textarea name="notes" rows="2"></textarea></label>
      </div>
    </template>
  </div>
  <?php
}

function rumble_render_admin(){ rumble_render_form_markup('admin'); }
