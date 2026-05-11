<?php
define("DB_NAME", "local");
define("DB_USER", "root");
define("DB_PASSWORD", "root");
define("DB_HOST", "localhost");
$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
if ($mysqli->connect_errno) { echo "Failed to connect to MySQL: " . $mysqli->connect_error; exit; }

$res = $mysqli->query("SELECT ID, post_title FROM wp_posts WHERE post_type = \"bricks_template\" AND post_title LIKE \"%Footer%\"");
while ($row = $res->fetch_assoc()) {
    $meta_res = $mysqli->query("SELECT meta_value FROM wp_postmeta WHERE post_id = " . $row["ID"] . " AND meta_key = \"_bricks_page_content_2\" OR meta_key = \"_bricks_page_footer_2\"");
    while ($meta_row = $meta_res->fetch_assoc()) {
        $meta = unserialize($meta_row["meta_value"]);
        if (is_array($meta)) {
            foreach ($meta as $n) {
                if ($n["name"] == "image" || $n["name"] == "logo") {
                    echo "Footer: " . $row["post_title"] . "\n";
                    echo json_encode(["name" => $n["name"], "settings" => $n["settings"]]) . "\n";
                }
            }
        }
    }
}

