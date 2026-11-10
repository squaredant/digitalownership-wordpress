<?php

defined('ABSPATH') || exit;

function dovp_test_fail($message) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function dovp_test_assert($condition, $message) {
    if (!$condition) {
        dovp_test_fail($message);
    }
}

$author_id = wp_insert_user(array(
    'user_login' => 'evidence-editor',
    'user_pass' => 'evidence-editor-password',
    'user_email' => 'evidence-editor@example.test',
    'role' => 'editor',
));
if (is_wp_error($author_id)) {
    $author = get_user_by('login', 'evidence-editor');
    $author_id = $author ? $author->ID : 0;
}
dovp_test_assert($author_id, 'Could not create the editor account.');

$post_id = wp_insert_post(array(
    'post_title' => 'Evidence save regression',
    'post_content' => 'Original publication text.',
    'post_status' => 'publish',
    'post_author' => $author_id,
));
dovp_test_assert($post_id && !is_wp_error($post_id), 'Could not create the public post.');

$version = '2026-11-02t120000z';
$plugin = DOVP_Plugin::instance();
$dovp_test_connection_state = 'linked';
update_option('dovp_site_device_token', 'test-connected-token', false);
add_filter('pre_http_request', static function ($preempt, $arguments, $url) use (&$dovp_test_connection_state) {
    if (false === strpos($url, '/publication-identity')) {
        return $preempt;
    }
    if ('linked' === $dovp_test_connection_state) {
        return array(
            'headers' => array(),
            'body' => wp_json_encode(array('registrationEmail' => 'publications@example.test')),
            'response' => array('code' => 200, 'message' => 'OK'),
            'cookies' => array(),
            'filename' => null,
        );
    }
    return array(
        'headers' => array(),
        'body' => wp_json_encode(array('error' => 'DigitalOwnership account is not linked to this plugin installation.')),
        'response' => array('code' => 401, 'message' => 'Unauthorized'),
        'cookies' => array(),
        'filename' => null,
    );
}, 10, 3);
$upload = wp_upload_dir();
$directory = trailingslashit($upload['basedir']) . 'digitalownership-publications/' . $post_id . '/' . $version;
wp_mkdir_p($directory);

$content_url = home_url('/digitalownership-publication/' . $post_id . '/' . $version . '/content.json');
$manifest_url = home_url('/digitalownership-publication/' . $post_id . '/' . $version . '/manifest.json');
$content = wp_json_encode(array(
    'schema' => 'digitalownership-wordpress-canonical-content-v2',
    'publication' => 'Evidence save regression',
    'version' => $version,
    'source' => array(
        'postId' => $post_id,
        'postType' => 'post',
        'pageUrl' => get_permalink($post_id),
        'canonicalContentUrl' => $content_url,
        'publishedAt' => gmdate('c'),
        'modifiedAt' => gmdate('c'),
    ),
    'content' => array('format' => 'html', 'body' => '<p>Original publication text.</p>'),
    'assets' => array(),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($directory . '/content.json', $content, LOCK_EX);

$manifest = wp_json_encode(array(
    'schema' => 'digitalownership-publication-manifest-v1',
    'publisher' => 'Local test publisher',
    'registrationEmail' => 'publications@example.test',
    'publishedAt' => gmdate('c'),
    'files' => array(array(
        'label' => 'Evidence save regression',
        'url' => $content_url,
        'sha512' => hash('sha512', $content),
        'hashScope' => 'digitalownership-exact-file-v1',
        'registryKey' => '0x' . str_repeat('a', 64),
        'transactionHash' => '0x' . str_repeat('b', 64),
    )),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($directory . '/manifest.json', $manifest, LOCK_EX);

$record = array(
    'version' => $version,
    'format' => 'json',
    'contentPath' => $post_id . '/' . $version . '/content.json',
    'contentUrl' => $content_url,
    'manifestPath' => $post_id . '/' . $version . '/manifest.json',
    'manifestUrl' => $manifest_url,
    'assetSources' => array(),
    'publicationMode' => 'prepublication',
    'editorialStatus' => 'published',
    'publiclyReleased' => true,
    'createdAt' => gmdate('c'),
    'releasedAt' => gmdate('c'),
    'registrationStatus' => 'completed',
    'registrationResult' => array(
        'registryKey' => '0x' . str_repeat('a', 64),
        'transactionHash' => '0x' . str_repeat('b', 64),
    ),
    'manifestRegistrationStatus' => 'completed',
);
update_post_meta($post_id, '_dovp_enabled', '1');
update_post_meta($post_id, '_dovp_active_version', $version);
update_post_meta($post_id, '_dovp_versions', array($version => $record));

// Gutenberg sends a REST DELETE request for "Move to trash". Return a JSON
// conflict before WordPress starts the deletion, rather than briefly showing
// an HTML wp_die response in the editor.
$trash_request = new WP_REST_Request('DELETE', '/wp/v2/posts/' . $post_id);
$trash_result = apply_filters('rest_pre_dispatch', null, null, $trash_request);
dovp_test_assert(is_wp_error($trash_result), 'REST trash request was not blocked for active evidence.');
dovp_test_assert('dovp_withdrawal_required' === $trash_result->get_error_code(), 'REST trash request returned the wrong error code.');
dovp_test_assert(409 === (int) $trash_result->get_error_data()['status'], 'REST trash request did not return HTTP 409.');

// This is the state created by the "Start private revision" action.
update_post_meta($post_id, '_dovp_revision_author', $author_id);
wp_set_current_user($author_id);
wp_update_post(array(
    'ID' => $post_id,
    'post_content' => 'Revised publication text.',
    // This mirrors a restricted editorial role or workflow plugin that sends
    // a pending status with an ordinary save.
    'post_status' => 'pending',
));

$records = get_post_meta($post_id, '_dovp_versions', true);
dovp_test_assert('1' === get_post_meta($post_id, '_dovp_enabled', true), 'Editor save removed the enabled flag.');
dovp_test_assert($version === get_post_meta($post_id, '_dovp_active_version', true), 'Editor save changed the active version.');
dovp_test_assert(is_array($records) && isset($records[$version]), 'Editor save removed the active evidence record.');
dovp_test_assert('publish' === get_post_status($post_id), 'Editor revision save changed the public post status.');
dovp_test_assert(file_exists($directory . '/content.json'), 'Editor save removed fixed content.');
dovp_test_assert(file_exists($directory . '/manifest.json'), 'Editor save removed the manifest.');

// A database/post-meta restore must not make the old fixed version disappear
// merely because the author is now working on replacement text.
delete_post_meta($post_id, '_dovp_enabled');
delete_post_meta($post_id, '_dovp_active_version');
delete_post_meta($post_id, '_dovp_versions');
global $wp_rewrite;
$wp_rewrite->flush_rules();
$artifact_url = str_replace('localhost:8099', 'wordpress', home_url('/digitalownership-publication/' . $post_id . '/' . $version . '/manifest.json'));
$response = wp_remote_get($artifact_url);
dovp_test_assert(200 === wp_remote_retrieve_response_code($response), 'Manifest route did not recover missing evidence metadata.');
dovp_test_assert('1' === get_post_meta($post_id, '_dovp_enabled', true), 'Manifest route did not restore the enabled flag.');
dovp_test_assert($version === get_post_meta($post_id, '_dovp_active_version', true), 'Manifest route did not restore the active version.');

// An administrator reviewing a submitted revision cannot save over the
// author’s working text or change the post status.
$records = get_post_meta($post_id, '_dovp_versions', true);
$candidate_version = '2026-11-02t130000z';
$records[$candidate_version] = array(
    'version' => $candidate_version,
    'publicationMode' => 'revision',
    'editorialStatus' => 'pending',
    'publiclyReleased' => false,
    'revisionAuthorId' => $author_id,
);
update_post_meta($post_id, '_dovp_versions', $records);
update_post_meta($post_id, '_dovp_candidate_version', $candidate_version);
$before_admin_save = get_post_field('post_content', $post_id);
wp_set_current_user(1);
wp_update_post(array('ID' => $post_id, 'post_content' => 'Administrator replacement.', 'post_status' => 'pending'));
dovp_test_assert($before_admin_save === get_post_field('post_content', $post_id), 'Administrator save changed a submitted revision.');
dovp_test_assert('publish' === get_post_status($post_id), 'Administrator save changed a submitted revision status.');
dovp_test_assert('1' === get_post_meta($post_id, '_dovp_enabled', true), 'Administrator save removed active evidence.');

// A completed revision must replace the active version without changing the
// published status or removing the earlier fixed evidence files.
$revision_release_record = $records[$candidate_version];
$revision_release_record['editorialStatus'] = 'approved';
$revision_release_record['registrationStatus'] = 'completed';
$revision_release_record['manifestRegistrationStatus'] = 'completed';
$revision_release_record['sourceRevisionHash'] = hash('sha256', wp_json_encode(array(
    'title' => 'Evidence save regression',
    'content' => 'Revised publication text.',
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$revision_release_record['manifestUrl'] = home_url('/digitalownership-publication/' . $post_id . '/' . $candidate_version . '/manifest.json');
$records[$candidate_version] = $revision_release_record;
update_post_meta($post_id, '_dovp_versions', $records);

$release_method = new ReflectionMethod(DOVP_Plugin::class, 'release_completed_version');
$release_method->setAccessible(true);
$revision_release_arguments = array($post_id, $candidate_version, &$revision_release_record);
$revision_released = $release_method->invokeArgs($plugin, $revision_release_arguments);
dovp_test_assert(true === $revision_released, 'Completed revision was not released.');
$records = get_post_meta($post_id, '_dovp_versions', true);
$records[$candidate_version] = $revision_release_record;
update_post_meta($post_id, '_dovp_versions', $records);
dovp_test_assert('publish' === get_post_status($post_id), 'Completed revision changed the public post status.');
dovp_test_assert($candidate_version === get_post_meta($post_id, '_dovp_active_version', true), 'Completed revision did not become active.');
dovp_test_assert('' === get_post_meta($post_id, '_dovp_candidate_version', true), 'Completed revision remained a candidate.');
dovp_test_assert(file_exists($directory . '/content.json'), 'Completed revision removed earlier fixed content.');
dovp_test_assert(file_exists($directory . '/manifest.json'), 'Completed revision removed earlier manifest.');

// The same lock applies before the first publication, when no active public
// version exists yet and an administrator is reviewing the author’s candidate.
$draft_id = wp_insert_post(array(
    'post_title' => 'First-publication review',
    'post_content' => 'Author candidate text.',
    'post_status' => 'draft',
    'post_author' => $author_id,
));
$first_version = '2026-11-02t140000z';
update_post_meta($draft_id, '_dovp_versions', array($first_version => array(
    'version' => $first_version,
    'publicationMode' => 'prepublication',
    'editorialStatus' => 'pending',
    'publiclyReleased' => false,
    'revisionAuthorId' => $author_id,
)));
update_post_meta($draft_id, '_dovp_candidate_version', $first_version);
wp_update_post(array('ID' => $draft_id, 'post_content' => 'Administrator replacement.', 'post_status' => 'pending'));
dovp_test_assert('Author candidate text.' === get_post_field('post_content', $draft_id), 'Administrator save changed a first-publication candidate.');

// An administrator may also be the original author. In that case the same
// account must retain author controls for its own candidate, while the normal
// administrator review controls remain available after submission.
$administrator_author_id = 1;
$administrator_author_post_id = wp_insert_post(array(
    'post_title' => 'Administrator author candidate',
    'post_content' => 'Administrator-authored candidate text.',
    'post_status' => 'draft',
    'post_author' => $administrator_author_id,
));
$administrator_author_version = '2026-11-04t090000z';
update_post_meta($administrator_author_post_id, '_dovp_enabled', '1');
update_post_meta($administrator_author_post_id, '_dovp_versions', array($administrator_author_version => array(
    'version' => $administrator_author_version,
    'publicationMode' => 'prepublication',
    'editorialStatus' => 'declined',
    'publiclyReleased' => false,
    'revisionAuthorId' => $administrator_author_id,
    'sourceRevisionHash' => hash('sha256', wp_json_encode(array(
        'title' => 'Administrator author candidate',
        'content' => 'Administrator-authored candidate text.',
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
)));
update_post_meta($administrator_author_post_id, '_dovp_candidate_version', $administrator_author_version);
wp_set_current_user($administrator_author_id);
$lock_method = new ReflectionMethod(DOVP_Plugin::class, 'publication_edit_locked_for_user');
$lock_method->setAccessible(true);
dovp_test_assert(false === $lock_method->invoke($plugin, $administrator_author_post_id, $administrator_author_id), 'Administrator-author was incorrectly locked out of their own candidate.');
ob_start();
$plugin->render_meta_box(get_post($administrator_author_post_id));
$administrator_author_html = (string) ob_get_clean();
dovp_test_assert(false !== strpos($administrator_author_html, 'Submit exact version for administrator review'), 'Administrator-author could not submit their own candidate.');

// The plugin must be able to publish a first publication after both completed
// registrations. This status change is intentionally the sole exception to
// the ordinary-save lock above.
$release_id = wp_insert_post(array(
    'post_title' => 'Completed first publication',
    'post_content' => 'Exact approved publication text.',
    'post_status' => 'draft',
    'post_author' => $author_id,
));
$release_version = '2026-11-02t150000z';
$release_record = array(
    'version' => $release_version,
    'publicationMode' => 'prepublication',
    'editorialStatus' => 'approved',
    'publiclyReleased' => false,
    'revisionAuthorId' => $author_id,
    'sourceRevisionHash' => hash('sha256', wp_json_encode(array(
        'title' => 'Completed first publication',
        'content' => 'Exact approved publication text.',
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    'manifestUrl' => home_url('/digitalownership-publication/' . $release_id . '/' . $release_version . '/manifest.json'),
);
update_post_meta($release_id, '_dovp_versions', array($release_version => $release_record));
update_post_meta($release_id, '_dovp_candidate_version', $release_version);

$release_arguments = array($release_id, $release_version, &$release_record);
$released = $release_method->invokeArgs($plugin, $release_arguments);
dovp_test_assert(true === $released, 'Completed first publication was not released.');
dovp_test_assert('publish' === get_post_status($release_id), 'Completed first publication remained a draft.');
dovp_test_assert('1' === get_post_meta($release_id, '_dovp_enabled', true), 'Completed first publication did not retain the enabled flag.');

// Withdrawal must archive the exact final files before removing public
// evidence. Making that withdrawn post private afterwards must not remove the
// administrator-only archive.
$withdraw_id = wp_insert_post(array(
    'post_title' => 'Withdrawn evidence archive',
    'post_content' => 'Public evidence that will be withdrawn.',
    'post_status' => 'publish',
    'post_author' => $author_id,
));
$withdraw_version = '2026-11-03t160000z';
$withdraw_directory = trailingslashit($upload['basedir']) . 'digitalownership-publications/' . $withdraw_id . '/' . $withdraw_version;
wp_mkdir_p($withdraw_directory);
$withdraw_content = "{\"fixed\":true}\n";
$withdraw_manifest = "{\"evidence\":true}\n";
file_put_contents($withdraw_directory . '/content.json', $withdraw_content, LOCK_EX);
file_put_contents($withdraw_directory . '/manifest.json', $withdraw_manifest, LOCK_EX);
update_post_meta($withdraw_id, '_dovp_enabled', '1');
update_post_meta($withdraw_id, '_dovp_active_version', $withdraw_version);
update_post_meta($withdraw_id, '_dovp_versions', array($withdraw_version => array(
    'version' => $withdraw_version,
    'contentPath' => $withdraw_id . '/' . $withdraw_version . '/content.json',
    'manifestPath' => $withdraw_id . '/' . $withdraw_version . '/manifest.json',
    'publiclyReleased' => true,
)));
$archive_method = new ReflectionMethod(DOVP_Plugin::class, 'archive_publication_evidence');
$archive_method->setAccessible(true);
$archive_method->invoke($plugin, $withdraw_id);
$withdraw_archive = get_post_meta($withdraw_id, '_dovp_private_archive', true);
dovp_test_assert(is_array($withdraw_archive) && isset($withdraw_archive[$withdraw_version]), 'Withdrawal did not create a private archive entry.');
dovp_test_assert($withdraw_content === ($withdraw_archive[$withdraw_version]['contentBytes'] ?? ''), 'Withdrawal did not retain exact fixed-content bytes.');
dovp_test_assert($withdraw_manifest === ($withdraw_archive[$withdraw_version]['manifestBytes'] ?? ''), 'Withdrawal did not retain exact manifest bytes.');
dovp_test_assert(!file_exists($withdraw_directory . '/content.json'), 'Withdrawal did not remove public fixed content.');
dovp_test_assert(!file_exists($withdraw_directory . '/manifest.json'), 'Withdrawal did not remove the public manifest.');
wp_update_post(array('ID' => $withdraw_id, 'post_status' => 'private'));
$withdraw_archive_after_private = get_post_meta($withdraw_id, '_dovp_private_archive', true);
dovp_test_assert($withdraw_archive === $withdraw_archive_after_private, 'Making a withdrawn post private removed its private evidence archive.');
wp_set_current_user(1);
ob_start();
$plugin->render_meta_box(get_post($withdraw_id));
$withdraw_admin_html = (string) ob_get_clean();
dovp_test_assert(false !== strpos($withdraw_admin_html, 'Private archived evidence'), 'Private post editor did not show the archived-evidence section to an administrator.');
dovp_test_assert(false !== strpos($withdraw_admin_html, 'Download fixed JSON'), 'Private post editor did not show the archived fixed-content download link.');
dovp_test_assert(false !== strpos($withdraw_admin_html, 'Download evidence file'), 'Private post editor did not show the archived manifest download link.');

// WordPress may create and later delete revisions and autosaves while a post is
// edited. Those child records must never be treated as a request to delete the
// parent publication's private evidence archive.
$withdraw_revision_id = wp_insert_post(array(
    'post_type' => 'revision',
    'post_parent' => $withdraw_id,
    'post_title' => 'Withdrawn evidence archive revision',
    'post_content' => 'Revision-only working copy.',
    'post_status' => 'inherit',
    'post_author' => $author_id,
));
dovp_test_assert($withdraw_revision_id && !is_wp_error($withdraw_revision_id), 'Could not create a WordPress revision for archive testing.');
wp_delete_post($withdraw_revision_id, true);
$withdraw_archive_after_revision_delete = get_post_meta($withdraw_id, '_dovp_private_archive', true);
dovp_test_assert($withdraw_archive === $withdraw_archive_after_revision_delete, 'Deleting a WordPress revision removed its parent private evidence archive.');

// A site-local token is created before an account holder confirms the remote
// connection. It must not be presented as a connected account or allow the
// administrator to start registration from a submitted candidate.
$dovp_test_connection_state = 'pending';
update_option('dovp_site_device_token', 'pending-connection-token', false);
wp_set_current_user(1);
ob_start();
$plugin->render_settings_page();
$pending_connection_html = (string) ob_get_clean();
dovp_test_assert(false !== strpos($pending_connection_html, 'Account connection not confirmed'), 'Pending local token was not identified as an incomplete connection.');
dovp_test_assert(false !== strpos($pending_connection_html, 'Complete DigitalOwnership account connection'), 'Pending local token did not provide a completion action.');
dovp_test_assert(false === strpos($pending_connection_html, 'installation is connected to a DigitalOwnership account'), 'Pending local token was presented as a connected account.');

$connection_post_id = wp_insert_post(array(
    'post_title' => 'Pending account connection',
    'post_content' => 'This version is ready for administrator review.',
    'post_status' => 'draft',
    'post_author' => $author_id,
));
$connection_version = '2026-11-10t120000z';
$connection_post = get_post($connection_post_id);
update_post_meta($connection_post_id, '_dovp_enabled', '1');
update_post_meta($connection_post_id, '_dovp_candidate_version', $connection_version);
update_post_meta($connection_post_id, '_dovp_versions', array($connection_version => array(
    'version' => $connection_version,
    'publicationMode' => 'prepublication',
    'editorialStatus' => 'pending',
    'publiclyReleased' => false,
    'revisionAuthorId' => $author_id,
    'sourceRevisionHash' => hash('sha256', wp_json_encode(array(
        'title' => $connection_post->post_title,
        'content' => $connection_post->post_content,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
)));
ob_start();
$plugin->render_meta_box($connection_post);
$pending_review_html = (string) ob_get_clean();
dovp_test_assert(false !== strpos($pending_review_html, 'Complete or cancel the pending connection before approving this version for registration.'), 'Administrator review did not explain the incomplete account connection.');
dovp_test_assert(false === strpos($pending_review_html, 'Approve and request DigitalOwnership registration'), 'Administrator could approve registration with an unconfirmed account connection.');

$dovp_test_connection_state = 'linked';
ob_start();
$plugin->render_settings_page();
$linked_connection_html = (string) ob_get_clean();
dovp_test_assert(false !== strpos($linked_connection_html, 'installation is connected to a DigitalOwnership account'), 'Confirmed account connection was not shown as connected.');
ob_start();
$plugin->render_meta_box($connection_post);
$linked_review_html = (string) ob_get_clean();
dovp_test_assert(false !== strpos($linked_review_html, 'Approve and request DigitalOwnership registration'), 'Confirmed account connection did not allow administrator approval.');
delete_option('dovp_site_device_token');

echo "PASS: editor saves, administrator reviews, account-link state, publication releases, withdrawal archiving, and metadata recovery preserved evidence.\n";
