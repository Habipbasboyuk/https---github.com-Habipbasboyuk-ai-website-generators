<?php

if (!defined('ABSPATH')) exit;

class AISB_Prompts {

    public function system_prompt() {
    $types = $this->section_types();
    $types_str = implode(' | ', array_map(function($t){ return $t; }, $types));

    return <<<PROMPT
You are a senior information architect and web strategist.
Return ONLY valid JSON (no markdown, no backticks, no commentary).

Create a sitemap based on the user's website brief.
The sitemap MUST include navigation hierarchy (parent/child pages).
IMPORTANT: Every page that is NOT the homepage MUST have a parent_slug (use "home" if unsure).
Also: Every page must include at least: Navbar, Hero, Footer sections. Ensure you return the sections in a normal order for a website, i.e. first navbar, then hero, then for example about us section and only at the end the footer.
Aim for 5–10 sections per page.
Blog listing page exception: sections may be: Navbar, Blog Hero (with featured posts), Blog List, Footer.

Each section MUST include:
- section_name (string)
- section_type (string) => MUST be one of: {$types_str}
- purpose (string) of at least 2 sentences. Make sure that a webdesigner knows what to design based on this purpose.
- key_content (array of strings)

Output JSON format:
{
  "website_name": "string",
  "website_goal": "string",
  "primary_audiences": ["string", "..."],
  "sitemap": [
    {
      "page_title": "string",
      "nav_label": "string",
      "slug": "string",
      "page_type": "Home|Service|About|Contact|Blog|Landing|Legal|Other",
      "priority": "Core|Support|Optional",
      "parent_slug": "string|null",
      "sections": [
        { "section_name": "string", "section_type": "string", "purpose": "string", "key_content": ["string", "..."] }
      ],
      "seo": {
        "primary_keyword": "string",
        "secondary_keywords": ["string", "..."],
        "meta_title": "string",
        "meta_description": "string"
      }
    }
  ],
  "notes": ["string", "..."]
}

Rules:
- Keep sitemap sensible and respect the user's desired number of pages if provided.
  Use this guidance:
  - "1" => exactly 1 page (Home only)
  - "2-5" => 2 to 5 pages
  - "5-10" => 5 to 10 pages
  - "10-15" => 10 to 15 pages
  - "15+" => at least 15 pages (cap at 25)
- Use clear, business-friendly page naming.
- Each page has 5–10 sections max (except Blog listing exception described above).
- Slugs are lowercase, hyphenated, no leading slash.
- Exactly one Home page with slug "home" and parent_slug = null.
- Parent/child relationships should be realistic (e.g., Services -> Service Detail pages).
- nav_label is short (1–3 words) for menus.
- SEO keywords must be inferred from the brief (no stuffing).
- For required sections, use these types:
  - Navbar => Headers (or Megamenu Sections - part of navbar if you include megamenu details)
  - Hero => Hero Sections
  - Footer => Footers
PROMPT;
  }

  /**
   * Prompt to generate a single child page (used by AJAX add-page).
   */
  public function single_page_prompt($title, $desc, $parent_slug, $site_context_json) {
    $site_context_json = is_string($site_context_json) ? trim($site_context_json) : '';
    $ctx = $site_context_json !== '' ? $site_context_json : '{}';

    return "Create ONE page JSON object (matching the page schema inside sitemap[]) for the sitemap.\n\n"
      . "Constraints:\n"
      . "- The page must include sections: Navbar, Hero, Footer.\n"
      . "- Each section must include: section_name, section_type, purpose, key_content.\n"
      . "- section_type must be one of the allowed types configured in the system prompt.\n"
      . "- Aim for at least 5 to 10 sections per page (except blog listing rule) 10 sections being better.\n"
      . "- Set parent_slug to: {$parent_slug}\n"
      . "- Create a reasonable slug from the title.\n\n"
      . "Page title: {$title}\n"
      . "Short description: {$desc}\n\n"
      . "Site context (JSON): {$ctx}\n\n"
      . "Return ONLY the JSON object for the page (no wrapping object, no array).";
  }

  public function section_types():array {
    $types = [
      'Banner Section',
      'Blog Sections',
      'Career Sections',
      'Category Filters',
      'Contact Sections',
      'Content Sections',
      'CTA Sections',
      'Event Sections',
      'FAQ Sections',
      'Feature Sections',
      'Footers',
      'Gallery Sections',
      'Headers',
      'Hero Sections',
      'Intro Sections',
      'Logo Sections',
      'Megamenu Sections - part of header section',
      'Portfolio Sections',
      'Pricing Sections',
      'Process Sections',
      'Products Sections',
      'Property Sections',
      'Single Event Sections',
      'Single Portfolio Sections',
      'Single Post Hero',
      'Single Post Sections',
      'Single Product Sections',
      'Single Property Sections',
      'Single Team Sections',
      'Team Sections',
      'Testimonial Sections',
      'Timeline Sections',
    ];

    // Ensure stable array
  $types = array_values(array_filter(array_map('trim', $types)));
  return $types;
  }

  /**
   * System prompt for step 1: generates page structure WITHOUT sections.
   */
  public function structure_only_system_prompt(): string {
    return <<<PROMPT
You are a senior information architect and web strategist.
Return ONLY valid JSON (no markdown, no backticks, no commentary).

Create a sitemap structure based on the user's website brief.
The sitemap MUST include navigation hierarchy (parent/child pages).
IMPORTANT: Every page that is NOT the homepage MUST have a parent_slug (use "home" if unsure).
Do NOT include sections — only the page structure.

Output JSON format:
{
  "website_name": "string",
  "website_goal": "string",
  "primary_audiences": ["string", "..."],
  "sitemap": [
    {
      "page_title": "string",
      "nav_label": "string",
      "slug": "string",
      "page_type": "Home|Service|About|Contact|Blog|Landing|Legal|Other",
      "priority": "Core|Support|Optional",
      "parent_slug": "string|null",
      "page_purpose": "string (2-3 sentences: what this page achieves and who it is for)",
      "seo": {
        "primary_keyword": "string",
        "secondary_keywords": ["string", "..."],
        "meta_title": "string",
        "meta_description": "string"
      }
    }
  ],
  "notes": ["string", "..."]
}

Rules:
- Keep sitemap sensible and respect the user's desired number of pages if provided.
  Use this guidance:
  - "1" => exactly 1 page (Home only)
  - "2-5" => 2 to 5 pages
  - "5-10" => 5 to 10 pages
  - "10-15" => 10 to 15 pages
  - "15+" => at least 15 pages (cap at 25)
- Use clear, business-friendly page naming.
- Slugs are lowercase, hyphenated, no leading slash.
- Exactly one Home page with slug "home" and parent_slug = null.
- Parent/child relationships should be realistic (e.g., Services → Service Detail pages).
- nav_label is short (1–3 words) for menus.
- SEO keywords must be inferred from the brief (no stuffing).
PROMPT;
  }

  /**
   * User prompt for step 2: fills sections into an approved page structure.
   */
  public function fill_sections_user_prompt(string $structure_json): string {
    $types = $this->section_types();
    $types_str = implode(' | ', $types);
    return "Below is an approved sitemap structure (pages only, no sections). "
      . "Your task: add sections to EVERY page in this sitemap.\n\n"
      . "Rules for sections:\n"
      . "- Every page must include at least: Navbar, Hero, Footer sections.\n"
      . "- Return sections in natural order (Navbar first, Footer last).\n"
      . "- Aim for 5–10 sections per page.\n"
      . "- Blog listing page exception: Navbar, Blog Hero (with featured posts), Blog List, Footer.\n"
      . "- Each section must include: section_name, section_type, purpose (at least 2 sentences explaining what a web designer should create), key_content (array of strings).\n"
      . "- section_type must be one of: {$types_str}\n\n"
      . "Return the COMPLETE sitemap JSON with ALL pages, keeping all existing page fields (page_title, nav_label, slug, page_type, priority, parent_slug, page_purpose, seo, notes) exactly as-is — ONLY add a sections array to each page.\n\n"
      . "Existing structure:\n{$structure_json}";
  }

  /**
   * Demo structure response (step 1) — pages without sections.
   */
  public function demo_structure_response(): array {
    return [
      'website_name' => 'Demo Website',
      'website_goal' => 'Showcase capabilities of the AI Sitemap Builder',
      'primary_audiences' => ['Business owners', 'Designers'],
      'sitemap' => [
        [
          'page_title' => 'Home',
          'nav_label' => 'Home',
          'slug' => 'home',
          'page_type' => 'Home',
          'priority' => 'Core',
          'parent_slug' => null,
          'page_purpose' => 'Main landing page that introduces the business and drives visitors to key actions.',
          'sections' => [],
          'seo' => [
            'primary_keyword' => 'demo website',
            'secondary_keywords' => [],
            'meta_title' => 'Demo Website',
            'meta_description' => 'Demo homepage generated without an API key.',
          ],
        ],
        [
          'page_title' => 'About',
          'nav_label' => 'About',
          'slug' => 'about',
          'page_type' => 'About',
          'priority' => 'Core',
          'parent_slug' => 'home',
          'page_purpose' => 'Tells the story of the brand, builds trust, and highlights key team members.',
          'sections' => [],
          'seo' => [
            'primary_keyword' => 'about us',
            'secondary_keywords' => [],
            'meta_title' => 'About Us — Demo Website',
            'meta_description' => 'Learn more about who we are and what we stand for.',
          ],
        ],
        [
          'page_title' => 'Contact',
          'nav_label' => 'Contact',
          'slug' => 'contact',
          'page_type' => 'Contact',
          'priority' => 'Support',
          'parent_slug' => 'home',
          'page_purpose' => 'Allows visitors to reach out via a contact form and find location details.',
          'sections' => [],
          'seo' => [
            'primary_keyword' => 'contact us',
            'secondary_keywords' => [],
            'meta_title' => 'Contact — Demo Website',
            'meta_description' => 'Get in touch with us.',
          ],
        ],
      ],
      'notes' => ['Demo mode — set API key to enable live generation'],
      'structure_only' => true,
    ];
  }

  /**
   * Fallback demo response when no API key is configured.
   * Keep this aligned with the frontend JSON shape.
   */
  public function demo_response(): array {
    return [
      'website_name' => 'Demo Website',
      'sitemap' => [
        [
          'page_title' => 'Home',
          'nav_label' => 'Home',
          'slug' => 'home',
          'page_type' => 'Home',
          'priority' => 'Core',
          'parent_slug' => null,
          'sections' => [
            [
              'section_name' => 'Navbar',
              'section_type' => 'Headers',
              'purpose' => 'Primary navigation and key CTAs.',
              'key_content' => ['Logo', 'Menu items', 'CTA button'],
            ],
            [
              'section_name' => 'Hero',
              'section_type' => 'Hero Sections',
              'purpose' => 'Explain what this website is about and drive the next step.',
              'key_content' => ['Headline', 'Subheadline', 'Primary CTA'],
            ],
            [
              'section_name' => 'Footer',
              'section_type' => 'Footers',
              'purpose' => 'Secondary navigation and contact details.',
              'key_content' => ['Links', 'Contact info', 'Legal'],
            ],
          ],
          'seo' => [
            'primary_keyword' => 'demo website',
            'secondary_keywords' => [],
            'meta_title' => 'Demo Website',
            'meta_description' => 'Demo homepage generated without an API key.',
          ],
        ],
      ],
    ];
  }


}