<?php
require 'wp-load.php';
$post_id = 15077;
$post = get_post($post_id);
if ($post) {
    echo "Sitemap ID: " . get_post_meta($post_id, 'aisb_latest_sitemap_id', true) . "\n";
    $sg = get_post_meta($post_id, 'aisb_style_guide', true);
    print_r($sg);
}
