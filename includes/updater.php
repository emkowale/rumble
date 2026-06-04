<?php
if (!defined('ABSPATH')) exit;

class Rumble_Updater {
  private $owner = 'emkowale';
  private $repo  = 'rumble';
  private $plugin_slug;    // directory slug, e.g. rumble
  private $plugin_file;    // basename, e.g. rumble/rumble.php
  private $api_latest = 'https://api.github.com/repos/emkowale/rumble/releases/latest';

  public function __construct($plugin_file) {
    $this->plugin_file = plugin_basename($plugin_file);
    $this->plugin_slug = dirname($this->plugin_file);

    add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
    add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
  }

  public function check_for_update($transient) {
    if (!is_object($transient)) return $transient;
    if (empty($transient->checked)) return $transient;
    if (!isset($transient->checked[$this->plugin_file])) return $transient;

    $current_version = $transient->checked[$this->plugin_file];
    $release = $this->get_latest_release();
    if (!$release || empty($release['version']) || empty($release['download_url'])) return $transient;

    if (version_compare($release['version'], $current_version, '<=')) return $transient;

    $update = new stdClass();
    $update->slug = $this->plugin_slug;
    $update->plugin = $this->plugin_file;
    $update->new_version = $release['version'];
    $update->url = sprintf('https://github.com/%s/%s', $this->owner, $this->repo);
    $update->package = $release['download_url'];
    $update->tested = $release['tested'] ?? '';
    $update->requires = $release['requires'] ?? '';

    $transient->response[$this->plugin_file] = $update;
    return $transient;
  }

  public function plugin_info($result, $action, $args) {
    if ($action !== 'plugin_information') return $result;
    $slug = $args->slug ?? '';
    if ($slug !== $this->plugin_slug && $slug !== $this->plugin_file) return $result;

    $release = $this->get_latest_release();
    if (!$release) return $result;

    $info = new stdClass();
    $info->name = 'Rumble';
    $info->slug = $this->plugin_slug;
    $info->plugin = $this->plugin_file;
    $info->version = $release['version'];
    $info->author = '<a href="https://github.com/emkowale">emkowale</a>';
    $info->homepage = sprintf('https://github.com/%s/%s', $this->owner, $this->repo);
    $info->download_link = $release['download_url'];
    $info->requires = $release['requires'] ?? '';
    $info->tested = $release['tested'] ?? '';
    $info->last_updated = $release['last_updated'] ?? '';
    $info->sections = [
      'description' => wpautop(esc_html('Kiosk quoting for Bear Traxs with quote accept + product creation.')),
      'changelog' => !empty($release['body'])
        ? wpautop(esc_html($release['body']))
        : wpautop('See the latest changes on GitHub.'),
    ];

    return $info;
  }

  private function get_latest_release() {
    $cached = get_site_transient('rumble_latest_release');
    if ($cached !== false) return $cached;

    $response = wp_remote_get($this->api_latest, [
      'headers' => [
        'Accept' => 'application/vnd.github+json',
        'User-Agent' => 'rumble-wp-updater',
      ],
      'timeout' => 15,
    ]);

    if (is_wp_error($response)) return false;
    $code = wp_remote_retrieve_response_code($response);
    if ($code !== 200) return false;

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body) || empty($body['tag_name'])) return false;

    $version = ltrim($body['tag_name'], 'v');
    $download = $this->resolve_asset_url($body);

    $release = [
      'version' => $version,
      'download_url' => $download,
      'body' => $body['body'] ?? '',
      'last_updated' => $body['published_at'] ?? '',
      'requires' => '',
      'tested' => '',
    ];

    set_site_transient('rumble_latest_release', $release, 6 * HOUR_IN_SECONDS);
    return $release;
  }

  private function resolve_asset_url($release_body) {
    if (!empty($release_body['assets']) && is_array($release_body['assets'])) {
      foreach ($release_body['assets'] as $asset) {
        if (empty($asset['browser_download_url']) || empty($asset['name'])) continue;
        if (preg_match('/rumble.*\\.zip$/', $asset['name'])) {
          return $asset['browser_download_url'];
        }
      }
    }

    $tag = $release_body['tag_name'] ?? '';
    if (!$tag) return '';

    $fallback = sprintf(
      'https://github.com/%s/%s/archive/refs/tags/%s.zip',
      $this->owner,
      $this->repo,
      $tag
    );

    return $fallback;
  }
}

new Rumble_Updater(RUMBLE_SLUG);
