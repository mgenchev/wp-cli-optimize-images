<?php

namespace OptimizeImages;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class Tiny_Png_Optimization_Exception extends \RuntimeException {

	private $disable_for_run;

	public function __construct( $message, $disable_for_run = false ) {
		parent::__construct( $message );

		$this->disable_for_run = (bool) $disable_for_run;
	}

	public function should_disable_for_run() {
		return $this->disable_for_run;
	}
}

class Optimize_Images_Command {

	private const SUPPORTED_EXTENSIONS = [
		'jpg',
		'jpeg',
		'png',
		'webp',
		'avif',
	];

	private const CONFIG_FILENAME = 'optimize-images.json';
	private const CACHE_FILENAME = '.optimize-images-cache.json';
	private const LOCAL_RUNTIME_DIRNAME = 'optimize-images-local';
	private const LOCAL_OPTIMIZER_FILENAME = 'local-optimizer.cjs';
	private const SHARP_VERSION = '^0.35.0';
	private const MINIMUM_NODE_VERSION = '20.9.0';

	private $tinypng_disabled_for_run = false;
	private $tinypng_fallback_notice_shown = false;
	private $tinypng_compression_count = null;

	/**
	 * Run the optimize-images command.
	 */
	public function __invoke( $args, $assoc_args ) {
		$action = $args[0] ?? null;

		if ( 'configure' === $action ) {
			$this->configure();

			return;
		}

		if ( 'status' === $action ) {
			$this->status();

			return;
		}

		if ( 'sync' === $action ) {
			$directory = $args[1] ?? null;

			if ( ! $directory ) {
				\WP_CLI::error(
					'Please provide an images directory. Example: wp optimize-images sync ./images'
				);
			}

			$this->optimize(
				$directory,
				$assoc_args,
				true
			);

			return;
		}

		if ( ! $action ) {
			\WP_CLI::error(
				'Please provide an images directory.'
			);
		}

		$this->optimize(
			$action,
			$assoc_args,
			false
		);
	}

	/**
	 * Configure the TinyPNG API key.
	 */
	private function configure() {
		\WP_CLI::log( 'TinyPNG API configuration' );
		\WP_CLI::log( '' );

		fwrite(
			STDOUT,
			'Enter TinyPNG API key: '
		);

		$api_key = trim(
			(string) fgets( STDIN )
		);

		if ( '' === $api_key ) {
			\WP_CLI::error(
				'API key cannot be empty.'
			);
		}

		$config_file = $this->get_config_file();
		$config_dir = dirname( $config_file );

		if (
			! is_dir( $config_dir )
			&& ! mkdir( $config_dir, 0755, true )
		) {
			\WP_CLI::error(
				'Could not create WP-CLI configuration directory.'
			);
		}

		$config = [
			'tinify_api_key' => $api_key,
		];

		$json = json_encode(
			$config,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		if ( false === $json ) {
			\WP_CLI::error(
				'Could not encode configuration.'
			);
		}

		if ( false === file_put_contents( $config_file, $json ) ) {
			\WP_CLI::error(
				'Could not save configuration.'
			);
		}

		@chmod( $config_file, 0600 );

		\WP_CLI::success(
			"TinyPNG API key saved to {$config_file}"
		);
	}

	/**
	 * Install the free local Sharp fallback.
	 */
	private function install_local_optimizer() {
		$node_version = $this->get_node_version();

		if ( ! $node_version ) {
			\WP_CLI::error(
				'Node.js is required for the local optimizer.'
			);
		}

		if (
			version_compare(
				$node_version,
				self::MINIMUM_NODE_VERSION,
				'<'
			)
		) {
			\WP_CLI::error(
				sprintf(
					'Node.js %s+ is required. Current version: %s',
					self::MINIMUM_NODE_VERSION,
					$node_version
				)
			);
		}

		if ( ! $this->command_exists( 'npm' ) ) {
			\WP_CLI::error(
				'npm is required for the local optimizer.'
			);
		}

		$runtime_dir = $this->get_local_runtime_dir();

		if (
			! is_dir( $runtime_dir )
			&& ! mkdir( $runtime_dir, 0755, true )
		) {
			\WP_CLI::error(
				'Could not create the local optimizer directory.'
			);
		}

		$source_script = dirname( __DIR__ )
			. DIRECTORY_SEPARATOR
			. 'resources'
			. DIRECTORY_SEPARATOR
			. self::LOCAL_OPTIMIZER_FILENAME;

		$target_script = $this->get_local_optimizer_script();

		if ( ! file_exists( $source_script ) ) {
			\WP_CLI::error(
				'Local optimizer script is missing from the package.'
			);
		}

		if ( ! copy( $source_script, $target_script ) ) {
			\WP_CLI::error(
				'Could not install the local optimizer script.'
			);
		}

		$package_json = [
			'name' => 'wp-cli-optimize-images-local-runtime',
			'private' => true,
			'dependencies' => [
				'sharp' => self::SHARP_VERSION,
			],
		];

		$package_json_path = $runtime_dir
			. DIRECTORY_SEPARATOR
			. 'package.json';

		$json = json_encode(
			$package_json,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		if (
			false === $json
			|| false === file_put_contents(
				$package_json_path,
				$json
			)
		) {
			\WP_CLI::error(
				'Could not create the local optimizer package.json.'
			);
		}

		\WP_CLI::log(
			'Installing local image optimizer...'
		);

		$original_dir = getcwd();

		if ( ! chdir( $runtime_dir ) ) {
			\WP_CLI::error(
				'Could not enter the local optimizer directory.'
			);
		}

		$npm_command = '\\' === DIRECTORY_SEPARATOR
			? 'npm.cmd'
			: 'npm';

		$command = sprintf(
			'%s install --omit=dev --no-audit --no-fund 2>&1',
			$npm_command
		);

		$output = [];
		$status = 0;

		try {
			exec(
				$command,
				$output,
				$status
			);
		} finally {
			if ( $original_dir ) {
				chdir( $original_dir );
			}
		}

		if (
			0 !== $status
			|| ! $this->is_local_optimizer_ready()
		) {
			$message = ! empty( $output )
				? implode( PHP_EOL, $output )
				: 'npm install failed.';

			\WP_CLI::error(
				"Could not install the local optimizer.\n{$message}"
			);
		}

		\WP_CLI::success(
			sprintf(
				'Local optimizer installed. Sharp %s is ready.',
				$this->get_sharp_version() ?: 'unknown'
			)
		);
	}

	/**
	 * Ensure the local fallback optimizer is available.
	 */
	private function ensure_local_optimizer() {
		if ( $this->is_local_optimizer_ready() ) {
			return true;
		}

		\WP_CLI::log(
			'Local optimizer is not installed. Setting it up automatically...'
		);

		$this->install_local_optimizer();

		return $this->is_local_optimizer_ready();
	}

	/**
	 * Display package status.
	 */
	private function status() {
		$config_file = $this->get_config_file();
		$environment_key = getenv( 'TINIFY_API_KEY' );
		$stored_key = $this->get_stored_api_key();
		$node_version = $this->get_node_version();
		$local_ready = $this->is_local_optimizer_ready();

		if ( $environment_key ) {
			$key_status = 'configured via TINIFY_API_KEY';
		} elseif ( $stored_key ) {
			$key_status = 'configured';
		} else {
			$key_status = 'not configured';
		}

		\WP_CLI::log( 'WP-CLI Optimize Images' );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'PHP: ' . PHP_VERSION );

		\WP_CLI::log(
			'cURL: '
			. (
				function_exists( 'curl_init' )
					? 'enabled'
					: 'disabled'
			)
		);

		\WP_CLI::log( 'TinyPNG API key: ' . $key_status );
		\WP_CLI::log( 'Config: ' . $config_file );

		\WP_CLI::log(
			'Node.js: '
			. (
				$node_version
					? $node_version
					: 'not found'
			)
		);

		\WP_CLI::log(
			'Local fallback: '
			. (
				$local_ready
					? 'ready (Sharp ' . ( $this->get_sharp_version() ?: 'unknown' ) . ')'
					: 'not installed'
			)
		);

		\WP_CLI::log(
			'Supported extensions: '
			. implode( ', ', self::SUPPORTED_EXTENSIONS )
		);

		\WP_CLI::log( '' );

		if ( $stored_key || $environment_key ) {
			if ( $local_ready ) {
				\WP_CLI::log( 'Strategy: TinyPNG -> local fallback' );
			} else {
				\WP_CLI::log( 'Strategy: TinyPNG only' );
				\WP_CLI::log(
					'Run `wp optimize-images setup-local` to enable free fallback.'
				);
			}
		} elseif ( $local_ready ) {
			\WP_CLI::log( 'Strategy: local optimizer' );
		} else {
			\WP_CLI::log( 'Strategy: unavailable' );
		}

		\WP_CLI::log( '' );

		if (
			( $stored_key || $environment_key )
			&& function_exists( 'curl_init' )
		) {
			\WP_CLI::success( 'Ready.' );

			return;
		}

		if ( $local_ready ) {
			\WP_CLI::success( 'Ready with local optimizer.' );

			return;
		}

		\WP_CLI::warning(
			'Set up TinyPNG or run: wp optimize-images setup-local'
		);
	}

	/**
	 * Optimize an image directory.
	 */
	private function optimize( $directory, $assoc_args, $sync ) {
		$source_dir = realpath( $directory );

		if ( ! $source_dir || ! is_dir( $source_dir ) ) {
			\WP_CLI::error(
				'Directory does not exist.'
			);
		}

		$source_dir = $this->normalize_path( $source_dir );

		$extensions = $this->get_extensions(
			$assoc_args['extensions'] ?? null
		);

		$target_dir = $this->get_target_dir(
			$source_dir,
			$assoc_args['output'] ?? null
		);

		if ( $this->is_same_or_child_path( $target_dir, $source_dir ) ) {
			\WP_CLI::error(
				'The output directory cannot be inside the input directory.'
			);
		}

		$dry_run = isset( $assoc_args['dry-run'] );
		$force = isset( $assoc_args['force'] );

		$cache_file = $target_dir
			. '/'
			. self::CACHE_FILENAME;

		$cache = $this->load_cache( $cache_file );

		$source_files = $this->collect_source_files(
			$source_dir,
			$extensions
		);

		$stale_files = [];

		if ( $sync ) {
			$stale_files = $this->find_stale_files(
				$target_dir,
				$source_files,
				$extensions
			);
		}

		if ( $dry_run ) {
			$this->run_dry_run(
				$source_dir,
				$target_dir,
				$source_files,
				$stale_files,
				$cache,
				$extensions,
				$force,
				$sync
			);

			return;
		}

		$api_key = $this->get_api_key();

		$local_ready = $this->is_local_optimizer_ready();

		if ( ! $local_ready ) {
			$local_ready = $this->ensure_local_optimizer();
		}

		if ( $api_key && ! function_exists( 'curl_init' ) ) {
			\WP_CLI::warning(
				'PHP cURL is unavailable. TinyPNG will be skipped.'
			);

			$api_key = null;
		}

		if ( ! $api_key && ! $local_ready ) {
			\WP_CLI::error(
				'No TinyPNG API key is configured and the local optimizer is not installed. Run: wp optimize-images setup-local'
			);
		}

		if ( $api_key && ! $local_ready ) {
			\WP_CLI::warning(
				'Local fallback is not installed. Run `wp optimize-images setup-local` to enable automatic fallback.'
			);
		}

		if (
			! is_dir( $target_dir )
			&& ! mkdir( $target_dir, 0755, true )
		) {
			\WP_CLI::error(
				'Could not create output directory.'
			);
		}

		\WP_CLI::log( "Source: {$source_dir}" );
		\WP_CLI::log( "Output: {$target_dir}" );
		\WP_CLI::log(
			'Extensions: '
			. implode( ',', $extensions )
		);

		\WP_CLI::log(
			'Engine: '
			. $this->get_engine_strategy_label(
				$api_key,
				$local_ready
			)
		);

		\WP_CLI::log( '' );

		$removed = 0;

		if ( $sync ) {
			$removed = $this->remove_stale_files(
				$stale_files,
				$cache
			);

			$this->prune_cache(
				$cache,
				$source_files,
				$extensions
			);

			$this->clean_empty_directories(
				$target_dir
			);

			$this->save_cache(
				$cache_file,
				$cache
			);
		}

		$optimized = 0;
		$skipped = 0;
		$failed = 0;
		$original_size = 0;
		$output_size = 0;

		$engine_counts = [
			'tinypng' => 0,
			'local' => 0,
		];

		foreach ( $source_files as $cache_key => $file ) {
			$source_file = $file['source'];
			$relative = $file['relative'];

			$target_file = $target_dir
				. '/'
				. $relative;

			$source_hash = $file['hash'];

			if (
				! $force
				&& file_exists( $target_file )
				&& isset( $cache[ $cache_key ] )
				&& $this->cache_hash_matches(
					$cache[ $cache_key ],
					$source_hash
				)
			) {
				\WP_CLI::log(
					"↷ {$relative} (unchanged)"
				);

				$skipped++;

				continue;
			}

			$target_file_dir = dirname(
				$target_file
			);

			if (
				! is_dir( $target_file_dir )
				&& ! mkdir(
					$target_file_dir,
					0755,
					true
				)
			) {
				\WP_CLI::warning(
					"{$relative}: Could not create output directory."
				);

				$failed++;

				continue;
			}

			$before = filesize(
				$source_file
			);

			try {
				$engine = $this->optimize_image_with_fallback(
					$source_file,
					$target_file,
					$file['extension'],
					$api_key,
					$relative
				);

				$after = filesize(
					$target_file
				);

				$saved = max(
					0,
					$before - $after
				);

				$original_size += $before;
				$output_size += $after;
				$optimized++;

				$engine_counts[ $engine ]++;

				$cache[ $cache_key ] = [
					'hash' => $source_hash,
					'engine' => $engine,
				];

				$this->save_cache(
					$cache_file,
					$cache
				);

				\WP_CLI::log(
					sprintf(
						'✓ %s [%s]  %s → %s (-%s)',
						$relative,
						$this->format_engine_name( $engine ),
						$this->format_bytes( $before ),
						$this->format_bytes( $after ),
						$before > 0
							? round(
								( $saved / $before ) * 100
							) . '%'
							: '0%'
					)
				);
			} catch ( \Exception $e ) {
				\WP_CLI::warning(
					"{$relative}: {$e->getMessage()}"
				);

				$failed++;
			}
		}

		\WP_CLI::log( '' );

		$total_saved = max(
			0,
			$original_size - $output_size
		);

		$saved_percent = $original_size > 0
			? round(
				( $total_saved / $original_size ) * 100
			)
			: 0;

		$message = sprintf(
			'Optimized: %d | Skipped: %d | Failed: %d',
			$optimized,
			$skipped,
			$failed
		);

		if ( $sync ) {
			$message .= sprintf(
				' | Removed: %d',
				$removed
			);
		}

		$message .= sprintf(
			' | Saved: %s (%d%%)',
			$this->format_bytes( $total_saved ),
			$saved_percent
		);

		\WP_CLI::success(
			$message
		);

		if ( $optimized > 0 ) {
			\WP_CLI::log(
				sprintf(
					'Engines: TinyPNG %d | Local %d',
					$engine_counts['tinypng'],
					$engine_counts['local']
				)
			);
		}

		if ( null !== $this->tinypng_compression_count ) {
			\WP_CLI::log(
				'TinyPNG compressions this month: '
				. $this->tinypng_compression_count
			);
		}
	}

	/**
	 * Optimize with TinyPNG first and automatically fall back locally.
	 */
	private function optimize_image_with_fallback(
		$source,
		$target,
		$extension,
		$api_key,
		$relative
	) {
		if (
			$api_key
			&& ! $this->tinypng_disabled_for_run
		) {
			try {
				$this->optimize_image_tinypng(
					$source,
					$target,
					$api_key
				);

				return 'tinypng';
			} catch ( Tiny_Png_Optimization_Exception $e ) {
				if ( $e->should_disable_for_run() ) {
					$this->tinypng_disabled_for_run = true;

					if (
						! $this->tinypng_fallback_notice_shown
					) {
						\WP_CLI::warning(
							'TinyPNG is unavailable ('
							. $e->getMessage()
							. '). Switching to the free local optimizer for the remaining images.'
						);

						$this->tinypng_fallback_notice_shown = true;
					}
				} else {
					\WP_CLI::warning(
						"TinyPNG failed for {$relative} ({$e->getMessage()}). Using local fallback."
					);
				}
			}
		}

		if ( ! $this->is_local_optimizer_ready() ) {
			$this->ensure_local_optimizer();
		}

		if ( ! $this->is_local_optimizer_ready() ) {
			throw new \RuntimeException(
				'Could not initialize the local optimizer.'
			);
		}

		$this->optimize_image_local(
			$source,
			$target,
			$extension
		);

		return 'local';
	}

	/**
	 * Optimize an image through TinyPNG.
	 */
	private function optimize_image_tinypng(
		$source,
		$target,
		$api_key
	) {
		$ch = curl_init(
			'https://api.tinify.com/shrink'
		);

		curl_setopt_array(
			$ch,
			[
				CURLOPT_USERPWD => 'api:' . $api_key,
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => file_get_contents( $source ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => true,
			]
		);

		$response = curl_exec( $ch );

		if ( false === $response ) {
			$error = curl_error( $ch );

			curl_close( $ch );

			throw new Tiny_Png_Optimization_Exception(
				$error ?: 'Connection error',
				true
			);
		}

		$status = curl_getinfo(
			$ch,
			CURLINFO_HTTP_CODE
		);

		$header_size = curl_getinfo(
			$ch,
			CURLINFO_HEADER_SIZE
		);

		$headers = substr(
			$response,
			0,
			$header_size
		);

		$body = substr(
			$response,
			$header_size
		);

		curl_close( $ch );

		$this->capture_tinypng_compression_count(
			$headers
		);

		if ( 201 !== $status ) {
			$error = json_decode(
				$body,
				true
			);

			$message = is_array( $error )
				&& ! empty( $error['message'] )
					? $error['message']
					: 'TinyPNG compression failed.';

			throw new Tiny_Png_Optimization_Exception(
				$message,
				$this->is_global_tinypng_failure(
					$status,
					$error,
					$message
				)
			);
		}

		if (
			! preg_match(
				'/^Location:\s*(.+)$/mi',
				$headers,
				$matches
			)
		) {
			throw new Tiny_Png_Optimization_Exception(
				'TinyPNG output URL was not returned.',
				true
			);
		}

		$output_url = trim(
			$matches[1]
		);

		$ch = curl_init(
			$output_url
		);

		curl_setopt_array(
			$ch,
			[
				CURLOPT_USERPWD => 'api:' . $api_key,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => true,
			]
		);

		$response = curl_exec( $ch );

		if ( false === $response ) {
			$error = curl_error( $ch );

			curl_close( $ch );

			throw new Tiny_Png_Optimization_Exception(
				$error ?: 'Could not download optimized image.',
				true
			);
		}

		$status = curl_getinfo(
			$ch,
			CURLINFO_HTTP_CODE
		);

		$header_size = curl_getinfo(
			$ch,
			CURLINFO_HEADER_SIZE
		);

		$headers = substr(
			$response,
			0,
			$header_size
		);

		$image = substr(
			$response,
			$header_size
		);

		curl_close( $ch );

		$this->capture_tinypng_compression_count(
			$headers
		);

		if ( 200 !== $status ) {
			throw new Tiny_Png_Optimization_Exception(
				'Could not download optimized image.',
				$status >= 500
				|| in_array(
					$status,
					[ 401, 403, 429 ],
					true
				)
			);
		}

		if (
			false === file_put_contents(
				$target,
				$image
			)
		) {
			throw new \RuntimeException(
				'Could not save optimized image.'
			);
		}
	}

	/**
	 * Optimize an image using the local Sharp runtime.
	 */
	private function optimize_image_local(
		$source,
		$target,
		$extension
	) {
		$temp_target = $target
			. '.tmp-'
			. bin2hex(
				random_bytes( 6 )
			);

		$script = $this->get_local_optimizer_script();

		$command = sprintf(
			'node %s %s %s %s 2>&1',
			escapeshellarg( $script ),
			escapeshellarg( $source ),
			escapeshellarg( $temp_target ),
			escapeshellarg( $extension )
		);

		$output = [];
		$status = 0;

		exec(
			$command,
			$output,
			$status
		);

		if (
			0 !== $status
			|| ! file_exists( $temp_target )
		) {
			if ( file_exists( $temp_target ) ) {
				@unlink( $temp_target );
			}

			$message = ! empty( $output )
				? trim(
					implode( ' ', $output )
				)
				: 'Local optimization failed.';

			throw new \RuntimeException(
				$message
			);
		}

		$source_size = filesize(
			$source
		);

		$optimized_size = filesize(
			$temp_target
		);

		if ( false === $optimized_size ) {
			@unlink( $temp_target );

			throw new \RuntimeException(
				'Could not read local optimizer output.'
			);
		}

		// Never replace the source with a larger optimized file.
		if ( $optimized_size >= $source_size ) {
			@unlink( $temp_target );

			if ( ! copy( $source, $target ) ) {
				throw new \RuntimeException(
					'Could not save local optimizer output.'
				);
			}

			return;
		}

		if (
			file_exists( $target )
			&& ! unlink( $target )
		) {
			@unlink( $temp_target );

			throw new \RuntimeException(
				'Could not replace existing optimized image.'
			);
		}

		if ( ! rename( $temp_target, $target ) ) {
			if (
				! copy(
					$temp_target,
					$target
				)
			) {
				@unlink( $temp_target );

				throw new \RuntimeException(
					'Could not save local optimizer output.'
				);
			}

			@unlink( $temp_target );
		}
	}

	/**
	 * Decide whether TinyPNG should be disabled for the rest of this run.
	 */
	private function is_global_tinypng_failure(
		$status,
		$error,
		$message
	) {
		if (
			in_array(
				$status,
				[ 401, 403, 429 ],
				true
			)
			|| $status >= 500
		) {
			return true;
		}

		$error_name = is_array( $error )
			&& ! empty( $error['error'] )
				? strtolower(
					(string) $error['error']
				)
				: '';

		$message = strtolower(
			(string) $message
		);

		return str_contains(
			$error_name,
			'account'
		)
			|| str_contains(
				$error_name,
				'too many'
			)
			|| str_contains(
				$message,
				'compression limit'
			)
			|| str_contains(
				$message,
				'limit reached'
			)
			|| str_contains(
				$message,
				'monthly limit'
			);
	}

	/**
	 * Capture monthly TinyPNG usage from response headers.
	 */
	private function capture_tinypng_compression_count(
		$headers
	) {
		if (
			preg_match(
				'/^Compression-Count:\s*(\d+)/mi',
				$headers,
				$matches
			)
		) {
			$this->tinypng_compression_count = (int) $matches[1];
		}
	}

	/**
	 * Run without modifying files or calling TinyPNG.
	 */
	private function run_dry_run(
		$source_dir,
		$target_dir,
		$source_files,
		$stale_files,
		$cache,
		$extensions,
		$force,
		$sync
	) {
		$api_key = $this->get_api_key();
		$local_ready = $this->is_local_optimizer_ready();

		\WP_CLI::log(
			"Source: {$source_dir}"
		);

		\WP_CLI::log(
			"Output: {$target_dir}"
		);

		\WP_CLI::log(
			'Extensions: '
			. implode( ',', $extensions )
		);

		\WP_CLI::log(
			'Engine: '
			. $this->get_engine_strategy_label(
				$api_key,
				$local_ready
			)
		);

		\WP_CLI::log(
			'Mode: dry-run'
		);

		\WP_CLI::log( '' );

		$would_optimize = 0;
		$unchanged = 0;
		$total_size = 0;

		foreach ( $source_files as $cache_key => $file ) {
			$target_file = $target_dir
				. '/'
				. $file['relative'];

			$total_size += $file['size'];

			if (
				! $force
				&& file_exists( $target_file )
				&& isset( $cache[ $cache_key ] )
				&& $this->cache_hash_matches(
					$cache[ $cache_key ],
					$file['hash']
				)
			) {
				\WP_CLI::log(
					"↷ {$file['relative']} (unchanged)"
				);

				$unchanged++;

				continue;
			}

			\WP_CLI::log(
				"+ {$file['relative']} (would optimize)"
			);

			$would_optimize++;
		}

		if ( $sync ) {
			foreach (
				$stale_files
				as $relative => $path
			) {
				\WP_CLI::log(
					"- {$relative} (would remove)"
				);
			}
		}

		\WP_CLI::log( '' );
		\WP_CLI::log(
			'Found: '
			. count( $source_files )
		);

		\WP_CLI::log(
			"Would optimize: {$would_optimize}"
		);

		\WP_CLI::log(
			"Unchanged: {$unchanged}"
		);

		if ( $sync ) {
			\WP_CLI::log(
				'Would remove: '
				. count( $stale_files )
			);
		}

		\WP_CLI::log(
			'Source size: '
			. $this->format_bytes(
				$total_size
			)
		);

		\WP_CLI::log( '' );

		\WP_CLI::success(
			'Dry run complete. No files were changed.'
		);
	}

	/**
	 * Collect supported source images.
	 */
	private function collect_source_files(
		$source_dir,
		$extensions
	) {
		$files = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$source_dir,
				\FilesystemIterator::SKIP_DOTS
			)
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower(
				$file->getExtension()
			);

			if (
				! in_array(
					$extension,
					$extensions,
					true
				)
			) {
				continue;
			}

			$source_file = $this->normalize_path(
				$file->getPathname()
			);

			$relative = substr(
				$source_file,
				strlen( $source_dir ) + 1
			);

			$relative = $this->normalize_relative_path(
				$relative
			);

			$files[ $relative ] = [
				'source' => $source_file,
				'relative' => $relative,
				'extension' => $extension,
				'size' => filesize( $source_file ),
				'hash' => hash_file(
					'sha256',
					$source_file
				),
			];
		}

		ksort( $files );

		return $files;
	}

	/**
	 * Find optimized files whose source no longer exists.
	 */
	private function find_stale_files(
		$target_dir,
		$source_files,
		$extensions
	) {
		if ( ! is_dir( $target_dir ) ) {
			return [];
		}

		$stale = [];

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$target_dir,
				\FilesystemIterator::SKIP_DOTS
			)
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			if (
				self::CACHE_FILENAME
				=== $file->getFilename()
			) {
				continue;
			}

			$extension = strtolower(
				$file->getExtension()
			);

			if (
				! in_array(
					$extension,
					$extensions,
					true
				)
			) {
				continue;
			}

			$target_file = $this->normalize_path(
				$file->getPathname()
			);

			$relative = substr(
				$target_file,
				strlen( $target_dir ) + 1
			);

			$relative = $this->normalize_relative_path(
				$relative
			);

			if (
				! isset(
					$source_files[ $relative ]
				)
			) {
				$stale[ $relative ] = $target_file;
			}
		}

		ksort( $stale );

		return $stale;
	}

	/**
	 * Remove stale output files.
	 */
	private function remove_stale_files(
		$stale_files,
		&$cache
	) {
		$removed = 0;

		foreach (
			$stale_files
				as $relative => $path
		) {
			if (
				file_exists( $path )
				&& ! unlink( $path )
			) {
				\WP_CLI::warning(
					"{$relative}: Could not remove stale file."
				);

				continue;
			}

			unset(
				$cache[ $relative ]
			);

			\WP_CLI::log(
				"− {$relative} (removed)"
			);

			$removed++;
		}

		return $removed;
	}

	/**
	 * Remove stale cache entries.
	 */
	private function prune_cache(
		&$cache,
		$source_files,
		$extensions
	) {
		foreach (
			array_keys( $cache )
				as $relative
		) {
			$extension = strtolower(
				pathinfo(
					$relative,
					PATHINFO_EXTENSION
				)
			);

			if (
				! in_array(
					$extension,
					$extensions,
					true
				)
			) {
				continue;
			}

			if (
				! isset(
					$source_files[ $relative ]
				)
			) {
				unset(
					$cache[ $relative ]
				);
			}
		}
	}

	/**
	 * Remove empty output directories.
	 */
	private function clean_empty_directories(
		$target_dir
	) {
		if ( ! is_dir( $target_dir ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$target_dir,
				\FilesystemIterator::SKIP_DOTS
			),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isDir() ) {
				continue;
			}

			$path = $file->getPathname();

			$contents = scandir(
				$path
			);

			if (
				is_array( $contents )
				&& 2 === count( $contents )
			) {
				@rmdir( $path );
			}
		}
	}

	/**
	 * Parse the --extensions option.
	 */
	private function get_extensions(
		$extensions
	) {
		if ( ! $extensions ) {
			return self::SUPPORTED_EXTENSIONS;
		}

		$extensions = explode(
			',',
			strtolower( $extensions )
		);

		$extensions = array_map(
			static function ( $extension ) {
				return ltrim(
					trim( $extension ),
					'.'
				);
			},
			$extensions
		);

		$extensions = array_values(
			array_unique(
				array_filter(
					$extensions
				)
			)
		);

		if ( empty( $extensions ) ) {
			\WP_CLI::error(
				'No valid extensions were provided.'
			);
		}

		$unsupported = array_diff(
			$extensions,
			self::SUPPORTED_EXTENSIONS
		);

		if ( ! empty( $unsupported ) ) {
			\WP_CLI::error(
				'Unsupported extensions: '
				. implode(
					', ',
					$unsupported
				)
				. '. Supported: '
				. implode(
					', ',
					self::SUPPORTED_EXTENSIONS
				)
			);
		}

		return $extensions;
	}

	/**
	 * Get output directory.
	 */
	private function get_target_dir(
		$source_dir,
		$output
	) {
		if ( ! $output ) {
			return $this->normalize_path(
				dirname( $source_dir )
				. '/optimized-images'
			);
		}

		$output = trim(
			$output
		);

		if (
			! $this->is_absolute_path(
				$output
			)
		) {
			$output = getcwd()
				. '/'
				. $output;
		}

		return $this->normalize_path(
			$output
		);
	}

	/**
	 * Check if path is absolute.
	 */
	private function is_absolute_path(
		$path
	) {
		$path = str_replace(
			'\\',
			'/',
			$path
		);

		return 1 === preg_match(
			'/^[a-zA-Z]:\//',
			$path
		)
			|| str_starts_with(
				$path,
				'/'
			);
	}

	/**
	 * Normalize filesystem path.
	 */
	private function normalize_path(
		$path
	) {
		$path = str_replace(
			'\\',
			'/',
			$path
		);

		$drive = '';

		if (
			preg_match(
				'/^([a-zA-Z]:)(.*)$/',
				$path,
				$matches
			)
		) {
			$drive = strtoupper(
				$matches[1]
			);

			$path = $matches[2];
		}

		$is_absolute = str_starts_with(
			$path,
			'/'
		);

		$parts = explode(
			'/',
			$path
		);

		$normalized = [];

		foreach ( $parts as $part ) {
			if (
				'' === $part
				|| '.' === $part
			) {
				continue;
			}

			if ( '..' === $part ) {
				array_pop(
					$normalized
				);

				continue;
			}

			$normalized[] = $part;
		}

		$result = implode(
			'/',
			$normalized
		);

		if ( $drive ) {
			return $drive
				. '/'
				. $result;
		}

		if ( $is_absolute ) {
			return '/'
				. $result;
		}

		return $result;
	}

	/**
	 * Normalize cache/output relative path.
	 */
	private function normalize_relative_path(
		$path
	) {
		return ltrim(
			str_replace(
				'\\',
				'/',
				$path
			),
			'/'
		);
	}

	/**
	 * Check if a path equals or is inside another.
	 */
	private function is_same_or_child_path(
		$path,
		$parent
	) {
		$path = rtrim(
			$path,
			'/'
		);

		$parent = rtrim(
			$parent,
			'/'
		);

		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$path = strtolower(
				$path
			);

			$parent = strtolower(
				$parent
			);
		}

		return $path === $parent
			|| str_starts_with(
				$path,
				$parent . '/'
			);
	}

	/**
	 * Get TinyPNG API key.
	 */
	private function get_api_key() {
		$environment_key = getenv(
			'TINIFY_API_KEY'
		);

		if ( $environment_key ) {
			return trim(
				$environment_key
			);
		}

		return $this->get_stored_api_key();
	}

	/**
	 * Read stored API key.
	 */
	private function get_stored_api_key() {
		$config_file = $this->get_config_file();

		if ( ! file_exists( $config_file ) ) {
			return null;
		}

		$contents = file_get_contents(
			$config_file
		);

		if ( false === $contents ) {
			return null;
		}

		$config = json_decode(
			$contents,
			true
		);

		if (
			! is_array( $config )
			|| empty( $config['tinify_api_key'] )
		) {
			return null;
		}

		return trim(
			$config['tinify_api_key']
		);
	}

	/**
	 * Get global config file.
	 */
	private function get_config_file() {
		$home_dir = \WP_CLI\Utils\get_home_dir();

		return $this->normalize_path(
			rtrim(
				$home_dir,
				'/\\'
			)
			. '/.wp-cli/'
			. self::CONFIG_FILENAME
		);
	}

	/**
	 * Get the directory used by the local Node runtime.
	 */
	private function get_local_runtime_dir() {
		$home_dir = \WP_CLI\Utils\get_home_dir();

		return $this->normalize_path(
			rtrim(
				$home_dir,
				'/\\'
			)
			. '/.wp-cli/'
			. self::LOCAL_RUNTIME_DIRNAME
		);
	}

	/**
	 * Get installed local optimizer script.
	 */
	private function get_local_optimizer_script() {
		return $this->get_local_runtime_dir()
			. '/'
			. self::LOCAL_OPTIMIZER_FILENAME;
	}

	/**
	 * Check local optimizer installation.
	 */
	private function is_local_optimizer_ready() {
		$runtime_dir = $this->get_local_runtime_dir();
		$node_version = $this->get_node_version();

		return file_exists(
			$this->get_local_optimizer_script()
		)
			&& file_exists(
				$runtime_dir
				. '/node_modules/sharp/package.json'
			)
			&& $node_version
			&& version_compare(
				$node_version,
				self::MINIMUM_NODE_VERSION,
				'>='
			);
	}

	/**
	 * Read installed Sharp version.
	 */
	private function get_sharp_version() {
		$package_json = $this->get_local_runtime_dir()
			. '/node_modules/sharp/package.json';

		if ( ! file_exists( $package_json ) ) {
			return null;
		}

		$contents = file_get_contents(
			$package_json
		);

		if ( false === $contents ) {
			return null;
		}

		$data = json_decode(
			$contents,
			true
		);

		return is_array( $data )
			&& ! empty( $data['version'] )
				? $data['version']
				: null;
	}

	/**
	 * Get Node.js version.
	 */
	private function get_node_version() {
		$output = [];
		$status = 0;

		exec(
			'node --version 2>&1',
			$output,
			$status
		);

		if (
			0 !== $status
			|| empty( $output[0] )
		) {
			return null;
		}

		$version = ltrim(
			trim( $output[0] ),
			'vV'
		);

		return preg_match(
			'/^\d+\.\d+\.\d+/',
			$version
		)
			? $version
			: null;
	}

	/**
	 * Determine if an executable is available.
	 */
	private function command_exists(
		$command
	) {
		$output = [];
		$status = 0;

		$check_command = '\\' === DIRECTORY_SEPARATOR
			? 'where '
				. escapeshellarg( $command )
				. ' 2>NUL'
			: 'command -v '
				. escapeshellarg( $command )
				. ' 2>/dev/null';

		exec(
			$check_command,
			$output,
			$status
		);

		return 0 === $status
			&& ! empty( $output );
	}

	/**
	 * Human-readable engine strategy.
	 */
	private function get_engine_strategy_label(
		$api_key,
		$local_ready
	) {
		if ( $api_key && $local_ready ) {
			return 'TinyPNG -> Local fallback';
		}

		if ( $api_key ) {
			return 'TinyPNG';
		}

		if ( $local_ready ) {
			return 'Local';
		}

		return 'Unavailable';
	}

	/**
	 * Format engine name.
	 */
	private function format_engine_name(
		$engine
	) {
		return 'tinypng' === $engine
			? 'TinyPNG'
			: 'Local';
	}

	/**
	 * Support old string cache entries and new metadata entries.
	 */
	private function cache_hash_matches(
		$cache_entry,
		$source_hash
	) {
		$cached_hash = is_array(
			$cache_entry
		)
			? (
				$cache_entry['hash']
					?? null
			)
			: $cache_entry;

		return is_string(
			$cached_hash
		)
			&& hash_equals(
				$cached_hash,
				$source_hash
			);
	}

	/**
	 * Load optimization cache.
	 */
	private function load_cache(
		$cache_file
	) {
		if ( ! file_exists( $cache_file ) ) {
			return [];
		}

		$contents = file_get_contents(
			$cache_file
		);

		if ( false === $contents ) {
			return [];
		}

		$data = json_decode(
			$contents,
			true
		);

		return is_array( $data )
			? $data
			: [];
	}

	/**
	 * Save optimization cache.
	 */
	private function save_cache(
		$cache_file,
		$cache
	) {
		$cache_dir = dirname(
			$cache_file
		);

		if (
			! is_dir( $cache_dir )
			&& ! mkdir(
				$cache_dir,
				0755,
				true
			)
		) {
			throw new \RuntimeException(
				'Could not create cache directory.'
			);
		}

		$json = json_encode(
			$cache,
			JSON_PRETTY_PRINT
				| JSON_UNESCAPED_SLASHES
		);

		if ( false === $json ) {
			throw new \RuntimeException(
				'Could not encode optimization cache.'
			);
		}

		if (
			false === file_put_contents(
				$cache_file,
				$json
			)
		) {
			throw new \RuntimeException(
				'Could not save optimization cache.'
			);
		}
	}

	/**
	 * Format bytes for CLI output.
	 */
	private function format_bytes(
		$bytes
	) {
		if ( $bytes >= 1024 * 1024 ) {
			return round(
				$bytes / 1024 / 1024,
				2
			) . ' MB';
		}

		if ( $bytes >= 1024 ) {
			return round(
				$bytes / 1024,
				2
			) . ' KB';
		}

		return $bytes . ' B';
	}
}

\WP_CLI::add_command(
	'optimize-images',
	__NAMESPACE__ . '\\Optimize_Images_Command',
	[
		'when' => 'before_wp_load',
		'shortdesc' => 'Optimize images using TinyPNG with a free local fallback.',
		'synopsis' => [
			[
				'type' => 'positional',
				'name' => 'arguments',
				'description' => 'Image directory, or configure/status/sync command.',
				'optional' => true,
				'repeating' => true,
			],
			[
				'type' => 'assoc',
				'name' => 'output',
				'description' => 'Custom output directory.',
				'optional' => true,
			],
			[
				'type' => 'assoc',
				'name' => 'extensions',
				'description' => 'Comma-separated extensions, e.g. jpg,png.',
				'optional' => true,
			],
			[
				'type' => 'flag',
				'name' => 'dry-run',
				'description' => 'Show what would happen without changing files.',
				'optional' => true,
			],
			[
				'type' => 'flag',
				'name' => 'force',
				'description' => 'Ignore cache and optimize all selected images.',
				'optional' => true,
			],
		],
		'longdesc' => <<<'EOT'
## EXAMPLES

    wp optimize-images configure

    wp optimize-images status

    wp optimize-images ./images

    wp optimize-images ./images --output=./dist/images

    wp optimize-images ./images --extensions=jpg,png

    wp optimize-images ./images --dry-run

    wp optimize-images sync ./images

    wp optimize-images sync ./images --dry-run
EOT,
	]
);