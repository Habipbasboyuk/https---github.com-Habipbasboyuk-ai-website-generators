
const fs = require('fs');
let code = fs.readFileSync('wp-content/plugins/ai-sitemap-builder/includes/step2-wireframes/class-aisb-wireframes-ai.php', 'utf8');

code = code.replace(
    /(\\\\s*=\\s*\\(int\\)\\s*\\\\['bricks_template_id'\\];[\\s\\n]*)(\\\\s*=\\s*get_post_meta\\(\\,\\s*'_bricks_page_content_2',\\s*true\\);)/,
    '\\ = \'_bricks_page_content_2\';\n          \'
);

code = code.replace(
    /(\\\\s*=\\s*get_post_meta\\(\\,\\s*'_bricks_data',\\s*true\\);)/,
    '\\n              if (is_array(\\)) \ = \'_bricks_data\';'
);

code = code.replace(
    /(\\\\s*=\\s*get_post_meta\\(\\,\\s*'_bricks_page_header_2',\\s*true\\);)/,
    '\\n              if (is_array(\\)) \ = \'_bricks_page_header_2\';'
);

code = code.replace(
    /(\\\\s*=\\s*get_post_meta\\(\\,\\s*'_bricks_page_footer_2',\\s*true\\);)/,
    '\\n              if (is_array(\\)) \ = \'_bricks_page_footer_2\';'
);

code = code.replace(
    /\\\\[\\\\]\\s*=\\s*\\;/,
    '\\[\\] = [ \\'data\\' => \\, \\'meta_key\\' => \\ ];'
);

code = code.replace(
    /(\\\\s*=\\s*\\\\[\\\\];)/,
    '\\ = \\[\\][\\'data\\'];\\n        \\ = \\[\\][\\'meta_key\\'];'
);

code = code.replace(
    /update_post_meta\\(\\,\\s*'_bricks_page_content_2',\\s*\\\\);/,
    'update_post_meta(\\, \\'_bricks_page_content_2\\', \\);\\n              if (\\ === \\'_bricks_page_header_2\\' || \\ === \\'_bricks_page_footer_2\\') {\\n                  update_post_meta(\\, \\, \\);\\n              }'
);

fs.writeFileSync('wp-content/plugins/ai-sitemap-builder/includes/step2-wireframes/class-aisb-wireframes-ai.php', code);

