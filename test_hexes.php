<?php
require 'wp-load.php';
$v = json_encode(get_option('bricks_global_variables', []));
$c = json_encode(get_option('bricks_color_palette', []));
preg_match_all('/(#[0-9a-fA-F]{6}|#[0-9a-fA-F]{3})/', $v . $c, $matches);
print_r(array_unique($matches[0]));
