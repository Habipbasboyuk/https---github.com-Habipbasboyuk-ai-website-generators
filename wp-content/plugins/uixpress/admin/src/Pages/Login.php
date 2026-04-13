<?php
namespace UiXpress\Pages;
use UiXpress\Options\Settings;
use UiXpress\Security\TurnStyle;

// Prevent direct access to this file
defined("ABSPATH") || exit();

/**
 * Class login
 *
 * Adds styles to login page
 */
class Login
{
  private static $is_custom_login = false;
  private static $options = null;
  /**
   * uixpress constructor.
   *
   * Initialises the main app.
   */
  public function __construct()
  {
    // Get options
    add_action("login_init", [$this, "get_options"], 0);
    add_action("login_init", [$this, "maybe_load_turnstyle"], 1);

    // Login Redirect options
    add_action("login_init", [$this, "redirect_login_page"], 1);
    add_filter("login_url", [$this, "custom_login_url"]);
    add_action("init", [$this, "custom_login_page"], 2);
    add_filter("logout_url", [$this, "custom_logout_url"], 10, 2);
    add_filter("retrieve_password_url", [$this, "custom_retrieve_password_url"], 10, 1);
    add_filter("lostpassword_url", [$this, "custom_lostpassword_url"], 10, 2);
    add_filter("lostpassword_redirect", [$this, "custom_lostpassword_redirect"], 10, 2);
    add_filter("retrieve_password_message", [$this, "custom_password_reset_message"], 99, 4);
    add_action("login_form_resetpass", [$this, "modify_resetpass_form"]);
    //add_action("login_form_rp", [$this, "modify_resetpass_form"]);

    // Style and logo options
    add_action("login_header", [$this, "start_login_wrapper"]);
    add_action("login_footer", [$this, "end_login_wrapper"]);
    add_action("login_enqueue_scripts", [$this, "load_styles"]);
    add_filter("login_headerurl", [$this, "login_logo_url"]);
  }

  /**
   * Modifies the reset password form to use the custom login path.
   */
  public function modify_resetpass_form($test)
  {
    $custom_path = self::login_path();

    // No custom path set, so don't modify the form
    if (!$custom_path) {
      return;
    }

    add_filter("resetpass_form", function ($form) use ($custom_path) {
      $new_action = home_url("/{$custom_path}/?action=resetpass");
      return preg_replace('/(action=")[^"]*(")/i', 'action="' . esc_url($new_action) . '"', $form);
    });
  }

  /**
   * Customizes the password reset message to use the custom login path.
   *
   * @param string  $message    Default password reset message.
   * @param string  $key        The password reset key.
   * @param string  $user_login The username for the user.
   * @param WP_User $user_data  WP_User object.
   * @return string The modified password reset message.
   */
  public function custom_password_reset_message($message, $key, $user_login, $user_data)
  {
    $custom_path = self::login_path();

    // No custom path set, so return the default message
    if (!$custom_path) {
      return $message;
    }

    // Replace 'wp-login.php' with the custom path in the message
    $message = str_replace("wp-login.php", $custom_path, $message);

    return $message;
  }

  /**
   * Customizes the password reset URL to use the custom login path.
   *
   * @param string $url The default password reset URL.
   * @return string The modified password reset URL.
   */
  public function custom_lostpassword_redirect($url)
  {
    $custom_path = self::login_path();

    if (!$custom_path) {
      return $url;
    }

    // Construct the new URL
    $new_url = home_url("/{$custom_path}/");
    $new_url = add_query_arg(
      [
        "checkemail" => "confirm",
      ],
      $new_url
    );

    return $new_url;
  }

  /**
   * Customizes the password reset URL to use the custom login path.
   *
   * @param string $url The default password reset URL.
   * @return string The modified password reset URL.
   */
  public function custom_retrieve_password_url($url)
  {
    $custom_path = self::login_path();

    // No custom path set, so return the default URL
    if (!$custom_path) {
      return $url;
    }

    // Parse the original URL
    $parsed_url = parse_url($url);
    $query = [];
    if (isset($parsed_url["query"])) {
      parse_str($parsed_url["query"], $query);
    }

    // Construct the new URL
    $new_url = home_url("/{$custom_path}/");
    $new_url = add_query_arg(
      [
        "action" => "rp",
        "key" => isset($query["key"]) ? $query["key"] : "",
        "login" => isset($query["login"]) ? $query["login"] : "",
      ],
      $new_url
    );

    return $new_url;
  }

  /**
   * Customizes the WordPress logout URL.
   *
   * This function replaces the default WordPress logout URL with a custom one
   * that redirects to the custom login page after logout.
   *
   * @param string $logout_url The default logout URL.
   * @param string $redirect   The path to redirect to after logout, if supplied.
   *
   * @return string The custom logout URL.
   */
  public function custom_logout_url($logout_url, $redirect = "")
  {
    $custom_path = self::login_path();

    // No custom path set, so return the default logout URL
    if (!$custom_path) {
      return $logout_url;
    }

    // Create the custom logout URL
    $custom_logout_url = wp_nonce_url(home_url("/{$custom_path}/?action=logout"), "log-out");

    // If a redirect is specified, add it to the URL
    if (!empty($redirect)) {
      $custom_logout_url = add_query_arg("redirect_to", urlencode($redirect), $custom_logout_url);
    }

    return $custom_logout_url;
  }

  /**
   * Redirects wp-login.php requests to the home page.
   *
   * This function checks if the requested URL contains 'wp-login.php' and
   * if the request method is GET. If both conditions are met, it redirects
   * the user to the home page.
   *
   * @return void
   */
  public static function redirect_login_page()
  {
    if (!isset($GLOBALS["pagenow"]) || self::$is_custom_login || !self::login_path()) {
      return;
    }
    // Update global
    $home_page = home_url();

    if ($GLOBALS["pagenow"] == "wp-login.php" && $_SERVER["REQUEST_METHOD"] == "GET") {
      if (is_user_logged_in()) {
        wp_redirect(admin_url());
      } else {
        wp_redirect($home_page);
      }
      exit();
    }
  }

  /**
   * Customises the WordPress login URL.
   *
   * This function replaces the default WordPress login URL with a custom one.
   * It also handles additional parameters like redirect_to and reauth.
   *
   * @param string $login_url    The login URL. Not used in this implementation.
   * @param string $redirect     The path to redirect to on login, if supplied.
   * @param bool   $force_reauth Whether to force reauthorisation, even if a cookie is present.
   *
   * @return string The custom login URL.
   */
  public static function custom_login_url($login_url, $redirect = "", $force_reauth = false)
  {
    $custom_path = self::login_path();

    // No custom path set so bail
    if (!$custom_path) {
      return $login_url;
    }

    $custom_path = esc_url("/{$custom_path}/");
    $login_url = home_url($custom_path);

    if (!empty($redirect)) {
      $login_url = add_query_arg("redirect_to", urlencode($redirect), $login_url);
    }

    if ($force_reauth) {
      $login_url = add_query_arg("reauth", "1", $login_url);
    }

    return $login_url;
  }

  /**
   * Customizes the WordPress lost password URL.
   *
   * This function replaces the default WordPress lost password URL with a custom one.
   *
   * @param string $lostpassword_url The default lost password URL.
   * @param string $redirect         The path to redirect to after password reset, if supplied.
   *
   * @return string The custom lost password URL.
   */
  public static function custom_lostpassword_url($lostpassword_url, $redirect = "")
  {
    $custom_path = self::login_path();

    // No custom path set so bail
    if (!$custom_path) {
      return $lostpassword_url;
    }

    $custom_path = esc_url("/{$custom_path}/?action=lostpassword");
    $url = home_url($custom_path);

    if (!empty($redirect)) {
      $url = add_query_arg("redirect_to", urlencode($redirect), $url);
    }

    return $url;
  }

  /**
   * Handles requests to the custom login URL.
   *
   * This function intercepts requests to the custom login URL ('/login'),
   * sets up the necessary global variables, and then loads the WordPress
   * login page template.
   *
   * @return void
   */
  public static function custom_login_page()
  {
    $custom_path = self::login_path();

    if (!isset($_SERVER["REQUEST_URI"]) || !$custom_path || is_admin()) {
      return;
    }

    $custom_path = esc_url("/{$custom_path}");
    $length = strlen($custom_path);

    if (substr($_SERVER["REQUEST_URI"], 0, $length) === $custom_path) {
      if (is_user_logged_in()) {
        wp_redirect(admin_url());
      }

      global $error, $interim_login, $action, $user_login;

      // Define variables that wp-login.php expects - sanitize all inputs
      $error = isset($_GET["error"]) ? sanitize_text_field($_GET["error"]) : "";
      $interim_login = isset($_REQUEST["interim-login"]);
      $action = isset($_REQUEST["action"]) ? sanitize_text_field($_REQUEST["action"]) : "login";
      $user_login = isset($_POST["log"]) ? sanitize_text_field($_POST["log"]) : "";

      // Update global
      $GLOBALS["pagenow"] = "wp-login.php";
      self::$is_custom_login = true;

      // Load the login page template
      require_once ABSPATH . "wp-login.php";
      exit();
    }
  }

  /**
   * Gets options
   *
   */
  public static function get_options()
  {
    self::$options = Settings::get();
  }

  /**
   * Gets options
   *
   */
  public static function maybe_load_turnstyle()
  {
    if (!self::turnstyle_enabled() || !self::turnstyle_site_key() || !self::turnstyle_secret_key()) {
      return;
    }
    new TurnStyle(self::turnstyle_site_key(), self::turnstyle_secret_key());
  }

  /**
   * Gets options
   *
   */
  private static function login_path()
  {
    if (is_null(self::$options)) {
      self::get_options();
    }
    return is_array(self::$options) && isset(self::$options["login_path"]) && self::$options["login_path"] ? self::$options["login_path"] : false;
  }

  /**
   * Gets options
   *
   */
  private static function turnstyle_site_key()
  {
    return is_array(self::$options) && isset(self::$options["turnstyle_site_key"]) && self::$options["turnstyle_site_key"] ? self::$options["turnstyle_site_key"] : false;
  }

  /**
   * Gets options
   *
   */
  private static function turnstyle_secret_key()
  {
    return is_array(self::$options) && isset(self::$options["turnstyle_secret_key"]) && self::$options["turnstyle_secret_key"] ? self::$options["turnstyle_secret_key"] : false;
  }

  /**
   * Gets options
   *
   */
  private static function turnstyle_enabled()
  {
    return is_array(self::$options) && isset(self::$options["enable_turnstyle"]) && self::$options["enable_turnstyle"];
  }

  /**
   * Gets options
   *
   */
  private static function login_theme_enabled()
  {
    return is_array(self::$options) && isset(self::$options["style_login"]) && self::$options["style_login"];
  }

  /**
   * Gets options
   *
   */
  private static function has_login_image()
  {
    return is_array(self::$options) && isset(self::$options["login_image"]) && self::$options["login_image"] ? self::$options["login_image"] : false;
  }

  /**
   * Gets options for css
   *
   */
  private static function has_custom_css()
  {
    return is_array(self::$options) && isset(self::$options["custom_css"]) && self::$options["custom_css"] ? self::$options["custom_css"] : false;
  }

  /**
   * Adds a wrap to the login page for when the login theme is enabled
   *
   */
  public static function start_login_wrapper()
  {
    if (!self::login_theme_enabled()) {
      return;
    }

    $script = '
    
        const userThemePreference = localStorage.getItem("uipc_theme") || "system";
        if ((window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches && userThemePreference != "light") || userThemePreference == "dark") {
          document.body.classList.add("dark");
        } else {
          document.body.classList.remove("dark");
        }';

    wp_print_inline_script_tag($script);

    $wrapper = '
        
		<div id="uipx-login-wrap">
			<div id="uipx-login-form-wrap">
				<div id="uipx-login-form">';

    echo wp_kses_post($wrapper);
  }

  /**
   * Adds end of wrap to the login page
   *
   */
  public static function end_login_wrapper()
  {
    if (!self::login_theme_enabled()) {
      return;
    }

    $wrapper = '
	  			</div>
	  		</div>
	  		<div id="uipx-login-panel"></div>
	  </div>
	  <!-- END OF UIP WRAP -->
	 ';

    echo wp_kses_post($wrapper);

    $login_image = self::has_login_image();

    if ($login_image) {
      $css = "
         #uipx-login-panel {
          background-image: url('{$login_image}');
          background-size: cover;
          background-position: center;
         }
      ";

      echo "<style>" . wp_kses_post($css) . "</style>";
    }

    $custom_css = self::has_custom_css();
    if ($custom_css) {
      echo "<style>" . wp_kses_post($custom_css) . "</style>";
    }

    self::load_custom_properties();
  }

  /**
   * Loads user properties
   *
   * @since 3.0.0
   */
  private static function load_custom_properties()
  {
    $base_styles_scale = isset(self::$options["base_theme_scale"]) && is_array(self::$options["base_theme_scale"]) ? self::$options["base_theme_scale"] : [];
    $accent_styles_scale = isset(self::$options["accent_theme_scale"]) && is_array(self::$options["accent_theme_scale"]) ? self::$options["accent_theme_scale"] : [];

    $baseStyles = self::build_custom_properties($base_styles_scale, "base");
    $accentStyles = self::build_custom_properties($accent_styles_scale, "accent");
    ?>
  <style type="text/css">
    <?php echo esc_html($baseStyles); ?>
    <?php echo esc_html($accentStyles); ?>
  </style>
  <?php
  }

  /**
   * Loads user properties
   *
   * @since 3.0.0
   */
  private static function build_custom_properties($scale, $colorName)
  {
    /* Loop custom base scales */
    $baseStyles = ":root{";
    foreach ($scale as $color) {
      if (!is_array($color)) {
        continue;
      }
      $hexArray = self::hexToRgb($color["color"]);
      $escaped_step = esc_html($color["step"]);
      $color_value = is_array($hexArray) ? join(" ", $hexArray) : "";
      $color_value = esc_html($color_value);
      $propertyName = "--uix-{$colorName}-{$escaped_step}";
      $baseStyles .= "{$propertyName}:{$color_value};";
    }

    return $baseStyles .= "}";
  }

  /**
   * Loads required scripts and styles for login pages
   *
   * @since 3.0.0
   */
  public static function load_styles()
  {
    if (!self::login_theme_enabled()) {
      return;
    }

    // Get plugin url
    $url = plugins_url("uixpress/");
    $style = $url . "app/dist/assets/styles/login.css";
    wp_enqueue_style("uixpress-login", $style, [], uixpress_plugin_version);

    $logo = isset(self::$options["logo"]) && self::$options["logo"] != "" ? esc_html(self::$options["logo"]) : false;
    $dark_logo = isset(self::$options["dark_logo"]) && self::$options["dark_logo"] != "" ? esc_html(self::$options["dark_logo"]) : false;

    /* Light logo  */
    if ($logo) { ?>
	<style type="text/css">
	 #login h1 a, .login h1 a {
		background-image: url(<?php echo esc_html($logo); ?>);
		margin-left: 0;
		background-size: contain;
		height: 50px;
		width: auto;
		background-position: left;
	  }
	</style>
  <?php }

    /* Dark logo override */
    if ($dark_logo) { ?>
    <style type="text/css">
       .dark #login h1 a, .dark .login h1 a {
         background-image: url(<?php echo esc_html($dark_logo); ?>);
       }
    </style>
    <?php }
  }

  private static function hexToRgb($hex)
  {
    if (!$hex) {
      return "";
    }
    // Remove the '#' character if it's present
    $hex = ltrim($hex, "#");

    // Parse the hex string
    if (strlen($hex) == 3) {
      // Convert shorthand (e.g. #ABC) to full form (e.g. #AABBCC)
      $r = hexdec(str_repeat(substr($hex, 0, 1), 2));
      $g = hexdec(str_repeat(substr($hex, 1, 1), 2));
      $b = hexdec(str_repeat(substr($hex, 2, 1), 2));
    } else {
      // Parse full form
      $r = hexdec(substr($hex, 0, 2));
      $g = hexdec(substr($hex, 2, 2));
      $b = hexdec(substr($hex, 4, 2));
    }

    // Return RGB values as an array
    return [$r, $g, $b];
  }

  /**
   * Removes wordpress link on login page
   *
   * @since 2.2
   */
  public static function login_logo_url($url)
  {
    return get_home_url();
  }
}
