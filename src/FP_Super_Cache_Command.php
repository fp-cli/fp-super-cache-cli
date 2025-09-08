<?php

/**
 * Manages the FP Super Cache plugin
 */
class FP_Super_Cache_Command extends FP_CLI_Command {

	/**
	 * Version of FP Super Cache plugin.
	 *
	 * @var string Version.
	 */
	protected $fpsc_version;

	/**
	 * Loads FP Super Cache config file and dependencies.
	 *
	 * @return void
	 */
	private function load() {
		global $cache_enabled, $super_cache_enabled, $cache_path, $fp_cache_mod_rewrite, $fp_cache_debug_log;

		$cli_loader = new FP_Super_Cache_CLI_Loader();

		$cli_loader->load();
		$this->fpsc_version = $cli_loader->get_fpsc_version();

		// Check if basic global variables are populated.
		if ( ! isset( $cache_enabled, $super_cache_enabled, $cache_path, $fp_cache_mod_rewrite, $fp_cache_debug_log ) ) {
			FP_CLI::error( 'FP Super Cache plugin is not properly loaded' );
		}
	}

	/**
	 * Clear something from the cache.
	 *
	 * @synopsis [--post_id=<post-id>] [--permalink=<permalink>]
	 *
	 * @when after_fp_load
	 */
	public function flush( $args = array(), $assoc_args = array() ) {
		global $file_prefix;

		$this->load();

		if ( isset( $assoc_args['post_id'] ) ) {
			if ( is_numeric( $assoc_args['post_id'] ) ) {
				fp_cache_post_change( $assoc_args['post_id'] );
			} else {
				FP_CLI::error( 'This is not a valid post id.' );
			}

			fp_cache_post_change( $assoc_args['post_id'] );
		} elseif ( isset( $assoc_args['permalink'] ) ) {
			$id = url_to_postid( $assoc_args['permalink'] );

			if ( is_numeric( $id ) ) {
				fp_cache_post_change( $id );
			} else {
				FP_CLI::error( 'There is no post with this permalink.' );
			}
		} else {
			fp_cache_clean_cache( $file_prefix, true );
			FP_CLI::success( 'Cache cleared.' );
		}
	}

	/**
	 * Get the status of the cache.
	 *
	 * @when after_fp_load
	 */
	public function status( $args = array(), $assoc_args = array() ) {
		global $cache_enabled, $super_cache_enabled, $fp_cache_mod_rewrite;

		$this->load();

		FP_CLI::line( 'Version of FP Super Cache: ' . $this->fpsc_version );
		FP_CLI::line();

		$cache_method = 'FP-Cache';
		if ( $cache_enabled && $super_cache_enabled ) {
			$cache_method = $fp_cache_mod_rewrite ? 'Expert' : 'Simple';
		}

		$cache_status  = 'Cache status: ' . FP_CLI::colorize( $cache_enabled ? '%gOn%n' : '%rOff%n' ) . PHP_EOL;
		$cache_status .= $cache_enabled
			? 'Cache Delivery Method: ' . $cache_method . PHP_EOL
			: '';
		FP_CLI::line( $cache_status );

		$cache_stats = get_option( 'supercache_stats' );
		if ( ! empty( $cache_stats ) ) {
			if ( $cache_stats['generated'] > time() - 3600 * 24 ) {
				FP_CLI::line( 'Cache content on ' . date( 'r', $cache_stats['generated'] ) . ': ' );
				FP_CLI::line();
				FP_CLI::line( '    FinPress cache:' );
				FP_CLI::line( '        Cached: ' . $cache_stats['fpcache']['cached'] );
				FP_CLI::line( '        Expired: ' . $cache_stats['fpcache']['expired'] );
				FP_CLI::line();
				FP_CLI::line( '    FP Super Cache:' );
				FP_CLI::line( '        Cached: ' . $cache_stats['supercache']['cached'] );
				FP_CLI::line( '        Expired: ' . $cache_stats['supercache']['expired'] );
			} else {
				FP_CLI::error( 'The FP Super Cache stats are too old to work with (older than 24 hours).' );
			}
		} else {
			FP_CLI::error( 'No FP Super Cache stats found.' );
		}
	}

	/**
	 * Enable the FP Super Cache.
	 *
	 * @when after_fp_load
	 */
	public function enable( $args = array(), $assoc_args = array() ) {
		global $cache_enabled, $fp_cache_mod_rewrite;

		$this->load();

		fp_cache_enable();
		if ( ! defined( 'DISABLE_SUPERCACHE' ) ) {
			fp_super_cache_enable();
		}

		if ( $fp_cache_mod_rewrite ) {
			add_mod_rewrite_rules();
		}

		if ( $cache_enabled ) {
			FP_CLI::success( 'The FP Super Cache is enabled.' );
		} else {
			FP_CLI::error( 'The FP Super Cache is not enabled, check its settings page for more info.' );
		}
	}

	/**
	 * Disable the FP Super Cache.
	 *
	 * @when after_fp_load
	 */
	public function disable( $args = array(), $assoc_args = array() ) {
		global $cache_enabled;

		$this->load();

		fp_cache_disable();
		fp_super_cache_disable();

		if ( ! $cache_enabled ) {
			FP_CLI::success( 'The FP Super Cache is disabled.' );
		} else {
			FP_CLI::error( 'The FP Super Cache is still enabled, check its settings page for more info.' );
		}
	}

	/**
	 * Primes the cache by creating static pages before users visit them
	 *
	 * @synopsis [--status] [--cancel]
	 *
	 * @when after_fp_load
	 */
	public function preload( $args = array(), $assoc_args = array() ) {
		global $super_cache_enabled;

		$this->load();

		$preload_counter = get_option( 'preload_cache_counter' );
		$preloading      = is_array( $preload_counter ) && $preload_counter['c'] > 0;
		$pending_cancel  = get_option( 'preload_cache_stop' );

		// Bail early if caching or preloading is disabled
		if ( ! $super_cache_enabled ) {
			FP_CLI::error( 'The FP Super Cache is not enabled.' );
		}

		if ( defined( 'DISABLESUPERCACHEPRELOADING' ) && true == DISABLESUPERCACHEPRELOADING ) {
			FP_CLI::error( 'Cache preloading is not enabled.' );
		}

		// Display status
		if ( isset( $assoc_args['status'] ) ) {
			$this->preload_status( $preload_counter, $pending_cancel );
			exit();
		}

		// Cancel preloading if in progress
		if ( isset( $assoc_args['cancel'] ) ) {
			if ( $preloading ) {
				if ( $pending_cancel ) {
					FP_CLI::error( 'There is already a pending preload cancel. It may take up to a minute for it to cancel completely.' );
				} else {
					update_option( 'preload_cache_stop', true );
					FP_CLI::success( 'Scheduled preloading of cache almost cancelled. It may take up to a minute for it to cancel completely.' );
					exit();
				}
			} else {
				FP_CLI::error( 'Not currently preloading.' );
			}
		}

		// Start preloading if not already in progress
		if ( $preloading ) {
			FP_CLI::warning( 'Cache preloading is already in progress.' );
			$this->preload_status( $preload_counter, $pending_cancel );
			exit();
		} else {
			fp_schedule_single_event( time(), 'fp_cache_full_preload_hook' );
			FP_CLI::success( 'Scheduled preload for next cron run.' );
		}
	}

	/**
	 * Outputs the status of preloading
	 *
	 * @param $preload_counter
	 * @param $pending_cancel
	 */
	protected function preload_status( $preload_counter, $pending_cancel ) {
		if ( is_array( $preload_counter ) && $preload_counter['c'] > 0 ) {
			FP_CLI::line( sprintf( 'Currently caching from post %d to %d.', $preload_counter['c'] - 100, $preload_counter['c'] ) );

			if ( $pending_cancel ) {
				FP_CLI::warning( 'Pending preload cancel. It may take up to a minute for it to cancel completely.' );
			}
		} else {
			FP_CLI::line( 'Not currently preloading.' );
		}
	}
}
