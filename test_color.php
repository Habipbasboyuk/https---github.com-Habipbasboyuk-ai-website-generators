<?php
require 'wp-load.php';

$acss = get_option('automatic_css_options', get_option('acss_options', []));
echo "ACSS:\n";
print_r($acss);

$global_colors = get_option('bricks_global_colors', []);
echo "\nBricks Global:\n";
print_r($global_colors);
