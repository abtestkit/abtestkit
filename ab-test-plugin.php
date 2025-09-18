<?php
/**
 * Plugin Name:       abtestkit
 * Plugin URI:        https://placeholder.com/
 * Description:       Simple A/B testing for Core Editor (Gutenberg) blocks
 * Version:           1.0.0
 * Author:            abtestkit
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


//ini_set('log_errors', 1);
//ini_set('error_log', __DIR__ . '/my-abtest-debug.log');

// ─────────────────────────────────────────────────────────────────────────────
// Telemetry (anonymous, opt-in, one-shot milestones)
// ─────────────────────────────────────────────────────────────────────────────
if ( ! defined( 'ABTEST_TELEMETRY_ENDPOINT' ) ) {
    // TODO: change to your collector endpoint
    define( 'ABTEST_TELEMETRY_ENDPOINT', 'https://script.google.com/macros/s/AKfycbxA2iLDaOF1o-_yBQ2DJ_KAZz5abd91PHs1Lic3NDpN-R8vtD0Svup7bdG67W2zpwIvLg/exec?key=1skod3kdn3k4nj491kjn2m' );
}

// ─────────────────────────────────────────────────────────────
// Email capture (post-first-launch)
// ─────────────────────────────────────────────────────────────
if ( ! defined( 'ABTEST_EMAIL_CAPTURE_ENABLED' ) ) {
    define( 'ABTEST_EMAIL_CAPTURE_ENABLED', true ); // set to false to kill globally
}

// Re-use telemetry endpoint by default (can be a different Apps Script if you like)
if ( ! defined( 'ABTEST_EMAIL_APPS_SCRIPT' ) ) {
    define( 'ABTEST_EMAIL_APPS_SCRIPT', ABTEST_TELEMETRY_ENDPOINT );
}

define( 'ABTEST_TELEMETRY_OPTIN_OPTION',   'abtest_telemetry_opted_in' );
define( 'ABTEST_TELEMETRY_FLAGS_OPTION',   'abtest_telemetry_flags' );
define( 'ABTEST_TELEMETRY_INSTALL_OPTION', 'abtest_telemetry_installed_at' );

function abtest_is_telemetry_opted_in(): bool {
    return (bool) get_option( ABTEST_TELEMETRY_OPTIN_OPTION, false );
}
function abtest_set_telemetry_optin( bool $yes ) {
    update_option( ABTEST_TELEMETRY_OPTIN_OPTION, $yes );
    if ( $yes ) {
        $flags = get_option( ABTEST_TELEMETRY_FLAGS_OPTION, [] );
        if ( empty( $flags['installed_sent'] ) ) {
            abtest_send_telemetry( 'plugin_installed', [
                'installed_at' => (int) get_option( ABTEST_TELEMETRY_INSTALL_OPTION, time() ),
            ] );
            $flags['installed_sent'] = true;
            update_option( ABTEST_TELEMETRY_FLAGS_OPTION, $flags );
        }
    }
}
function abtest_get_flags(): array {
    $f = get_option( ABTEST_TELEMETRY_FLAGS_OPTION, [] );
    return is_array( $f ) ? $f : [];
}
function abtest_mark_flag( string $key ) {
    $f = abtest_get_flags();
    $f[ $key ] = true;
    update_option( ABTEST_TELEMETRY_FLAGS_OPTION, $f );
}
function abtest_flag_is_set( string $key ): bool {
    $f = abtest_get_flags();
    return ! empty( $f[ $key ] );
}
function abtest_build_telemetry_base(): array {
    return [
        'plugin'   => 'ab-test-gutenberg',
        'version'  => '1.0.0', // keep in sync with header
        'site'     => md5( home_url() ), // anonymous hash
        'wp'       => get_bloginfo( 'version' ),
        'php'      => PHP_VERSION,
        'env'      => ( wp_get_environment_type() ?: 'production' ),
    ];
}
function abtest_send_telemetry( string $event, array $data = [] ) {
    if ( ! abtest_is_telemetry_opted_in() ) return;

    $payload = array_merge( abtest_build_telemetry_base(), [
        'event' => $event,
        'ts'    => time(),
        'data'  => $data,
    ] );

    wp_remote_post( ABTEST_TELEMETRY_ENDPOINT, [
        'timeout'   => 2,
        'blocking'  => false,
        'headers'   => [ 'Content-Type' => 'application/json' ],
        'body'      => wp_json_encode( $payload ),
    ] );
}

/**
 * Load block-config.json into a PHP array for localization.
 */
function abtest_load_block_config() {
    // Make sure block-config.json lives under assets/js/, next to editor.js & ab-sidebar.js
    $config_path = plugin_dir_path( __FILE__ ) . 'assets/js/block-config.json';
    if ( ! file_exists( $config_path ) ) {
        return [];
    }
    $json = file_get_contents( $config_path );
    $arr  = json_decode( $json, true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return [];
    }
    return $arr;
}

function abtest_make_sig( int $post_id, int $ts ): string {
    return hash_hmac('sha256', $post_id . '|' . $ts, wp_salt('auth'));
}

function abtest_sanitize_test_id( $id ): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $id);
}

function abtest_rest_check_nonce(WP_REST_Request $request) {
    $nonce = $request->get_header('X-WP-Nonce');
    if (!$nonce) return false;
    return wp_verify_nonce($nonce, 'wp_rest') === 1;
}

function abtest_rest_permission(WP_REST_Request $request) {
    return abtest_rest_check_nonce($request) && current_user_can('edit_posts');
}

/**
 * Register REST endpoints: /track, /stats, /evaluate, /reset.
 */
add_action('rest_api_init', function() {
    // Tracking endpoint (anonymous allowed; security handled inside handler)
    register_rest_route('ab-test/v1', '/track', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'abtest_handle_track',
    ]);

    // Secure endpoints (require nonce+capability)
    foreach (['stats', 'evaluate', 'reset'] as $route) {
        register_rest_route('ab-test/v1', "/$route", [
            'methods'             => $route === 'reset' ? 'POST' : 'GET',
            'permission_callback' => 'abtest_rest_permission',
            'callback'            => "abtest_handle_$route",
        ]);
    }

    // 🔔 Editor-only telemetry milestones (one-shot, gated by flags)
    register_rest_route('ab-test/v1', '/telemetry', [
    'methods'             => 'POST',
    'permission_callback' => 'abtest_rest_permission',
    'callback'            => function( WP_REST_Request $req ) {
        $event   = sanitize_key( $req->get_param('event') );
        $payload = (array) $req->get_param('payload');

        switch ( $event ) {
            case 'first_toggle_enabled':
                if ( ! abtest_flag_is_set('first_toggle_enabled') ) {
                    abtest_send_telemetry('first_toggle_enabled', $payload);
                    abtest_mark_flag('first_toggle_enabled');
                }
                break;

            case 'first_test_launched':
                if ( ! abtest_flag_is_set('first_test_launched') ) {
                    abtest_send_telemetry('first_test_launched', $payload);
                    abtest_mark_flag('first_test_launched');
                }
                break;

            case 'first_test_finished':
                if ( ! abtest_flag_is_set('first_test_finished') ) {
                    abtest_send_telemetry('first_test_finished', $payload);
                    abtest_mark_flag('first_test_finished');
                }
                break;

            // NEW: fire every time a winner is applied (no one-shot gating)
            case 'winner_applied':
                abtest_send_telemetry('winner_applied', $payload);
                break;

            default:
                return rest_ensure_response([ 'ok' => false, 'error' => 'unknown_event' ]);
        }
        return rest_ensure_response([ 'ok' => true ]);
    },
]);
});

function abtest_log_event_to_db($type, $post_id, $ab_test_id, $variant) {
  global $wpdb;

  $table = $wpdb->prefix . 'ab_test_events';

  // keep IDs tight and types sane
  $ab_test_id = abtest_sanitize_test_id($ab_test_id);
  $variant    = ($variant === 'A' || $variant === 'B') ? $variant : '';
  $allowed    = ['impression','click','decision','decision_applied','stale'];
  $event_type = in_array($type, $allowed, true) ? $type : 'impression';

  // light IP/UA hygiene
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  if (!filter_var($ip, FILTER_VALIDATE_IP)) $ip = '';

  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
  $ua = wp_strip_all_tags($ua);
  if (strlen($ua) > 255) $ua = substr($ua, 0, 255);

  $now = current_time('mysql');

  $wpdb->insert($table, [
    'time'       => $now,
    'post_id'    => absint($post_id),
    'ab_test_id' => $ab_test_id,
    'variant'    => $variant,
    'event_type' => $event_type,
    'ip'         => $ip,
    'user_agent' => $ua,
  ]);
}

// Save A/B test variants into post meta on save
add_action('save_post', function ($post_id) {
    if (wp_is_post_revision($post_id)) return;

    $content = get_post_field('post_content', $post_id);
    $blocks = parse_blocks($content);
    $variants = [];

    $extract_variants = function($blocks) use (&$extract_variants, &$variants) {
        foreach ($blocks as $block) {
            if (!is_array($block) || !isset($block['blockName'])) continue;

            $attrs = $block['attrs'] ?? [];
            $abTestId = $attrs['abTestId'] ?? null;
            $abTestVariants = $attrs['abTestVariants'] ?? null;

            if ($abTestId && $abTestVariants && isset($abTestVariants[$abTestId])) {
                $variants[$abTestId] = $abTestVariants[$abTestId];
                $locked = $abTestVariants[$abTestId]['locked'] ?? true;
                error_log("🧪 $abTestId locked=" . var_export($locked, true));
                $variants[$abTestId]['running'] = !$locked;
            }

            // Recursively scan innerBlocks if needed
            if (!empty($block['innerBlocks'])) {
                $extract_variants($block['innerBlocks']);
            }
        }
    };

    $extract_variants($blocks);
    update_post_meta($post_id, '_ab_test_variants', $variants);
});

// Frontend-only injection of data-ab-test-id
add_filter('render_block', function ($block_content, $block) {
    if (is_admin()) return $block_content; // don't touch editor

    $name = $block['blockName'] ?? '';
    $supported = ['core/button','core/heading','core/paragraph','core/image'];
    if (!in_array($name, $supported, true)) return $block_content;
    if (!$block_content) return $block_content;

    // Use existing abTestId if present; otherwise make a stable one
    $ab_id = $block['attrs']['abTestId'] ?? '';
    if (!$ab_id) {
        $post_id = get_the_ID() ?: 0;
        $seed = $post_id . '|' . $name . '|' . wp_json_encode($block['attrs'] ?? []);
        $ab_id = 'ab-' . substr(md5($seed), 0, 9);
    }

    // Inject attributes with DOMDocument
    $html = '<div id="__abtest_wrap__">'.$block_content.'</div>';
    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $wrap = $dom->getElementById('__abtest_wrap__');
    if (!$wrap || !$wrap->firstChild) return $block_content;

    // Add data-ab-test-id on outermost element
    $root = $wrap->firstChild;
    if ($root instanceof DOMElement && !$root->hasAttribute('data-ab-test-id')) {
        $root->setAttribute('data-ab-test-id', $ab_id);
    }

        // 🔗 Group Sync: if this block is configured to sync, tag the DOM with data-ab-group
    $attrs = $block['attrs'] ?? [];
    if ( ! empty( $attrs['abSync'] ) && ! empty( $attrs['abGroupKey'] ) ) {
        $group = sanitize_key( (string) $attrs['abGroupKey'] );
        if ( $group && $root instanceof DOMElement && ! $root->hasAttribute('data-ab-group') ) {
            $root->setAttribute('data-ab-group', $group);
        }
    }

    // For buttons, also add it on <a.wp-block-button__link>
    if ($name === 'core/button') {
        $xpath = new DOMXPath($dom);
        $aList = $xpath->query('//*[@class and contains(concat(" ", normalize-space(@class), " "), " wp-block-button__link ")]');
        foreach ($aList as $a) {
            if ($a instanceof DOMElement && !$a->hasAttribute('data-ab-test-id')) {
                $a->setAttribute('data-ab-test-id', $ab_id);
            }
        }
    }

    // Return inner HTML
    $out = '';
    foreach ($wrap->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }
    return $out;
}, 10, 2);

/**
 * Dynamically extend ACF blocks to include abTestId and abTestVariants attributes.
 */
add_filter('register_block_type_args', function($args, $block_name) {
    if ($block_name === 'acf/bv-panel') {
        $args['attributes']['abTestId'] = [
            'type' => 'string',
            'default' => '',
        ];
        $args['attributes']['abTestVariants'] = [
            'type' => 'object',
            'default' => [],
        ];
        $args['attributes']['abTestEnabled'] = [
            'type' => 'boolean',
            'default' => false,
        ];
        $args['attributes']['abTestRunning'] = [
            'type' => 'boolean',
            'default' => false,
        ];
        $args['attributes']['abTestWinner'] = [
            'type' => 'string',
            'default' => '',
        ];
    }
    return $args;
}, 10, 2);


// 3️⃣ Beta + Gamma samplers
function sample_beta($alpha, $beta) {
  $x = sample_gamma($alpha, 1.0);
  $y = sample_gamma($beta,  1.0);
  return $x / ($x + $y);
}

function sample_gamma($shape, $scale) {
  if ($shape >= 1) {
    $d = $shape - 1/3;
    $c = 1 / sqrt(9 * $d);
    while (true) {
      do {
        $u = mt_rand()/mt_getrandmax();
        $v = 1 + $c * normal_rand();
      } while ($v <= 0);
      $v = $v * $v * $v;
      $u2 = mt_rand()/mt_getrandmax();
      if ($u2 < 1 - 0.0331 * pow(normal_rand(), 4)) break;
      if (log($u2) < 0.5 * pow(normal_rand(), 2) + $d * (1 - $v + log($v))) break;
    }
    return $d * $v * $scale;
  }
  return sample_gamma($shape + 1, $scale) * pow(mt_rand()/mt_getrandmax(), 1/$shape);
}

function normal_rand() {
  static $useLast = false, $y2;
  if ($useLast) {
    $useLast = false;
    return $y2;
  }
  $u1 = mt_rand()/mt_getrandmax();
  $u2 = mt_rand()/mt_getrandmax();
  $x1 = sqrt(-2*log($u1)) * cos(2*M_PI*$u2);
  $y2 = sqrt(-2*log($u1)) * sin(2*M_PI*$u2);
  $useLast = true;
  return $x1;
}

/**
 * ─────────────────────────────────────────────────────────────────────────────
 * PHP-side variant rendering for core/image, core/heading, core/paragraph, core/button
 * Pattern: wrapper [data-ab-test-id], 2 children [data-ab-variant="A|B"] hidden by default
 * ─────────────────────────────────────────────────────────────────────────────
 */
function abtest_can_render_variants(array $block): bool {
    $attrs = $block['attrs'] ?? [];
    if (empty($attrs['abTestEnabled']) || empty($attrs['abTestId'])) return false;
    $id = $attrs['abTestId'];
    $all = $attrs['abTestVariants'][$id] ?? [];
    return is_array($all) && isset($all['A']) && isset($all['B']);
}

function abtest_pick($arr, $keys, $fallback = '') {
    foreach ((array)$keys as $k) {
        if (isset($arr[$k]) && $arr[$k] !== '') return $arr[$k];
    }
    return $fallback;
}

function abtest_clean_id($id) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$id);
}

/**
 * core/image → outputs <figure data-ab-test-id> with two <img data-ab-variant>
 */
add_filter('render_block_core/image', function($html, $block){
    if (!abtest_can_render_variants($block)) return $html;

    $attrs   = $block['attrs'];
    $id      = abtest_clean_id($attrs['abTestId']);
    $varArr  = $attrs['abTestVariants'][$id];

    $A = $varArr['A'] ?? [];
    $B = $varArr['B'] ?? [];

    // Try common keys; fall back to existing markup’s <img src>
    $existing_src = '';
    if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
        $existing_src = $m[1];
    }

    $srcA = abtest_pick($A, ['url','src','image','img'], $existing_src);
    $srcB = abtest_pick($B, ['url','src','image','img'], $existing_src);

    // Alt text if present in variants, otherwise try to scrape from existing
    $altExisting = '';
    if (preg_match('/<img[^>]+alt=["\']([^"\']*)["\']/i', $html, $m)) {
        $altExisting = $m[1];
    }
    $altA = abtest_pick($A, ['alt','alt_text'], $altExisting);
    $altB = abtest_pick($B, ['alt','alt_text'], $altExisting);

    // Preserve figcaption if present
    $caption = '';
    if (preg_match('/<figcaption[^>]*>.*?<\/figcaption>/is', $html, $m)) {
        $caption = $m[0];
    }

    $groupAttr = '';
    $attrsAll  = $block['attrs'] ?? [];
    if ( ! empty( $attrsAll['abSync'] ) && ! empty( $attrsAll['abGroupKey'] ) ) {
        $groupAttr = ' data-ab-group="' . esc_attr( sanitize_key( (string) $attrsAll['abGroupKey'] ) ) . '"';
    }

    $out  = '<figure class="wp-block-image" data-ab-test-id="' . esc_attr($id) . '"' . $groupAttr . '>';
    $out .= '<img data-ab-variant="A" style="display:none" src="' . esc_url($srcA) . '" alt="' . esc_attr($altA) . '" />';
    $out .= '<img data-ab-variant="B" style="display:none" src="' . esc_url($srcB) . '" alt="' . esc_attr($altB) . '" />';
    $out .= $caption;
    $out .= '</figure>';

    return $out;
}, 10, 2);

/**
 * core/heading → wraps one heading tag containing two spans with variant text
 */
add_filter('render_block_core/heading', function($html, $block){
    if (!abtest_can_render_variants($block)) return $html;

    $attrs   = $block['attrs'];
    $id      = abtest_clean_id($attrs['abTestId']);
    $varArr  = $attrs['abTestVariants'][$id];
    $A = $varArr['A'] ?? [];
    $B = $varArr['B'] ?? [];

    // Determine tag (h1..h6); fall back to h2
    $tag = 'h2';
    if (preg_match('/<(h[1-6])\b/i', $html, $m)) $tag = strtolower($m[1]);

    // Variant text: prefer 'content' then 'text'; fallback to stripped existing
    $existing = trim(strip_tags($html));
    $textA = abtest_pick($A, ['content','text','html'], $existing);
    $textB = abtest_pick($B, ['content','text','html'], $existing);

    $groupAttr = '';
    $attrsAll  = $block['attrs'] ?? [];
    if ( ! empty( $attrsAll['abSync'] ) && ! empty( $attrsAll['abGroupKey'] ) ) {
        $groupAttr = ' data-ab-group="' . esc_attr( sanitize_key( (string) $attrsAll['abGroupKey'] ) ) . '"';
    }
    $out  = '<' . $tag . ' data-ab-test-id="' . esc_attr($id) . '"' . $groupAttr . '>';
    $out .= '<span data-ab-variant="A" style="display:none">' . wp_kses_post($textA) . '</span>';
    $out .= '<span data-ab-variant="B" style="display:none">' . wp_kses_post($textB) . '</span>';
    $out .= '</' . $tag . '>';

    return $out;
}, 10, 2);

/**
 * core/paragraph → renders two spans with variant text inside a <p>
 */
add_filter('render_block_core/paragraph', function($html, $block){
    if (!abtest_can_render_variants($block)) return $html;

    $attrs   = $block['attrs'];
    $id      = abtest_clean_id($attrs['abTestId']);
    $varArr  = $attrs['abTestVariants'][$id];
    $A = $varArr['A'] ?? [];
    $B = $varArr['B'] ?? [];

    $existing = trim(strip_tags($html));
    $textA = abtest_pick($A, ['content','text','html'], $existing);
    $textB = abtest_pick($B, ['content','text','html'], $existing);

    $groupAttr = '';
    $attrsAll  = $block['attrs'] ?? [];
    if ( ! empty( $attrsAll['abSync'] ) && ! empty( $attrsAll['abGroupKey'] ) ) {
     $groupAttr = ' data-ab-group="' . esc_attr( sanitize_key( (string) $attrsAll['abGroupKey'] ) ) . '"';
    }
    $out  = '<p data-ab-test-id="' . esc_attr($id) . '"' . $groupAttr . '>';

    $out .= '<span data-ab-variant="A" style="display:none">' . wp_kses_post($textA) . '</span>';
    $out .= '<span data-ab-variant="B" style="display:none">' . wp_kses_post($textB) . '</span>';
    $out .= '</p>';

    return $out;
}, 10, 2);

/**
 * core/button → your plugin already did this; here’s a hardened version.
 * Keeps original attributes, injects two label spans. (URL can be variant too.)
 */
add_filter('render_block_core/button', function ($html, $block) {
    // Only touch when an AB test is actually running on this button
    if (empty($block['attrs']['abTestEnabled']) || empty($block['attrs']['abTestId'])) {
        return $html;
    }

    $id   = abtest_clean_id($block['attrs']['abTestId']);
    $vars = $block['attrs']['abTestVariants'][$id] ?? [];
    if (empty($vars['A']) || empty($vars['B'])) return $html;

    // Parse the existing <a> so we preserve ALL original attributes/classes (incl. Popup Maker)
    libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $a = $dom->getElementsByTagName('a')->item(0);
    if (!$a) return $html;

    // Add AB markers, but DO NOT touch href/class/data-* from the original block
    $a->setAttribute('data-ab-test-id', $id);
    $a->setAttribute('data-ab-index', '0');

    $attrsAll = $block['attrs'] ?? [];
    if ( ! empty( $attrsAll['abSync'] ) && ! empty( $attrsAll['abGroupKey'] ) ) {
        $group = sanitize_key( (string) $attrsAll['abGroupKey'] );
        if ( $group ) {
            $a->setAttribute('data-ab-group', $group);
        }
    }

    // Current visible label (fallback)
    $existing = trim($a->textContent);

    // Compute variant labels (prefer content/text if provided)
    $labelA = '';
    $labelB = '';
    $labelA = isset($vars['A']['content']) ? $vars['A']['content'] :
              (isset($vars['A']['text']) ? $vars['A']['text'] : $existing);
    $labelB = isset($vars['B']['content']) ? $vars['B']['content'] :
              (isset($vars['B']['text']) ? $vars['B']['text'] : $existing);

    // Optional: attach variant URLs to the span as data-href (frontend.js already reads this)
    $urlA = '';
    foreach (['url','myButtonURL','href'] as $k) { if (!empty($vars['A'][$k])) { $urlA = $vars['A'][$k]; break; } }
    $urlB = '';
    foreach (['url','myButtonURL','href'] as $k) { if (!empty($vars['B'][$k])) { $urlB = $vars['B'][$k]; break; } }

    // Remove plain text nodes (keep icons/elements) before inserting our spans
    for ($i = $a->childNodes->length - 1; $i >= 0; $i--) {
        $n = $a->childNodes->item($i);
        if ($n->nodeType === XML_TEXT_NODE) {
            $a->removeChild($n);
        }
    }

    // Build hidden A & B spans (JS reveals the assigned one)
    $spanA = $dom->createElement('span');
    $spanA->setAttribute('data-ab-variant', 'A');
    $spanA->setAttribute('style', 'display:none');
    $spanA->appendChild($dom->createTextNode($labelA));
    if ($urlA !== '') $spanA->setAttribute('data-href', esc_url_raw($urlA));

    $spanB = $dom->createElement('span');
    $spanB->setAttribute('data-ab-variant', 'B');
    $spanB->setAttribute('style', 'display:none');
    $spanB->appendChild($dom->createTextNode($labelB));
    if ($urlB !== '') $spanB->setAttribute('data-href', esc_url_raw($urlB));

    $a->appendChild($spanA);
    $a->appendChild($spanB);

    // Return just the updated fragment
    return $dom->saveHTML($dom->documentElement);
}, 10, 2);

/**
 * ACF block (bv-panel) wrapper injection (minimal): wrap existing HTML with data-ab-test-id
 * and append two transparent markers if you need them later.
 */
add_filter('render_block_acf/bv-panel', function($html, $block){
    if (!abtest_can_render_variants($block)) return $html;
    $id = abtest_clean_id($block['attrs']['abTestId']);
    // If it already starts with a tag, inject attribute on the first tag.
    if (preg_match('/^<([a-z0-9:-]+)\b/i', $html, $m)) {
        $tag = $m[1];
        return preg_replace(
            '/^<' . preg_quote($tag, '/') . '\b(?![^>]*data-ab-test-id)/i',
            '<' . $tag . ' data-ab-test-id="' . esc_attr($id) . '"',
            $html,
            1
        );
    }
    // Fallback: wrap
    return '<div data-ab-test-id="' . esc_attr($id) . '">' . $html . '</div>';
}, 10, 2);


/**
 * Accept only requests coming from this site (same-origin).
 */
function abtest_is_same_origin_request(): bool {
    $origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $host    = wp_parse_url( home_url(), PHP_URL_HOST );

    if ($origin) {
        $o = wp_parse_url($origin, PHP_URL_HOST);
        if ($o && $o === $host) return true;
    }
    if ($referer) {
        $r = wp_parse_url($referer, PHP_URL_HOST);
        if ($r && $r === $host) return true;
    }
    return false;
}


//Quick existence check: is the ab_test_id present

function abtest_test_id_exists_on_post( int $post_id, string $ab_test_id ): bool {
    $variants = get_post_meta($post_id, '_ab_test_variants', true);
    if (is_array($variants) && isset($variants[$ab_test_id])) return true;

    // Fallback: scan blocks (covers cases before meta is saved)
    $content = get_post_field('post_content', $post_id);
    if (!$content) return false;
    $blocks = parse_blocks($content);
    $found  = false;
    $scan = function($blocks) use (&$scan, &$found, $ab_test_id) {
        foreach ($blocks as $b) {
            if (!is_array($b)) continue;
            $attrs = $b['attrs'] ?? [];
            if (($attrs['abTestId'] ?? '') === $ab_test_id) { $found = true; return; }
            if (!empty($b['innerBlocks'])) $scan($b['innerBlocks']);
        }
    };
    $scan($blocks);
    return $found;
}

function abtest_handle_track( WP_REST_Request $request ) {
    // Parse + sanitize JSON body
    $raw   = $request->get_body();
    $body  = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
    $type  = sanitize_text_field( $body['type'] ?? '' );         // 'impression' | 'click'
    $post_id = absint( $body['postId'] ?? 0 );
    $index   = absint( $body['index'] ?? 0 );                    // (not used yet, still sanitize)
    $variant = $body['variant'] ?? '';
    $ab_id   = abtest_sanitize_test_id( $body['abTestId'] ?? '' );

    // HMAC fallback (accept privacy users who strip Origin/Referer)
    $ts  = isset($body['ts']) ? (int) $body['ts'] : 0;
    $sig = $body['sig'] ?? '';
    $now = time();
    $valid_sig = false;
    if ( $post_id > 0 && $ts > 0 && abs($now - $ts) < 6 * HOUR_IN_SECONDS ) {
        $expected = abtest_make_sig( (int) $post_id, $ts );
        if ( hash_equals( $expected, (string) $sig ) ) {
            $valid_sig = true;
        }
    }

    // Nonce check (for logged-in editors)
    $nonce_ok = abtest_rest_check_nonce($request);
    // Same-origin for browsers
    $origin_ok = abtest_is_same_origin_request();

    // Gate: require any of (1) valid nonce, (2) same-origin, or (3) valid signature
    if ( ! $nonce_ok && ! $origin_ok && ! $valid_sig ) {
        return rest_ensure_response([
            'success' => false,
            'error'   => 'Unauthorised: nonce, origin, or signature required.',
        ]);
    }

    $valid_types   = ['impression','click','decision','decision_applied','stale'];
    $needs_variant = in_array($type, ['impression','click','decision','decision_applied'], true);

    if (
        empty($ab_id) ||
        ! in_array($type, $valid_types, true) ||
        ($needs_variant && ! in_array($variant, ['A','B'], true)) ||
        $post_id <= 0
    ) {
        return rest_ensure_response([
            'success' => false,
            'error'   => 'Invalid tracking payload',
        ]);
    }

    // Ensure the test actually exists on this post
    if ( ! abtest_test_id_exists_on_post( $post_id, $ab_id ) ) {
        return rest_ensure_response([
            'success' => false,
            'error'   => 'Unknown test on this post',
        ]);
    }

    // 🚦 Simple rate limit: max 120 events/minute per IP + test
    $ip_for_limit = $_SERVER['REMOTE_ADDR'] ?? '';
    $limit_key = 'abtrk_' . md5($ip_for_limit . '|' . $post_id . '|' . $ab_id . '|' . gmdate('YmdHi'));
    $current_count = (int) get_transient($limit_key);
    if ( $current_count > 120 ) {
        return rest_ensure_response([ 'success' => true ]); // silently ignore
    }
    set_transient($limit_key, $current_count + 1, MINUTE_IN_SECONDS + 5);

    // ✅ Log to database
    abtest_log_event_to_db( $type, $post_id, $ab_id, $variant );

    return rest_ensure_response([ 'success' => true ]);
}


//Handler for /stats

function abtest_handle_stats( WP_REST_Request $request ) {
    global $wpdb;

    $post_id = absint( $request->get_param( 'post_id' ) );
    if ( $post_id <= 0 ) {
        return rest_ensure_response([
            'A' => [ 'impressions' => 0, 'clicks' => 0 ],
            'B' => [ 'impressions' => 0, 'clicks' => 0 ],
        ]);
    }

    // Accept either a single abTestId or multiple abTestIds
    $single = $request->get_param( 'abTestId' );
    $many   = $request->get_param( 'abTestIds' );

    // Normalise to an array of sanitized IDs
    $ab_ids = [];
    if ( is_array( $many ) ) {
        foreach ( $many as $id ) {
            $clean = abtest_sanitize_test_id( $id );
            if ( $clean !== '' ) $ab_ids[] = $clean;
        }
    } elseif ( is_string( $many ) && $many !== '' ) {
        foreach ( preg_split( '/[,\|]/', $many ) as $id ) {
            $clean = abtest_sanitize_test_id( $id );
            if ( $clean !== '' ) $ab_ids[] = $clean;
        }
    } elseif ( $single ) {
        $clean = abtest_sanitize_test_id( $single );
        if ( $clean !== '' ) $ab_ids[] = $clean;
    }

    $ab_ids = array_values( array_unique( $ab_ids ) );

    if ( empty( $ab_ids ) ) {
        return rest_ensure_response([
            'A' => [ 'impressions' => 0, 'clicks' => 0 ],
            'B' => [ 'impressions' => 0, 'clicks' => 0 ],
        ]);
    }

    $table = $wpdb->prefix . 'ab_test_events';

    if ( count( $ab_ids ) === 1 ) {
        $sql = $wpdb->prepare(
            "SELECT ab_test_id, variant, event_type, COUNT(*) AS count
             FROM $table
             WHERE post_id = %d AND ab_test_id = %s
             GROUP BY ab_test_id, variant, event_type",
            $post_id, $ab_ids[0]
        );
    } else {
        $placeholders = implode( ',', array_fill( 0, count( $ab_ids ), '%s' ) );
        $params = array_merge( [ $post_id ], $ab_ids );
        // phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
        $sql = $wpdb->prepare(
            "SELECT ab_test_id, variant, event_type, COUNT(*) AS count
             FROM $table
             WHERE post_id = %d AND ab_test_id IN ($placeholders)
             GROUP BY ab_test_id, variant, event_type",
            $params
        );
        // phpcs:enable
    }

    $rows = $wpdb->get_results( $sql, ARRAY_A );

    $default_pair = [ 'A' => [ 'impressions' => 0, 'clicks' => 0 ],
                      'B' => [ 'impressions' => 0, 'clicks' => 0 ] ];

    if ( count( $ab_ids ) === 1 && isset( $ab_ids[0] ) && $single ) {
        // Back-compat single shape
        $stats = $default_pair;
        foreach ( $rows as $r ) {
            $v = $r['variant'];
            $t = $r['event_type'];
            $c = (int) $r['count'];
            if ( isset( $stats[$v][$t . 's'] ) ) {
                $stats[$v][$t . 's'] += $c;
            }
        }
        return rest_ensure_response( $stats );
    }

    // Multi shape keyed by test id
    $out = [];
    foreach ( $ab_ids as $id ) {
        $out[$id] = $default_pair;
    }
    foreach ( $rows as $r ) {
        $id = $r['ab_test_id'];
        $v  = $r['variant'];
        $t  = $r['event_type'];
        $c  = (int) $r['count'];
        if ( isset( $out[$id][$v][$t . 's'] ) ) {
            $out[$id][$v][$t . 's'] += $c;
        }
    }

    return rest_ensure_response( $out );
}


/**
 * Handler for /evaluate — Bayesian with Beta(5,5) prior + 5-impression floor.
 */
function abtest_handle_evaluate( WP_REST_Request $request ) {
    $abTestId = abtest_sanitize_test_id( $request->get_param('abTestId') );
    $post_id  = absint( $request->get_param('post_id') );

    if ( empty( $abTestId ) || empty( $post_id ) ) {
        return rest_ensure_response([
            'error'   => 'Missing abTestId or post_id',
            'message' => 'Evaluation requires abTestId and post_id.'
        ]);
    }

    //Parse post content to find if this test has grouped dependencies (conversionFrom)
    $content = get_post_field('post_content', $post_id );
    $blocks  = parse_blocks( $content );
    $grouped = [];

    $scan = function( $blocks ) use ( &$scan, $abTestId, &$grouped ) {
        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) ) continue;
            $attrs = $block['attrs'] ?? [];
            if ( ( $attrs['abTestId'] ?? null ) === $abTestId && ! empty( $attrs['conversionFrom'] ) ) {
                $grouped = $attrs['conversionFrom'];
                return;
            }
            if ( ! empty( $block['innerBlocks'] ) ) {
                $scan( $block['innerBlocks'] );
            }
        }
    };
    $scan( $blocks );

    //Get current stats for this test
    $stats_resp = abtest_handle_stats( $request );
    $stats = $stats_resp instanceof WP_REST_Response
           ? $stats_resp->get_data()
           : (array) $stats_resp;

    //Merge in click stats from grouped sources
//    foreach ( $grouped as $sourceId ) {
//        $req = new WP_REST_Request( 'GET' );
//        $req->set_param( 'post_id', $post_id );
//        $req->set_param( 'abTestId', $sourceId );

//        $source_stats = abtest_handle_stats( $req );
//        $source_data  = $source_stats instanceof WP_REST_Response
//                      ? $source_stats->get_data()
//                      : (array) $source_stats;

//        foreach ( [ 'A', 'B' ] as $variant ) {
//            $stats[ $variant ]['clicks'] += $source_data[ $variant ]['clicks'] ?? 0;
//        }
//     }

    // Defensive fallback: zero data
$impA = $stats['A']['impressions'];
$clkA = $stats['A']['clicks'];
$impB = $stats['B']['impressions'];
$clkB = $stats['B']['clicks'];

// Early exits for low data
if ($impA === 0 && $impB === 0) {
    return rest_ensure_response([
        'probA' => 0.5, 'probB' => 0.5, 'winner' => '',
        'message' => 'No impressions recorded yet.',
    ]);
}
if ($impA === 0 || $impB === 0) {
    return rest_ensure_response([
        'probA' => 0.5, 'probB' => 0.5, 'winner' => '',
        'message' => 'Only one variant has impressions — test needs more data.',
    ]);
}
if ($clkA === 0 && $clkB === 0) {
    return rest_ensure_response([
        'probA' => 0.5, 'probB' => 0.5, 'winner' => '',
        'message' => 'No clicks yet — defaulting to 50/50.',
    ]);
}

    //Apply Bayesian prior and sample distributions
    $priorN = 10;
    $alphaA = $priorN/2 + $clkA;
    $betaA  = $priorN/2 + max(0, $impA - $clkA);
    $alphaB = $priorN/2 + $clkB;
    $betaB  = $priorN/2 + max(0, $impB - $clkB);

    $numSamples = 50000;
    $countA = 0;
    $diffs  = [];

    for ( $i = 0; $i < $numSamples; $i++ ) {
        $sampA = sample_beta( $alphaA, $betaA );
        $sampB = sample_beta( $alphaB, $betaB );
        if ( $sampA > $sampB ) {
            $countA++;
        }
        $diffs[] = $sampA - $sampB;
    }

    $probA = $countA / $numSamples;
    $probB = 1 - $probA;
    sort( $diffs );
    $ciLower = $diffs[ (int) (0.025 * $numSamples) ];
    $ciUpper = $diffs[ (int) (0.975 * $numSamples) ];

    $winner = '';
    if ( $probA > 0.95 && $ciLower > 0 ) {
        $winner = 'A';
    } elseif ( $probB > 0.95 && $ciUpper < 0 ) {
        $winner = 'B';
    }

    return rest_ensure_response([
        'probA'   => round( $probA, 4 ),
        'probB'   => round( $probB, 4 ),
        'ciLower' => round( $ciLower, 4 ),
        'ciUpper' => round( $ciUpper, 4 ),
        'winner'  => $winner
    ]);
}

/**
 * Handler for /reset — removes events for given post, index, and abTestId only.
 */
function abtest_handle_reset( WP_REST_Request $request ) {
    global $wpdb;

    $body     = json_decode( $request->get_body(), true );
    $post_id  = absint( $body['post_id'] ?? 0 );
    $abTestId = abtest_sanitize_test_id( $body['abTestId'] ?? '' );

    if ( empty( $post_id ) || empty( $abTestId ) ) {
        return rest_ensure_response( [
            'success' => false,
            'error'   => 'Missing post_id or abTestId'
        ] );
    }

    $table = $wpdb->prefix . 'ab_test_events';

    $deleted = $wpdb->delete(
        $table,
        [
            'post_id'    => $post_id,
            'ab_test_id' => $abTestId,
        ],
        [ '%d', '%s' ]
    );

    error_log(" Reset A/B test: post_id=$post_id abTestId=$abTestId deleted=$deleted");

    return rest_ensure_response( [ 'success' => true, 'deleted' => $deleted ] );
}

/**
 * Enqueue block-editor scripts: ab-sidebar.js (plugin sidebar) + editor.js (inline-lock HOC).
 */
add_action( 'enqueue_block_editor_assets', function() {
    $plugin_dir = plugin_dir_url( __FILE__ );

    // 1) Sidebar script — add 'wp-block-editor' here
    wp_enqueue_script(
        'abtest-editor-sidebar',
        $plugin_dir . 'assets/js/ab-sidebar.js',
        [
            'wp-edit-post',
            'wp-element',
            'wp-components',
            'wp-api-fetch',
            'wp-data',
            'wp-hooks',
            'wp-compose',
            'wp-block-editor', // <-- add this
        ],
        filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/ab-sidebar.js' ),
        true
    );

    // 2) HOC script (optional but recommended to also add 'wp-block-editor')
    wp_enqueue_script(
        'abtest-editor-hoc',
        $plugin_dir . 'assets/js/editor.js',
        [
            'wp-element',
            'wp-edit-post',
            'wp-hooks',
            'wp-data',
            'wp-compose',
            'wp-blocks',
            'wp-api-fetch',
            'wp-components',
            'wp-block-editor', // optional, keeps block editor APIs guaranteed
        ],
        filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/editor.js' ),
        true
    );
    
    $block_config = abtest_load_block_config();
    wp_localize_script( 'abtest-editor-sidebar', 'abTestConfig', [
        'blockConfig'  => $block_config,
        'nonce'        => wp_create_nonce( 'wp_rest' ),
        'restUrl'      => esc_url_raw( rest_url( '/ab-test/v1' ) ),
        'telemetry'    => [
            'optedIn' => abtest_is_telemetry_opted_in(),
        ],
        // Email capture config for the modal
        'emailCapture' => [
            'enabled'       => ABTEST_EMAIL_CAPTURE_ENABLED ? 'yes' : 'no',
            'appsScriptUrl' => ABTEST_EMAIL_APPS_SCRIPT, // includes ?key=...
            'plugin'        => 'abtestkit',
            'version'       => '1.0.0',
            // Base fields so GAS fills standard columns
            'site'          => md5( home_url() ),
            'wp'            => get_bloginfo( 'version' ),
            'php'           => PHP_VERSION,
            'env'           => ( wp_get_environment_type() ?: 'production' ),
        ],
    ] );
} );

// ─────────────────────────────────────────────────────────────────────────────
// Admin opt-in notice (one-time until accepted/declined)
// ─────────────────────────────────────────────────────────────────────────────
add_action('admin_notices', function () {
    if ( ! current_user_can('manage_options') ) return;
    if ( get_option( ABTEST_TELEMETRY_OPTIN_OPTION, null ) !== null ) return;

    echo '<div class="notice notice-info is-dismissible"><p><strong>abtestkit</strong>: Share <em>anonymous</em> usage so we can fix bugs faster and prioritise the right features. ';
    echo 'No content, no personal data. ';
    echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url('admin-post.php?action=abtest_telemetry_optin&v=1'), 'abtest_optin' ) ) . '">Share anonymous data</a> ';
    echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url('admin-post.php?action=abtest_telemetry_optin&v=0'), 'abtest_optin' ) ) . '">No thanks</a>';
    echo '</p></div>';
});
add_action('admin_post_abtest_telemetry_optin', function () {
    if ( ! current_user_can('manage_options') ) wp_die('forbidden');
    check_admin_referer('abtest_optin');
    $v = isset($_GET['v']) && (int) $_GET['v'] === 1;
    abtest_set_telemetry_optin( (bool) $v );
    wp_safe_redirect( remove_query_arg( ['action','v','_wpnonce'], wp_get_referer() ?: admin_url() ) );
    exit;
});

/**
 * Enqueue frontend tracking script on single posts (and inject live variants directly from saved block content).
 */
add_action('wp_enqueue_scripts', function () {
    if (!is_singular()) return;

    $plugin_dir = plugin_dir_url(__FILE__);

    wp_enqueue_script(
        'abtest-frontend',
        $plugin_dir . 'assets/js/frontend.js',
        [],
        filemtime(plugin_dir_path(__FILE__) . 'assets/js/frontend.js'),
        true
    );

    $post_id = get_the_ID();
$content = get_post_field('post_content', $post_id);
$blocks = parse_blocks($content); // ✅ you were missing this

$frontendConfig = [
    'postId'  => $post_id,
    'index'   => 0,
    'nonce'   => wp_create_nonce('wp_rest'),
    'restUrl' => esc_url_raw(rest_url('/ab-test/v1')),
];

$ts  = time();
$sig = abtest_make_sig( (int) $post_id, $ts );
$frontendConfig['_ts']  = $ts;
$frontendConfig['_sig'] = $sig;

$clickTargetMap = [];

$extract_variants = function($blocks) use (&$extract_variants, &$frontendConfig, &$clickTargetMap) {
    foreach ($blocks as $block) {
        if (!is_array($block) || !isset($block['blockName'])) continue;

        $attrs = $block['attrs'] ?? [];
        $abTestId = $attrs['abTestId'] ?? null;
        $abTestVariants = $attrs['abTestVariants'] ?? null;
        $conversionFrom = $attrs['conversionFrom'] ?? [];

        foreach ($conversionFrom as $sourceId) {
            if (!isset($clickTargetMap[$sourceId])) {
                $clickTargetMap[$sourceId] = [];
            }
            $clickTargetMap[$sourceId][] = $abTestId;
        }

        if ($abTestId && $abTestVariants && isset($abTestVariants[$abTestId])) {
         $locked = $abTestVariants[$abTestId]['locked'] ?? true;
            $abTestVariants[$abTestId]['running'] = !$locked;

            // ✅ Respect either source of groupedAbTests: calculated or stored
            $storedGrouped = $abTestVariants[$abTestId]['groupedAbTests'] ?? [];
            $calculatedGrouped = $clickTargetMap[$abTestId] ?? [];

            // Merge and dedupe
            $mergedGrouped = array_values(array_unique(array_merge($storedGrouped, $calculatedGrouped)));

            $abTestVariants[$abTestId]['groupedAbTests'] = $mergedGrouped;
            $abTestVariants[$abTestId]['conversionFrom'] = $conversionFrom ?? [];

            $frontendConfig[$abTestId] = $abTestVariants[$abTestId];
        }

        if (!empty($block['innerBlocks'])) {
            $extract_variants($block['innerBlocks']);
        }
    }
};

$extract_variants($blocks); // ✅ now this will work

wp_localize_script('abtest-frontend', 'abTestConfig', $frontendConfig);

});

register_activation_hook(__FILE__, function () {
    abtest_create_event_table();
    if ( ! get_option( ABTEST_TELEMETRY_INSTALL_OPTION ) ) {
        update_option( ABTEST_TELEMETRY_INSTALL_OPTION, time() );
    }
    if ( abtest_is_telemetry_opted_in() && ! abtest_flag_is_set('installed_sent') ) {
        abtest_send_telemetry( 'plugin_installed', [
            'installed_at' => (int) get_option( ABTEST_TELEMETRY_INSTALL_OPTION ),
        ] );
        abtest_mark_flag('installed_sent');
    }
});

// Deactivation: remove rate-limit transients and flush page/object caches
register_deactivation_hook(__FILE__, 'abtest_on_deactivate');
function abtest_on_deactivate() {
    global $wpdb;

    // Kill any per-minute tracking transients
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_abtrk_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_abtrk_%'");

    // Best-effort cache flushers (object cache + common caching plugins)
    if (function_exists('wp_cache_flush')) { wp_cache_flush(); }
    if (function_exists('w3tc_flush_all')) { w3tc_flush_all(); }
    if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); }
    if (function_exists('autoptimize_flush_cache')) { autoptimize_flush_cache(); }
    // LiteSpeed
    if (class_exists('LiteSpeed_Cache')) { do_action('litespeed_purge_all'); }
    // WP Super Cache
    if (function_exists('wp_cache_clear_cache')) { wp_cache_clear_cache(); }
}

register_uninstall_hook(__FILE__, 'abtest_uninstall');
function abtest_uninstall() {
    global $wpdb;

    // --- 0) Helper to strip AB attributes from block trees ---
    if (!function_exists('abtest__strip_ab_attrs_from_blocks')) {
        function abtest__strip_ab_attrs_from_blocks(array $blocks, &$changed = false) {
            $out = [];
            foreach ($blocks as $b) {
                if (!is_array($b)) { $out[] = $b; continue; }

                // Remove all AB-related attributes the plugin added
                $attrs = isset($b['attrs']) && is_array($b['attrs']) ? $b['attrs'] : [];
                $keys  = [
                    'abTestEnabled','abTestVariants','abTestId','abTestRunning','abTestWinner',
                    'abTestResultsViewed','conversionFrom','abTestLastUnlocked','abTestStartedAt',
                    'abSync','abGroupKey'
                ];
                $touched = false;
                foreach ($keys as $k) {
                    if (isset($attrs[$k])) { unset($attrs[$k]); $touched = true; }
                }
                if ($touched) { $changed = true; }
                $b['attrs'] = $attrs;

                // Recurse innerBlocks
                if (!empty($b['innerBlocks']) && is_array($b['innerBlocks'])) {
                    $b['innerBlocks'] = abtest__strip_ab_attrs_from_blocks($b['innerBlocks'], $changed);
                }
                $out[] = $b;
            }
            return $out;
        }
    }

    // --- 1) Drop events table ---
    $table = $wpdb->prefix . 'ab_test_events';
    $wpdb->query("DROP TABLE IF EXISTS $table");

    // --- 2) Remove stored per-post meta mirror ---
    if (function_exists('delete_post_meta_by_key')) {
        delete_post_meta_by_key('_ab_test_variants');
    } else {
        $wpdb->query($wpdb->prepare(
            "DELETE pm FROM {$wpdb->postmeta} pm WHERE pm.meta_key = %s",
            '_ab_test_variants'
        ));
    }

    // --- 3) Strip AB attributes from saved block content (safe & optional) ---
    // This ensures blocks re-render as normal Gutenberg blocks everywhere.
    $post_types = get_post_types(['public' => true], 'names');
    if (!empty($post_types)) {
        // Process in batches to avoid timeouts on moderate sites
        foreach ($post_types as $pt) {
            $paged = 1;
            do {
                $q = new WP_Query([
                    'post_type'      => $pt,
                    'post_status'    => 'any',
                    'posts_per_page' => 200,
                    'paged'          => $paged,
                    'no_found_rows'  => true,
                    'fields'         => 'ids',
                ]);
                if (empty($q->posts)) { break; }

                foreach ($q->posts as $pid) {
                    $content = get_post_field('post_content', $pid);
                    if (!$content) { continue; }

                    $changed = false;
                    $blocks  = parse_blocks($content);
                    $blocks  = abtest__strip_ab_attrs_from_blocks($blocks, $changed);

                    if ($changed) {
                        $new_html = serialize_blocks($blocks);
                        // Update post content with AB attributes removed
                        wp_update_post([
                            'ID'           => $pid,
                            'post_content' => $new_html,
                        ]);
                    }

                    // Remove per-post meta mirror just in case
                    delete_post_meta($pid, '_ab_test_variants');
                }

                $paged++;
            } while (true);
        }
    }

    // --- 4) Purge rate-limit transients ---
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_abtrk_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_abtrk_%'");

    // --- 5) Best-effort cache flush so no stale HTML remains in caches/CDNs ---
    if (function_exists('wp_cache_flush')) { wp_cache_flush(); }
    if (function_exists('w3tc_flush_all')) { w3tc_flush_all(); }
    if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); }
    if (function_exists('autoptimize_flush_cache')) { autoptimize_flush_cache(); }
    if (class_exists('LiteSpeed_Cache')) { do_action('litespeed_purge_all'); }
    if (function_exists('wp_cache_clear_cache')) { wp_cache_clear_cache(); }
}

function abtest_create_event_table() {
  global $wpdb;
  $table = $wpdb->prefix . 'ab_test_events';
  $charset_collate = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE $table (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    time DATETIME NOT NULL,
    post_id BIGINT,
    ab_test_id VARCHAR(64),
    variant CHAR(1),
    event_type ENUM('impression','click','decision','decision_applied','stale'),
    ip VARCHAR(45),
    user_agent TEXT,
    KEY idx_time (time),
    KEY idx_post_test (post_id, ab_test_id),
    KEY idx_variant_type (variant, event_type)
  ) $charset_collate;";

  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
}