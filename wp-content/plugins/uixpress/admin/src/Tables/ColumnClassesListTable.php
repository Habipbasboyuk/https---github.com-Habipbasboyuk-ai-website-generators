<?php

namespace UiXpress\Tables;

// Prevent direct access to this file
defined("ABSPATH") || exit();

require_once ABSPATH . "wp-admin/includes/class-wp-list-table.php";
require_once ABSPATH . "wp-admin/includes/class-wp-posts-list-table.php";

/**
 * Enhanced WordPress Posts List Table with column class management.
 *
 * This class extends the core WordPress Posts List Table functionality to provide
 * additional features for managing column classes and cell styling. It captures
 * and allows modification of column classes through WordPress filters.
 *
 * @package UiXpress\Tables
 * @extends \WP_Posts_List_Table
 * @since 1.0.0
 */
class ColumnClassesListTable extends \WP_Posts_List_Table
{
  /**
   * Stores the captured classes for each column.
   *
   * @var array<string, string[]>
   */
  public $captured_classes = [];

  /**
   * Prints column headers and captures their classes.
   *
   * Extends the parent method to capture and store classes applied to each column header.
   * Classes can be modified using WordPress filters.
   *
   * @param bool $with_id Whether to include column IDs in the header markup.
   * @return void
   *
   * @uses \WP_Posts_List_Table::get_column_info()
   * @uses apply_filters() Calls 'manage_{post_type}_posts_column_classes'
   *                      and 'manage_posts_column_classes'
   */
  public function print_column_headers($with_id = true)
  {
    list($columns, $hidden, $sortable, $primary) = $this->get_column_info();
    foreach ($columns as $column_key => $column_display_name) {
      $classes = ["manage-column"];
      if (in_array($column_key, $hidden)) {
        $classes[] = "hidden";
      }
      if ("cb" === $column_key) {
        $classes[] = "check-column";
      } elseif ("comments" === $column_key) {
        $classes[] = "num comments column-comments";
      } else {
        $classes[] = "column-$column_key";
      }
      if ($column_key === $primary) {
        $classes[] = "column-primary";
      }
      if (isset($sortable[$column_key])) {
        $classes[] = "sortable";
        $classes[] = $this->get_sort_direction($column_key) === "asc" ? "asc" : "desc";
      }

      $classes = apply_filters("manage_{$this->screen->post_type}_posts_column_classes", $classes, $column_key, $column_display_name);
      $classes = apply_filters("manage_posts_column_classes", $classes, $column_key, $column_display_name);

      $this->captured_classes[$column_key] = array_unique($classes);
    }
  }

  /**
   * Gets the CSS classes for a specific table cell.
   *
   * Generates and returns an array of CSS classes for a table cell based on
   * the column name and post data. Classes can be modified using WordPress filters.
   *
   * @param string    $column_name The name of the column
   * @param \WP_Post  $post        The current post object
   * @return string[] Array of CSS classes for the cell
   *
   * @uses apply_filters() Calls 'manage_{post_type}_posts_column_cell_classes'
   *                      and 'manage_posts_column_cell_classes'
   */
  public function get_cell_classes($column_name, $post)
  {
    $classes = [];
    $classes[] = "column-$column_name";

    list($columns, $hidden, $sortable, $primary) = $this->get_column_info();
    if (in_array($column_name, $hidden)) {
      $classes[] = "hidden";
    }

    switch ($column_name) {
      case "cb":
        $classes[] = "check-column";
        break;
      case "comments":
        $classes[] = "num";
        break;
      case "title":
        if ($post->post_parent > 0) {
          $classes[] = "has-parent";
        }
        if (in_array($post->post_status, ["pending", "draft", "future"])) {
          $classes[] = "status-" . $post->post_status;
        }
        break;
      case "date":
        $classes[] = "date column-date";
        break;
    }

    $classes = apply_filters("manage_{$this->screen->post_type}_posts_column_cell_classes", $classes, $column_name, $post);
    $classes = apply_filters("manage_posts_column_cell_classes", $classes, $column_name, $post);

    return array_unique($classes);
  }

  /**
   * Determines the sort direction for a column.
   *
   * Returns the current sort direction for a given column based on URL parameters
   * or defaults to ascending order.
   *
   * @param string $column The column name to check
   * @return string Sort direction ('asc' or 'desc')
   */
  public function get_sort_direction($column)
  {
    $orderby = isset($_GET["orderby"]) ? $_GET["orderby"] : "";
    $order = isset($_GET["order"]) ? $_GET["order"] : "asc";

    if ($orderby === $column || $this->get_default_primary_column_name() === $column) {
      return strtolower($order);
    }

    return "asc";
  }
}
