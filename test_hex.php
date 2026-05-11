<?php
require 'wp-load.php';
include_once 'wp-content/plugins/ai-sitemap-builder/includes/step4-design/class-aisb-design.php';

$design = new AISB_Design();
$reflection = new ReflectionClass($design);
$method = $reflection->getMethod('_build_figma_export');
$method->setAccessible(true);
$m2 = $reflection->getMethod('_build_color_map');
$m2->setAccessible(true);

$project_id = 15077;
$guide_raw = get_post_meta($project_id, 'aisb_style_guide', true);
$style_guide = $guide_raw ? json_decode($guide_raw, true) : [];

$map = $m2->invokeArgs($design, [$style_guide]);
echo "Primary Hex: " . ($map['--primary'] ?? 'none') . "\n";
echo "Light Hex: " . ($map['--light'] ?? 'none') . "\n";
echo "White Hex: " . ($map['--white'] ?? 'none') . "\n";
echo "Dark Hex: " . ($map['--dark'] ?? 'none') . "\n";
