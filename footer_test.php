<?php
require_once "wp-load.php";
$posts = get_posts([
  "post_type" => "bricks_template",
  "posts_per_page" => -1,
  "s" => "Footer"
]);
foreach ($posts as $p) {
  echo "Template: " . $p->post_title . " (ID: " . $p->ID . ")\n";
  $elements = get_post_meta($p->ID, "_bricks_page_content_2", true);
  if ($elements) {
    foreach ($elements as $el) {
      if (in_array($el["name"] ?? "", ["logo", "image"])) {
        echo "Found: " . $el["name"] . " -> " . print_r($el["settings"], true) . "\n";
        if (isset($el["_cssClasses"]) || isset($el["cssClasses"])) {
            echo "Classes: " . print_r($el, true) . "\n";
        }
      }
    }
  }
}

