<?php
require 'wp-load.php';
echo "bricks_color_palette:\n";
print_r(get_option('bricks_color_palette', []));
echo "\nbricks_global_variables:\n";
print_r(get_option('bricks_global_variables', []));
echo "\nbricks_theme_styles:\n";
print_r(get_option('bricks_theme_styles', []));
