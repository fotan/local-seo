<?php
/**
 * Plugin Name: Local SEO
 * Description: Generates LocalBusiness JSON-LD structured data in wp_head from a settings form. Uses a shared @id so it merges into an existing Organization node (e.g. The SEO Framework) instead of conflicting. Blank fields are omitted from the output.
 * Version:     1.4.3
 * Author:      Matt Danskine
 * License:     GPL-2.0-or-later
 * Requires PHP: 7.4
 * Text Domain: local-seo
 */

if (!defined("ABSPATH")) {
    exit();
}

// ==============================================================================
// Auto-updates from GitHub
// ------------------------------------------------------------------------------
// Plugin Update Checker (vendor/) watches this public GitHub repo for tagged
// releases. When a new tag lands, WordPress (and bulk tools like MainWP) shows
// a normal "update available" notice on the Plugins list, and updating swaps
// the files the same way any other plugin update does. No token needed — the
// repo is public. Releases are cut automatically by .github/workflows/release.yml
// whenever the Version header in this file is bumped on the master branch.
// ==============================================================================
require __DIR__ . "/vendor/plugin-update-checker/plugin-update-checker.php";

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$local_seo_update_checker = PucFactory::buildUpdateChecker(
    "https://github.com/fotan/local-seo/",
    __FILE__,
    "local-seo",
);
$local_seo_update_checker->setBranch("master");
// Install the clean plugin zip attached to each GitHub Release (built by
// .github/workflows/release.yml) rather than GitHub's auto-generated source
// tarball, which would carry .github/, .gitignore, etc. into wp-content/plugins.
if (
    method_exists($local_seo_update_checker->getVcsApi(), "enableReleaseAssets")
) {
    $local_seo_update_checker
        ->getVcsApi()
        ->enableReleaseAssets('/local-seo\.zip$/');
}

class Local_SEO_Plugin {
    const OPTION_KEY = "local_seo_options";
    const MENU_SLUG = "local-seo";

    /** @var Local_SEO_Plugin|null */
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action("admin_menu", [$this, "add_menu"]);
        add_action("admin_init", [$this, "register_settings"]);
        // Priority 2 puts our node right after The SEO Framework's head output (which runs at priority 1),
        // so the two JSON-LD blocks sit together near the top of the page source.
        add_action(
            "wp_head",
            [$this, "output_schema"],
            (int) apply_filters("local_seo_wp_head_priority", 2),
        );
        add_shortcode("local_seo_hours", [$this, "render_hours_shortcode"]);
    }

    /* ---------------------------------------------------------------------
     * Options
     * ------------------------------------------------------------------- */

    public function defaults() {
        return [
            "schema_type" => "LocalBusiness",
            "schema_id" => home_url("/#/schema/Organization"),
            "telephone" => "",
            "email" => "",
            "image" => "",
            "price_range" => "",
            "description" => "",
            "street_address" => "",
            "address_locality" => "",
            "address_region" => "",
            "postal_code" => "",
            "address_country" => "",
            "latitude" => "",
            "longitude" => "",
            "include_hasmap" => 0,
            "area_served" => "",
            // List of rows: [ 'days' => [...], 'opens' => 'HH:MM', 'closes' => 'HH:MM' ].
            // A row's days can share one open/close pair (e.g. Mon-Fri 9-5), and
            // different rows can have different hours (e.g. Sat 10-2) — one
            // OpeningHoursSpecification is emitted per row.
            "hours" => [],
            "hours_shortcode_enabled" => 0,
            "temporarily_closed" => 0,
            "temporarily_closed_heading" => "",
            "temporarily_closed_note" => "",
            "founding_date" => "",
        ];
    }

    public function get_options() {
        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        $out = wp_parse_args($saved, $this->defaults());

        // Migrate pre-1.1.0 single open/close range (hours_days/hours_opens/
        // hours_closes) into the new multi-row "hours" format on read, so
        // sites upgrading don't lose their saved hours before their next save.
        if (
            empty($out["hours"]) &&
            !empty($saved["hours_days"]) &&
            !empty($saved["hours_opens"]) &&
            !empty($saved["hours_closes"])
        ) {
            $out["hours"] = [
                [
                    "days" => $saved["hours_days"],
                    "opens" => $saved["hours_opens"],
                    "closes" => $saved["hours_closes"],
                ],
            ];
        }

        return $out;
    }

    /* ---------------------------------------------------------------------
     * Admin menu + settings registration
     * ------------------------------------------------------------------- */

    /**
     * Minimum capability to see the menu and open the settings page.
     * Defaults to `edit_pages` — i.e. the Editor role and up. Filter it to
     * `manage_options` to lock it back down to administrators only.
     */
    public function capability() {
        return (string) apply_filters("local_seo_capability", "edit_pages");
    }

    public function add_menu() {
        add_menu_page(
            __("Local SEO", "local-seo"),
            __("Local SEO", "local-seo"),
            $this->capability(),
            self::MENU_SLUG,
            [$this, "render_settings_page"],
            "dashicons-location-alt",
            3,
        );
    }

    public function register_settings() {
        // `capability` (WP 5.3+) lets non-admins submit options.php for this
        // setting — without it the Settings API save would 403 for Editors
        // even though they can see the page.
        register_setting(self::OPTION_KEY . "_group", self::OPTION_KEY, [
            "sanitize_callback" => [$this, "sanitize"],
            "capability" => $this->capability(),
        ]);
    }

    /* ---------------------------------------------------------------------
     * Sanitising
     * ------------------------------------------------------------------- */

    public function sanitize($input) {
        $out = $this->defaults();

        if (!is_array($input)) {
            return $out;
        }

        $out["schema_type"] = sanitize_text_field(
            isset($input["schema_type"]) ? $input["schema_type"] : "",
        );
        if ("" === $out["schema_type"]) {
            $out["schema_type"] = "LocalBusiness";
        }

        $out["schema_id"] = esc_url_raw(
            isset($input["schema_id"]) ? $input["schema_id"] : "",
        );
        $out["telephone"] = $this->clean_phone(
            isset($input["telephone"]) ? $input["telephone"] : "",
        );
        $out["email"] = sanitize_email(
            isset($input["email"]) ? $input["email"] : "",
        );
        $out["image"] = esc_url_raw(
            isset($input["image"]) ? $input["image"] : "",
        );
        $price_range = isset($input["price_range"])
            ? trim((string) $input["price_range"])
            : "";
        $out["price_range"] = in_array(
            $price_range,
            ["$", "$$", "$$$", "$$$$"],
            true,
        )
            ? $price_range
            : "";
        $out["description"] = sanitize_textarea_field(
            isset($input["description"]) ? $input["description"] : "",
        );
        $out["street_address"] = sanitize_text_field(
            isset($input["street_address"]) ? $input["street_address"] : "",
        );
        $out["address_locality"] = sanitize_text_field(
            isset($input["address_locality"]) ? $input["address_locality"] : "",
        );
        $out["address_region"] = sanitize_text_field(
            isset($input["address_region"]) ? $input["address_region"] : "",
        );
        $out["postal_code"] = sanitize_text_field(
            isset($input["postal_code"]) ? $input["postal_code"] : "",
        );
        $out["address_country"] = sanitize_text_field(
            isset($input["address_country"]) ? $input["address_country"] : "",
        );
        $out["latitude"] = $this->clean_decimal(
            isset($input["latitude"]) ? $input["latitude"] : "",
        );
        $out["longitude"] = $this->clean_decimal(
            isset($input["longitude"]) ? $input["longitude"] : "",
        );
        $out["include_hasmap"] = empty($input["include_hasmap"]) ? 0 : 1;
        $out["area_served"] = sanitize_textarea_field(
            isset($input["area_served"]) ? $input["area_served"] : "",
        );

        $out["hours"] = [];
        if (!empty($input["hours"]) && is_array($input["hours"])) {
            foreach ($input["hours"] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $row_days = [];
                if (!empty($row["days"]) && is_array($row["days"])) {
                    foreach ($this->valid_days() as $d) {
                        if (in_array($d, $row["days"], true)) {
                            $row_days[] = $d;
                        }
                    }
                }
                $opens = $this->clean_time(
                    isset($row["opens"]) ? $row["opens"] : "",
                );
                $closes = $this->clean_time(
                    isset($row["closes"]) ? $row["closes"] : "",
                );
                // Drop incomplete rows rather than emitting a bare/misleading
                // OpeningHoursSpecification for them.
                if (empty($row_days) || "" === $opens || "" === $closes) {
                    continue;
                }
                $out["hours"][] = [
                    "days" => $row_days,
                    "opens" => $opens,
                    "closes" => $closes,
                ];
            }
        }
        $out["hours_shortcode_enabled"] = empty(
            $input["hours_shortcode_enabled"]
        )
            ? 0
            : 1;
        $out["temporarily_closed"] = empty($input["temporarily_closed"])
            ? 0
            : 1;
        $out["temporarily_closed_heading"] = sanitize_text_field(
            isset($input["temporarily_closed_heading"])
                ? $input["temporarily_closed_heading"]
                : "",
        );
        $out["temporarily_closed_note"] = sanitize_text_field(
            isset($input["temporarily_closed_note"])
                ? $input["temporarily_closed_note"]
                : "",
        );
        $out["founding_date"] = $this->clean_date(
            isset($input["founding_date"]) ? $input["founding_date"] : "",
        );

        return $out;
    }

    private function clean_decimal($v) {
        $v = trim((string) $v);
        return preg_match('/^-?\d+(\.\d+)?$/', $v) ? $v : "";
    }

    /**
     * Normalise to E.164 (e.g. +15551234567), the format schema.org recommends
     * and the one that stays consistent with a Google Business Profile.
     */
    private function clean_phone($v) {
        $v = trim((string) $v);
        $had_plus = "" !== $v && "+" === $v[0];
        $digits = preg_replace("/\D+/", "", $v);

        if (!$had_plus && 10 === strlen($digits)) {
            // Bare US/Canada number: assume +1.
            $digits = "1" . $digits;
        }

        if ("" !== $digits && strlen($digits) >= 8 && strlen($digits) <= 15) {
            return "+" . $digits;
        }

        return sanitize_text_field($v);
    }

    private function valid_days() {
        return [
            "Monday",
            "Tuesday",
            "Wednesday",
            "Thursday",
            "Friday",
            "Saturday",
            "Sunday",
        ];
    }

    /**
     * valid_days(), rotated to start on the day set in Settings > General >
     * Week Starts On (the "start_of_week" option: 0 = Sunday ... 6 = Saturday).
     * Used for shortcode display order; the admin editing table and the JSON-LD
     * dayOfWeek order are unaffected — this is purely a display concern.
     */
    private function week_starting_days() {
        $week = [
            "Sunday",
            "Monday",
            "Tuesday",
            "Wednesday",
            "Thursday",
            "Friday",
            "Saturday",
        ];
        $start = ((int) get_option("start_of_week", 0)) % 7;
        return array_merge(array_slice($week, $start), array_slice($week, 0, $start));
    }

    private function clean_time($v) {
        $v = trim((string) $v);
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : "";
    }

    private function clean_date($v) {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : "";
    }

    /* ---------------------------------------------------------------------
     * Schema output
     * ------------------------------------------------------------------- */

    public function build_schema_array() {
        $o = $this->get_options();
        $node = [];

        $node["@context"] = "https://schema.org";
        $node["@type"] =
            "" !== $o["schema_type"] ? $o["schema_type"] : "LocalBusiness";
        if ("" !== $o["schema_id"]) {
            $node["@id"] = $o["schema_id"];
        }

        if ("" !== $o["telephone"]) {
            $node["telephone"] = $o["telephone"];
        }
        if ("" !== $o["email"]) {
            $node["email"] = $o["email"];
        }
        if ("" !== $o["image"]) {
            $node["image"] = $o["image"];
        }
        if ("" !== $o["price_range"]) {
            $node["priceRange"] = $o["price_range"];
        }
        if ("" !== $o["description"]) {
            $node["description"] = $o["description"];
        }

        $address = [];
        if ("" !== $o["street_address"]) {
            $address["streetAddress"] = $o["street_address"];
        }
        if ("" !== $o["address_locality"]) {
            $address["addressLocality"] = $o["address_locality"];
        }
        if ("" !== $o["address_region"]) {
            $address["addressRegion"] = $o["address_region"];
        }
        if ("" !== $o["postal_code"]) {
            $address["postalCode"] = $o["postal_code"];
        }
        if ("" !== $o["address_country"]) {
            $address["addressCountry"] = $o["address_country"];
        }
        if (!empty($address)) {
            $node["address"] = array_merge(
                ["@type" => "PostalAddress"],
                $address,
            );
        }

        if ("" !== $o["latitude"] && "" !== $o["longitude"]) {
            $node["geo"] = [
                "@type" => "GeoCoordinates",
                "latitude" => $o["latitude"],
                "longitude" => $o["longitude"],
            ];
            if ($o["include_hasmap"]) {
                $node["hasMap"] =
                    "https://www.google.com/maps/search/?api=1&query=" .
                    $o["latitude"] .
                    "," .
                    $o["longitude"];
            }
        }

        $areas = array_filter(
            array_map("trim", preg_split('/\r\n|\r|\n/', $o["area_served"])),
            "strlen",
        );
        if (!empty($areas)) {
            $node["areaServed"] = [];
            foreach ($areas as $a) {
                $node["areaServed"][] = [
                    "@type" => "City",
                    "name" => $a,
                ];
            }
        }

        // schema.org/Google Search have no recognised "temporarily closed"
        // property for LocalBusiness — that status comes from the Google
        // Business Profile, not page markup — so there's nothing accurate to
        // add here. What we *can* do accurately is stop claiming hours that
        // no longer apply while closed.
        if (!empty($o["hours"]) && empty($o["temporarily_closed"])) {
            $node["openingHoursSpecification"] = [];
            foreach ($o["hours"] as $row) {
                $node["openingHoursSpecification"][] = [
                    "@type" => "OpeningHoursSpecification",
                    "dayOfWeek" => array_values($row["days"]),
                    "opens" => $row["opens"],
                    "closes" => $row["closes"],
                ];
            }
        }

        if ("" !== $o["founding_date"]) {
            $node["foundingDate"] = $o["founding_date"];
        }

        return $node;
    }

    public function output_schema() {
        if (is_admin()) {
            return;
        }

        $node = $this->build_schema_array();

        // Don't emit a bare node with nothing useful on it.
        $meaningful = array_diff_key(
            $node,
            array_flip(["@context", "@type", "@id"]),
        );
        if (empty($meaningful)) {
            return;
        }

        // Slashes are left escaped (no JSON_UNESCAPED_SLASHES) and tags/ampersands/
        // quotes are hex-encoded so nothing in any field can break out of the
        // <script> element or its HTML script-data parsing state.
        $json = wp_json_encode(
            $node,
            JSON_UNESCAPED_UNICODE |
                JSON_PRETTY_PRINT |
                JSON_HEX_TAG |
                JSON_HEX_AMP |
                JSON_HEX_APOS |
                JSON_HEX_QUOT,
        );

        // $json is safe for a <script> context: wp_json_encode() above hex-encodes
        // <, >, &, ' and " (JSON_HEX_* flags), so no field value can terminate the
        // element or shift its script-data parser state. No further escaping applies
        // to a JSON string without corrupting it.
        $out =
            "\n<!-- BEGIN Local SEO plugin output -->\n" .
            "<script type=\"application/ld+json\">\n" .
            $json .
            "\n</script>\n" .
            "<!-- END Local SEO plugin output -->\n";
        echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see note above; JSON hex-encoded, safe in <script>
    }

    /**
     * [local_seo_hours] — renders the Opening Hours settings as one line per
     * calendar day (Monday through Sunday, always all seven, in that order),
     * showing "Closed" for any day not covered by a saved row. Gated on the
     * "Enable [local_seo_hours] shortcode" checkbox. Each element is a block
     * (<div>), so the list reads vertically by default; every element also
     * gets a CSS class so the theme can restyle it without editing PHP:
     *
     *   .ls-hours                  wrapper
     *   .ls-hours-row              one calendar day's row
     *   .ls-hours-row.ls-hours-monday, etc.   that specific day, individually
     *   .ls-hours-row.ls-hours-today          the row for today
     *   .ls-hours-row.ls-hours-closed         a day with no hours set
     *   .ls-hours-day              the day-name span ("Monday:")
     *   .ls-hours-time             the hours span ("9:00 am–5:00 pm" or "Closed")
     *
     * If "Temporarily Closed" is checked, the day-by-day list is replaced
     * entirely by the Closed Heading/Closed Note text (since listing hours
     * that don't currently apply would be misleading). Nothing is output if
     * both are left blank; either one alone is fine.
     *
     *   .ls-hours.ls-hours-temporarily-closed   wrapper, closed state
     *   .ls-temp-closed-heading                the heading text (an <h3>)
     *   .ls-temp-closed-note                   the note text
     */
    public function render_hours_shortcode($atts = []) {
        $o = $this->get_options();
        if (empty($o["hours_shortcode_enabled"])) {
            return "";
        }

        if (!empty($o["temporarily_closed"])) {
            $heading = $o["temporarily_closed_heading"];
            $note = $o["temporarily_closed_note"];
            if ("" === $heading && "" === $note) {
                return "";
            }
            $html = '<div class="ls-hours ls-hours-temporarily-closed">';
            if ("" !== $heading) {
                $html .=
                    '<h3 class="ls-temp-closed-heading">' .
                    esc_html($heading) .
                    "</h3>";
            }
            if ("" !== $note) {
                $html .=
                    '<div class="ls-temp-closed-note">' .
                    esc_html($note) .
                    "</div>";
            }
            $html .= "</div>";
            return $html;
        }

        if (empty($o["hours"])) {
            return "";
        }

        $time_format = get_option("time_format", "g:i a");
        $today = wp_date("l");

        // Flatten the saved rows into a single day => {opens, closes} lookup,
        // so every calendar day can be rendered once, in order, below.
        $by_day = [];
        foreach ($o["hours"] as $row) {
            foreach ($row["days"] as $d) {
                $by_day[$d] = $row;
            }
        }

        $html = '<div class="ls-hours">';
        foreach ($this->week_starting_days() as $d) {
            $row = isset($by_day[$d]) ? $by_day[$d] : null;

            $classes = ["ls-hours-row", "ls-hours-" . strtolower($d)];
            if ($d === $today) {
                $classes[] = "ls-hours-today";
            }
            if (!$row) {
                $classes[] = "ls-hours-closed";
            }

            if ($row) {
                $opens = date_i18n($time_format, strtotime($row["opens"]));
                $closes = date_i18n($time_format, strtotime($row["closes"]));
                $time_text = esc_html($opens) . "&ndash;" . esc_html($closes);
            } else {
                $time_text = esc_html__("Closed", "local-seo");
            }

            $html .= sprintf(
                '<div class="%1$s"><span class="ls-hours-day">%2$s:</span> <span class="ls-hours-time">%3$s</span></div>',
                esc_attr(implode(" ", $classes)),
                esc_html($d),
                $time_text,
            );
        }
        $html .= "</div>";

        return $html;
    }

    /* ---------------------------------------------------------------------
     * Settings page
     * ------------------------------------------------------------------- */

    /**
     * Renders one <tr> of the opening-hours repeater. Used both for existing
     * saved rows and, with $index = "__INDEX__", as the JS clone template.
     */
    private function render_hours_row($index, $row, $name, $all_days) {
        $row = wp_parse_args($row, ["days" => [], "opens" => "", "closes" => ""]);
        $base = $name . "[hours][" . $index . "]";
        ?>
        <tr>
            <td>
                <?php foreach ($all_days as $d): ?>
                    <label style="display:inline-block;min-width:8em;">
                        <input type="checkbox" value="<?php echo esc_attr($d); ?>"
                            name="<?php echo esc_attr($base); ?>[days][]"
                            <?php checked(
                                in_array($d, $row["days"], true),
                            ); ?> />
                        <?php echo esc_html($d); ?>
                    </label>
                <?php endforeach; ?>
            </td>
            <td><input type="time" name="<?php echo esc_attr(
                $base,
            ); ?>[opens]" value="<?php echo esc_attr($row["opens"]); ?>" /></td>
            <td><input type="time" name="<?php echo esc_attr(
                $base,
            ); ?>[closes]" value="<?php echo esc_attr(
     $row["closes"],
 ); ?>" /></td>
            <td><button type="button" class="button-link-delete ls-hours-remove"><?php esc_html_e(
                "Remove",
                "local-seo",
            ); ?></button></td>
        </tr>
        <?php
    }

    public function render_settings_page() {
        if (!current_user_can($this->capability())) {
            return;
        }

        wp_enqueue_media();

        $o = $this->get_options();
        $days = [
            "Monday",
            "Tuesday",
            "Wednesday",
            "Thursday",
            "Friday",
            "Saturday",
            "Sunday",
        ];
        $name = self::OPTION_KEY;
        ?>
		<div class="wrap">
			<h1><?php esc_html_e("Local SEO", "local-seo"); ?></h1>
			<p class="description">
				<?php esc_html_e(
        "Outputs LocalBusiness structured data in the site head. Leave a field blank to omit it from the output.",
        "local-seo",
    ); ?>
			</p>

			<ul class="subsubsub" style="float:none;margin-bottom:1em;">
				<?php
    $ls_toc = [
        "ls-section-schema-identity" => __("Schema Identity", "local-seo"),
        "ls-section-contact" => __("Contact", "local-seo"),
        "ls-section-address" => __("Address", "local-seo"),
        "ls-section-geo" => __("Geo", "local-seo"),
        "ls-section-service-areas" => __("Service Areas", "local-seo"),
        "ls-section-opening-hours" => __("Opening Hours", "local-seo"),
        "ls-section-other" => __("Other", "local-seo"),
        "ls-section-current-output" => __("Current Output", "local-seo"),
    ];
    $ls_toc_last = array_key_last($ls_toc);
    foreach ($ls_toc as $ls_toc_id => $ls_toc_label):
        ?>
					<li>
						<a href="#<?php echo esc_attr(
          $ls_toc_id,
      ); ?>"><?php echo esc_html($ls_toc_label); ?></a>
						<?php echo $ls_toc_id !== $ls_toc_last ? " |" : ""; ?>
					</li>
				<?php
    endforeach; ?>
			</ul>

			<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">
			<div style="flex:1 1 520px;min-width:0;">

			<form method="post" action="options.php">
				<?php settings_fields(self::OPTION_KEY . "_group"); ?>

				<h2 class="title" id="ls-section-schema-identity"><?php esc_html_e("Schema Identity", "local-seo"); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ls_schema_type"><?php esc_html_e(
          "Business Type",
          "local-seo",
      ); ?></label></th>
						<td>
							<input type="text" id="ls_schema_type" class="regular-text"
								name="<?php echo esc_attr($name); ?>[schema_type]"
								value="<?php echo esc_attr(
            $o["schema_type"],
        ); ?>" placeholder="LocalBusiness" />
							<p class="description">
								<?php esc_html_e(
            "A schema.org type, e.g. LocalBusiness, Store, ProfessionalService.",
            "local-seo",
        ); ?>
								<a href="https://schema.org/LocalBusiness" target="_blank" rel="noopener noreferrer"><?php esc_html_e(
            "Browse business types on schema.org",
            "local-seo",
        ); ?></a>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ls_schema_id"><?php esc_html_e(
          "Schema @id",
          "local-seo",
      ); ?></label></th>
						<td>
							<input type="text" id="ls_schema_id" class="large-text code"
								name="<?php echo esc_attr($name); ?>[schema_id]"
								value="<?php echo esc_attr($o["schema_id"]); ?>" />
							<p class="description"><?php esc_html_e(
           "Match this to your existing Organization node @id (e.g. The SEO Framework) so the two merge into one entity instead of conflicting.",
           "local-seo",
       ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title" id="ls-section-contact"><?php esc_html_e("Contact", "local-seo"); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ls_telephone"><?php esc_html_e(
          "Telephone",
          "local-seo",
      ); ?></label></th>
						<td><input type="text" id="ls_telephone" class="regular-text"
							name="<?php echo esc_attr($name); ?>[telephone]"
							value="<?php echo esc_attr($o["telephone"]); ?>" />
							<p class="description"><?php esc_html_e(
           "Saved in E.164 format (e.g. +15551234567). A bare 10-digit number is treated as US/Canada (+1).",
           "local-seo",
       ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="ls_email"><?php esc_html_e(
          "Email",
          "local-seo",
      ); ?></label></th>
						<td><input type="email" id="ls_email" class="regular-text"
							name="<?php echo esc_attr($name); ?>[email]"
							value="<?php echo esc_attr($o["email"]); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ls_image"><?php esc_html_e(
          "Image",
          "local-seo",
      ); ?></label></th>
						<td>
							<input type="url" id="ls_image" class="large-text code ls-image-url"
								name="<?php echo esc_attr($name); ?>[image]"
								value="<?php echo esc_attr($o["image"]); ?>" />
							<p>
								<button type="button" class="button ls-image-pick"><?php esc_html_e(
            "Choose from media library",
            "local-seo",
        ); ?></button>
								<button type="button" class="button ls-image-clear"<?php echo "" === $o["image"]
            ? ' style="display:none;"'
            : ""; ?>><?php esc_html_e("Remove", "local-seo"); ?></button>
							</p>
							<p class="ls-image-preview">
								<?php if ("" !== $o["image"]): ?>
									<img src="<?php echo esc_url(
             $o["image"],
         ); ?>" alt="" style="max-width:180px;height:auto;border:1px solid #ccd0d4;" />
								<?php endif; ?>
							</p>
							<p class="description"><?php esc_html_e(
           "Google's LocalBusiness rich result recommends an image; the Organization logo does not count for it. Prefer a real photo (storefront, products, work samples) over the logo. Use a high-resolution file; 16:9, 4:3, and 1:1 crops are ideal. Points at the media library URL, so replacing the file in the library updates the markup.",
           "local-seo",
       ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ls_description"><?php esc_html_e(
          "Description",
          "local-seo",
      ); ?></label></th>
						<td><textarea id="ls_description" class="large-text" rows="3"
							name="<?php echo esc_attr($name); ?>[description]"><?php echo esc_textarea(
    $o["description"],
); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="ls_price_range"><?php esc_html_e(
          "Price Range",
          "local-seo",
      ); ?></label></th>
						<td>
							<?php $price_ranges = [
           "" => __("— not set —", "local-seo"),
           "$" => __("$ — Inexpensive", "local-seo"),
           "$$" => __("$$ — Moderate", "local-seo"),
           "$$$" => __("$$$ — Pricey", "local-seo"),
           "$$$$" => __("$$$$ — High-End", "local-seo"),
       ]; ?>
							<select id="ls_price_range" name="<?php echo esc_attr($name); ?>[price_range]">
								<?php foreach ($price_ranges as $value => $label): ?>
									<option value="<?php echo esc_attr($value); ?>" <?php selected(
    $o["price_range"],
    $value,
); ?>><?php echo esc_html($label); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e(
           "Google's LocalBusiness rich result recommends priceRange. The dollar-sign scale ($ to $$$$) is the format it expects; picking a value here always emits it in that form.",
           "local-seo",
       ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title" id="ls-section-address"><?php esc_html_e("Address", "local-seo"); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ls_street"><?php esc_html_e(
          "Street Address",
          "local-seo",
      ); ?></label></th>
						<td>
							<input type="text" id="ls_street" class="regular-text"
								name="<?php echo esc_attr($name); ?>[street_address]"
								value="<?php echo esc_attr($o["street_address"]); ?>" />
							<p class="description"><?php esc_html_e(
           "Leave Street Address blank if you are a service-area business — you travel to customers or work remotely and they do not visit a premises of yours. In that case list the places you cover under Areas served instead, and keep this empty so search engines and your Google Business Profile agree that there is no public storefront. Google's testing tools will still show a “missing address” warning; that is only a recommendation and is safe to ignore here. Fill this in only if customers actually come to a location of yours, even by appointment.",
           "local-seo",
       ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ls_locality"><?php esc_html_e(
          "City / Locality",
          "local-seo",
      ); ?></label></th>
						<td><input type="text" id="ls_locality" class="regular-text"
							name="<?php echo esc_attr($name); ?>[address_locality]"
							value="<?php echo esc_attr($o["address_locality"]); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ls_region"><?php esc_html_e(
          "State / Region",
          "local-seo",
      ); ?></label></th>
						<td><input type="text" id="ls_region" class="regular-text"
							name="<?php echo esc_attr($name); ?>[address_region]"
							value="<?php echo esc_attr($o["address_region"]); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ls_postal"><?php esc_html_e(
          "Postal Code",
          "local-seo",
      ); ?></label></th>
						<td><input type="text" id="ls_postal" class="regular-text"
							name="<?php echo esc_attr($name); ?>[postal_code]"
							value="<?php echo esc_attr($o["postal_code"]); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ls_country"><?php esc_html_e(
          "Country",
          "local-seo",
      ); ?></label></th>
						<td>
							<input type="text" id="ls_country" class="small-text"
								name="<?php echo esc_attr($name); ?>[address_country]"
								value="<?php echo esc_attr($o["address_country"]); ?>" placeholder="US" />
							<p class="description"><?php esc_html_e(
           "Two-letter country code, e.g. US.",
           "local-seo",
       ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title" id="ls-section-geo"><?php esc_html_e("Geo", "local-seo"); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ls_lat"><?php esc_html_e(
          "Latitude",
          "local-seo",
      ); ?></label></th>
						<td><input type="text" id="ls_lat" class="regular-text"
							name="<?php echo esc_attr($name); ?>[latitude]"
							value="<?php echo esc_attr($o["latitude"]); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ls_lng"><?php esc_html_e(
          "Longitude",
          "local-seo",
      ); ?></label></th>
						<td><input type="text" id="ls_lng" class="regular-text"
							name="<?php echo esc_attr($name); ?>[longitude]"
							value="<?php echo esc_attr($o["longitude"]); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e("Map Link", "local-seo"); ?></th>
						<td>
							<label>
								<input type="checkbox" value="1"
									name="<?php echo esc_attr($name); ?>[include_hasmap]"
									<?php checked($o["include_hasmap"], 1); ?> />
								<?php esc_html_e(
            "Include a hasMap link (generated from the coordinates above)",
            "local-seo",
        ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2 class="title" id="ls-section-service-areas"><?php esc_html_e("Service Areas", "local-seo"); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ls_areas"><?php esc_html_e(
          "Areas Served",
          "local-seo",
      ); ?></label></th>
						<td>
							<textarea id="ls_areas" class="large-text code" rows="5"
								name="<?php echo esc_attr($name); ?>[area_served]"><?php echo esc_textarea(
    $o["area_served"],
); ?></textarea>
							<p class="description"><?php esc_html_e(
           "One city per line. Each becomes an areaServed City entry.",
           "local-seo",
       ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title" id="ls-section-opening-hours"><?php esc_html_e("Opening Hours", "local-seo"); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e(
      "Temporarily Closed",
      "local-seo",
  ); ?></th>
						<td>
							<label>
								<input type="checkbox" value="1" id="ls_temp_closed"
									name="<?php echo esc_attr(
       $name,
   ); ?>[temporarily_closed]"
									<?php checked(
           !empty($o["temporarily_closed"]),
       ); ?> />
								<?php esc_html_e(
        "Business is temporarily closed (e.g. seasonal)",
        "local-seo",
    ); ?>
							</label>
						<p class="description"><?php esc_html_e(
           "While checked, the [local_seo_hours] shortcode shows the heading/note below instead of the daily hours, and hours are left out of the structured data. There is no schema.org/Google property for \"temporarily closed\" itself, so this only controls what's shown on your own pages.",
           "local-seo",
       ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ls_temp_closed_heading"><?php esc_html_e(
          "Closed Heading",
          "local-seo",
      ); ?></label></th>
					<td>
						<input type="text" id="ls_temp_closed_heading" class="regular-text"
							name="<?php echo esc_attr(
       $name,
   ); ?>[temporarily_closed_heading]"
							value="<?php echo esc_attr(
       $o["temporarily_closed_heading"],
   ); ?>" />
						<p class="description"><?php esc_html_e(
           "Optional, rendered as an <h3>, e.g. \"Closed for the Season\".",
           "local-seo",
       ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ls_temp_closed_note"><?php esc_html_e(
          "Closed Note",
          "local-seo",
      ); ?></label></th>
					<td>
						<input type="text" id="ls_temp_closed_note" class="regular-text"
							name="<?php echo esc_attr(
       $name,
   ); ?>[temporarily_closed_note]"
							value="<?php echo esc_attr(
       $o["temporarily_closed_note"],
   ); ?>" />
						<p class="description"><?php esc_html_e(
           "Optional, e.g. \"Reopening in March\".",
           "local-seo",
       ); ?></p>
					</td>
				</tr>
			</table>

				<p class="description">
					<?php esc_html_e(
     "Add one row per group of days that share the same hours (e.g. Mon-Fri 9-5, then a second row for Sat 10-2). A row is only output once at least one day and both times are set.",
     "local-seo",
 ); ?>
				</p>
				<table class="widefat" style="max-width:800px;" id="ls-hours-table">
					<thead>
						<tr>
							<th><?php esc_html_e("Days", "local-seo"); ?></th>
							<th><?php esc_html_e("Opens", "local-seo"); ?></th>
							<th><?php esc_html_e("Closes", "local-seo"); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody id="ls-hours-rows">
						<?php foreach ($o["hours"] as $i => $row): ?>
							<?php $this->render_hours_row($i, $row, $name, $days); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="ls-hours-add"><?php esc_html_e(
       "+ Add Row",
       "local-seo",
   ); ?></button></p>

				<p>
					<label>
						<input type="checkbox" value="1"
							name="<?php echo esc_attr(
       $name,
   ); ?>[hours_shortcode_enabled]"
							<?php checked(
           !empty($o["hours_shortcode_enabled"]),
       ); ?> />
						<?php esc_html_e(
        "Enable the [local_seo_hours] shortcode",
        "local-seo",
    ); ?>
					</label>
				</p>
				<p class="description">
					<?php esc_html_e(
       "Place [local_seo_hours] in any page, post, or widget. It lists all seven days, in order, showing \"Closed\" for any day with no hours set. Each part gets a CSS class to style from your theme:",
       "local-seo",
   ); ?>
					<code>.ls-hours</code>,
					<code>.ls-hours-row</code>
					(<code>.ls-hours-monday</code>
					<?php esc_html_e(
        "etc., one per day, plus",
        "local-seo",
    ); ?>
					<code>.ls-hours-today</code>
					<?php esc_html_e("and", "local-seo"); ?>
					<code>.ls-hours-closed</code>),
					<code>.ls-hours-day</code>,
					<code>.ls-hours-time</code>.
				</p>

				<template id="ls-hours-row-template">
					<?php $this->render_hours_row(
       "__INDEX__",
       ["days" => [], "opens" => "", "closes" => ""],
       $name,
       $days,
   ); ?>
				</template>

				<script>
				( function () {
					var rows = document.getElementById( 'ls-hours-rows' );
					var addButton = document.getElementById( 'ls-hours-add' );
					var template = document.getElementById( 'ls-hours-row-template' );
					var nextIndex = <?php echo (int) count($o["hours"]); ?>;

					addButton.addEventListener( 'click', function () {
						var html = template.innerHTML.replace( /__INDEX__/g, String( nextIndex ) );
						var tmp = document.createElement( 'tbody' );
						tmp.innerHTML = html;
						rows.appendChild( tmp.firstElementChild );
						nextIndex++;
					} );

					rows.addEventListener( 'click', function ( e ) {
						if ( e.target && e.target.classList.contains( 'ls-hours-remove' ) ) {
							var tr = e.target.closest( 'tr' );
							if ( tr ) {
								tr.parentNode.removeChild( tr );
							}
						}
					} );
				} )();
				</script>

				<h2 class="title" id="ls-section-other"><?php esc_html_e("Other", "local-seo"); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ls_founding"><?php esc_html_e(
          "Founding Date",
          "local-seo",
      ); ?></label></th>
						<td><input type="date" id="ls_founding"
							name="<?php echo esc_attr($name); ?>[founding_date]"
							value="<?php echo esc_attr($o["founding_date"]); ?>" /></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2 id="ls-section-current-output"><?php esc_html_e("Current Output", "local-seo"); ?></h2>
			<?php
   $preview = $this->build_schema_array();
   $has_more = array_diff_key(
       $preview,
       array_flip(["@context", "@type", "@id"]),
   );
   if (empty($has_more)) {
       echo "<p><em>" .
           esc_html__(
               "Nothing will be output yet — fill in at least one field above.",
               "local-seo",
           ) .
           "</em></p>";
   } else {
       echo '<pre class="code" style="padding:1em;background:#fff;border:1px solid #ccd0d4;overflow:auto;">' .
           esc_html(
               wp_json_encode(
                   $preview,
                   JSON_UNESCAPED_SLASHES |
                       JSON_UNESCAPED_UNICODE |
                       JSON_PRETTY_PRINT,
               ),
           ) .
           "</pre>";
   }
   ?>

			</div><!-- /left column -->

			<div style="flex:0 0 550px;">
				<div class="postbox">
					<h2 class="hndle" style="padding:8px 12px;"><?php esc_html_e(
         "About this plugin",
         "local-seo",
     ); ?></h2>
					<div class="inside">
						<p><?php esc_html_e(
          "This plugin is made to work specifically with The SEO Framework, adding local SEO data that search engines use.",
          "local-seo",
      ); ?></p>
						<p><?php esc_html_e(
          'It emits a LocalBusiness node sharing the same @id as The SEO Framework\'s Organization node, so the two merge into a single entity in your structured data instead of conflicting.',
          "local-seo",
      ); ?></p>
					</div>
				</div>

				<div class="postbox">
					<h2 class="hndle" style="padding:8px 12px;"><?php esc_html_e(
         "Keep this consistent with your Google Business Profile",
         "local-seo",
     ); ?></h2>
					<div class="inside">
						<p><?php esc_html_e(
          "Search engines cross-reference this markup against your Google Business Profile and other listings. Different formatting is harmless, but different facts weaken your local ranking and can surface wrong details in search results.",
          "local-seo",
      ); ?></p>
						<ul style="list-style:disc;margin-left:1.4em;">
							<li><strong><?php esc_html_e(
           "Name:",
           "local-seo",
       ); ?></strong> <?php esc_html_e(
    "Use your real-world business name exactly as it appears on the profile. Do not add keywords or location.",
    "local-seo",
); ?></li>
							<li><strong><?php esc_html_e(
           "Address:",
           "local-seo",
       ); ?></strong> <?php esc_html_e(
    "Match the profile line for line (same suite number, same abbreviations). If you are service-area only, leave the address blank.",
    "local-seo",
); ?></li>
							<li><strong><?php esc_html_e(
           "Phone:",
           "local-seo",
       ); ?></strong> <?php esc_html_e(
    "Must be the same number as the profile, not a separate tracking or forwarding line. It is stored in E.164 format.",
    "local-seo",
); ?></li>
							<li><strong><?php esc_html_e(
           "Hours:",
           "local-seo",
       ); ?></strong> <?php esc_html_e(
    "If these disagree with the profile, Google trusts the profile and treats the conflict as a poor signal.",
    "local-seo",
); ?></li>
						</ul>
						<p><?php esc_html_e(
          "Treat the Google Business Profile as the source of truth and mirror it here.",
          "local-seo",
      ); ?></p>
					</div>
				</div>
			</div><!-- /right column -->

			</div><!-- /columns -->
		</div>
		<script>
		// The wp.media scripts are printed in the admin footer, after this inline
		// block, so wait for full page load before wiring the picker up.
		window.addEventListener( 'load', function () {
			var wrap = document.getElementById( 'ls_image' );
			if ( ! wrap || ! window.wp || ! wp.media ) {
				return;
			}
			var row     = wrap.closest( 'td' );
			var field   = row.querySelector( '.ls-image-url' );
			var preview = row.querySelector( '.ls-image-preview' );
			var clear   = row.querySelector( '.ls-image-clear' );
			var frame;

			function setValue( url ) {
				field.value = url;
				preview.textContent = '';
				if ( url ) {
					var img = document.createElement( 'img' );
					img.src = url;
					img.alt = '';
					img.style.cssText = 'max-width:180px;height:auto;border:1px solid #ccd0d4;';
					preview.appendChild( img );
				}
				clear.style.display = url ? '' : 'none';
			}

			row.querySelector( '.ls-image-pick' ).addEventListener( 'click', function () {
				if ( ! frame ) {
					frame = wp.media( {
						title: '<?php echo esc_js(__("Choose an image", "local-seo")); ?>',
						button: { text: '<?php echo esc_js(__("Use this image", "local-seo")); ?>' },
						library: { type: 'image' },
						multiple: false
					} );
					frame.on( 'select', function () {
						var att = frame.state().get( 'selection' ).first().toJSON();
						var size = att.sizes && att.sizes.large ? att.sizes.large.url : att.url;
						setValue( size );
					} );
				}
				frame.open();
			} );

			clear.addEventListener( 'click', function () {
				setValue( '' );
			} );
		} );
		</script>
		<?php
    }
}

Local_SEO_Plugin::instance();

/**
 * Public accessor for other code (theme templates, site-specific snippets,
 * blocks) that wants to display the same opening hours this plugin outputs
 * as JSON-LD, without reaching into the plugin's internals or wp_options
 * directly. Returns a list of rows: [ 'days' => [...], 'opens' => 'HH:MM',
 * 'closes' => 'HH:MM' ]. Empty/incomplete rows are already filtered out.
 */
function local_seo_get_hours() {
    return Local_SEO_Plugin::instance()->get_options()["hours"];
}
