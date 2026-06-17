<?php

if (!defined('ABSPATH')) exit;

/**
 * Installeert en migreert de eigen database-tabellen van de plugin.
 *
 * Wordt aangeroepen bij pluginactivatie en maakt de tabellen voor templates en
 * opgeslagen wireframes aan via WordPress dbDelta().
 */
class AISB_Installer {

  const DB_VERSION_OPT = 'aisb_db_version';
  const DB_VERSION = 2;

  public static function activate(): void {
    self::maybe_install();
  }

  // Controleert of de database up-to-date is en voert zo niet de installatie/migratie uit. Wordt ook aangeroepen bij plugin-updates.
  public static function maybe_install(): void {
    $current = (int) get_option(self::DB_VERSION_OPT, 0);
    if ($current >= self::DB_VERSION) return;

    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    // Tabel met geanalyseerde Bricks/Brixies sectietemplates.
    $templates = $wpdb->prefix . 'aisb_section_templates';

    // Tabel met gegenereerde wireframemodellen per project, versie en pagina.
    $wireframes = $wpdb->prefix . 'aisb_wireframes';

    $sql1 = "CREATE TABLE {$templates} (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      section_type VARCHAR(50) NOT NULL,
      layout_key VARCHAR(100) NOT NULL,
      source_url TEXT NULL,
      tags TEXT NULL,
      bricks_json LONGTEXT NOT NULL,
      preview_schema LONGTEXT NOT NULL,
      signature VARCHAR(255) NOT NULL,
      complexity_score INT(11) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY  (id),
      UNIQUE KEY layout_key (layout_key),
      KEY section_type (section_type)
    ) {$charset_collate};";

    $sql2 = "CREATE TABLE {$wireframes} (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      project_id BIGINT(20) UNSIGNED NOT NULL,
      sitemap_version_id BIGINT(20) UNSIGNED NOT NULL,
      page_slug VARCHAR(200) NOT NULL,
      model_json LONGTEXT NOT NULL,
      compiled_bricks_json LONGTEXT NULL,
      created_by BIGINT(20) UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      PRIMARY KEY  (id),
      UNIQUE KEY uniq_page (project_id, sitemap_version_id, page_slug),
      KEY created_by (created_by)
    ) {$charset_collate};";

// dbDelta() vergelijkt de huidige tabellen met de opgegeven SQL en past aan waar nodig, zonder data te verliezen. Het kan ook nieuwe tabellen aanmaken.
    dbDelta($sql1);
    dbDelta($sql2);

    update_option(self::DB_VERSION_OPT, self::DB_VERSION);
  }
}
