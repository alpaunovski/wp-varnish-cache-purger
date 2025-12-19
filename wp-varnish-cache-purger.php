<?php
/**
 * Plugin Name:       WP Varnish Cache Purger
 * Plugin URI:        https://example.com/wp-varnish-cache-purger
 * Description:       Purge a Varnish cache whenever content is published or updated and on an automatic schedule.
 * Version:           1.0.0
 * Author:            Cloud Panel Tools
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-varnish-cache-purger
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_Varnish_Cache_Purger')) {
    final class WP_Varnish_Cache_Purger
    {
        private const OPTION_NAME = 'wp_vcp_settings';
        private const CRON_HOOK = 'wp_vcp_scheduled_purge';

        /**
         * @var WP_Varnish_Cache_Purger|null
         */
        private static $instance = null;

        /**
         * Retrieve singleton instance.
         */
        public static function instance(): WP_Varnish_Cache_Purger
        {
            if (null === self::$instance) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        private function __construct()
        {
            add_action('init', [$this, 'ensure_event_scheduled']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_menu', [$this, 'register_settings_page']);
            add_action('add_option_' . self::OPTION_NAME, [$this, 'maybe_reschedule_on_add'], 10, 2);
            add_action('update_option_' . self::OPTION_NAME, [$this, 'maybe_reschedule_on_update'], 10, 2);

            add_filter('cron_schedules', [$this, 'register_custom_schedules']);
            add_action(self::CRON_HOOK, [$this, 'run_scheduled_purge']);

            add_action('transition_post_status', [$this, 'handle_post_transition'], 10, 3);
        }

        /**
         * Plugin activation callback.
         */
        public static function activate(): void
        {
            self::instance()->schedule_event();
        }

        /**
         * Plugin deactivation callback.
         */
        public static function deactivate(): void
        {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }

        /**
         * Ensure the cron event exists in case it was cleared externally.
         */
        public function ensure_event_scheduled(): void
        {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                $this->schedule_event();
            }
        }

        /**
         * Register plugin settings.
         */
        public function register_settings(): void
        {
            register_setting(
                'wp_vcp_settings',
                self::OPTION_NAME,
                [
                    'type'              => 'array',
                    'sanitize_callback' => [$this, 'sanitize_settings'],
                    'default'           => $this->get_default_settings(),
                ]
            );

            add_settings_section(
                'wp_vcp_general_section',
                __('Varnish Settings', 'wp-varnish-cache-purger'),
                '__return_false',
                'wp-vcp'
            );

            add_settings_field(
                'wp_vcp_hosts',
                __('Varnish Endpoints', 'wp-varnish-cache-purger'),
                [$this, 'render_hosts_field'],
                'wp-vcp',
                'wp_vcp_general_section'
            );

            add_settings_field(
                'wp_vcp_purge_server',
                __('Direct Varnish Server (Optional)', 'wp-varnish-cache-purger'),
                [$this, 'render_purge_server_field'],
                'wp-vcp',
                'wp_vcp_general_section'
            );

            add_settings_field(
                'wp_vcp_schedule',
                __('Schedule Interval', 'wp-varnish-cache-purger'),
                [$this, 'render_schedule_field'],
                'wp-vcp',
                'wp_vcp_general_section'
            );

            add_settings_field(
                'wp_vcp_daily_time',
                __('Daily Purge Time', 'wp-varnish-cache-purger'),
                [$this, 'render_daily_time_field'],
                'wp-vcp',
                'wp_vcp_general_section'
            );

            add_settings_field(
                'wp_vcp_weekly_schedule',
                __('Weekly Purge Timing', 'wp-varnish-cache-purger'),
                [$this, 'render_weekly_schedule_field'],
                'wp-vcp',
                'wp_vcp_general_section'
            );

            add_settings_field(
                'wp_vcp_paths',
                __('Paths to Purge on Schedule', 'wp-varnish-cache-purger'),
                [$this, 'render_paths_field'],
                'wp-vcp',
                'wp_vcp_general_section'
            );

            add_settings_field(
                'wp_vcp_header',
                __('Optional Authentication Header', 'wp-varnish-cache-purger'),
                [$this, 'render_header_field'],
                'wp-vcp',
                'wp_vcp_general_section'
            );

            add_settings_field(
                'wp_vcp_verbose_logging',
                __('Verbose Debug Logging', 'wp-varnish-cache-purger'),
                [$this, 'render_verbose_logging_field'],
                'wp-vcp',
                'wp_vcp_general_section'
            );

            add_settings_field(
                'wp_vcp_post_purge',
                __('Automatic Post Purge', 'wp-varnish-cache-purger'),
                [$this, 'render_post_purge_field'],
                'wp-vcp',
                'wp_vcp_general_section'
            );
        }

        /**
         * Add an entry to the Settings menu.
         */
        public function register_settings_page(): void
        {
            add_options_page(
                __('Varnish Cache Purger', 'wp-varnish-cache-purger'),
                __('Varnish Cache Purger', 'wp-varnish-cache-purger'),
                'manage_options',
                'wp-vcp',
                [$this, 'render_settings_page']
            );
        }

        /**
         * Handle initial option creation.
         *
         * @param string $option
         * @param mixed  $value
         */
        public function maybe_reschedule_on_add(string $option, $value): void
        {
            if (self::OPTION_NAME !== $option || !is_array($value)) {
                return;
            }

            $this->schedule_event($value['schedule_interval'] ?? null, $value);
        }

        /**
         * Re-schedule cron event whenever settings change.
         *
         * @param mixed $old_value
         * @param mixed $value
         */
        public function maybe_reschedule_on_update($old_value, $value): void
        {
            if (!is_array($value)) {
                return;
            }

            $this->schedule_event($value['schedule_interval'] ?? null, $value);
        }

        /**
         * Register additional cron schedules.
         *
         * @param array<string,array> $schedules
         *
         * @return array<string,array>
         */
        public function register_custom_schedules(array $schedules): array
        {
            $schedules['five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __('Every Five Minutes', 'wp-varnish-cache-purger'),
            ];

            $schedules['fifteen_minutes'] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __('Every Fifteen Minutes', 'wp-varnish-cache-purger'),
            ];

            return $schedules;
        }

        /**
         * Run the scheduled purge job.
         */
        public function run_scheduled_purge(): void
        {
            $settings = $this->get_settings();
            $hosts    = $settings['hosts'];
            $paths    = $settings['scheduled_paths'];
            foreach ($hosts as $host) {
                $host_header = $this->get_host_header_for_endpoint($host);
                foreach ($paths as $path) {
                    $url = $this->build_purge_request_url($host, $path);
                    $this->send_purge_request($url, 'schedule', $host_header);
                }
            }
        }

        /**
         * Hook into post status transitions to purge cached pages.
         *
         * @param string  $new_status
         * @param string  $old_status
         * @param WP_Post $post
         */
        public function handle_post_transition(string $new_status, string $old_status, $post): void
        {
            if (!is_a($post, 'WP_Post')) {
                return;
            }

            if ('revision' === $post->post_type) {
                return;
            }

            $settings = $this->get_settings();
            $is_publish_transition = ('publish' === $new_status && 'publish' !== $old_status);
            $is_publish_update     = ('publish' === $new_status && 'publish' === $old_status);

            if ($is_publish_transition && !empty($settings['purge_on_publish'])) {
                $this->purge_post_urls($post, 'publish');
            } elseif ($is_publish_update && !empty($settings['purge_on_update'])) {
                $this->purge_post_urls($post, 'update');
            }
        }

        /**
         * Purge URLs attached to a post.
         *
         * @param WP_Post $post
         * @param string  $context
         */
        private function purge_post_urls($post, string $context): void
        {
            $permalink = get_permalink($post);
            if (!$permalink) {
                return;
            }

            $settings = $this->get_settings();
            $hosts    = $settings['hosts'];
            $urls     = [];

            foreach ($hosts as $host) {
                $urls[] = [
                    'url'  => $this->swap_host_in_url($permalink, $host),
                    'host' => $host,
                ];

                // Purge homepage when posts change as it usually lists latest content.
                if (!empty($settings['purge_home_on_post'])) {
                    $urls[] = [
                        'url'  => trailingslashit($host),
                        'host' => $host,
                    ];
                }
            }

            $urls = array_filter($urls);
            foreach ($urls as $entry) {
                if (!is_array($entry) || empty($entry['url']) || empty($entry['host'])) {
                    continue;
                }

                [$path, $query] = $this->get_path_and_query($entry['url']);
                $request_url = $this->build_purge_request_url($entry['host'], $path, $query);
                $host_header = $this->get_host_header_for_endpoint($entry['host']);
                $this->send_purge_request($request_url, $context, $host_header);
            }
        }

        /**
         * Render settings page output.
         */
        public function render_settings_page(): void
        {
            if (!current_user_can('manage_options')) {
                return;
            }

            ?>
            <div class="wrap">
                <h1><?php esc_html_e('Varnish Cache Purger', 'wp-varnish-cache-purger'); ?></h1>
                <form action="options.php" method="post">
                    <?php
                    settings_fields('wp_vcp_settings');
                    do_settings_sections('wp-vcp');
                    submit_button(__('Save Settings', 'wp-varnish-cache-purger'));
                    ?>
                </form>
            </div>
            <?php
        }

        /**
         * Render hosts textarea field.
         */
        public function render_hosts_field(): void
        {
            $settings   = $this->get_settings();
            $hosts      = implode("\n", $settings['hosts']);
            $field_id   = 'wp_vcp_hosts';
            $field_name = self::OPTION_NAME . '[hosts]';
            ?>
            <textarea
                id="<?php echo esc_attr($field_id); ?>"
                name="<?php echo esc_attr($field_name); ?>"
                rows="5"
                cols="50"
                class="large-text code"
                placeholder="https://example.com"
            ><?php echo esc_textarea($hosts); ?></textarea>
            <p class="description">
                <?php esc_html_e('Enter one Varnish endpoint (protocol + domain) per line.', 'wp-varnish-cache-purger'); ?>
            </p>
            <?php
        }

        /**
         * Render direct Varnish server field.
         */
        public function render_purge_server_field(): void
        {
            $settings   = $this->get_settings();
            $field_id   = 'wp_vcp_purge_server';
            $field_name = self::OPTION_NAME . '[purge_server]';
            ?>
            <input
                type="text"
                id="<?php echo esc_attr($field_id); ?>"
                name="<?php echo esc_attr($field_name); ?>"
                value="<?php echo esc_attr($settings['purge_server']); ?>"
                class="regular-text code"
                placeholder="varnish.internal:6081"
            />
            <p class="description">
                <?php esc_html_e('Send PURGE requests to this server (bypassing CDN/proxy). Host header is set from each endpoint above. Leave blank to purge the public URLs directly.', 'wp-varnish-cache-purger'); ?>
            </p>
            <?php
        }

        /**
         * Render cron schedule select field.
         */
        public function render_schedule_field(): void
        {
            $settings = $this->get_settings();
            $field_id = 'wp_vcp_schedule';
            $name     = self::OPTION_NAME . '[schedule_interval]';
            $current  = $settings['schedule_interval'];
            $schedules = wp_get_schedules();
            ?>
            <select id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($name); ?>">
                <?php foreach ($schedules as $slug => $schedule) : ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($slug, $current); ?>>
                        <?php echo esc_html($schedule['display']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="description">
                <?php esc_html_e('Choose how often the full-site purge should run. Set the exact time below for daily or weekly schedules.', 'wp-varnish-cache-purger'); ?>
            </p>
            <?php
        }

        /**
         * Render daily time field.
         */
        public function render_daily_time_field(): void
        {
            $settings = $this->get_settings();
            $field_id = 'wp_vcp_daily_time';
            $name     = self::OPTION_NAME . '[daily_time]';
            ?>
            <input
                type="time"
                id="<?php echo esc_attr($field_id); ?>"
                name="<?php echo esc_attr($name); ?>"
                value="<?php echo esc_attr($settings['daily_time']); ?>"
                step="60"
            />
            <p class="description">
                <?php esc_html_e('24-hour time (WordPress timezone) for the daily purge. Ignored for other schedules.', 'wp-varnish-cache-purger'); ?>
            </p>
            <?php
        }

        /**
         * Render weekly schedule field.
         */
        public function render_weekly_schedule_field(): void
        {
            $settings  = $this->get_settings();
            $day_name  = self::OPTION_NAME . '[weekly_day]';
            $time_name = self::OPTION_NAME . '[weekly_time]';
            $days      = [
                0 => __('Sunday', 'wp-varnish-cache-purger'),
                1 => __('Monday', 'wp-varnish-cache-purger'),
                2 => __('Tuesday', 'wp-varnish-cache-purger'),
                3 => __('Wednesday', 'wp-varnish-cache-purger'),
                4 => __('Thursday', 'wp-varnish-cache-purger'),
                5 => __('Friday', 'wp-varnish-cache-purger'),
                6 => __('Saturday', 'wp-varnish-cache-purger'),
            ];
            ?>
            <label>
                <span class="screen-reader-text"><?php esc_html_e('Weekly Purge Day', 'wp-varnish-cache-purger'); ?></span>
                <select name="<?php echo esc_attr($day_name); ?>">
                    <?php foreach ($days as $value => $label) : ?>
                        <option value="<?php echo esc_attr((string) $value); ?>" <?php selected((int) $settings['weekly_day'], $value); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span class="screen-reader-text"><?php esc_html_e('Weekly Purge Time', 'wp-varnish-cache-purger'); ?></span>
                <input
                    type="time"
                    name="<?php echo esc_attr($time_name); ?>"
                    value="<?php echo esc_attr($settings['weekly_time']); ?>"
                    step="60"
                />
            </label>
            <p class="description">
                <?php esc_html_e('Select the day of week and 24-hour time (WordPress timezone) for the weekly purge. Ignored for other schedules.', 'wp-varnish-cache-purger'); ?>
            </p>
            <?php
        }

        /**
         * Render scheduled paths textarea field.
         */
        public function render_paths_field(): void
        {
            $settings   = $this->get_settings();
            $paths      = implode("\n", $settings['scheduled_paths']);
            $field_id   = 'wp_vcp_paths';
            $field_name = self::OPTION_NAME . '[scheduled_paths]';
            ?>
            <textarea
                id="<?php echo esc_attr($field_id); ?>"
                name="<?php echo esc_attr($field_name); ?>"
                rows="4"
                cols="50"
                class="large-text code"
                placeholder="/"
            ><?php echo esc_textarea($paths); ?></textarea>
            <p class="description">
                <?php esc_html_e('Paths to purge during the scheduled run. Use relative URLs such as /, /blog/, /feed/.', 'wp-varnish-cache-purger'); ?>
            </p>
            <?php
        }

        /**
         * Render optional header inputs.
         */
        public function render_header_field(): void
        {
            $settings    = $this->get_settings();
            $name_field  = self::OPTION_NAME . '[header_name]';
            $value_field = self::OPTION_NAME . '[header_value]';
            ?>
            <input
                type="text"
                class="regular-text"
                name="<?php echo esc_attr($name_field); ?>"
                value="<?php echo esc_attr($settings['header_name']); ?>"
                placeholder="X-Secret-Header"
            />
            <input
                type="text"
                class="regular-text"
                name="<?php echo esc_attr($value_field); ?>"
                value="<?php echo esc_attr($settings['header_value']); ?>"
                placeholder="token"
            />
            <p class="description">
                <?php esc_html_e('Optional header sent with each purge request for protected Varnish instances.', 'wp-varnish-cache-purger'); ?>
            </p>
            <?php
        }

        /**
         * Render verbose logging checkbox field.
         */
        public function render_verbose_logging_field(): void
        {
            $settings = $this->get_settings();
            ?>
            <fieldset>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME . '[verbose_logging]'); ?>" value="1" <?php checked($settings['verbose_logging'], true); ?> />
                    <?php esc_html_e('Log detailed purge responses to the PHP error log.', 'wp-varnish-cache-purger'); ?>
                </label>
            </fieldset>
            <?php
        }

        /**
         * Render publish/update purge checkboxes.
         */
        public function render_post_purge_field(): void
        {
            $settings = $this->get_settings();
            ?>
            <fieldset>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME . '[purge_on_publish]'); ?>" value="1" <?php checked($settings['purge_on_publish'], true); ?> />
                    <?php esc_html_e('Purge when posts are first published', 'wp-varnish-cache-purger'); ?>
                </label>
                <br />
                <label>
                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME . '[purge_on_update]'); ?>" value="1" <?php checked($settings['purge_on_update'], true); ?> />
                    <?php esc_html_e('Purge when published posts are updated', 'wp-varnish-cache-purger'); ?>
                </label>
                <br />
                <label>
                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME . '[purge_home_on_post]'); ?>" value="1" <?php checked($settings['purge_home_on_post'], true); ?> />
                    <?php esc_html_e('Include the homepage when purging posts', 'wp-varnish-cache-purger'); ?>
                </label>
            </fieldset>
            <?php
        }

        /**
         * Sanitize user settings.
         *
         * @param mixed $raw
         *
         * @return array<string,mixed>
         */
        public function sanitize_settings($raw): array
        {
            $defaults = $this->get_default_settings();
            $sanitized = $defaults;

            if (is_array($raw)) {
                if (!empty($raw['hosts'])) {
                    $sanitized['hosts'] = $this->sanitize_hosts($raw['hosts']);
                }

                if (!empty($raw['purge_server'])) {
                    $sanitized['purge_server'] = $this->sanitize_purge_server($raw['purge_server']);
                } else {
                    $sanitized['purge_server'] = '';
                }

                if (!empty($raw['scheduled_paths'])) {
                    $sanitized['scheduled_paths'] = $this->sanitize_paths($raw['scheduled_paths']);
                }

                if (!empty($raw['schedule_interval'])) {
                    $candidate = sanitize_key($raw['schedule_interval']);
                    $schedules = wp_get_schedules();
                    if (isset($schedules[$candidate])) {
                        $sanitized['schedule_interval'] = $candidate;
                    }
                }

                $sanitized['daily_time']  = $this->sanitize_time_field($raw['daily_time'] ?? $defaults['daily_time'], $defaults['daily_time']);
                $sanitized['weekly_time'] = $this->sanitize_time_field($raw['weekly_time'] ?? $defaults['weekly_time'], $defaults['weekly_time']);
                $sanitized['weekly_day']  = $this->sanitize_weekday($raw['weekly_day'] ?? $defaults['weekly_day']);

                $sanitized['purge_on_publish'] = !empty($raw['purge_on_publish']);
                $sanitized['purge_on_update']  = !empty($raw['purge_on_update']);
                $sanitized['purge_home_on_post'] = !empty($raw['purge_home_on_post']);

                $sanitized['header_name']  = isset($raw['header_name']) ? sanitize_text_field($raw['header_name']) : '';
                $sanitized['header_value'] = isset($raw['header_value']) ? sanitize_text_field($raw['header_value']) : '';
                $sanitized['verbose_logging'] = !empty($raw['verbose_logging']);
            }

            return $sanitized;
        }

        /**
         * Parse hosts input into a normalized array.
         *
         * @param string|array<int,string> $hosts_input
         *
         * @return array<int,string>
         */
        private function sanitize_hosts($hosts_input): array
        {
            if (is_string($hosts_input)) {
                $lines = explode("\n", $hosts_input);
            } else {
                $lines = (array) $hosts_input;
            }

            $hosts = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }

                $line = esc_url_raw($line);
                if (empty($line)) {
                    continue;
                }

                $hosts[] = untrailingslashit($line);
            }

            if (empty($hosts)) {
                $hosts = $this->get_default_settings()['hosts'];
            }

            return array_values(array_unique($hosts));
        }

        /**
         * Parse scheduled paths input into normalized array.
         *
         * @param string|array<int,string> $paths_input
         *
         * @return array<int,string>
         */
        private function sanitize_paths($paths_input): array
        {
            if (is_string($paths_input)) {
                $lines = explode("\n", $paths_input);
            } else {
                $lines = (array) $paths_input;
            }

            $paths = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }

                $line = '/' . ltrim($line, '/');
                $paths[] = $line;
            }

            if (empty($paths)) {
                $paths = $this->get_default_settings()['scheduled_paths'];
            }

            return array_values(array_unique($paths));
        }

        /**
         * Sanitize direct Varnish server address.
         */
        private function sanitize_purge_server($server): string
        {
            if (!is_string($server)) {
                return '';
            }

            $server = trim($server);
            if ('' === $server) {
                return '';
            }

            $server = rtrim($server, '/');
            return sanitize_text_field($server);
        }

        /**
         * Sanitize HH:MM time fields.
         */
        private function sanitize_time_field($time, string $fallback): string
        {
            if (!is_string($time)) {
                return $fallback;
            }

            $time = trim($time);
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $matches)) {
                return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
            }

            return $fallback;
        }

        /**
         * Sanitize weekday selection (0 = Sunday ... 6 = Saturday).
         */
        private function sanitize_weekday($day): int
        {
            if (is_numeric($day)) {
                $day = (int) $day;
                if ($day >= 0 && $day <= 6) {
                    return $day;
                }
            }

            return $this->get_default_settings()['weekly_day'];
        }

        /**
         * Build URL from host + relative path.
         */
        private function build_url(string $host, string $path): string
        {
            $host = untrailingslashit($host);
            $path = '/' . ltrim($path, '/');

            return $host . $path;
        }

        /**
         * Build a purge URL against the direct Varnish server when configured.
         */
        private function build_purge_request_url(string $host, string $path, string $query = ''): string
        {
            $path = '/' . ltrim($path, '/');
            $query = ltrim($query, '?');
            $settings = $this->get_settings();
            $purge_server = trim($settings['purge_server']);

            if ('' === $purge_server) {
                $url = $this->build_url($host, $path);
            } else {
                $purge_server = rtrim($purge_server, '/');
                if (!preg_match('#^https?://#i', $purge_server)) {
                    $purge_server = 'http://' . $purge_server;
                }
                $url = $purge_server . $path;
            }

            if ('' !== $query) {
                $url .= '?' . $query;
            }

            return $url;
        }

        /**
         * Extract host header value from an endpoint URL.
         */
        private function get_host_header_for_endpoint(string $host): string
        {
            $parts = wp_parse_url($host);
            if (is_array($parts) && !empty($parts['host'])) {
                return $parts['host'];
            }

            return '';
        }

        /**
         * Extract path and query string from a URL.
         *
         * @return array{0:string,1:string}
         */
        private function get_path_and_query(string $url): array
        {
            $parts = wp_parse_url($url);
            if (!is_array($parts)) {
                return ['/', ''];
            }

            $path = $parts['path'] ?? '/';
            $query = $parts['query'] ?? '';

            return [$path, $query];
        }

        /**
         * Replace the host portion of a URL.
         */
        private function swap_host_in_url(string $url, string $host): string
        {
            $parts = wp_parse_url($url);
            if (false === $parts) {
                return '';
            }

            $path  = $parts['path'] ?? '/';
            $query = isset($parts['query']) ? '?' . $parts['query'] : '';

            return untrailingslashit($host) . $path . $query;
        }

        /**
         * Parse time string into hour/minute tuple.
         *
         * @return array{0:int,1:int}
         */
        private function parse_time_to_parts(string $time): array
        {
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $matches)) {
                return [(int) $matches[1], (int) $matches[2]];
            }

            $default = '00:00';
            if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $default, $fallback_matches)) {
                return [(int) $fallback_matches[1], (int) $fallback_matches[2]];
            }

            return [0, 0];
        }

        /**
         * Dispatch an HTTP PURGE request to Varnish.
         */
        private function send_purge_request(string $url, string $context = 'manual', string $host_header = ''): void
        {
            if ('' === $url) {
                return;
            }

            $args = [
                'method'  => 'PURGE',
                'timeout' => 10,
                'headers' => [],
            ];

            $settings = $this->get_settings();
            if (!empty($settings['header_name']) && !empty($settings['header_value'])) {
                $args['headers'][$settings['header_name']] = $settings['header_value'];
            }
            if ('' !== $host_header) {
                $args['headers']['Host'] = $host_header;
            }

            if (!empty($settings['verbose_logging'])) {
                $this->log(sprintf('PURGE %s (%s)', $url, $context));
            }

            $response = wp_remote_request($url, $args);
            if (is_wp_error($response)) {
                error_log(sprintf('[WP Varnish Cache Purger] Failed to purge %s (%s): %s', $url, $context, $response->get_error_message()));
                return;
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code >= 400) {
                if (!empty($settings['verbose_logging'])) {
                    $body = wp_remote_retrieve_body($response);
                    $headers = wp_remote_retrieve_headers($response);
                    $this->log(sprintf(
                        'Unexpected response %d while purging %s (%s). Headers: %s. Body: %s',
                        $code,
                        $url,
                        $context,
                        $this->stringify_headers($headers),
                        $this->truncate_for_log($body)
                    ));
                } else {
                    error_log(sprintf('[WP Varnish Cache Purger] Unexpected response %d while purging %s (%s)', $code, $url, $context));
                }
            }
        }

        /**
         * Get stored settings merged with defaults.
         *
         * @return array<string,mixed>
         */
        private function get_settings(): array
        {
            $defaults = $this->get_default_settings();
            $saved    = get_option(self::OPTION_NAME, []);

            if (!is_array($saved)) {
                return $defaults;
            }

            return wp_parse_args($saved, $defaults);
        }

        /**
         * Default option values.
         *
         * @return array<string,mixed>
         */
        private function get_default_settings(): array
        {
            return [
                'hosts'             => [untrailingslashit(home_url())],
                'purge_server'      => '',
                'schedule_interval' => 'hourly',
                'scheduled_paths'   => ['/'],
                'daily_time'        => '02:00',
                'weekly_day'        => 1,
                'weekly_time'       => '03:00',
                'purge_on_publish'  => true,
                'purge_on_update'   => true,
                'purge_home_on_post' => true,
                'header_name'       => '',
                'header_value'      => '',
                'verbose_logging'   => false,
            ];
        }

        /**
         * Schedule cron event.
         *
         * @param string|null $custom_interval
         */
        private function schedule_event(?string $custom_interval = null, ?array $settings_override = null): void
        {
            $defaults = $this->get_default_settings();
            $settings = $settings_override ? wp_parse_args($settings_override, $defaults) : $this->get_settings();
            $interval = $custom_interval ?: $settings['schedule_interval'];

            $schedules = wp_get_schedules();
            if (!isset($schedules[$interval])) {
                $interval = $defaults['schedule_interval'];
            }

            $timestamp = time() + 60;
            if ('daily' === $interval) {
                $timestamp = $this->get_daily_run_timestamp($settings['daily_time']);
            } elseif ('weekly' === $interval) {
                $timestamp = $this->get_weekly_run_timestamp((int) $settings['weekly_day'], $settings['weekly_time']);
            }

            wp_clear_scheduled_hook(self::CRON_HOOK);
            wp_schedule_event($timestamp, $interval, self::CRON_HOOK);
        }

        /**
         * Calculate first run timestamp for daily jobs.
         */
        private function get_daily_run_timestamp(string $time): int
        {
            [$hour, $minute] = $this->parse_time_to_parts($time);
            $timezone = $this->get_wordpress_timezone();
            $now = new \DateTimeImmutable('now', $timezone);
            $target = $now->setTime($hour, $minute, 0);

            if ($target <= $now) {
                $target = $target->modify('+1 day');
            }

            return $target->getTimestamp();
        }

        /**
         * Calculate first run timestamp for weekly jobs.
         */
        private function get_weekly_run_timestamp(int $weekday, string $time): int
        {
            [$hour, $minute] = $this->parse_time_to_parts($time);
            $timezone = $this->get_wordpress_timezone();
            $now = new \DateTimeImmutable('now', $timezone);
            $target = $now->setTime($hour, $minute, 0);
            $current_weekday = (int) $now->format('w');
            $days_ahead = ($weekday - $current_weekday + 7) % 7;

            if (0 === $days_ahead && $target <= $now) {
                $days_ahead = 7;
            }

            if ($days_ahead > 0) {
                $target = $target->modify('+' . $days_ahead . ' days');
            }

            return $target->getTimestamp();
        }

        /**
         * Retrieve WordPress timezone regardless of WP version.
         */
        private function get_wordpress_timezone(): \DateTimeZone
        {
            if (function_exists('wp_timezone')) {
                return wp_timezone();
            }

            $timezone_string = get_option('timezone_string');
            if (!empty($timezone_string)) {
                try {
                    return new \DateTimeZone($timezone_string);
                } catch (\Exception $e) {
                    // fall through to offset handling.
                }
            }

            $offset = (float) get_option('gmt_offset', 0);
            $timezone_name = timezone_name_from_abbr('', (int) round($offset * HOUR_IN_SECONDS), 0);
            if (false === $timezone_name) {
                $timezone_name = 'UTC';
            }

            return new \DateTimeZone($timezone_name);
        }

        /**
         * Write verbose logs when enabled.
         */
        private function log(string $message): void
        {
            $settings = $this->get_settings();
            if (!empty($settings['verbose_logging'])) {
                error_log('[WP Varnish Cache Purger] ' . $message);
            }
        }

        /**
         * Normalize headers for error logging.
         *
         * @param mixed $headers
         */
        private function stringify_headers($headers): string
        {
            if (is_object($headers) && method_exists($headers, 'getAll')) {
                $headers = $headers->getAll();
            }

            if (is_array($headers)) {
                $parts = [];
                foreach ($headers as $key => $value) {
                    if (is_array($value)) {
                        $value = implode(', ', $value);
                    }
                    $parts[] = $key . ': ' . $value;
                }

                return implode('; ', $parts);
            }

            if (is_string($headers)) {
                return $headers;
            }

            return '';
        }

        /**
         * Prevent excessive log sizes for response bodies.
         */
        private function truncate_for_log(string $body, int $limit = 1000): string
        {
            $body = trim($body);
            if ('' === $body) {
                return '(empty)';
            }

            if (strlen($body) <= $limit) {
                return $body;
            }

            return substr($body, 0, $limit) . '...';
        }

    }

    WP_Varnish_Cache_Purger::instance();

    register_activation_hook(__FILE__, ['WP_Varnish_Cache_Purger', 'activate']);
    register_deactivation_hook(__FILE__, ['WP_Varnish_Cache_Purger', 'deactivate']);
}
