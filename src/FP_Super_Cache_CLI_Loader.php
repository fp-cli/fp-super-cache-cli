<?php

/**
 * Load FPSC files and config file if they aren't loaded.
 */
final class FP_Super_Cache_CLI_Loader {

	/**
	 * Version of FP Super Cache plugin.
	 *
	 * @var string Version.
	 */
	protected $fpsc_version;

	/**
	 * Absolute path to the plugin file.
	 *
	 * @var string File path.
	 */
	protected $fpsc_plugin_file;

	/**
	 * Checks status of FP Super Cache and loads config/dependencies if it needs.
	 *
	 * @return void
	 */
	public function load() {
		// If FP isn't loaded then registers hooks.
		if ( ! function_exists( 'add_filter' ) ) {
			$this->register_hooks();
			return;
		}

		$error_msg = '';

		// Before loading files check is plugin installed/activated.
		if ( $this->get_fpsc_version() === '' ) {
			$error_msg = 'FP Super Cache needs to be installed to use its FP-CLI commands.';
		} elseif ( version_compare( $this->get_fpsc_version(), '1.5.2', '<' ) ) {
			$error_msg = 'Minimum required version of FP Super Cache is 1.5.2';
		} elseif ( ! $this->is_fpsc_plugin_active() ) {
			$error_msg = 'FP Super Cache needs to be activated to use its FP-CLI commands.';
		} elseif ( ! defined( 'FP_CACHE' ) || ! FP_CACHE ) {
			$error_msg = 'FP_CACHE constant is false or not defined';
		} elseif ( defined( 'FP_CACHE' ) && FP_CACHE && defined( 'ADVANCEDCACHEPROBLEM' ) ) {
			$error_msg = 'FP Super Cache caching is broken';
		}

		if ( $error_msg ) {
			FP_CLI::error( $error_msg );
		}

		// Initialization of cache-base.
		$this->init_cache_base();

		// Load dependencies if they aren't loaded.
		$this->maybe_load_files();
	}

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		FP_CLI::add_fp_hook( 'muplugins_loaded', array( $this, 'init_cache_base' ) );
		FP_CLI::add_fp_hook( 'plugins_loaded', array( $this, 'maybe_load_files' ) );
	}

	/**
	 * Initialization of cache-base.
	 *
	 * @global string $FPSC_HTTP_HOST
	 *
	 * @return void
	 */
	public function init_cache_base() {
		global $FPSC_HTTP_HOST;

		if ( ! defined( 'FPCACHEHOME' ) ) {
			return;
		}

		// Loads config file.
		$this->maybe_load_config();

		// If the parameter --url doesn't exist then gets HTTP_HOST from FinPress Address.
		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			$_SERVER['HTTP_HOST'] = $this->parse_home_url( PHP_URL_HOST );
		}

		if ( empty( $FPSC_HTTP_HOST ) ) {
			$this->maybe_include_file( 'include', 'fp-cache-base.php' );
		}
	}

	/**
	 * Loads config file and populates globals.
	 *
	 * @return void
	 */
	private function maybe_load_config() {
		global $cache_enabled, $super_cache_enabled, $cache_path, $fp_cache_mod_rewrite, $fp_cache_debug_log;
		global $fp_cache_config_file, $fp_cache_config_file_sample, $fp_cache_home_path;

		if ( empty( $fp_cache_config_file ) ) {
			return;
		}

		if ( ! isset( $cache_enabled, $super_cache_enabled, $cache_path, $fp_cache_mod_rewrite, $fp_cache_debug_log )
			&& ! $this->maybe_include_file( 'include', $fp_cache_config_file )
		) {
			if ( ! defined( 'FPCACHEHOME' )
				|| empty( $fp_cache_config_file_sample )
				|| ! $this->maybe_include_file( 'include', $fp_cache_config_file_sample )
			) {
				FP_CLI::error( 'Cannot load cache config file.' );
			}

			FP_CLI::warning( 'Default cache config file loaded - ' . str_replace( ABSPATH, '', $fp_cache_config_file_sample ) );
		}

		$fp_cache_home_path = trailingslashit( $this->parse_home_url( PHP_URL_PATH ) );
	}

	/**
	 * Loads config file, PHP files and overrides multisite settings.
	 *
	 * @return void
	 */
	public function maybe_load_files() {
		// FPSC >= 1.5.2 and it's active?
		if ( ! defined( 'FPCACHEHOME' ) || ! function_exists( 'fpsc_init' ) ) {
			return;
		}

		if ( version_compare( $this->get_fpsc_version(), '1.5.9', '>=' ) ) {
			// In rare cases, loading of fp-cache-phase2.php may be necessary.
			$this->maybe_include_file( 'fp_cache_phase2', 'fp-cache-phase2.php' );
		} else {
			// Prevents creation of output buffer or serving file for older versions.
			$request_method            = $_SERVER['REQUEST_METHOD'];
			$_SERVER['REQUEST_METHOD'] = 'POST';
		}

		// List of required files.
		$include_files = array(
			'fp_cache_postload'             => array(
				'file' => 'fp-cache-phase1.php',
				'run'  => '',
			),
			'domain_mapping_actions'        => array(
				'file' => 'plugins/domain-mapping.php',
				'run'  => 'domain_mapping_actions',
			),
			'fp_super_cache_multisite_init' => array(
				'file' => 'plugins/multisite.php',
				'run'  => 'fp_super_cache_override_on_flag',
			),
		);

		foreach ( $include_files as $func => $file ) {
			$this->maybe_include_file( $func, $file['file'], $file['run'] );
		}

		if ( ! empty( $request_method ) ) {
			$_SERVER['REQUEST_METHOD'] = $request_method;
		}

		$this->multisite_override_settings();
	}

	/**
	 * Overrides multisite settings.
	 *
	 * @global string $cache_path     Absolute path to cache directory.
	 * @global string $blogcacheid
	 * @global string $blog_cache_dir
	 * @global object $current_site   The current site.
	 *
	 * @return void
	 */
	private function multisite_override_settings() {
		global $cache_path, $blogcacheid, $blog_cache_dir, $current_site;

		if ( ! is_multisite() ) {
			// Prevents PHP notices for single site installation.
			if ( ! isset( $blog_cache_dir ) ) {
				$blog_cache_dir = $cache_path;
			}

			return;
		}

		if ( is_object( $current_site ) ) {
			$blogcacheid    = trim( is_subdomain_install() ? $current_site->domain : $current_site->path, '/' );
			$blog_cache_dir = $cache_path . 'blogs/' . $blogcacheid . '/';
		}
	}

	/**
	 * Gets absolute path for file if file exists.
	 * Returns empty string if file doesn't exist or isn't readable.
	 *
	 * @param string $filename File name.
	 *
	 * @return string
	 */
	private function get_fpsc_filename( $filename ) {
		if ( 0 !== strpos( $filename, ABSPATH ) ) {
			$filename = FPCACHEHOME . $filename;
		}

		if ( ! is_file( $filename ) || ! is_readable( $filename ) ) {
			return '';
		}

		return $filename;
	}

	/**
	 * If function doesn't exist then loads file and ivokes function if it needs.
	 * Explicitly declares all globals which FPSC uses.
	 *
	 * @param string $func     Function name.
	 * @param string $filename File name.
	 * @param string $run      Optional function will be called if file is included.
	 *
	 * @return boolean True if file is included or false if it isn't included.
	 */
	private function maybe_include_file( $func, $filename, $run = '' ) {
		// Globals from fp-cache-config.php.
		global $super_cache_enabled, $cache_enabled, $fp_cache_mod_rewrite, $fp_cache_home_path, $cache_path, $file_prefix;
		global $fp_cache_mutex_disabled, $mutex_filename, $sem_id, $fp_super_cache_late_init;
		global $cache_compression, $cache_max_time, $fp_cache_shutdown_gc, $cache_rebuild_files;
		global $fp_super_cache_debug, $fp_super_cache_advanced_debug, $fp_cache_debug_level, $fp_cache_debug_to_file;
		global $fp_cache_debug_log, $fp_cache_debug_ip, $fp_cache_debug_username, $fp_cache_debug_email;
		global $cache_time_interval, $cache_scheduled_time, $cache_schedule_interval, $cache_schedule_type, $cache_gc_email_me;
		global $fp_cache_preload_on, $fp_cache_preload_interval, $fp_cache_preload_posts, $fp_cache_preload_taxonomies;
		global $fp_cache_preload_email_me, $fp_cache_preload_email_volume;
		global $fp_cache_mobile, $fp_cache_mobile_enabled, $fp_cache_mobile_browsers, $fp_cache_mobile_prefixes;
		// Globals from other files.
		global $fp_cache_config_file, $fp_cache_config_file_sample, $cache_domain_mapping;
		global $FPSC_HTTP_HOST, $blogcacheid, $blog_cache_dir;

		$file = $this->get_fpsc_filename( $filename );

		if ( empty( $file ) ||
			( ! in_array( $func, array( 'require', 'require_once', 'include', 'include_once' ), true )
				&& function_exists( $func )
			)
		) {
			return false;
		}

		switch ( $func ) {
			case 'require':
				$loaded = require $file;
				break;
			case 'require_once':
				$loaded = require_once $file;
				break;
			case 'include':
				$loaded = include $file;
				break;
			case 'include_once':
			default:
				$loaded = include_once $file;
				break;
		}

		if ( $loaded && ! empty( $run ) && function_exists( $run ) ) {
			call_user_func( $run );
		}

		return $loaded;
	}

	/**
	 * Gets version of FP Super Cache.
	 *
	 * @global string $fp_cache_config_file_sample Absolute path to fp-cache config sample file.
	 *
	 * @return string
	 */
	public function get_fpsc_version() {
		global $fp_cache_config_file_sample;

		if ( isset( $this->fpsc_version ) ) {
			return $this->fpsc_version;
		}

		if ( ! function_exists( 'get_file_data' ) ) {
			return '';
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'fp-admin/includes/plugin.php';
		}

		$this->fpsc_version     = '';
		$this->fpsc_plugin_file = empty( $fp_cache_config_file_sample )
			? trailingslashit( FP_PLUGIN_DIR ) . 'fp-super-cache/fp-cache.php'
			: plugin_dir_path( $fp_cache_config_file_sample ) . 'fp-cache.php';

		if ( ! is_file( $this->fpsc_plugin_file ) || ! is_readable( $this->fpsc_plugin_file ) ) {
			return $this->fpsc_version;
		}

		$plugin_details = get_plugin_data( $this->fpsc_plugin_file );
		if ( ! empty( $plugin_details['Version'] ) ) {
			$this->fpsc_version = $plugin_details['Version'];
		}

		return $this->fpsc_version;
	}

	/**
	 * Check whether fp-super-cache plugin is active.
	 *
	 * @return bool
	 */
	private function is_fpsc_plugin_active() {
		if ( $this->get_fpsc_version() && is_plugin_active( plugin_basename( $this->fpsc_plugin_file ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Retrieves the component (PHP_URL_HOST or PHP_URL_PATH) from home URL.
	 *
	 * @param int $component The component to retrieve.
	 *
	 * @return string
	 */
	private function parse_home_url( $component ) {
		return function_exists( 'fp_parse_url' )
			? (string) fp_parse_url( get_option( 'home' ), $component )
			: (string) parse_url( get_option( 'home' ), $component );
	}
}
