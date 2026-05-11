<?php
require_once "wp-load.php";
$posts = get_posts(["post_type" => "bricks_template", "posts_per_page" => -1]);
foreach($posts as $p) {
    if (stripos($p->post_title, "footer") !== false) {
        $meta = get_post_meta($p->ID, "_bricks_page_content_2", true);
        if (is_array($meta)) {
            foreach ($meta as $n) {
                if ($n["name"] === "logo" || $n["name"] === "image") {
                    echo "TITLE: " . $p->post_title . "\n";
                    echo wp_json_encode(["id" => $n["id"], "name" => $n["name"], "settings" => $n["settings"]]) . "\n";
                }
            }
        }
    }
}

