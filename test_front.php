<?php
$html = file_get_contents('http://ai-sitemap-generators.local/');
if (preg_match_all('/--primary\s*:\s*([^;]+);/i', $html, $matches)) {
    print_r($matches[1]);
}
if (preg_match_all('/--[a-z0-9\-]+primary\s*:\s*([^;]+);/i', $html, $matches)) {
    print_r($matches[0]);
}
