<?php
require_once "wp-load.php";
global $wpdb;
$post = $wpdb->get_row("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = \"bricks_template\" AND post_title LIKE \"%Footer%\" LIMIT 1");
if (!$post) { echo "No footer found."; exit; }

$meta = get_post_meta($post->ID, "_bricks_page_content_2", true);
if (!$meta) { echo "No meta found."; exit; }

foreach ($meta as $n) {
    if ($n["name"] === "logo" || $n["name"] === "image") {
        echo wp_json_encode(["id" => $n["id"], "name" => $n["name"], "settings" => $n["settings"]]) . "\n";
    }
}

