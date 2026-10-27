<?php

defined('ABSPATH') || exit;

final class DOVP_Plugin {
    const OPTION_SETTINGS = 'dovp_settings';
    const OPTION_SITE_TOKEN = 'dovp_site_device_token';
    const CREDIT_SERVICE_URL = 'https://digitalownership.squaredant.com/api/credit/api/plugin';
    const REGISTRY_ADDRESS = '0xf495d81eb4A64d213f6BAC2bD23EE9A147956169';
    const CHAIN_ID = '0xa4b1';
    const META_ENABLED = '_dovp_enabled';
    const META_FORMAT = '_dovp_format';
    const META_VERSION = '_dovp_active_version';
    const META_VERSIONS = '_dovp_versions';
    const META_PRIVATE_ARCHIVE = '_dovp_private_archive';
    const MANIFEST_SCHEMA = 'digitalownership-publication-manifest-v1';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate() {
        $plugin = self::instance();
        $plugin->add_rewrite_rules();
        flush_rewrite_rules();
        $plugin->purge_non_public_publication_evidence();
        update_option('dovp_rewrite_version', DOVP_VERSION, false);
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    private function __construct() {
        add_action('init', array($this, 'add_rewrite_rules'));
        add_action('init', array($this, 'maybe_flush_rewrite_rules'), 20);
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_filter('redirect_canonical', array($this, 'prevent_artifact_canonical_redirect'), 10, 2);
        add_action('template_redirect', array($this, 'serve_publication_artifact'));
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('save_post', array($this, 'save_post_settings'), 10, 2);
        add_action('post_updated', array($this, 'remove_evidence_for_non_public_post'), 10, 3);
        add_action('before_delete_post', array($this, 'remove_publication_evidence'));
        add_action('admin_post_dovp_download_archived_evidence', array($this, 'download_archived_evidence'));
        add_action('admin_post_dovp_generate_canonical', array($this, 'generate_canonical'));
        add_action('admin_post_dovp_attach_manifest', array($this, 'attach_manifest'));
        add_action('admin_post_dovp_connect_account', array($this, 'connect_account'));
        add_action('admin_post_dovp_disconnect_account', array($this, 'disconnect_account'));
        add_action('admin_post_dovp_request_publication_approval', array($this, 'request_publication_approval'));
        add_action('admin_post_dovp_refresh_publication_approval', array($this, 'refresh_publication_approval'));
        add_action('admin_post_dovp_prepare_manifest', array($this, 'prepare_manifest'));
        add_action('admin_post_dovp_request_manifest_approval', array($this, 'request_manifest_approval'));
        add_action('dovp_process_publication_workflow', array($this, 'process_publication_workflow'), 10, 2);
        add_action('admin_notices', array($this, 'render_admin_notice'));
        add_action('admin_menu', array($this, 'add_admin_pages'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'add_privacy_policy_content'));
        add_action('admin_footer-post.php', array($this, 'render_action_form'));
        add_action('admin_footer-post-new.php', array($this, 'render_action_form'));
        add_action('enqueue_block_editor_assets', array($this, 'refresh_evidence_box_after_editor_save'));
        // Run last so theme or editor filters cannot replace the canonical body
        // after the verification panel has been appended.
        add_filter('the_content', array($this, 'render_canonical_content'), PHP_INT_MAX);
        add_action('wp_head', array($this, 'add_discovery_links'), 5);
    }

    public function add_privacy_policy_content() {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $terms_url = 'https://digitalownership.squaredant.com/terms';
        $privacy_url = 'https://digitalownership.squaredant.com/privacy';
        $content = '<p class="privacy-policy-tutorial">' . esc_html__('Use this suggested text only when this plugin is enabled for one or more publications. Review it and adapt it to your site before publishing.', 'digitalownership-publication-verification') . '</p>'
            . '<h2>' . esc_html__('DigitalOwnership Publication Verification', 'digitalownership-publication-verification') . '</h2>'
            . '<p>' . esc_html__('This site uses DigitalOwnership Publication Verification for selected posts or pages. The plugin creates a fixed public JSON copy of the selected post body and may create a public evidence manifest. These public files are stored on this site and can be read by site visitors and independent verification tools.', 'digitalownership-publication-verification') . '</p>'
            . '<p>' . esc_html__('When an authorised editor requests registration and the linked DigitalOwnership account holder approves it, the plugin sends DigitalOwnership the SHA-512 fingerprint of the fixed JSON file, its hash scope, blockchain registry details, and a request identifier. The plugin does not upload the post body, local images, attachments, or other original file bytes to DigitalOwnership for registration.', 'digitalownership-publication-verification') . '</p>'
            . '<p>' . esc_html__('A completed public evidence manifest contains the registration email address associated with the linked DigitalOwnership account, the fixed-content URL, its SHA-512 fingerprint, and public blockchain registration references. The email address is included because independent verifiers use it to derive the account-linked registration key. Do not enable this plugin for content where that disclosure is inappropriate.', 'digitalownership-publication-verification') . '</p>'
            . '<p>' . sprintf(
                /* translators: 1: Terms URL. 2: Privacy URL. */
                esc_html__('DigitalOwnership processes approved registration requests as an external service. Review the DigitalOwnership %1$s and %2$s.', 'digitalownership-publication-verification'),
                '<a href="' . esc_url($terms_url) . '">' . esc_html__('Terms of Service', 'digitalownership-publication-verification') . '</a>',
                '<a href="' . esc_url($privacy_url) . '">' . esc_html__('Privacy Notice', 'digitalownership-publication-verification') . '</a>'
            ) . '</p>'
            . '<p>' . esc_html__('The plugin stores the active publication-version records in WordPress post metadata, the fixed JSON and completed manifest files under the WordPress uploads directory, and a site-local connection token in the WordPress options table. These records remain until a site administrator removes the relevant post metadata and generated files. Removing the site token prevents further service calls but does not erase previously published public evidence.', 'digitalownership-publication-verification') . '</p>';

        wp_add_privacy_policy_content(
            __('DigitalOwnership Publication Verification', 'digitalownership-publication-verification'),
            wp_kses_post($content)
        );
    }

    public function add_rewrite_rules() {
        add_rewrite_rule(
            '^digitalownership-publication/([0-9]+)/([A-Za-z0-9._-]+)/assets/([0-9]+)/[^/]+$',
            'index.php?dovp_post_id=$matches[1]&dovp_version=$matches[2]&dovp_asset_index=$matches[3]',
            'top'
        );
        add_rewrite_rule(
            '^digitalownership-publication/([0-9]+)/([A-Za-z0-9._-]+)/(content|manifest|manifest-draft|workflow)\\.(json|md)$',
            'index.php?dovp_post_id=$matches[1]&dovp_version=$matches[2]&dovp_artifact=$matches[3]&dovp_extension=$matches[4]',
            'top'
        );
    }

    public function maybe_flush_rewrite_rules() {
        if (get_option('dovp_rewrite_version') === DOVP_VERSION) {
            return;
        }
        flush_rewrite_rules(false);
        $this->purge_non_public_publication_evidence();
        update_option('dovp_rewrite_version', DOVP_VERSION, false);
    }

    public function add_query_vars($vars) {
        $vars[] = 'dovp_post_id';
        $vars[] = 'dovp_version';
        $vars[] = 'dovp_artifact';
        $vars[] = 'dovp_extension';
        $vars[] = 'dovp_asset_index';
        return $vars;
    }

    public function prevent_artifact_canonical_redirect($redirect_url, $requested_url) {
        if (get_query_var('dovp_post_id') && get_query_var('dovp_version') && (get_query_var('dovp_artifact') || '' !== get_query_var('dovp_asset_index'))) {
            return false;
        }
        return $redirect_url;
    }

    public function serve_publication_artifact() {
        $post_id = absint(get_query_var('dovp_post_id'));
        $version = sanitize_key(get_query_var('dovp_version'));
        $artifact = sanitize_key(get_query_var('dovp_artifact'));
        $extension = sanitize_key(get_query_var('dovp_extension'));
        $asset_index = get_query_var('dovp_asset_index');

        if (!$post_id || !$version) {
            return;
        }
        if (!$this->is_public_publication(get_post($post_id))) {
            $this->send_not_found();
        }

        list($stored_version, $record) = $this->record_for_version($post_id, $version);
        if (!is_array($record)) {
            $this->send_not_found();
        }
        if ('' !== $asset_index) {
            $this->serve_versioned_asset($record, absint($asset_index));
        }
        if (!in_array($artifact, array('content', 'manifest', 'manifest-draft', 'workflow'), true)) {
            return;
        }
        if ('workflow' === $artifact) {
            if ('json' !== $extension) {
                $this->send_not_found();
            }
            $this->send_workflow_status($post_id, $stored_version, $record);
        }

        if ('manifest-draft' === $artifact) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- An unguessable draft token authorizes this private route.
            $draft_token = sanitize_text_field(wp_unslash($_GET['dovp_draft'] ?? ''));
            if ('json' !== $extension || empty($record['manifestDraft']) || empty($record['manifestDraftToken']) || !hash_equals((string) $record['manifestDraftToken'], $draft_token)) {
                $this->send_not_found();
            }
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Draft token authorizes this private preview.
            if ('1' === sanitize_text_field(wp_unslash($_GET['dovp_preview'] ?? ''))) {
                $this->send_manifest_preview((string) $record['manifestDraft'], $post_id, $stored_version);
            }
            $this->send_public_json((string) $record['manifestDraft'], false);
        }

        $path_key = 'content' === $artifact ? 'contentPath' : 'manifestPath';
        $expected_extension = 'content' === $artifact ? ($record['format'] ?? '') : 'json';
        if ($expected_extension !== $extension || empty($record[$path_key])) {
            $this->send_not_found();
        }

        $path = $this->safe_storage_path($record[$path_key]);
        if (!$path || !is_readable($path)) {
            $this->send_not_found();
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public fixed-content preview is intentionally readable.
        if ('content' === $artifact && '1' === sanitize_text_field(wp_unslash($_GET['dovp_preview'] ?? ''))) {
            $this->send_fixed_content_preview($path, $post_id, $stored_version, $extension);
        }

        $content_type = 'md' === $extension ? 'text/markdown; charset=utf-8' : 'application/json; charset=utf-8';
        nocache_headers();
        header('Content-Type: ' . $content_type);
        header('Content-Length: ' . (string) filesize($path));
        header('Access-Control-Allow-Origin: https://digitalownership.squaredant.com');
        header('Access-Control-Allow-Methods: GET');
        header('Vary: Origin');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Stored immutable JSON or Markdown bytes are served verbatim.
        echo $this->filesystem_contents($path);
        exit;
    }

    private function serve_versioned_asset($record, $asset_index) {
        $assets = is_array($record['assetSources'] ?? null) ? $record['assetSources'] : array();
        if (!isset($assets[$asset_index]) || !is_array($assets[$asset_index])) {
            $this->send_not_found();
        }
        $asset = $assets[$asset_index];
        $path = $this->local_upload_path_for_url($asset['sourceUrl'] ?? '');
        $expected_hash = strtolower((string) ($asset['sha512'] ?? ''));
        $content_type = sanitize_text_field($asset['contentType'] ?? '');
        $expected_bytes = absint($asset['bytes'] ?? 0);
        if (!$path || !preg_match('/^[a-f0-9]{128}$/', $expected_hash) || 0 !== strpos($content_type, 'image/') || !$expected_bytes) {
            $this->send_not_found();
        }

        clearstatcache(true, $path);
        if ((int) filesize($path) !== $expected_bytes || !hash_equals($expected_hash, hash_file('sha512', $path))) {
            status_header(409);
            header('Content-Type: application/json; charset=utf-8');
            header('Access-Control-Allow-Origin: https://digitalownership.squaredant.com');
            header('Access-Control-Allow-Methods: GET');
            header('Vary: Origin');
            header('X-Content-Type-Options: nosniff');
            echo wp_json_encode(array('error' => 'The local image no longer matches this fixed publication version.'));
            exit;
        }

        nocache_headers();
        header('Content-Type: ' . $content_type);
        header('Content-Length: ' . (string) $expected_bytes);
        header('Access-Control-Allow-Origin: https://digitalownership.squaredant.com');
        header('Access-Control-Allow-Methods: GET');
        header('Vary: Origin');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Verified image bytes are served verbatim.
        echo $this->filesystem_contents($path);
        exit;
    }

    private function send_fixed_content_preview($path, $post_id, $version, $extension) {
        $source = file_get_contents($path);
        $body = 'md' === $extension ? $this->render_markdown_content($source) : $this->render_json_content($source);
        if (null === $body) {
            $this->send_not_found();
        }
        $post = get_post($post_id);
        $title = $post ? get_the_title($post) : __('Fixed publication', 'digitalownership-publication-verification');
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=31536000, immutable');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />';
        echo '<title>' . esc_html($title) . '</title>';
        echo '<style>body{max-width:760px;margin:3rem auto;padding:0 1.25rem;font:18px/1.6 Georgia,serif;color:#1d2a24}img{max-width:100%;height:auto}a{color:#006e5a}.dovp-fixed-note{font:14px/1.4 system-ui,sans-serif;color:#52615a;border-bottom:1px solid #d6ddd8;padding-bottom:1rem;margin-bottom:2rem}</style>';
        /* translators: %s: fixed publication version identifier. */
        echo '</head><body><p class="dovp-fixed-note">' . esc_html(sprintf(__('Fixed publication version %s. This is a rendered view of the exact JSON file under review.', 'digitalownership-publication-verification'), $version)) . '</p>';
        // Rendered publication HTML was sanitized while the fixed artifact was created.
        echo '<main><h1>' . esc_html($title) . '</h1>' . wp_kses_post($body) . '</main></body></html>';
        exit;
    }

    private function send_manifest_preview($contents, $post_id, $version) {
        $manifest = json_decode($contents, true);
        if (!is_array($manifest) || empty($manifest['files'][0]) || !is_array($manifest['files'][0])) {
            $this->send_not_found();
        }
        $file = $manifest['files'][0];
        $post = get_post($post_id);
        $title = $post ? get_the_title($post) : __('Publication', 'digitalownership-publication-verification');
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />';
        /* translators: %s: publication title. */
        echo '<title>' . esc_html(sprintf(__('Evidence file for %s', 'digitalownership-publication-verification'), $title)) . '</title>';
        echo '<style>body{max-width:760px;margin:3rem auto;padding:0 1.25rem;font:18px/1.6 Georgia,serif;color:#1d2a24}.dovp-note{font:14px/1.45 system-ui,sans-serif;color:#52615a;border-bottom:1px solid #d6ddd8;padding-bottom:1rem;margin-bottom:2rem}dl{font:16px/1.5 system-ui,sans-serif}dt{font-weight:700;margin-top:1rem}dd{margin:0;overflow-wrap:anywhere}code{font:13px/1.4 ui-monospace,monospace}</style>';
        /* translators: %s: fixed publication version identifier. */
        echo '</head><body><p class="dovp-note">' . esc_html(sprintf(__('Public evidence file for fixed publication version %s. It connects this fixed file to its completed registration.', 'digitalownership-publication-verification'), $version)) . '</p>';
        echo '<main><h1>' . esc_html__('Publication evidence file', 'digitalownership-publication-verification') . '</h1><dl>';
        echo '<dt>' . esc_html__('Publication', 'digitalownership-publication-verification') . '</dt><dd>' . esc_html($title) . '</dd>';
        echo '<dt>' . esc_html__('Fixed content URL', 'digitalownership-publication-verification') . '</dt><dd><a href="' . esc_url($file['url'] ?? '') . '">' . esc_html($file['url'] ?? '') . '</a></dd>';
        echo '<dt>' . esc_html__('Fixed-content SHA-512', 'digitalownership-publication-verification') . '</dt><dd><code>' . esc_html($file['sha512'] ?? '') . '</code></dd>';
        echo '<dt>' . esc_html__('Content registration key', 'digitalownership-publication-verification') . '</dt><dd><code>' . esc_html($file['registryKey'] ?? '') . '</code></dd>';
        echo '<dt>' . esc_html__('Content transaction', 'digitalownership-publication-verification') . '</dt><dd><code>' . esc_html($file['transactionHash'] ?? '') . '</code></dd>';
        $revision_note = sanitize_textarea_field($manifest['revisionNote'] ?? '');
        if ($revision_note) {
            echo '<dt>' . esc_html__('Publisher revision note', 'digitalownership-publication-verification') . '</dt><dd>' . esc_html($revision_note) . '</dd>';
        }
        if (!empty($manifest['previousVersions']) && is_array($manifest['previousVersions'])) {
            echo '<dt>' . esc_html__('Earlier versions disclosed to readers', 'digitalownership-publication-verification') . '</dt><dd><ul>';
            foreach (array_slice($manifest['previousVersions'], 0, 10) as $previous) {
                if (!is_array($previous)) continue;
                $previous_version = sanitize_key($previous['version'] ?? '');
                $previous_registered_at = sanitize_text_field($previous['registeredAt'] ?? '');
                $previous_content_url = esc_url_raw($previous['contentUrl'] ?? '');
                $previous_manifest_url = esc_url_raw($previous['manifestUrl'] ?? '');
                if (!$previous_version || !$previous_content_url || !$previous_manifest_url || false === strtotime($previous_registered_at)) continue;
                /* translators: 1: fixed publication version identifier. 2: UTC registration timestamp. */
                echo '<li>' . esc_html(sprintf(__('Version %1$s, registered %2$s UTC.', 'digitalownership-publication-verification'), $previous_version, gmdate('Y-m-d H:i:s', strtotime($previous_registered_at))));
                echo ' <a href="' . esc_url($previous_content_url) . '" target="_blank" rel="noopener">' . esc_html__('Read version', 'digitalownership-publication-verification') . '</a>';
                echo ' <a href="' . esc_url($previous_manifest_url) . '" target="_blank" rel="noopener">' . esc_html__('View evidence file', 'digitalownership-publication-verification') . '</a></li>';
            }
            echo '</ul></dd>';
        }
        echo '</dl></main></body></html>';
        exit;
    }

    private function send_public_json($contents, $immutable = true) {
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . (string) strlen($contents));
        header('Access-Control-Allow-Origin: https://digitalownership.squaredant.com');
        header('Access-Control-Allow-Methods: GET');
        header('Vary: Origin');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON evidence bytes must remain unchanged for verification.
        echo $contents;
        exit;
    }

    private function send_workflow_status($post_id, $version, $record) {
        // The account review page polls this endpoint after approving step 1.
        // Rate-limit the service refresh per publication version so a quiet site
        // does not depend on WordPress cron, while public polling stays bounded.
        $refresh_key = 'dovp_workflow_' . $post_id . '_' . md5($version);
        if (false === get_transient($refresh_key)) {
            set_transient($refresh_key, '1', 5);
            $this->process_publication_workflow($post_id, $version);
            list(, $record) = $this->record_for_version($post_id, $version);
        }
        $payload = array(
            'version' => $version,
            'content' => array(
                'approvalStatus' => sanitize_key($record['approvalStatus'] ?? ''),
                'registrationStatus' => sanitize_key($record['registrationStatus'] ?? ''),
            ),
            'manifest' => array(
                'prepared' => !empty($record['manifestDraft']),
                'approvalStatus' => sanitize_key($record['manifestApprovalStatus'] ?? ''),
                'registrationStatus' => sanitize_key($record['manifestRegistrationStatus'] ?? ''),
                'complete' => !empty($record['manifestUrl']),
            ),
        );
        if (!empty($record['manifestApprovalUrl']) && 'pending' === ($record['manifestApprovalStatus'] ?? '')) {
            $payload['nextApprovalUrl'] = $this->publication_approval_url($record['manifestApprovalUrl'], $post_id, $record, 'manifest');
        }
        $this->send_public_json(wp_json_encode($payload), false);
    }

    private function send_not_found() {
        status_header(404);
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode(array('error' => 'Publication artifact not found.'));
        exit;
    }

    public function add_meta_box() {
        foreach (array('post', 'page') as $post_type) {
            add_meta_box(
                'dovp-publication',
                __('DigitalOwnership publication evidence', 'digitalownership-publication-verification'),
                array($this, 'render_meta_box'),
                $post_type,
                'normal',
                'high'
            );
        }
    }

    public function render_meta_box($post) {
        $enabled = '1' === get_post_meta($post->ID, self::META_ENABLED, true);
        $is_public = $this->is_public_publication($post);
        $active_version = get_post_meta($post->ID, self::META_VERSION, true);
        $records = $this->publication_records($post->ID);
        $private_archive = $this->private_archive($post->ID);
        $record = $active_version && isset($records[$active_version]) ? $records[$active_version] : null;
        $withdrawn_evidence = $is_public && !$record && !empty($private_archive);
        if ($record && $this->ensure_manifest_draft_token($record)) {
            if (!empty($record['manifestApprovalUrl'])) {
                $record['manifestApprovalUrl'] = $this->publication_approval_url($record['manifestApprovalUrl'], $post->ID, $record, 'manifest');
            }
            $records[$active_version] = $record;
            update_post_meta($post->ID, self::META_VERSIONS, $records);
        }
        $source_changed = $record && !empty($record['sourceModifiedGmt']) && $record['sourceModifiedGmt'] !== get_post_modified_time('c', true, $post);

        wp_nonce_field('dovp_save_post', 'dovp_save_post_nonce');
        ?>
        <p>
            <label>
                <input type="checkbox" name="dovp_enabled" value="1" <?php checked($enabled); ?> />
                <?php esc_html_e('Use a fixed, verifiable version of this publication and show its public verification link.', 'digitalownership-publication-verification'); ?>
            </label>
        </p>
        <p class="description">
            <?php esc_html_e('This checkbox does not register anything. It enables the public verification link for this post after you save or update it. A fixed version captures the last saved page text. Later WordPress edits are not shown until you create and register a new fixed version.', 'digitalownership-publication-verification'); ?>
        </p>
        <?php if (!$is_public) : ?>
            <p class="description"><strong><?php esc_html_e('Step 1: Publish this post first.', 'digitalownership-publication-verification'); ?></strong> <?php esc_html_e('Fixed content and its public verification link can be created only for a publicly published, non-password-protected post or page. After publishing, enable the checkbox above and save once.', 'digitalownership-publication-verification'); ?></p>
        <?php elseif (!$enabled && !$record) : ?>
            <p class="description"><strong><?php esc_html_e('Step 1: Enable public verification.', 'digitalownership-publication-verification'); ?></strong> <?php esc_html_e('Select the checkbox above and choose Update. After the save, this box will offer Step 2: create the fixed version for account review.', 'digitalownership-publication-verification'); ?></p>
        <?php endif; ?>
        <?php if ($private_archive && current_user_can('manage_options')) : ?>
            <hr />
            <p><strong><?php esc_html_e('Private archived evidence', 'digitalownership-publication-verification'); ?></strong></p>
            <p class="description"><?php esc_html_e('These exact files are no longer public. Only WordPress administrators can download them.', 'digitalownership-publication-verification'); ?></p>
            <ul>
                <?php foreach ($private_archive as $archived_version => $entry) : ?>
                    <?php $has_content = is_array($entry) && isset($entry['contentBytes']); $has_manifest = is_array($entry) && isset($entry['manifestBytes']); ?>
                    <li>
                        <?php echo esc_html($archived_version); ?>
                        <?php if ($has_content) : ?>
                            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dovp_download_archived_evidence&post_id=' . $post->ID . '&version=' . rawurlencode($archived_version) . '&artifact=content'), 'dovp_download_archived_evidence_' . $post->ID . '_' . $archived_version . '_content')); ?>"><?php esc_html_e('Download fixed JSON', 'digitalownership-publication-verification'); ?></a>
                        <?php endif; ?>
                        <?php if ($has_manifest) : ?>
                            &nbsp;|&nbsp;<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dovp_download_archived_evidence&post_id=' . $post->ID . '&version=' . rawurlencode($archived_version) . '&artifact=manifest'), 'dovp_download_archived_evidence_' . $post->ID . '_' . $archived_version . '_manifest')); ?>"><?php esc_html_e('Download evidence file', 'digitalownership-publication-verification'); ?></a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($source_changed) : ?>
            <p style="color:#b32d2e;"><strong><?php esc_html_e('This post has changed since its fixed version was created. Create and register a new fixed version before presenting the changed text as verified.', 'digitalownership-publication-verification'); ?></strong></p>
        <?php elseif ($withdrawn_evidence) : ?>
            <p style="color:#b32d2e;"><strong><?php esc_html_e('A previously verified version was withdrawn when this post was no longer public. This public post has no current verified version. Create and register a new fixed version before presenting it as verified.', 'digitalownership-publication-verification'); ?></strong></p>
        <?php endif; ?>
        <?php if ($is_public && $enabled && (!$record || $source_changed)) : ?>
            <p>
                <a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dovp_generate_canonical&post_id=' . $post->ID), 'dovp_generate_canonical_' . $post->ID)); ?>">
                    <?php esc_html_e('Step 2: Create fixed version of saved content', 'digitalownership-publication-verification'); ?>
                </a>
            </p>
            <?php if (!$record) : ?><p class="description"><?php esc_html_e('This does not spend a credit. It creates the exact saved JSON version that the writer can send for account review.', 'digitalownership-publication-verification'); ?></p><?php endif; ?>
        <?php endif; ?>
        <?php if ($record && !$source_changed) : ?>
            <?php
            $content_completed = 'completed' === ($record['registrationStatus'] ?? '') && !empty($record['registrationResult']);
            $manifest_completed = !empty($record['manifestUrl']);
            $fixed_publication_url = $this->fixed_content_preview_url($record);
            $content_approval_terminal = in_array($record['approvalStatus'] ?? '', array('expired', 'rejected'), true);
            ?>
            <hr />
            <p><strong><?php esc_html_e('Current fixed version:', 'digitalownership-publication-verification'); ?></strong> <?php echo esc_html($active_version); ?></p>
            <?php if ($manifest_completed) : ?>
                <p><strong><?php esc_html_e('Verification:', 'digitalownership-publication-verification'); ?></strong> <?php esc_html_e('Complete. Readers can independently verify this version.', 'digitalownership-publication-verification'); ?></p>
            <?php else : ?>
                <p><strong><?php esc_html_e('Verification:', 'digitalownership-publication-verification'); ?></strong> <?php esc_html_e('Two registrations are needed: the fixed content first, then its separate public evidence file.', 'digitalownership-publication-verification'); ?></p>
            <?php endif; ?>
            <?php if (!$manifest_completed && get_option(self::OPTION_SITE_TOKEN, '')) : ?>
                <?php if (!$content_completed) : ?>
                    <h3><?php esc_html_e('Step 1 of 2: Register the fixed content', 'digitalownership-publication-verification'); ?></h3>
                    <?php if ((empty($record['approvalId']) || $content_approval_terminal) && empty($record['registrationId'])) : ?>
                        <?php if ($content_approval_terminal) : ?><p><?php esc_html_e('The previous review request was not approved. The writer can request a fresh review for this unchanged fixed version.', 'digitalownership-publication-verification'); ?></p><?php endif; ?>
                        <p><?php esc_html_e('This sends the saved version to the linked DigitalOwnership account for review. The account holder sees the rendered publication, the exact JSON file, and its SHA-512 fingerprint before deciding.', 'digitalownership-publication-verification'); ?></p>
                        <?php if ($fixed_publication_url) : ?><p><a class="button button-secondary" href="<?php echo esc_url($fixed_publication_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Read fixed publication version', 'digitalownership-publication-verification'); ?></a></p><?php endif; ?>
                        <p><button class="button button-primary" type="submit" form="dovp-action-form" formmethod="post" formnovalidate formaction="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dovp_request_publication_approval&post_id=' . $post->ID . '&version=' . rawurlencode($active_version)), 'dovp_request_publication_approval_' . $post->ID . '_' . $active_version)); ?>"><?php esc_html_e('Request account review of fixed publication', 'digitalownership-publication-verification'); ?></button></p>
                        <p class="description"><?php esc_html_e('The writer requests review. The account holder separately decides whether to approve registration of this exact fixed JSON file. One credit is spent only after approval.', 'digitalownership-publication-verification'); ?></p>
                    <?php else : ?>
                        <p><?php esc_html_e('Your review request is pending. The account holder reviews the fixed publication and decides whether to approve registration of its exact SHA-512 fingerprint.', 'digitalownership-publication-verification'); ?></p>
                        <?php if ($fixed_publication_url) : ?><p><a class="button button-secondary" href="<?php echo esc_url($fixed_publication_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Read fixed publication version', 'digitalownership-publication-verification'); ?></a></p><?php endif; ?>
                        <?php if (!empty($record['approvalUrl']) && 'approved' !== ($record['approvalStatus'] ?? '')) : ?><p><a class="button button-primary" href="<?php echo esc_url($record['approvalUrl']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open account-holder review of fixed publication', 'digitalownership-publication-verification'); ?></a></p><?php endif; ?>
                        <p><a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dovp_refresh_publication_approval&post_id=' . $post->ID . '&version=' . rawurlencode($active_version)), 'dovp_refresh_publication_approval_' . $post->ID . '_' . $active_version)); ?>"><?php esc_html_e('Refresh fixed-content registration status', 'digitalownership-publication-verification'); ?></a></p>
                        <?php if (!empty($record['approvalStatus'])) : ?><p><strong><?php esc_html_e('Approval status:', 'digitalownership-publication-verification'); ?></strong> <?php echo esc_html($record['approvalStatus']); ?></p><?php endif; ?>
                        <?php if (!empty($record['registrationStatus'])) : ?><p><strong><?php esc_html_e('Registration status:', 'digitalownership-publication-verification'); ?></strong> <?php echo esc_html($record['registrationStatus']); ?></p><?php endif; ?>
                        <?php if (!empty($record['registrationError'])) : ?><p style="color:#b32d2e;"><strong><?php esc_html_e('Registration error:', 'digitalownership-publication-verification'); ?></strong> <?php echo esc_html($record['registrationError']); ?></p><?php endif; ?>
                    <?php endif; ?>
                <?php else : ?>
                    <h3><?php esc_html_e('Step 1 of 2: Fixed content registered', 'digitalownership-publication-verification'); ?></h3>
                    <p><?php esc_html_e('The fixed publication has been registered. Choose whether readers should see links to any earlier public versions before requesting review of the public evidence file.', 'digitalownership-publication-verification'); ?></p>
                    <h3><?php esc_html_e('Step 2 of 2: Register the public evidence file', 'digitalownership-publication-verification'); ?></h3>
                    <?php if (empty($record['manifestDraft'])) : ?>
                        <?php $history_candidates = $this->public_history_candidates($post->ID, $active_version); ?>
                        <p><?php esc_html_e('The evidence file will contain the fixed-content URL, fingerprint, registration key, and transaction reference from step 1. You may also disclose selected earlier public versions to readers.', 'digitalownership-publication-verification'); ?></p>
                        <?php if ($history_candidates) : ?>
                            <fieldset>
                                <legend><strong><?php esc_html_e('Previous verified versions to show readers', 'digitalownership-publication-verification'); ?></strong></legend>
                                <p class="description"><?php esc_html_e('Selected versions will be linked from the public verification panel and recorded in this evidence file. Private or archived versions cannot be selected here.', 'digitalownership-publication-verification'); ?></p>
                                <?php foreach ($history_candidates as $candidate) : ?>
                                    <label style="display:block; margin:0.5em 0;">
                                        <input form="dovp-action-form" type="checkbox" name="dovp_previous_versions[]" value="<?php echo esc_attr($candidate['version']); ?>" />
                                        <?php /* translators: 1: fixed publication version identifier. 2: UTC registration timestamp. */ ?>
                                        <?php echo esc_html(sprintf(__('Version %1$s, registered %2$s UTC', 'digitalownership-publication-verification'), $candidate['version'], gmdate('Y-m-d H:i:s', strtotime($candidate['registeredAt'])))); ?>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <p>
                                <label for="dovp-revision-note"><strong><?php esc_html_e('Revision note (optional)', 'digitalownership-publication-verification'); ?></strong></label><br />
                                <textarea form="dovp-action-form" id="dovp-revision-note" name="dovp_revision_note" rows="3" maxlength="500" style="width:100%; max-width:640px;"></textarea>
                            </p>
                            <p class="description"><?php esc_html_e('For example: “Updated deadline and clarified eligibility criteria.” This note is public and becomes part of the registered evidence file.', 'digitalownership-publication-verification'); ?></p>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e('There are no earlier public registered versions available to disclose.', 'digitalownership-publication-verification'); ?></p>
                        <?php endif; ?>
                        <p><button class="button button-primary" type="submit" form="dovp-action-form" formmethod="post" formnovalidate formaction="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dovp_prepare_manifest&post_id=' . $post->ID . '&version=' . rawurlencode($active_version)), 'dovp_prepare_manifest_' . $post->ID . '_' . $active_version)); ?>"><?php esc_html_e('Request review of public evidence file', 'digitalownership-publication-verification'); ?></button></p>
                    <?php else : ?>
                        <p><?php esc_html_e('The public evidence file is ready. The account holder receives a second review where its exact file and SHA-512 fingerprint are shown.', 'digitalownership-publication-verification'); ?></p>
                        <?php if (empty($record['manifestApprovalId']) && empty($record['manifestRegistrationId'])) : ?>
                            <p><?php esc_html_e('Creating the account review request.', 'digitalownership-publication-verification'); ?></p>
                        <?php else : ?>
                            <?php if (!empty($record['manifestApprovalUrl']) && 'approved' !== ($record['manifestApprovalStatus'] ?? '')) : ?><p><a class="button button-primary" href="<?php echo esc_url($record['manifestApprovalUrl']); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open account-holder review of public evidence file', 'digitalownership-publication-verification'); ?></a></p><?php endif; ?>
                            <?php if (!empty($record['manifestApprovalStatus'])) : ?><p><strong><?php esc_html_e('Approval status:', 'digitalownership-publication-verification'); ?></strong> <?php echo esc_html($record['manifestApprovalStatus']); ?></p><?php endif; ?>
                            <?php if (!empty($record['manifestRegistrationStatus'])) : ?><p><strong><?php esc_html_e('Registration status:', 'digitalownership-publication-verification'); ?></strong> <?php echo esc_html($record['manifestRegistrationStatus']); ?></p><?php endif; ?>
                            <?php if (!empty($record['manifestRegistrationError'])) : ?><p style="color:#b32d2e;"><strong><?php esc_html_e('Registration error:', 'digitalownership-publication-verification'); ?></strong> <?php echo esc_html($record['manifestRegistrationError']); ?></p><?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>
                    <p><a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dovp_refresh_publication_approval&post_id=' . $post->ID . '&version=' . rawurlencode($active_version)), 'dovp_refresh_publication_approval_' . $post->ID . '_' . $active_version)); ?>"><?php esc_html_e('Refresh publication workflow', 'digitalownership-publication-verification'); ?></a></p>
                <?php endif; ?>
            <?php endif; ?>
            <p>
                <a href="<?php echo esc_url($record['contentUrl']); ?>" target="_blank" rel="noopener"><?php esc_html_e('View fixed content', 'digitalownership-publication-verification'); ?></a>
                <?php if (!empty($record['manifestUrl'])) : ?>
                    &nbsp;|&nbsp;<a href="<?php echo esc_url($record['manifestUrl']); ?>" target="_blank" rel="noopener"><?php esc_html_e('View evidence manifest', 'digitalownership-publication-verification'); ?></a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if ($records) : ?>
            <?php
            $version_history = $records;
            uksort($version_history, static function ($left, $right) {
                return strcmp((string) $right, (string) $left);
            });
            ?>
            <hr />
            <p><strong><?php esc_html_e('Publication version history', 'digitalownership-publication-verification'); ?></strong></p>
            <p class="description"><?php esc_html_e('Each link identifies a distinct fixed version. The active version is shown first; earlier public versions remain available as historical evidence.', 'digitalownership-publication-verification'); ?></p>
            <ul>
                <?php foreach ($version_history as $history_version => $history_record) : ?>
                    <?php
                    if (!is_array($history_record)) continue;
                    $created_at = sanitize_text_field($history_record['createdAt'] ?? '');
                    $created_timestamp = false === strtotime($created_at) ? 0 : strtotime($created_at);
                    $registration = is_array($history_record['registrationResult'] ?? null) ? $history_record['registrationResult'] : array();
                    $registered_at = sanitize_text_field($registration['registeredAt'] ?? '');
                    if (ctype_digit($registered_at)) $registered_at = gmdate('c', (int) $registered_at);
                    $registered_timestamp = false === strtotime($registered_at) ? 0 : strtotime($registered_at);
                    ?>
                    <li>
                        <strong><?php echo esc_html($history_version); ?></strong><?php if ($history_version === $active_version) : ?> <?php esc_html_e('(active)', 'digitalownership-publication-verification'); ?><?php endif; ?>
                        <?php if ($created_timestamp) : ?>
                            <?php /* translators: %s: UTC fixed-version timestamp. */ ?>
                            <?php echo esc_html(sprintf(__(' - fixed %s UTC', 'digitalownership-publication-verification'), gmdate('Y-m-d H:i:s', $created_timestamp))); ?>
                        <?php endif; ?>
                        <?php if ($registered_timestamp) : ?>
                            <?php /* translators: %s: UTC registration timestamp. */ ?>
                            <?php echo esc_html(sprintf(__(' - registered %s UTC', 'digitalownership-publication-verification'), gmdate('Y-m-d H:i:s', $registered_timestamp))); ?>
                        <?php endif; ?>
                        <br />
                        <?php if (!empty($history_record['contentUrl'])) : ?>
                            <a href="<?php echo esc_url($history_record['contentUrl']); ?>" target="_blank" rel="noopener"><?php esc_html_e('View fixed content', 'digitalownership-publication-verification'); ?></a>
                        <?php endif; ?>
                        <?php if (!empty($history_record['manifestUrl'])) : ?>
                            <?php if (!empty($history_record['contentUrl'])) : ?>&nbsp;|&nbsp;<?php endif; ?>
                            <a href="<?php echo esc_url($history_record['manifestUrl']); ?>" target="_blank" rel="noopener"><?php esc_html_e('View evidence manifest', 'digitalownership-publication-verification'); ?></a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php
    }

    public function render_action_form() {
        // Gutenberg and some themes do not expose a dependable enclosing form
        // for classic meta boxes. Keep this form outside the editor so every
        // evidence button has a stable POST target without nesting forms.
        echo '<form id="dovp-action-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:none"></form>';
    }

    public function refresh_evidence_box_after_editor_save() {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, array('post', 'page'), true)) {
            return;
        }

        // Gutenberg saves through the REST API without re-rendering classic
        // meta boxes. Refresh this box only after an explicit successful save.
        wp_enqueue_script('wp-data');
        $script = <<<'JS'
(function () {
    if (!window.wp || !window.wp.data || !window.wp.domReady) return;

    window.wp.domReady(function () {
        var wasSaving = false;
        var wasAutosaving = false;
        var refreshInProgress = false;

        function refreshEvidenceBox() {
            if (refreshInProgress) return;
            var currentBox = document.getElementById('dovp-publication');
            if (!currentBox || !window.fetch || !window.DOMParser) return;

            refreshInProgress = true;
            window.fetch(window.location.href, {
                credentials: 'same-origin',
                cache: 'no-store'
            }).then(function (response) {
                if (!response.ok) throw new Error('Could not refresh publication evidence.');
                return response.text();
            }).then(function (html) {
                var refreshedBox = new window.DOMParser().parseFromString(html, 'text/html').getElementById('dovp-publication');
                if (refreshedBox && currentBox.parentNode) {
                    currentBox.parentNode.replaceChild(refreshedBox, currentBox);
                }
            }).catch(function () {
                // The editor remains usable if this optional UI refresh fails.
            }).finally(function () {
                refreshInProgress = false;
            });
        }

        window.wp.data.subscribe(function () {
            var editor = window.wp.data.select('core/editor');
            if (!editor || 'function' !== typeof editor.isSavingPost) return;

            var isSaving = editor.isSavingPost();
            var isAutosaving = 'function' === typeof editor.isAutosavingPost' && editor.isAutosavingPost();
            var succeeded = 'function' === typeof editor.didPostSaveRequestSucceed' && editor.didPostSaveRequestSucceed();

            if (wasSaving && !wasAutosaving && !isSaving && !isAutosaving && succeeded) {
                refreshEvidenceBox();
            }
            wasSaving = isSaving;
            wasAutosaving = isAutosaving;
        });
    });
}());
JS;
        wp_add_inline_script('wp-data', $script, 'after');
    }

    public function save_post_settings($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || !in_array($post->post_type, array('post', 'page'), true)) {
            return;
        }
        if (empty($_POST['dovp_save_post_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dovp_save_post_nonce'])), 'dovp_save_post')) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (!$this->is_public_publication($post)) {
            $this->archive_publication_evidence($post_id);
            return;
        }

        update_post_meta($post_id, self::META_ENABLED, !empty($_POST['dovp_enabled']) ? '1' : '0');
        update_post_meta($post_id, self::META_FORMAT, 'json');
    }

    public function remove_evidence_for_non_public_post($post_id, $post_after, $post_before) {
        if (!$this->is_public_publication($post_after)) {
            $this->archive_publication_evidence($post_id);
        }
    }

    public function remove_publication_evidence($post_id) {
        $this->remove_active_publication_evidence($post_id);
        delete_post_meta(absint($post_id), self::META_PRIVATE_ARCHIVE);
    }

    private function archive_publication_evidence($post_id) {
        $post_id = absint($post_id);
        if (!$post_id) return;

        $records = $this->publication_records($post_id);
        if (!$records) return;
        $archive = $this->private_archive($post_id);
        foreach ($records as $version => $record) {
            if (!is_array($record)) continue;
            $entry = array('record' => $record, 'archivedAt' => gmdate('c'));
            $content_path = $this->safe_storage_path($record['contentPath'] ?? '');
            $manifest_path = $this->safe_storage_path($record['manifestPath'] ?? '');
            if ($content_path && is_readable($content_path)) $entry['contentBytes'] = file_get_contents($content_path);
            if ($manifest_path && is_readable($manifest_path)) $entry['manifestBytes'] = file_get_contents($manifest_path);
            $archive[sanitize_key($version)] = $entry;
        }
        update_post_meta($post_id, self::META_PRIVATE_ARCHIVE, $archive);
        $this->remove_active_publication_evidence($post_id);
    }

    private function remove_active_publication_evidence($post_id) {
        $post_id = absint($post_id);
        if (!$post_id) return;
        $records = $this->publication_records($post_id);
        foreach ($records as $record) {
            if (!is_array($record)) continue;
            foreach (array('contentPath', 'manifestPath') as $path_key) {
                $path = $this->safe_storage_path($record[$path_key] ?? '');
                if ($path && is_file($path)) wp_delete_file($path);
            }
        }
        $post_directory = trailingslashit($this->storage_root()) . $post_id;
        $this->remove_storage_directory($post_directory);

        delete_post_meta($post_id, self::META_ENABLED);
        delete_post_meta($post_id, self::META_FORMAT);
        delete_post_meta($post_id, self::META_VERSION);
        delete_post_meta($post_id, self::META_VERSIONS);
    }

    private function purge_non_public_publication_evidence() {
        // This activation/upgrade cleanup intentionally inspects only posts with evidence metadata.
        $post_ids = get_posts(array(
            'post_type' => array('post', 'page'),
            'post_status' => array('publish', 'future', 'draft', 'pending', 'private', 'trash'),
            'posts_per_page' => -1,
            'fields' => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs only during activation or upgrade to remove stale evidence.
            'meta_key' => self::META_VERSIONS,
            'no_found_rows' => true,
        ));
        foreach ($post_ids as $post_id) {
            if (!$this->is_public_publication(get_post($post_id))) {
                $this->archive_publication_evidence($post_id);
            }
        }
    }

    public function download_archived_evidence() {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        $version = isset($_GET['version']) ? sanitize_key(wp_unslash($_GET['version'])) : '';
        $artifact = isset($_GET['artifact']) ? sanitize_key(wp_unslash($_GET['artifact'])) : '';
        if (!$post_id || !current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot download archived publication evidence.', 'digitalownership-publication-verification'), 403);
        }
        check_admin_referer('dovp_download_archived_evidence_' . $post_id . '_' . $version . '_' . $artifact);
        $archive = $this->private_archive($post_id);
        $entry = $archive[$version] ?? null;
        $key = 'content' === $artifact ? 'contentBytes' : ('manifest' === $artifact ? 'manifestBytes' : '');
        if (!is_array($entry) || !$key || !isset($entry[$key]) || !is_string($entry[$key])) {
            wp_die(esc_html__('The requested archived evidence file is unavailable.', 'digitalownership-publication-verification'), 404);
        }
        $filename = 'content' === $artifact ? 'fixed-content-' . $version . '.json' : 'publication-manifest-' . $version . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Length: ' . (string) strlen($entry[$key]));
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Administrator-requested archived JSON is downloaded verbatim.
        echo $entry[$key];
        exit;
    }

    public function generate_canonical() {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('You cannot generate publication evidence for this post.', 'digitalownership-publication-verification'), 403);
        }
        check_admin_referer('dovp_generate_canonical_' . $post_id);

        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, array('post', 'page'), true)) {
            $this->redirect_with_notice($post_id, 'invalid-post');
        }
        if (!$this->is_public_publication($post)) {
            wp_die(esc_html__('Publication evidence can be created only for a publicly published post or page without password protection.', 'digitalownership-publication-verification'), 403);
        }
        $format = 'json';
        // Route values are normalized by WordPress, so use a URL-safe lowercase
        // identifier for both the stored record and its public artifact URL.
        $version = sanitize_key(gmdate('Y-m-d\\THis\\Z'));
        $directory = $this->version_directory($post_id, $version);
        if (!wp_mkdir_p($directory)) {
            $this->redirect_with_notice($post_id, 'storage-error');
        }

        $content_path = trailingslashit($directory) . 'content.' . $format;
        if (file_exists($content_path)) {
            $this->redirect_with_notice($post_id, 'already-exists');
        }

        $body_html = $this->snapshot_post_body($post);
        $content_url = $this->artifact_url($post_id, $version, 'content', $format);
        $image_evidence = $this->fixed_image_evidence($post_id, $version, $body_html);
        $artifact = 'json' === $format
            ? $this->canonical_json($post, $version, $content_url, $image_evidence)
            : $this->canonical_markdown($post, $version, $content_url, $body_html);

        if (false === file_put_contents($content_path, $artifact, LOCK_EX)) {
            $this->redirect_with_notice($post_id, 'storage-error');
        }

        $records = $this->publication_records($post_id);
        $record = array(
            'version' => $version,
            'format' => $format,
            'contentPath' => $this->relative_storage_path($content_path),
            'contentUrl' => $content_url,
            'assetSources' => $image_evidence['sources'],
            'sourceModifiedGmt' => get_post_modified_time('c', true, $post),
            'createdAt' => gmdate('c'),
        );
        $supersedes = $this->previous_registered_version($post_id);
        if ($supersedes) $record['supersedes'] = $supersedes;
        $records[$version] = $record;
        update_post_meta($post_id, self::META_VERSIONS, $records);
        update_post_meta($post_id, self::META_VERSION, $version);
        update_post_meta($post_id, self::META_ENABLED, '1');
        $this->redirect_with_notice($post_id, 'generated');
    }

    public function add_admin_pages() {
        add_options_page(
            __('DigitalOwnership Publication Verification', 'digitalownership-publication-verification'),
            __('DigitalOwnership Verification', 'digitalownership-publication-verification'),
            'manage_options',
            'dovp-settings',
            array($this, 'render_settings_page')
        );
    }

    public function render_admin_notice() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This value selects a predefined notice and performs no action.
        $notice = isset($_GET['dovp_notice']) ? sanitize_key(wp_unslash($_GET['dovp_notice'])) : '';
        $messages = array(
            'approval-requested' => array('success', __('Review request sent. The DigitalOwnership account holder can read the fixed publication version and approve or decline its exact fingerprint. Return here afterwards to refresh the status.', 'digitalownership-publication-verification')),
            'approval-refreshed' => array('success', __('Approval status refreshed.', 'digitalownership-publication-verification')),
            'manifest-prepared' => array('success', __('Public manifest draft created. Review the public email and SHA-512 in the evidence box, then request a separate account approval.', 'digitalownership-publication-verification')),
            'manifest-approval-requested' => array('success', __('Public evidence file review requested. The DigitalOwnership account holder can approve or decline its exact fingerprint. Return here afterwards to refresh the status.', 'digitalownership-publication-verification')),
            'manifest-published' => array('success', __('Manifest registration completed and the public verification link is now active.', 'digitalownership-publication-verification')),
            'approval-unavailable' => array('error', __('The WordPress site is not connected, or this fixed version is unavailable.', 'digitalownership-publication-verification')),
            'approval-failed' => array('error', __('DigitalOwnership could not create the approval request. Check the linked account, available credits, and service status.', 'digitalownership-publication-verification')),
            'approval-refresh-failed' => array('error', __('DigitalOwnership could not retrieve the approval status. Check the linked account connection and try again.', 'digitalownership-publication-verification')),
            'manifest-unavailable' => array('error', __('The fixed version must be registered before its public evidence file can be created.', 'digitalownership-publication-verification')),
            'manifest-failed' => array('error', __('DigitalOwnership could not prepare or register the manifest. Check the linked account, available credits, and service status.', 'digitalownership-publication-verification')),
        );
        if (!$notice || empty($messages[$notice])) return;
        list($type, $message) = $messages[$notice];
        printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($type), esc_html($message));
    }

    public function render_settings_page() {
        $settings = get_option(self::OPTION_SETTINGS, array());
        $verifier = $settings['verifier_url'] ?? 'https://digitalownership.squaredant.com/verify-publication';
        $site_token = get_option(self::OPTION_SITE_TOKEN, '');
        $account_url = $this->account_link_url($site_token);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('DigitalOwnership Publication Verification', 'digitalownership-publication-verification'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('dovp_settings_group'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="dovp-verifier-url"><?php esc_html_e('Independent verifier URL', 'digitalownership-publication-verification'); ?></label></th>
                        <td>
                            <input class="regular-text code" id="dovp-verifier-url" name="<?php echo esc_attr(self::OPTION_SETTINGS); ?>[verifier_url]" type="url" value="<?php echo esc_attr($verifier); ?>" />
                            <p class="description"><?php esc_html_e('Readers are sent here to verify a published manifest. Use the production verifier unless testing a separate environment.', 'digitalownership-publication-verification'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            <hr />
            <h2><?php esc_html_e('DigitalOwnership account connection', 'digitalownership-publication-verification'); ?></h2>
            <?php if ($site_token) : ?>
                <p><?php esc_html_e('This WordPress installation has a site-local DigitalOwnership device token. Keep WordPress administrator access restricted. You can revoke this connection from your DigitalOwnership account at any time.', 'digitalownership-publication-verification'); ?></p>
                <p><a class="button button-secondary" href="<?php echo esc_url($account_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open account connection', 'digitalownership-publication-verification'); ?></a></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('dovp_disconnect_account'); ?>
                    <input type="hidden" name="action" value="dovp_disconnect_account" />
                    <p><button class="button button-link-delete" type="submit"><?php esc_html_e('Remove this site token', 'digitalownership-publication-verification'); ?></button></p>
                </form>
            <?php else : ?>
                <p><?php esc_html_e('Connect this WordPress installation to a DigitalOwnership account before using in-dashboard registration. The account owner approves the connection on DigitalOwnership.', 'digitalownership-publication-verification'); ?></p>
                <p class="description"><?php esc_html_e('Use an organisation-owned publishing account, such as publications@company.example, rather than a personal email address. Its registration email is included in the public evidence manifest for independently verifiable publications.', 'digitalownership-publication-verification'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('dovp_connect_account'); ?>
                    <input type="hidden" name="action" value="dovp_connect_account" />
                    <p><button class="button button-primary" type="submit"><?php esc_html_e('Connect DigitalOwnership account', 'digitalownership-publication-verification'); ?></button></p>
                </form>
            <?php endif; ?>
            <hr />
            <h2><?php esc_html_e('Who does what', 'digitalownership-publication-verification'); ?></h2>
            <p><?php esc_html_e('This workflow separates publishing preparation from credit-spending approval.', 'digitalownership-publication-verification'); ?></p>
            <ul style="list-style:disc; margin-left:1.5em;">
                <li><?php esc_html_e('WordPress Editors write, save, and create fixed versions. They can request account approval.', 'digitalownership-publication-verification'); ?></li>
                <li><?php esc_html_e('WordPress Administrators connect or remove this site from DigitalOwnership.', 'digitalownership-publication-verification'); ?></li>
                <li><?php esc_html_e('The DigitalOwnership account holder reviews the fixed content and fingerprint, then approves registrations that spend credits.', 'digitalownership-publication-verification'); ?></li>
            </ul>
            <p class="description"><?php esc_html_e('Use an organisation-owned publishing account, such as publications@company.example. Keep its access and any pipeline credentials with the responsible senior publisher or administrator. Editors do not need a DigitalOwnership account.', 'digitalownership-publication-verification'); ?></p>
        </div>
        <?php
    }

    public function connect_account() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot connect this WordPress installation.', 'digitalownership-publication-verification'), 403);
        }
        check_admin_referer('dovp_connect_account');
        $token = get_option(self::OPTION_SITE_TOKEN, '');
        if (!$token) {
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            update_option(self::OPTION_SITE_TOKEN, $token, false);
        }
        // The account-approval page is deliberately hosted on the independent
        // DigitalOwnership origin, so WordPress's same-host-only redirect is
        // not appropriate here.
        // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The authenticated account-link flow is deliberately hosted on the independent service origin.
        wp_redirect($this->account_link_url($token), 302, 'DigitalOwnership Publication Verification');
        exit;
    }

    public function disconnect_account() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot remove this WordPress site token.', 'digitalownership-publication-verification'), 403);
        }
        check_admin_referer('dovp_disconnect_account');
        delete_option(self::OPTION_SITE_TOKEN);
        wp_safe_redirect(add_query_arg(array('page' => 'dovp-settings', 'dovp_notice' => 'account-disconnected'), admin_url('options-general.php')));
        exit;
    }

    public function request_publication_approval() {
        $post_id = isset($_REQUEST['post_id']) ? absint($_REQUEST['post_id']) : 0;
        $version = isset($_REQUEST['version']) ? sanitize_key(wp_unslash($_REQUEST['version'])) : '';
        if (!$post_id || !current_user_can('edit_post', $post_id)) wp_die(esc_html__('You cannot request publication approval.', 'digitalownership-publication-verification'), 403);
        check_admin_referer('dovp_request_publication_approval_' . $post_id . '_' . $version);
        list($stored_version, $record) = $this->record_for_version($post_id, $version);
        $token = get_option(self::OPTION_SITE_TOKEN, '');
        $path = is_array($record) ? $this->safe_storage_path($record['contentPath'] ?? '') : false;
        if (!$token || !$path || !is_readable($path)) $this->redirect_with_notice($post_id, 'approval-unavailable');
        $approval_terminal = in_array($record['approvalStatus'] ?? '', array('expired', 'rejected'), true);
        $approval_attempt = sanitize_text_field($record['approvalAttempt'] ?? '');
        if (!$approval_attempt || $approval_terminal) {
            $approval_attempt = wp_generate_uuid4();
        }
        $response = wp_remote_post(self::CREDIT_SERVICE_URL . '/approvals', array('timeout' => 20, 'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'), 'body' => wp_json_encode(array('sourceHash' => hash_file('sha512', $path), 'hashScope' => 'digitalownership-exact-file-v1', 'registryAddress' => self::REGISTRY_ADDRESS, 'chainId' => self::CHAIN_ID, 'approvalMode' => 'required', 'immediateExecutionAcknowledged' => true, 'idempotencyKey' => 'wordpress-' . $post_id . '-' . $stored_version . '-' . $approval_attempt))));
        $body = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
        $status = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        if (201 !== $status || empty($body['approval']['id']) || empty($body['approval']['approvalUrl'])) $this->redirect_with_notice($post_id, 'approval-failed');
        $record['approvalId'] = $body['approval']['id'];
        $record['approvalUrl'] = $this->publication_approval_url($body['approval']['approvalUrl'], $post_id, $record);
        $record['approvalStatus'] = $body['approval']['status'] ?? 'pending';
        $record['approvalAttempt'] = $approval_attempt;
        unset($record['registrationId'], $record['registrationStatus'], $record['registrationError'], $record['registrationResult']);
        $records = $this->publication_records($post_id); $records[$stored_version] = $record; update_post_meta($post_id, self::META_VERSIONS, $records);
        $this->schedule_publication_workflow($post_id, $stored_version, 15);
        $this->redirect_with_notice($post_id, 'approval-requested');
    }

    public function refresh_publication_approval() {
        $post_id = isset($_REQUEST['post_id']) ? absint($_REQUEST['post_id']) : 0;
        $version = isset($_REQUEST['version']) ? sanitize_key(wp_unslash($_REQUEST['version'])) : '';
        if (!$post_id || !current_user_can('edit_post', $post_id)) wp_die(esc_html__('You cannot check publication approval.', 'digitalownership-publication-verification'), 403);
        check_admin_referer('dovp_refresh_publication_approval_' . $post_id . '_' . $version);
        $this->process_publication_workflow($post_id, $version);
        list($stored_version, $record) = $this->record_for_version($post_id, $version);
        $this->redirect_with_notice($post_id, !empty($record['manifestUrl']) ? 'manifest-published' : 'approval-refreshed');
    }

    public function process_publication_workflow($post_id, $version) {
        $post_id = absint($post_id);
        $version = sanitize_key($version);
        list($stored_version, $record) = $this->record_for_version($post_id, $version);
        $token = get_option(self::OPTION_SITE_TOKEN, '');
        if (!$post_id || !$stored_version || !is_array($record) || !$token) {
            return;
        }

        $this->ensure_manifest_draft_token($record);
        $this->refresh_approval_and_registration($token, $post_id, $record, 'content');
        if (!empty($record['manifestDraft']) && empty($record['manifestApprovalId']) && empty($record['manifestRegistrationId'])) {
            $this->create_manifest_approval($post_id, $stored_version, $record, $token);
        }
        $this->refresh_approval_and_registration($token, $post_id, $record, 'manifest');

        $records = $this->publication_records($post_id);
        $records[$stored_version] = $record;
        update_post_meta($post_id, self::META_VERSIONS, $records);

        $content_open = in_array($record['approvalStatus'] ?? '', array('pending', 'approved'), true) && 'completed' !== ($record['registrationStatus'] ?? '');
        $manifest_open = !empty($record['manifestDraft']) && in_array($record['manifestApprovalStatus'] ?? '', array('pending', 'approved'), true) && empty($record['manifestUrl']);
        if ($content_open || $manifest_open) {
            $this->schedule_publication_workflow($post_id, $stored_version, 30);
        }
    }

    private function refresh_approval_and_registration($token, $post_id, &$record, $kind) {
        $is_manifest = 'manifest' === $kind;
        $approval_id_key = $is_manifest ? 'manifestApprovalId' : 'approvalId';
        $approval_status_key = $is_manifest ? 'manifestApprovalStatus' : 'approvalStatus';
        $approval_url_key = $is_manifest ? 'manifestApprovalUrl' : 'approvalUrl';
        $registration_id_key = $is_manifest ? 'manifestRegistrationId' : 'registrationId';
        $registration_status_key = $is_manifest ? 'manifestRegistrationStatus' : 'registrationStatus';
        $registration_error_key = $is_manifest ? 'manifestRegistrationError' : 'registrationError';
        $registration_result_key = $is_manifest ? 'manifestRegistrationResult' : 'registrationResult';
        $registration_id = sanitize_text_field($record[$registration_id_key] ?? '');

        if (!empty($record[$approval_id_key])) {
            $approval = $this->linked_service_get('/approvals/' . rawurlencode($record[$approval_id_key]), $token);
            if (!empty($approval['approval']) && is_array($approval['approval'])) {
                $record[$approval_status_key] = sanitize_key($approval['approval']['status'] ?? '');
                $record[$approval_url_key] = $this->publication_approval_url($approval['approval']['approvalUrl'] ?? $record[$approval_url_key] ?? '', $post_id, $record, $kind);
                $registration_id = sanitize_text_field($approval['approval']['registrationRequestId'] ?? '');
            }
        }
        if (!$registration_id) {
            return;
        }
        $record[$registration_id_key] = $registration_id;
        $registration = $this->linked_service_get('/registrations/' . rawurlencode($registration_id), $token);
        if (empty($registration['registration']) || !is_array($registration['registration'])) {
            return;
        }
        $result = $registration['registration'];
        $record[$registration_status_key] = sanitize_key($result['status'] ?? '');
        $record[$registration_error_key] = sanitize_text_field($result['error'] ?? '');
        if ('completed' !== $record[$registration_status_key]) {
            return;
        }
        if ($is_manifest) {
            if ($this->publish_completed_manifest($post_id, sanitize_key($record['version'] ?? ''), $record, $result)) {
                $record[$registration_error_key] = '';
            } else {
                $record[$registration_error_key] = __('The completed registration did not match the prepared public evidence file.', 'digitalownership-publication-verification');
            }
            return;
        }
        if (!empty($result['result']) && is_array($result['result'])) {
            $record[$registration_result_key] = $this->public_registration_result($result['result']);
        }
    }

    private function prepare_manifest_draft($post_id, $version, &$record, $token, $previous_versions = array(), $revision_note = '') {
        $content_path = $this->safe_storage_path($record['contentPath'] ?? '');
        $registration = is_array($record['registrationResult'] ?? null) ? $record['registrationResult'] : array();
        if (!$content_path || !is_readable($content_path) || 'completed' !== ($record['registrationStatus'] ?? '') || !$this->valid_public_registration($registration)) {
            return false;
        }
        $identity = $this->linked_service_get('/publication-identity', $token);
        $email = strtolower(sanitize_email($identity['registrationEmail'] ?? ''));
        if (!is_email($email)) {
            return false;
        }
        $post = get_post($post_id);
        $publisher = sanitize_text_field(get_bloginfo('name'));
        if (!$publisher) $publisher = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'Publisher';
        $manifest = array(
            'schema' => self::MANIFEST_SCHEMA,
            'publisher' => $publisher,
            'registrationEmail' => $email,
            'publishedAt' => gmdate('c'),
            'files' => array(array(
                'label' => sanitize_text_field(get_the_title($post)),
                'url' => $record['contentUrl'],
                'sha512' => strtolower(hash_file('sha512', $content_path)),
                'hashScope' => 'digitalownership-exact-file-v1',
                'registryKey' => strtolower($registration['registryKey']),
                'transactionHash' => strtolower($registration['transactionHash']),
            )),
        );
        if ($previous_versions) {
            $manifest['previousVersions'] = $previous_versions;
        }
        $revision_note = sanitize_textarea_field($revision_note);
        if ($revision_note) {
            $manifest['revisionNote'] = $revision_note;
        }
        $selected_versions = wp_list_pluck($previous_versions, 'version');
        if (!empty($record['supersedes']) && is_array($record['supersedes']) && in_array($record['supersedes']['version'] ?? '', $selected_versions, true)) {
            $manifest['supersedes'] = $record['supersedes'];
        }
        if (!$this->manifest_matches_content($manifest, $record['contentUrl'], $content_path)) {
            return false;
        }
        $contents = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        $record['manifestDraft'] = $contents;
        $record['manifestDraftSha512'] = hash('sha512', $contents);
        $record['manifestDraftToken'] = wp_generate_password(32, false, false);
        $record['manifestRegistrationEmail'] = $email;
        $record['manifestPublishedAt'] = $manifest['publishedAt'];
        $record['previousVersions'] = $previous_versions;
        $record['revisionNote'] = $revision_note;
        unset($record['manifestApprovalId'], $record['manifestApprovalUrl'], $record['manifestApprovalStatus'], $record['manifestRegistrationId'], $record['manifestRegistrationStatus'], $record['manifestRegistrationError'], $record['manifestRegistrationResult']);
        return true;
    }

    private function create_manifest_approval($post_id, $version, &$record, $token) {
        $contents = (string) ($record['manifestDraft'] ?? '');
        $expected_hash = strtolower(sanitize_text_field($record['manifestDraftSha512'] ?? ''));
        if (!$contents || !$expected_hash || !hash_equals($expected_hash, hash('sha512', $contents))) {
            return false;
        }
        $response = wp_remote_post(self::CREDIT_SERVICE_URL . '/approvals', array('timeout' => 20, 'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'), 'body' => wp_json_encode(array('sourceHash' => $expected_hash, 'hashScope' => 'digitalownership-exact-file-v1', 'registryAddress' => self::REGISTRY_ADDRESS, 'chainId' => self::CHAIN_ID, 'approvalMode' => 'required', 'immediateExecutionAcknowledged' => true, 'idempotencyKey' => 'wordpress-manifest-' . $post_id . '-' . $version))));
        $body = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
        if (201 !== (is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response)) || empty($body['approval']['id']) || empty($body['approval']['approvalUrl'])) {
            return false;
        }
        $record['manifestApprovalId'] = $body['approval']['id'];
        $record['manifestApprovalUrl'] = $this->publication_approval_url($body['approval']['approvalUrl'], $post_id, $record, 'manifest');
        $record['manifestApprovalStatus'] = sanitize_key($body['approval']['status'] ?? 'pending');
        return true;
    }

    private function schedule_publication_workflow($post_id, $version, $delay) {
        $args = array(absint($post_id), sanitize_key($version));
        if (!wp_next_scheduled('dovp_process_publication_workflow', $args)) {
            wp_schedule_single_event(time() + max(5, absint($delay)), 'dovp_process_publication_workflow', $args);
        }
    }

    public function prepare_manifest() {
        $post_id = isset($_REQUEST['post_id']) ? absint($_REQUEST['post_id']) : 0;
        $version = isset($_REQUEST['version']) ? sanitize_key(wp_unslash($_REQUEST['version'])) : '';
        if (!$post_id || !current_user_can('edit_post', $post_id)) wp_die(esc_html__('You cannot prepare this manifest.', 'digitalownership-publication-verification'), 403);
        check_admin_referer('dovp_prepare_manifest_' . $post_id . '_' . $version);

        list($stored_version, $record) = $this->record_for_version($post_id, $version);
        $token = get_option(self::OPTION_SITE_TOKEN, '');
        $content_path = is_array($record) ? $this->safe_storage_path($record['contentPath'] ?? '') : false;
        $registration = is_array($record['registrationResult'] ?? null) ? $record['registrationResult'] : array();
        if (!$token || !$content_path || !is_readable($content_path) || 'completed' !== ($record['registrationStatus'] ?? '') || !$this->valid_public_registration($registration)) {
            $this->redirect_with_notice($post_id, 'manifest-unavailable');
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_admin_referer() above verifies this request.
        $selected_versions = isset($_POST['dovp_previous_versions']) && is_array($_POST['dovp_previous_versions']) ? array_map('sanitize_key', wp_unslash($_POST['dovp_previous_versions'])) : array();
        $previous_versions = $this->selected_public_history_versions($post_id, $stored_version, $selected_versions);
        $revision_note = isset($_POST['dovp_revision_note']) ? sanitize_textarea_field(wp_unslash($_POST['dovp_revision_note'])) : '';
        if (!$this->prepare_manifest_draft($post_id, $stored_version, $record, $token, $previous_versions, $revision_note)) {
            $this->redirect_with_notice($post_id, 'manifest-failed');
        }
        if (!$this->create_manifest_approval($post_id, $stored_version, $record, $token)) {
            $this->redirect_with_notice($post_id, 'manifest-failed');
        }
        $records = $this->publication_records($post_id);
        $records[$stored_version] = $record;
        update_post_meta($post_id, self::META_VERSIONS, $records);
        $this->schedule_publication_workflow($post_id, $stored_version, 15);
        $this->redirect_with_notice($post_id, 'manifest-approval-requested');
    }

    public function request_manifest_approval() {
        $post_id = isset($_REQUEST['post_id']) ? absint($_REQUEST['post_id']) : 0;
        $version = isset($_REQUEST['version']) ? sanitize_key(wp_unslash($_REQUEST['version'])) : '';
        if (!$post_id || !current_user_can('edit_post', $post_id)) wp_die(esc_html__('You cannot request manifest approval.', 'digitalownership-publication-verification'), 403);
        check_admin_referer('dovp_request_manifest_approval_' . $post_id . '_' . $version);

        list($stored_version, $record) = $this->record_for_version($post_id, $version);
        $token = get_option(self::OPTION_SITE_TOKEN, '');
        $contents = is_array($record) ? (string) ($record['manifestDraft'] ?? '') : '';
        $expected_hash = strtolower(sanitize_text_field($record['manifestDraftSha512'] ?? ''));
        if (!$token || !$contents || !hash_equals($expected_hash, hash('sha512', $contents))) {
            $this->redirect_with_notice($post_id, 'manifest-unavailable');
        }
        $response = wp_remote_post(self::CREDIT_SERVICE_URL . '/approvals', array('timeout' => 20, 'headers' => array('Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'), 'body' => wp_json_encode(array('sourceHash' => $expected_hash, 'hashScope' => 'digitalownership-exact-file-v1', 'registryAddress' => self::REGISTRY_ADDRESS, 'chainId' => self::CHAIN_ID, 'approvalMode' => 'required', 'immediateExecutionAcknowledged' => true, 'idempotencyKey' => 'wordpress-manifest-' . $post_id . '-' . $stored_version . '-' . floor(time() / 300)))));
        $body = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
        $status = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        if (201 !== $status || empty($body['approval']['id']) || empty($body['approval']['approvalUrl'])) $this->redirect_with_notice($post_id, 'manifest-failed');
        $record['manifestApprovalId'] = $body['approval']['id'];
        $record['manifestApprovalUrl'] = $this->publication_approval_url($body['approval']['approvalUrl'], $post_id, $record, 'manifest');
        $record['manifestApprovalStatus'] = sanitize_key($body['approval']['status'] ?? 'pending');
        $records = $this->publication_records($post_id);
        $records[$stored_version] = $record;
        update_post_meta($post_id, self::META_VERSIONS, $records);
        $this->schedule_publication_workflow($post_id, $stored_version, 15);
        $this->redirect_with_notice($post_id, 'manifest-approval-requested');
    }

    public function render_attach_manifest_page() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This read-only admin screen checks edit capability before rendering.
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This read-only admin screen checks edit capability before rendering.
        $version = isset($_GET['version']) ? sanitize_key(wp_unslash($_GET['version'])) : '';
        list($stored_version, $record) = $this->record_for_version($post_id, $version);
        if (!$post_id || !current_user_can('edit_post', $post_id) || !is_array($record)) {
            wp_die(esc_html__('The requested publication version is unavailable.', 'digitalownership-publication-verification'), 404);
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Attach registered publication manifest', 'digitalownership-publication-verification'); ?></h1>
            <p><?php esc_html_e('Create and register the manifest with the DigitalOwnership CLI first. Upload only the completed public manifest, never a private receipt.', 'digitalownership-publication-verification'); ?></p>
            <p><strong><?php esc_html_e('Fixed-content URL:', 'digitalownership-publication-verification'); ?></strong><br /><code><?php echo esc_html($record['contentUrl']); ?></code></p>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('dovp_attach_manifest_' . $post_id . '_' . $version); ?>
                <input type="hidden" name="action" value="dovp_attach_manifest" />
                <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>" />
                <input type="hidden" name="version" value="<?php echo esc_attr($stored_version); ?>" />
                <p><input type="file" name="dovp_manifest" accept="application/json,.json" required /></p>
                <p><button class="button button-primary" type="submit"><?php esc_html_e('Attach manifest', 'digitalownership-publication-verification'); ?></button></p>
            </form>
        </div>
        <?php
    }

    public function attach_manifest() {
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        $version = isset($_POST['version']) ? sanitize_key(wp_unslash($_POST['version'])) : '';
        if (!$post_id || !$version || !current_user_can('edit_post', $post_id)) {
            wp_die(esc_html__('You cannot attach this manifest.', 'digitalownership-publication-verification'), 403);
        }
        check_admin_referer('dovp_attach_manifest_' . $post_id . '_' . $version);
        list($stored_version, $record) = $this->record_for_version($post_id, $version);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File metadata is validated below after check_admin_referer().
        $upload = $_FILES['dovp_manifest'] ?? null;
        if (!is_array($record) || !is_array($upload) || UPLOAD_ERR_OK !== (int) $upload['error'] || (int) $upload['size'] > 1024 * 1024) {
            $this->redirect_with_notice($post_id, 'manifest-invalid');
        }

        // This is an exact-file registration. Validate the JSON structure but
        // retain the uploaded bytes so its registered SHA-512 remains valid.
        $contents = file_get_contents($upload['tmp_name']);
        if (false === $contents) {
            $this->redirect_with_notice($post_id, 'manifest-invalid');
        }
        $manifest = json_decode($contents, true);
        $content_path = $this->safe_storage_path($record['contentPath'] ?? '');
        if (!$this->manifest_matches_content($manifest, $record['contentUrl'], $content_path)) {
            $this->redirect_with_notice($post_id, 'manifest-mismatch');
        }

        $directory = $this->version_directory($post_id, $stored_version);
        if (!wp_mkdir_p($directory) || false === file_put_contents(trailingslashit($directory) . 'manifest.json', $contents, LOCK_EX)) {
            $this->redirect_with_notice($post_id, 'storage-error');
        }
        $record['manifestPath'] = $this->relative_storage_path(trailingslashit($directory) . 'manifest.json');
        $record['manifestUrl'] = $this->artifact_url($post_id, $stored_version, 'manifest', 'json');
        $records = $this->publication_records($post_id);
        $records[$stored_version] = $record;
        update_post_meta($post_id, self::META_VERSIONS, $records);
        $this->redirect_with_notice($post_id, 'manifest-attached');
    }

    public function render_canonical_content($content) {
        // the_content can run with a different global post context. On a
        // singular page, the queried object is the publication being shown.
        $post_id = is_singular() ? get_queried_object_id() : get_the_ID();
        if (!$post_id) {
            return $this->diagnostic_content($content, 'no-post');
        }
        if ('1' !== get_post_meta($post_id, self::META_ENABLED, true)) {
            return $this->diagnostic_content($content, 'evidence-disabled');
        }
        $record = $this->active_record($post_id);
        if (!$record) {
            return $this->diagnostic_content($content, 'no-active-record');
        }
        $path = $this->safe_storage_path($record['contentPath'] ?? '');
        if (!$path || !is_readable($path)) {
            return $this->diagnostic_content($content, 'canonical-file-unreadable');
        }
        $source = file_get_contents($path);
        $rendered = 'json' === $record['format'] ? $this->render_json_content($source) : $this->render_markdown_content($source);
        if (null === $rendered) {
            return $this->diagnostic_content($content, 'canonical-file-invalid');
        }
        return $this->diagnostic_content($rendered . $this->verification_panel($record), 'canonical-content-rendered');
    }

    public function add_discovery_links() {
        if (!is_singular()) {
            return;
        }
        $record = $this->active_record(get_queried_object_id());
        if (!$record) {
            return;
        }
        $type = 'md' === $record['format'] ? 'text/markdown' : 'application/json';
        printf("\n<link rel=\"alternate\" type=\"%s\" href=\"%s\" title=\"Canonical publication content\" />\n", esc_attr($type), esc_url($record['contentUrl']));
        if (!empty($record['manifestUrl'])) {
            printf("<link rel=\"provenance\" type=\"application/json\" href=\"%s\" title=\"DigitalOwnership publication manifest\" />\n", esc_url($record['manifestUrl']));
            printf("<link rel=\"alternate\" type=\"application/vnd.digitalownership.manifest+json\" href=\"%s\" title=\"DigitalOwnership publication manifest\" />\n", esc_url($record['manifestUrl']));
        }

        $post = get_post();
        if (!$post) {
            return;
        }
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'page' === $post->post_type ? 'WebPage' : 'Article',
            'headline' => get_the_title($post),
            'datePublished' => get_post_time('c', true, $post),
            'dateModified' => get_post_modified_time('c', true, $post),
            'mainEntityOfPage' => get_permalink($post),
            'author' => array('@type' => 'Person', 'name' => get_the_author_meta('display_name', $post->post_author)),
            'encoding' => array(
                '@type' => 'MediaObject',
                'contentUrl' => $record['contentUrl'],
                'encodingFormat' => $type,
            ),
            'subjectOf' => !empty($record['manifestUrl']) ? array(
                '@type' => 'WebPage',
                'name' => 'DigitalOwnership publication verification',
                'url' => $this->verification_url($record['manifestUrl']),
            ) : null,
        );
        $schema = array_filter($schema, static function ($value) { return null !== $value; });
        printf("<script type=\"application/ld+json\">%s</script>\n", wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP));
    }

    public function register_settings() {
        register_setting('dovp_settings_group', self::OPTION_SETTINGS, array($this, 'sanitize_settings'));
    }

    public function sanitize_settings($settings) {
        $verifier = isset($settings['verifier_url']) ? esc_url_raw(trim($settings['verifier_url'])) : '';
        if (!$verifier || 'https' !== wp_parse_url($verifier, PHP_URL_SCHEME)) {
            $verifier = 'https://digitalownership.squaredant.com/verify-publication';
        }
        return array('verifier_url' => untrailingslashit($verifier));
    }

    private function active_record($post_id) {
        if ('1' !== get_post_meta($post_id, self::META_ENABLED, true)) {
            return null;
        }
        $version = get_post_meta($post_id, self::META_VERSION, true);
        $records = $this->publication_records($post_id);
        return $version && isset($records[$version]) ? $records[$version] : null;
    }

    private function private_archive($post_id) {
        $archive = get_post_meta($post_id, self::META_PRIVATE_ARCHIVE, true);
        return is_array($archive) ? $archive : array();
    }

    private function previous_registered_version($post_id) {
        $candidates = array();
        foreach ($this->publication_records($post_id) as $record) {
            $path = is_array($record) ? $this->safe_storage_path($record['contentPath'] ?? '') : false;
            $bytes = $path && is_readable($path) ? file_get_contents($path) : false;
            $reference = $this->registered_version_reference($record, $bytes);
            if ($reference) $candidates[] = $reference;
        }
        foreach ($this->private_archive($post_id) as $entry) {
            $reference = $this->registered_version_reference($entry['record'] ?? null, $entry['contentBytes'] ?? false);
            if ($reference) $candidates[] = $reference;
        }
        if (!$candidates) return null;
        usort($candidates, static function ($left, $right) {
            return strcmp($right['registeredAt'], $left['registeredAt']);
        });
        return $candidates[0];
    }

    private function public_history_candidates($post_id, $exclude_version) {
        $candidates = array();
        foreach ($this->publication_records($post_id) as $stored_version => $record) {
            if (!is_array($record) || sanitize_key($stored_version) === sanitize_key($exclude_version)) {
                continue;
            }
            if (empty($record['contentUrl']) || empty($record['manifestUrl'])) {
                continue;
            }
            $content_path = $this->safe_storage_path($record['contentPath'] ?? '');
            $reference = $this->registered_version_reference($record, $content_path && is_readable($content_path) ? file_get_contents($content_path) : false);
            if (!$reference) {
                continue;
            }
            $reference['contentUrl'] = esc_url_raw($record['contentUrl']);
            $reference['manifestUrl'] = esc_url_raw($record['manifestUrl']);
            if ($reference['contentUrl'] && $reference['manifestUrl']) {
                $candidates[] = $reference;
            }
        }
        usort($candidates, static function ($left, $right) {
            return strcmp($right['registeredAt'], $left['registeredAt']);
        });
        return array_slice($candidates, 0, 10);
    }

    private function selected_public_history_versions($post_id, $exclude_version, $requested_versions) {
        $requested = array();
        foreach ((array) $requested_versions as $version) {
            $version = sanitize_key($version);
            if ($version) {
                $requested[$version] = true;
            }
        }
        $selected = array();
        foreach ($this->public_history_candidates($post_id, $exclude_version) as $candidate) {
            if (!empty($requested[$candidate['version']])) {
                $selected[] = $candidate;
            }
        }
        return $selected;
    }

    private function registered_version_reference($record, $content_bytes) {
        $registration = is_array($record) && is_array($record['registrationResult'] ?? null) ? $record['registrationResult'] : array();
        if (!is_array($record) || !is_string($content_bytes) || !$this->valid_public_registration($registration)) {
            return null;
        }
        $registered_at = sanitize_text_field($registration['registeredAt'] ?? '');
        if (ctype_digit($registered_at)) $registered_at = gmdate('c', (int) $registered_at);
        if (false === strtotime($registered_at)) $registered_at = sanitize_text_field($record['createdAt'] ?? '');
        if (false === strtotime($registered_at)) return null;
        return array(
            'version' => sanitize_key($record['version'] ?? ''),
            'sha512' => hash('sha512', $content_bytes),
            'registryKey' => strtolower(sanitize_text_field($registration['registryKey'] ?? '')),
            'transactionHash' => strtolower(sanitize_text_field($registration['transactionHash'] ?? '')),
            'registeredAt' => gmdate('c', strtotime($registered_at)),
        );
    }

    private function is_public_publication($post) {
        return $post
            && in_array($post->post_type, array('post', 'page'), true)
            && 'publish' === $post->post_status
            && '' === (string) $post->post_password;
    }

    private function publication_records($post_id) {
        $records = get_post_meta($post_id, self::META_VERSIONS, true);
        return is_array($records) ? $records : array();
    }

    private function record_for_version($post_id, $requested_version) {
        $normalized = sanitize_key($requested_version);
        foreach ($this->publication_records($post_id) as $stored_version => $record) {
            if ($normalized === sanitize_key($stored_version)) {
                return array($stored_version, $record);
            }
        }
        return array('', null);
    }

    private function snapshot_post_body($post) {
        $body = do_blocks($post->post_content);
        if ($body === $post->post_content) {
            $body = wpautop($body);
        }
        return wp_kses_post($body);
    }

    private function canonical_json($post, $version, $content_url, $image_evidence) {
        return wp_json_encode(array(
            'schema' => 'digitalownership-wordpress-canonical-content-v2',
            'publication' => get_the_title($post),
            'version' => $version,
            'source' => array(
                'postId' => (int) $post->ID,
                'postType' => $post->post_type,
                'pageUrl' => get_permalink($post),
                'canonicalContentUrl' => $content_url,
                'publishedAt' => get_post_time('c', true, $post),
                'modifiedAt' => get_post_modified_time('c', true, $post),
            ),
            'content' => array(
                'format' => 'html',
                'body' => $image_evidence['body'],
            ),
            // These public publisher-hosted URLs are committed by the fixed
            // JSON. They hash the original upload before returning its bytes.
            'assets' => $image_evidence['assets'],
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    private function fixed_image_evidence($post_id, $version, $body_html) {
        // Do not let responsive-image selection substitute an unrecorded file.
        $body_html = preg_replace('/\s+(?:srcset|sizes)\s*=\s*(["\']).*?\1/is', '', (string) $body_html);
        $assets = array();
        $sources = array();
        $source_indexes = array();
        $body = preg_replace_callback('/<img\\b[^>]*\\bsrc\\s*=\\s*(["\\\'])(.*?)\\1[^>]*>/i', function ($match) use ($post_id, $version, &$assets, &$sources, &$source_indexes) {
            $url = html_entity_decode(trim($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (isset($source_indexes[$url])) {
                $index = $source_indexes[$url];
                return $this->replace_image_source($match[0], $assets[$index]['url']);
            }
            $path = $this->local_upload_path_for_url($url);
            if (!$path) return $match[0];
            $type = wp_check_filetype($path)['type'] ?? '';
            $size = filesize($path);
            if (0 !== strpos($type, 'image/') || false === $size) return $match[0];
            $index = count($assets);
            $filename = sanitize_file_name((string) wp_basename((string) wp_parse_url($url, PHP_URL_PATH)));
            if (!$filename) $filename = 'image-' . ($index + 1);
            $asset_url = $this->asset_url($post_id, $version, $index, $filename);
            $asset = array(
                'type' => 'image',
                'url' => $asset_url,
                'sha512' => hash_file('sha512', $path),
                'contentType' => $type,
                'bytes' => (int) $size,
            );
            $assets[$index] = $asset;
            $sources[$index] = array_merge(array('sourceUrl' => esc_url_raw($url)), $asset);
            $source_indexes[$url] = $index;
            return $this->replace_image_source($match[0], $asset_url);
        }, $body_html);
        return array('body' => $body, 'assets' => array_values($assets), 'sources' => $sources);
    }

    private function replace_image_source($markup, $asset_url) {
        return preg_replace('/\\bsrc\\s*=\\s*(["\']).*?\\1/i', 'src="' . esc_url($asset_url) . '"', $markup, 1);
    }

    private function local_upload_path_for_url($url) {
        $upload = wp_upload_dir();
        $root = realpath($upload['basedir']);
        $source_path = wp_parse_url($url, PHP_URL_PATH);
        $source_host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $upload_host = strtolower((string) wp_parse_url($upload['baseurl'], PHP_URL_HOST));
        $base_path = rtrim((string) wp_parse_url($upload['baseurl'], PHP_URL_PATH), '/');
        if (!$root || !$source_path || !$base_path || ($source_host && $upload_host && $source_host !== $upload_host)) return false;
        if (0 !== strpos($source_path, $base_path . '/')) return false;
        $relative_path = rawurldecode(substr($source_path, strlen($base_path)));
        $path = realpath($root . DIRECTORY_SEPARATOR . ltrim($relative_path, '/'));
        return $path && 0 === strpos($path, $root . DIRECTORY_SEPARATOR) && is_file($path) ? $path : false;
    }

    private function canonical_markdown($post, $version, $content_url, $body_html) {
        $front_matter = array(
            'schema: digitalownership-wordpress-canonical-content-v1',
            'publication: ' . $this->yaml_value(get_the_title($post)),
            'version: ' . $this->yaml_value($version),
            'page_url: ' . $this->yaml_value(get_permalink($post)),
            'canonical_content_url: ' . $this->yaml_value($content_url),
            'published_at: ' . $this->yaml_value(get_post_time('c', true, $post)),
            'modified_at: ' . $this->yaml_value(get_post_modified_time('c', true, $post)),
        );
        return "---\n" . implode("\n", $front_matter) . "\n---\n\n# " . get_the_title($post) . "\n\n" . $this->html_to_markdown($body_html) . "\n";
    }

    private function yaml_value($value) {
        return '"' . str_replace(array('\\', '"'), array('\\\\', '\\"'), (string) $value) . '"';
    }

    private function html_to_markdown($html) {
        $html = preg_replace_callback('/<h([2-6])[^>]*>(.*?)<\\/h\\1>/is', static function ($matches) {
            return "\n\n" . str_repeat('#', (int) $matches[1]) . ' ' . wp_strip_all_tags($matches[2]) . "\n\n";
        }, $html);
        $html = preg_replace_callback('/<li[^>]*>(.*?)<\\/li>/is', static function ($matches) {
            return "- " . trim(wp_strip_all_tags($matches[1])) . "\n";
        }, $html);
        $html = preg_replace_callback('/<a[^>]+href=["\\\']([^"\\\']+)["\\\'][^>]*>(.*?)<\\/a>/is', static function ($matches) {
            $url = esc_url_raw($matches[1]);
            return $url ? '[' . trim(wp_strip_all_tags($matches[2])) . '](' . $url . ')' : trim(wp_strip_all_tags($matches[2]));
        }, $html);
        $html = preg_replace('/<\\/?(p|div|ul|ol|br)[^>]*>/i', "\n", $html);
        $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\\n{3,}/", "\n\n", $text);
        return trim($text);
    }

    private function render_json_content($source) {
        $data = json_decode($source, true);
        if (!is_array($data) || !in_array($data['schema'] ?? '', array('digitalownership-wordpress-canonical-content-v1', 'digitalownership-wordpress-canonical-content-v2'), true)) {
            return null;
        }
        return isset($data['content']['body']) && is_string($data['content']['body']) ? wp_kses_post($data['content']['body']) : null;
    }

    private function render_markdown_content($source) {
        $body = preg_replace('/^---\\R.*?\\R---\\R*/s', '', $source, 1);
        $lines = preg_split('/\\R/', trim($body));
        $output = '';
        $paragraph = array();
        $in_list = false;
        $flush = static function () use (&$output, &$paragraph) {
            if ($paragraph) {
                $output .= '<p>' . DOVP_Plugin::inline_markdown(implode(' ', $paragraph)) . '</p>';
                $paragraph = array();
            }
        };
        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\\s+(.+)$/', $line, $matches)) {
                $flush();
                if ($in_list) { $output .= '</ul>'; $in_list = false; }
                $level = strlen($matches[1]);
                $output .= '<h' . $level . '>' . self::inline_markdown($matches[2]) . '</h' . $level . '>';
            } elseif (preg_match('/^-\\s+(.+)$/', $line, $matches)) {
                $flush();
                if (!$in_list) { $output .= '<ul>'; $in_list = true; }
                $output .= '<li>' . self::inline_markdown($matches[1]) . '</li>';
            } elseif ('' === trim($line)) {
                $flush();
                if ($in_list) { $output .= '</ul>'; $in_list = false; }
            } else {
                $paragraph[] = $line;
            }
        }
        $flush();
        if ($in_list) { $output .= '</ul>'; }
        return wp_kses_post($output);
    }

    private static function inline_markdown($text) {
        $escaped = esc_html($text);
        return preg_replace_callback('/\\[([^\\]]+)\\]\\(([^)]+)\\)/', static function ($matches) {
            $url = esc_url($matches[2], array('http', 'https'));
            return $url ? '<a href="' . $url . '" rel="noopener">' . esc_html($matches[1]) . '</a>' : esc_html($matches[1]);
        }, $escaped);
    }

    private function verification_panel($record) {
        if (empty($record['manifestUrl'])) {
            return '';
        }
        $verify_url = $this->verification_url($record['manifestUrl']);
        $history = $this->public_history_from_manifest($record);
        $history_html = '';
        if (!empty($history['revisionNote'])) {
            $history_html .= '<p><strong>' . esc_html__('Publisher revision note:', 'digitalownership-publication-verification') . '</strong> ' . esc_html($history['revisionNote']) . '</p>';
        }
        if (!empty($history['previousVersions'])) {
            $history_html .= '<details><summary>' . esc_html__('Previous verified versions', 'digitalownership-publication-verification') . '</summary><ul>';
            foreach ($history['previousVersions'] as $previous) {
                /* translators: 1: fixed publication version identifier. 2: UTC registration timestamp. */
                $history_html .= '<li>' . esc_html(sprintf(__('Version %1$s, registered %2$s UTC', 'digitalownership-publication-verification'), $previous['version'], gmdate('Y-m-d H:i:s', strtotime($previous['registeredAt']))));
                $history_html .= ' <a href="' . esc_url($previous['contentUrl']) . '" target="_blank" rel="noopener">' . esc_html__('Read version', 'digitalownership-publication-verification') . '</a>';
                $history_html .= ' <a href="' . esc_url($this->verification_url($previous['manifestUrl'])) . '" target="_blank" rel="noopener">' . esc_html__('Verify version', 'digitalownership-publication-verification') . '</a></li>';
            }
            $history_html .= '</ul></details>';
        }
        return '<aside class="dovp-verification" aria-label="Publication verification">'
            . '<strong>DigitalOwnership evidence</strong>'
            . '<p>This publication body is rendered from a fixed, versioned file.</p>'
            . '<p><a href="' . esc_url($verify_url) . '" target="_blank" rel="noopener">Verify this version</a> <a href="' . esc_url($record['manifestUrl']) . '" target="_blank" rel="noopener">View manifest</a></p>'
            . $history_html
            . '</aside>';
    }

    private function public_history_from_manifest($record) {
        $path = $this->safe_storage_path($record['manifestPath'] ?? '');
        $manifest = $path && is_readable($path) ? json_decode(file_get_contents($path), true) : null;
        if (!is_array($manifest)) {
            return array();
        }
        $history = array();
        $revision_note = sanitize_textarea_field($manifest['revisionNote'] ?? '');
        if ($revision_note) {
            $history['revisionNote'] = $revision_note;
        }
        if (!is_array($manifest['previousVersions'] ?? null)) {
            return $history;
        }
        $previous_versions = array();
        foreach (array_slice($manifest['previousVersions'], 0, 10) as $previous) {
            if (!is_array($previous)) continue;
            $version = sanitize_key($previous['version'] ?? '');
            $content_url = esc_url_raw($previous['contentUrl'] ?? '');
            $manifest_url = esc_url_raw($previous['manifestUrl'] ?? '');
            $registered_at = sanitize_text_field($previous['registeredAt'] ?? '');
            if (!$version || !$content_url || !$manifest_url || 0 !== strpos($content_url, 'https://') || 0 !== strpos($manifest_url, 'https://') || false === strtotime($registered_at)) continue;
            $previous_versions[] = array('version' => $version, 'contentUrl' => $content_url, 'manifestUrl' => $manifest_url, 'registeredAt' => gmdate('c', strtotime($registered_at)));
        }
        if ($previous_versions) {
            $history['previousVersions'] = $previous_versions;
        }
        return $history;
    }

    private function diagnostic_content($content, $state) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Optional diagnostic markup is limited to site administrators and performs no action.
        if (isset($_GET['dovp-debug']) && current_user_can('manage_options')) {
            return $content . "\n<!-- DOVP diagnostic: " . esc_html($state) . " -->";
        }
        return $content;
    }

    private function verification_url($manifest_url) {
        $settings = get_option(self::OPTION_SETTINGS, array());
        $base = $settings['verifier_url'] ?? 'https://digitalownership.squaredant.com/verify-publication';
        return add_query_arg('manifest', $manifest_url, $base);
    }

    private function account_link_url($token) {
        $base = 'https://digitalownership.squaredant.com/account';
        $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'WordPress site';
        return add_query_arg(array(
            'source' => 'wordpress-publication',
            'pluginTokenHash' => hash('sha256', (string) $token),
            'deviceLabel' => 'WordPress: ' . $host,
        ), $base);
    }

    private function linked_service_get($path, $token) {
        $response = wp_remote_get(self::CREDIT_SERVICE_URL . $path, array(
            'timeout' => 20,
            'headers' => array('Authorization' => 'Bearer ' . $token),
        ));
        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            return null;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($body) ? $body : null;
    }

    private function public_registration_result($result) {
        $registration = is_array($result['registration'] ?? null) ? $result['registration'] : array();
        return array(
            'documentHash' => strtolower(sanitize_text_field($registration['documentHash'] ?? '')),
            'registryAddress' => sanitize_text_field($registration['registryAddress'] ?? ''),
            'registryKey' => sanitize_text_field($registration['eventRegistryKey'] ?? $registration['documentHash'] ?? ''),
            'transactionHash' => sanitize_text_field($registration['transactionHash'] ?? ''),
            'chainId' => sanitize_text_field($registration['chainId'] ?? ''),
            'blockNumber' => sanitize_text_field((string) ($registration['blockNumber'] ?? '')),
            'registeredAt' => sanitize_text_field($registration['registeredAt'] ?? ''),
        );
    }

    private function publication_approval_url($approval_url, $post_id, $record, $artifact = 'content') {
        $approval_url = esc_url_raw($approval_url);
        $is_manifest = 'manifest' === $artifact;
        $file_url = $is_manifest ? $this->manifest_draft_url($post_id, $record) : (is_array($record) ? esc_url_raw($record['contentUrl'] ?? '') : '');
        $preview_url = $is_manifest ? $this->manifest_draft_preview_url($post_id, $record) : $this->fixed_content_preview_url($record);
        if (!$approval_url || !$file_url || !$preview_url) {
            return $approval_url;
        }
        return add_query_arg(array(
            'approvalContext' => 'wordpress-publication',
            'approvalItem' => $is_manifest ? 'evidence-file' : 'fixed-content',
            'reviewUrl' => $preview_url,
            'fileUrl' => $file_url,
            'workflowUrl' => $this->workflow_url($post_id, $record),
        ), $approval_url);
    }

    private function fixed_content_preview_url($record) {
        $content_url = is_array($record) ? esc_url_raw($record['contentUrl'] ?? '') : '';
        return $content_url ? add_query_arg('dovp_preview', '1', $content_url) : '';
    }

    private function manifest_draft_url($post_id, $record) {
        if (!is_array($record) || empty($record['manifestDraft'])) {
            return '';
        }
        $version = sanitize_key($record['version'] ?? '');
        $token = sanitize_text_field($record['manifestDraftToken'] ?? '');
        return $version && $token ? add_query_arg('dovp_draft', rawurlencode($token), $this->artifact_url($post_id, $version, 'manifest-draft', 'json')) : '';
    }

    private function ensure_manifest_draft_token(&$record) {
        if (!is_array($record) || empty($record['manifestDraft']) || !empty($record['manifestDraftToken'])) {
            return false;
        }
        $record['manifestDraftToken'] = wp_generate_password(32, false, false);
        return true;
    }

    private function manifest_draft_preview_url($post_id, $record) {
        $url = $this->manifest_draft_url($post_id, $record);
        return $url ? add_query_arg('dovp_preview', '1', $url) : '';
    }

    private function workflow_url($post_id, $record) {
        $version = is_array($record) ? sanitize_key($record['version'] ?? '') : '';
        return $version ? $this->artifact_url($post_id, $version, 'workflow', 'json') : '';
    }

    private function valid_public_registration($registration) {
        return is_array($registration)
            && 1 === preg_match('/^0x[a-f0-9]{64}$/i', (string) ($registration['registryKey'] ?? ''))
            && 1 === preg_match('/^0x[a-f0-9]{64}$/i', (string) ($registration['transactionHash'] ?? ''));
    }

    private function publish_completed_manifest($post_id, $version, &$record, $registration_request) {
        $content_path = $this->safe_storage_path($record['contentPath'] ?? '');
        $expected_hash = strtolower(sanitize_text_field($record['manifestDraftSha512'] ?? ''));
        $contents = (string) ($record['manifestDraft'] ?? '');
        if (!$contents || !$content_path || !is_readable($content_path) || !hash_equals($expected_hash, hash('sha512', $contents))) {
            return false;
        }
        if (!hash_equals($expected_hash, strtolower(sanitize_text_field($registration_request['sourceHash'] ?? '')))) {
            return false;
        }
        $manifest = json_decode($contents, true);
        if (!$this->manifest_matches_content($manifest, $record['contentUrl'], $content_path)) {
            return false;
        }
        $directory = $this->version_directory($post_id, $version);
        $final_path = trailingslashit($directory) . 'manifest.json';
        if (!wp_mkdir_p($directory) || false === file_put_contents($final_path, $contents, LOCK_EX)) {
            return false;
        }
        $record['manifestPath'] = $this->relative_storage_path($final_path);
        $record['manifestUrl'] = $this->artifact_url($post_id, $version, 'manifest', 'json');
        $record['manifestRegistrationResult'] = $this->public_registration_result($registration_request['result'] ?? array());
        return true;
    }

    private function manifest_matches_content($manifest, $content_url, $content_path = false) {
        if (!is_array($manifest) || self::MANIFEST_SCHEMA !== ($manifest['schema'] ?? '') || empty($manifest['files'][0])) {
            return false;
        }
        $file = $manifest['files'][0];
        $matches = is_array($file)
            && isset($manifest['registrationEmail'], $manifest['publishedAt'], $file['url'], $file['sha512'], $file['hashScope'], $file['registryKey'], $file['transactionHash'])
            && $content_url === $file['url']
            && 'digitalownership-exact-file-v1' === $file['hashScope']
            && is_email($manifest['registrationEmail'])
            && false !== strtotime($manifest['publishedAt'])
            && 1 === preg_match('/^[a-f0-9]{128}$/', strtolower((string) $file['sha512']))
            && 1 === preg_match('/^0x[a-f0-9]{64}$/i', (string) $file['registryKey'])
            && 1 === preg_match('/^0x[a-f0-9]{64}$/i', (string) $file['transactionHash']);
        return $matches && (!$content_path || (is_readable($content_path) && hash_equals(strtolower((string) $file['sha512']), hash_file('sha512', $content_path))));
    }

    private function version_directory($post_id, $version) {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'digitalownership-publications/' . absint($post_id) . '/' . sanitize_key($version);
    }

    private function storage_root() {
        $upload = wp_upload_dir();
        return trailingslashit($upload['basedir']) . 'digitalownership-publications/';
    }

    private function filesystem_contents($path) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wp_filesystem;
        if (!WP_Filesystem() || !$wp_filesystem) {
            return '';
        }
        $contents = $wp_filesystem->get_contents($path);
        return is_string($contents) ? $contents : '';
    }

    private function filesystem_delete($path, $recursive = false) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wp_filesystem;
        return WP_Filesystem() && $wp_filesystem ? $wp_filesystem->delete($path, (bool) $recursive) : false;
    }

    private function remove_storage_directory($directory) {
        $root = realpath($this->storage_root());
        $target = realpath($directory);
        if (!$root || !$target || 0 !== strpos($target, $root . DIRECTORY_SEPARATOR) || !is_dir($target)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            if ($entry->isLink() || $entry->isFile()) {
                wp_delete_file($path);
            } elseif ($entry->isDir()) {
                $this->filesystem_delete($path, true);
            }
        }
        $this->filesystem_delete($target, true);
    }

    private function relative_storage_path($path) {
        return ltrim(str_replace($this->storage_root(), '', $path), '/');
    }

    private function safe_storage_path($relative_path) {
        $root = realpath($this->storage_root());
        if (!$root || !$relative_path) {
            return false;
        }
        $path = realpath($root . DIRECTORY_SEPARATOR . ltrim($relative_path, '/'));
        return $path && 0 === strpos($path, $root . DIRECTORY_SEPARATOR) ? $path : false;
    }

    private function artifact_url($post_id, $version, $artifact, $extension) {
        return home_url('/digitalownership-publication/' . absint($post_id) . '/' . rawurlencode($version) . '/' . $artifact . '.' . $extension);
    }

    private function asset_url($post_id, $version, $asset_index, $filename) {
        return home_url('/digitalownership-publication/' . absint($post_id) . '/' . rawurlencode($version) . '/assets/' . absint($asset_index) . '/' . rawurlencode($filename));
    }

    private function redirect_with_notice($post_id, $notice) {
        wp_safe_redirect(add_query_arg(array('post' => $post_id, 'action' => 'edit', 'dovp_notice' => $notice), admin_url('post.php')));
        exit;
    }
}
