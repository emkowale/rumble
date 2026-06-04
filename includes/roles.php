<?php
if (!defined('ABSPATH')) exit;

function rumble_register_roles(){
  add_role('employee', 'Employee', [
    'read' => true,
    'rumble_access' => true,
  ]);

  $employee = get_role('employee');
  if ($employee) {
    $employee->add_cap('read');
    $employee->add_cap('rumble_access');
  }

  $admin = get_role('administrator');
  if ($admin) $admin->remove_cap('rumble_access');
}

function rumble_maybe_grant_eric_employee_access(){
  if (!is_user_logged_in() || !current_user_can('manage_options')) return;
  $user = wp_get_current_user();
  if (!$user || !$user->exists() || user_can($user, 'rumble_access')) return;

  $login = strtolower((string) $user->user_login);
  $display = strtolower((string) $user->display_name);
  $email_name = strtolower((string) strtok((string) $user->user_email, '@'));

  if (strpos($login, 'eric') !== false || strpos($display, 'eric') !== false || strpos($email_name, 'eric') !== false) {
    $user->add_cap('rumble_access');
    update_user_meta($user->ID, '_rumble_employee', '1');
  }
}
add_action('init', 'rumble_maybe_grant_eric_employee_access', 20);

function rumble_render_employee_user_field($user){
  if (!current_user_can('edit_user', $user->ID)) return;
  $roles = (array) $user->roles;
  $is_employee_role = in_array('employee', $roles, true);
  $has_employee_cap = user_can($user, 'rumble_access') || get_user_meta($user->ID, '_rumble_employee', true) === '1';
  ?>
  <h2>Rumble</h2>
  <table class="form-table" role="presentation">
    <tr>
      <th><label for="rumble_employee">Rumble Employee</label></th>
      <td>
        <label>
          <input type="checkbox" name="rumble_employee" id="rumble_employee" value="1" <?php checked($has_employee_cap); ?> <?php disabled($is_employee_role); ?>>
          Allow this user to log in to Rumble as an employee.
        </label>
        <?php if ($is_employee_role): ?>
          <p class="description">This user already has the Employee role, so Rumble employee access is included.</p>
        <?php else: ?>
          <p class="description">For Reporting, the user must also be an administrator.</p>
        <?php endif; ?>
      </td>
    </tr>
  </table>
  <?php
}
add_action('show_user_profile', 'rumble_render_employee_user_field');
add_action('edit_user_profile', 'rumble_render_employee_user_field');

function rumble_save_employee_user_field($user_id){
  if (!current_user_can('edit_user', $user_id)) return;
  $user = get_user_by('id', $user_id);
  if (!$user) return;
  if (in_array('employee', (array) $user->roles, true)) return;

  if (!empty($_POST['rumble_employee'])) {
    $user->add_cap('rumble_access');
    update_user_meta($user_id, '_rumble_employee', '1');
  } else {
    $user->remove_cap('rumble_access');
    delete_user_meta($user_id, '_rumble_employee');
  }
}
add_action('personal_options_update', 'rumble_save_employee_user_field');
add_action('edit_user_profile_update', 'rumble_save_employee_user_field');
