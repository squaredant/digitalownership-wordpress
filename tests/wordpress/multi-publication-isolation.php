<?php

defined('ABSPATH') || exit;

function dovp_isolation_fail($message) {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function dovp_isolation_assert($condition, $message) {
    if (!$condition) {
        dovp_isolation_fail($message);
    }
}

function dovp_isolation_source_hash($post) {
    return hash('sha256', wp_json_encode(array(
        'title' => (string) $post->post_title,
        'content' => (string) $post->post_content,
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function dovp_isolation_record($post, $version, $mode) {
    $upload = wp_upload_dir();
    $directory = trailingslashit($upload['basedir']) . 'digitalownership-publications/' . $post->ID . '/' . $version;
    wp_mkdir_p($directory);

    $content_url = home_url('/digitalownership-publication/' . $post->ID . '/' . $version . '/content.json');
    $manifest_url = home_url('/digitalownership-publication/' . $post->ID . '/' . $version . '/manifest.json');
    $content = wp_json_encode(array(
        'schema' => 'digitalownership-wordpress-canonical-content-v2',
        'publication' => $post->post_title,
        'version' => $version,
        'content' => array('format' => 'html', 'body' => $post->post_content),
    ), JSON_UNESCAPED_SLASHES) . "\n";
    $manifest = wp_json_encode(array(
        'schema' => 'digitalownership-publication-manifest-v1',
        'files' => array(array('url' => $content_url, 'sha512' => hash('sha512', $content))),
    ), JSON_UNESCAPED_SLASHES) . "\n";
    file_put_contents($directory . '/content.json', $content, LOCK_EX);
    file_put_contents($directory . '/manifest.json', $manifest, LOCK_EX);

    return array(
        'version' => $version,
        'publicationMode' => $mode,
        'editorialStatus' => 'approved',
        'publiclyReleased' => false,
        'revisionAuthorId' => (int) $post->post_author,
        'sourceRevisionHash' => dovp_isolation_source_hash($post),
        'contentPath' => $post->ID . '/' . $version . '/content.json',
        'contentUrl' => $content_url,
        'manifestPath' => $post->ID . '/' . $version . '/manifest.json',
        'manifestUrl' => $manifest_url,
        'registrationStatus' => 'completed',
        'manifestRegistrationStatus' => 'completed',
    );
}

function dovp_isolation_release($plugin, $post_id, $version, &$record) {
    $method = new ReflectionMethod(DOVP_Plugin::class, 'release_completed_version');
    $method->setAccessible(true);
    $arguments = array($post_id, $version, &$record);
    dovp_isolation_assert(true === $method->invokeArgs($plugin, $arguments), "Could not release {$version} for publication {$post_id}.");
    $records = get_post_meta($post_id, '_dovp_versions', true);
    $records[$version] = $record;
    update_post_meta($post_id, '_dovp_versions', $records);
}

function dovp_isolation_assert_live($post_id, $expected_version) {
    $post = get_post($post_id);
    dovp_isolation_assert('publish' === $post->post_status, "Publication {$post_id} is no longer public.");
    dovp_isolation_assert('1' === get_post_meta($post_id, '_dovp_enabled', true), "Publication {$post_id} lost its evidence flag.");
    dovp_isolation_assert($expected_version === get_post_meta($post_id, '_dovp_active_version', true), "Publication {$post_id} changed active version unexpectedly.");
    $records = get_post_meta($post_id, '_dovp_versions', true);
    $record = $records[$expected_version] ?? null;
    dovp_isolation_assert(is_array($record) && !empty($record['publiclyReleased']), "Publication {$post_id} no longer has its released record.");
    $upload = wp_upload_dir();
    $base = trailingslashit($upload['basedir']) . 'digitalownership-publications/' . $post_id . '/' . $expected_version;
    dovp_isolation_assert(file_exists($base . '/content.json'), "Publication {$post_id} lost fixed content for {$expected_version}.");
    dovp_isolation_assert(file_exists($base . '/manifest.json'), "Publication {$post_id} lost its manifest for {$expected_version}.");
}

function dovp_isolation_assert_artifact($post_id, $version) {
    $records = get_post_meta($post_id, '_dovp_versions', true);
    $record = $records[$version] ?? null;
    dovp_isolation_assert(is_array($record) && !empty($record['publiclyReleased']), "Publication {$post_id} lost historical record {$version}.");
    $upload = wp_upload_dir();
    $base = trailingslashit($upload['basedir']) . 'digitalownership-publications/' . $post_id . '/' . $version;
    dovp_isolation_assert(file_exists($base . '/content.json'), "Publication {$post_id} lost historical fixed content for {$version}.");
    dovp_isolation_assert(file_exists($base . '/manifest.json'), "Publication {$post_id} lost historical manifest for {$version}.");
}

$plugin = DOVP_Plugin::instance();
$author_id = 1;
wp_set_current_user($author_id);

$fixtures = array(
    array('post', 'Isolation post one'),
    array('post', 'Isolation post two'),
    array('page', 'Isolation page one'),
    array('page', 'Isolation page two'),
);
$publications = array();

foreach ($fixtures as $index => $fixture) {
    list($post_type, $title) = $fixture;
    $post_id = wp_insert_post(array(
        'post_type' => $post_type,
        'post_title' => $title,
        'post_content' => 'Initial content for ' . $title . '.',
        'post_status' => 'draft',
        'post_author' => $author_id,
    ));
    dovp_isolation_assert($post_id && !is_wp_error($post_id), "Could not create {$title}.");
    $version = sprintf('2026-11-04t10%02dz', $index);
    $post = get_post($post_id);
    $record = dovp_isolation_record($post, $version, 'prepublication');
    update_post_meta($post_id, '_dovp_versions', array($version => $record));
    update_post_meta($post_id, '_dovp_candidate_version', $version);
    dovp_isolation_release($plugin, $post_id, $version, $record);
    $publications[] = array('id' => $post_id, 'version' => $version, 'title' => $title);
    dovp_isolation_assert_live($post_id, $version);
}

foreach ($publications as $index => &$publication) {
    $post_id = $publication['id'];
    $old_version = $publication['version'];
    update_post_meta($post_id, '_dovp_revision_author', $author_id);
    wp_update_post(array(
        'ID' => $post_id,
        'post_content' => 'Revised content for ' . $publication['title'] . '.',
        'post_status' => 'publish',
    ));
    $post = get_post($post_id);
    $version = sprintf('2026-11-04t11%02dz', $index);
    $record = dovp_isolation_record($post, $version, 'revision');
    $records = get_post_meta($post_id, '_dovp_versions', true);
    $records[$version] = $record;
    update_post_meta($post_id, '_dovp_versions', $records);
    update_post_meta($post_id, '_dovp_candidate_version', $version);

    // A candidate must not disturb other public evidence before release.
    foreach ($publications as $other_index => $other_publication) {
        if ($other_index !== $index) {
            dovp_isolation_assert_live($other_publication['id'], $other_publication['version']);
        }
    }

    dovp_isolation_release($plugin, $post_id, $version, $record);
    dovp_isolation_assert_live($post_id, $version);
    dovp_isolation_assert(get_post_meta($post_id, '_dovp_candidate_version', true) === '', "Publication {$post_id} retained a candidate after release.");
    dovp_isolation_assert(get_post_meta($post_id, '_dovp_revision_author', true) === '', "Publication {$post_id} retained a revision author after release.");
    dovp_isolation_assert_artifact($post_id, $old_version);
    $publication['version'] = $version;
}
unset($publication);

echo "PASS: two posts and two pages published and revised without cross-publication evidence changes.\n";
