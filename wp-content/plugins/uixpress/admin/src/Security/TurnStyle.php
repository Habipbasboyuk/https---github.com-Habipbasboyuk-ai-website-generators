<?php
namespace UiXpress\Security;
/**
 * Class TurnStyle
 *
 * This class integrates Cloudflare Turnstile protection into the WordPress login page.
 */
class TurnStyle
{
  /**
   * @var string $site_key The Cloudflare Turnstile site key.
   */
  private $site_key;

  /**
   * @var string $secret_key The Cloudflare Turnstile secret key.
   */
  private $secret_key;

  /**
   * TurnStyle constructor.
   *
   * @param string $site_key The Cloudflare Turnstile site key.
   * @param string $secret_key The Cloudflare Turnstile secret key.
   */
  public function __construct($site_key, $secret_key)
  {
    $this->site_key = $site_key;
    $this->secret_key = $secret_key;
    $this->init();
  }

  /**
   * Initialize the Turnstile integration by adding necessary hooks.
   */
  private function init()
  {
    add_action("login_enqueue_scripts", [$this, "add_turnstile_script"]);
    add_action("login_form", [$this, "add_turnstile_placeholder"]);
    add_action("login_footer", [$this, "add_turnstile_js"]);
    add_filter("authenticate", [$this, "validate_turnstile"], 21, 1);
    add_action("wp_login_failed", [$this, "handle_failed_login"]);
  }

  /**
   * Enqueue the Cloudflare Turnstile script on the login page.
   */
  public function add_turnstile_script()
  {
    wp_enqueue_script("cloudflare-turnstile", "https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit", [], null, true);
  }

  /**
   * Add the Turnstile widget to the login form.
   */
  public function add_turnstile_placeholder()
  {
    echo '<div id="cf-turnstile-placeholder"></div>';
  }

  /**
   * Add Turnstile JavaScript to the login page footer.
   *
   * This method injects JavaScript code into the login page footer to handle
   * the Turnstile widget initialization and form submission control.
   * It performs the following tasks:
   * 1. Defines an onloadTurnstileCallback function to render the Turnstile widget.
   * 2. Sets up a callback to enable the submit button when Turnstile validation is successful.
   * 3. Adds a DOMContentLoaded event listener to disable the submit button by default.
   *
   * The injected JavaScript ensures that the login form can only be submitted
   * after successful completion of the Turnstile challenge.
   *
   * @return void
   */
  public function add_turnstile_js()
  {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const userThemePreference = localStorage.getItem("uipc_theme") || "system";
        let theme = 'auto';
        if ((window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches && userThemePreference != "light") || userThemePreference == "dark") {
            theme = "dark";
        } else {
            theme = "light";
        }
    
        var submitButton = document.getElementById('wp-submit');
        var turnstileLoaded = false;
        var turnstileError = '';
    
        if (submitButton) {
            submitButton.disabled = true;
        }
    
        turnstile.ready(function() {
            try {
                turnstile.render('#cf-turnstile-placeholder', {
                    sitekey: '<?php echo esc_js($this->site_key); ?>',
                    callback: function(token) {
                        if (submitButton) {
                            submitButton.disabled = false;
                        }
                        turnstileLoaded = true;
                        turnstileError = '';
                    },
                    theme: theme,
                    size: 'flexible',
                    'error-callback': (errorCode) => {
                        if (submitButton) {
                            submitButton.disabled = false;
                        }
                        turnstileLoaded = false;
                        turnstileError = errorCode;
                        console.error('Turnstile error:', errorCode);
                    }
                });
            } catch (error) {
                console.error('Error rendering Turnstile:', error);
                if (submitButton) {
                    submitButton.disabled = false;
                }
                turnstileLoaded = false;
                turnstileError = 'render_failed';
            }
        });
    
        // Add hidden fields to indicate Turnstile status
        var form = document.getElementById('loginform');
        if (form) {
            var loadedField = document.createElement('input');
            loadedField.type = 'hidden';
            loadedField.name = 'turnstile_loaded';
            loadedField.value = 'false';
            form.appendChild(loadedField);
    
            var errorField = document.createElement('input');
            errorField.type = 'hidden';
            errorField.name = 'turnstile_error';
            errorField.value = '';
            form.appendChild(errorField);
    
            form.addEventListener('submit', function() {
                loadedField.value = turnstileLoaded ? 'true' : 'false';
                errorField.value = turnstileError;
            });
        }
    });
    </script>
    <?php
  }

  /**
   * Validate the Turnstile response during login attempt.
   *
   * @param WP_User|WP_Error|null $user WP_User if the user is authenticated, WP_Error or null otherwise.
   *
   * @return WP_User|WP_Error The authenticated user or an error if validation fails.
   */
  public function validate_turnstile($user)
  {
    $request_method = isset($_SERVER["REQUEST_METHOD"]) ? sanitize_text_field($_SERVER["REQUEST_METHOD"]) : '';
    if ($request_method !== "POST" || !isset($_POST["log"], $_POST["pwd"])) {
      return $user;
    }

    $turnstile_loaded = isset($_POST["turnstile_loaded"]) && sanitize_text_field($_POST["turnstile_loaded"]) === "true";
    $turnstile_error = isset($_POST["turnstile_error"]) ? sanitize_text_field($_POST["turnstile_error"]) : "";

    if ($turnstile_loaded) {
      // Proceed with Turnstile validation only if it loaded successfully
      if (!isset($_POST["cf-turnstile-response"])) {
        return new \WP_Error("turnstile_error", "Please complete the Turnstile challenge.");
      }

      $turnstile_response = sanitize_text_field($_POST["cf-turnstile-response"]);
      $remote_ip = isset($_SERVER["REMOTE_ADDR"]) ? sanitize_text_field($_SERVER["REMOTE_ADDR"]) : '';
      // Use WordPress helper for IP if available
      $ip_address = function_exists('wp_get_ip_address') ? wp_get_ip_address() : $remote_ip;

      $response = wp_remote_post("https://challenges.cloudflare.com/turnstile/v0/siteverify", [
        "body" => [
          "secret" => $this->secret_key,
          "response" => $turnstile_response,
          "remoteip" => $ip_address,
        ],
      ]);

      if (is_wp_error($response)) {
        return new \WP_Error("turnstile_error", "Failed to validate Turnstile response.");
      }

      $body = wp_remote_retrieve_body($response);
      $result = json_decode($body, true);

      if (!$result["success"]) {
        return new \WP_Error("turnstile_error", "Turnstile validation failed. Please try again.");
      }
    } else {
      // Check if the error is due to potential bot activity
      if (preg_match("/^(3|6)/", $turnstile_error)) {
        return new \WP_Error("turnstile_error", "Security check failed. Please try again or contact the site administrator.");
      } else {
        // Log the failure to load Turnstile
        error_log("Turnstile failed to load. Error: " . $turnstile_error);
      }
    }

    return $user;
  }

  /**
   * Handle failed login attempts due to Turnstile validation failure.
   *
   * @param string $username The username used in the failed login attempt.
   */
  public function handle_failed_login($username)
  {
    $error = $GLOBALS["errors"]->get_error_message("turnstile_error");
    if (!empty($error)) {
      error_log("Turnstile validation failed for user: $username");
    }
  }
}
