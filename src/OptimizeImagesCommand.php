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

	private const DEFAULT_MAX_WIDTH = 2880;
	private const DEFAULT_MAX_HEIGHT = 2880;

	private const TINIFY_FREE_MONTHLY_COMPRESSIONS = 500;

	/**
	 * Increment this whenever optimization behavior changes
	 * in a way that should invalidate the existing cache.
	 */
	private const PROCESSING_VERSION = 2;

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
	 * Configure TinyPNG.
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

		if (
			false === $json
			|| false === file_put_contents(
				$config_file,
				$json
			)
		) {
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
	 * Display package status.
	 */
	private function status() {
		$config_file = $this->get_config_file();

		$environment_key = getenv(
			'TINIFY_API_KEY'
		);

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

		\WP_CLI::log(
			'PHP: ' . PHP_VERSION
		);

		\WP_CLI::log(
			'cURL: '
			. (
				function_exists( 'curl_init' )
					? 'enabled'
					: 'disabled'
			)
		);

		\WP_CLI::log(
			'TinyPNG API key: ' . $key_status
		);

		\WP_CLI::log(
			'Node.js: '
			. (
				$node_version
					? $node_version
					: 'not found'
			)
		);

		\WP_CLI::log(
			'Local optimizer: '
			. (
				$local_ready
					? 'ready'
					: 'not installed'
			)
		);

		\WP_CLI::log(
			sprintf(
				'Default resize: %d × %d px',
				self::DEFAULT_MAX_WIDTH,
				self::DEFAULT_MAX_HEIGHT
			)
		);

		\WP_CLI::log(
			'Supported extensions: '
			. implode(
				', ',
				self::SUPPORTED_EXTENSIONS
			)
		);

		\WP_CLI::log(
			'Config: ' . $config_file
		);

		\WP_CLI::log( '' );

		if (
			$environment_key
			|| $stored_key
			|| $local_ready
			|| (
				$node_version
				&& $this->command_exists( 'npm' )
			)
		) {
			\WP_CLI::success(
				'Ready.'
			);

			return;
		}

		\WP_CLI::warning(
			'Node.js and npm are required when TinyPNG is not available.'
		);
	}

	/**
	 * Optimize an image directory.
	 */
	private function optimize(
		$directory,
		$assoc_args,
		$sync
	) {
		$source_dir = realpath(
			$directory
		);

		if (
			! $source_dir
			|| ! is_dir( $source_dir )
		) {
			\WP_CLI::error(
				'Directory does not exist.'
			);
		}

		$source_dir = $this->normalize_path(
			$source_dir
		);

		$extensions = $this->get_extensions(
			$assoc_args['extensions'] ?? null
		);

		$resize_settings = $this->get_resize_settings(
			$assoc_args
		);

		$optimization_signature = $this->get_optimization_signature(
			$resize_settings
		);

		$target_dir = $this->get_target_dir(
			$source_dir,
			$assoc_args['output'] ?? null
		);

		if (
			$this->is_same_or_child_path(
				$target_dir,
				$source_dir
			)
		) {
			\WP_CLI::error(
				'The output directory cannot be inside the input directory.'
			);
		}

		$dry_run = isset(
			$assoc_args['dry-run']
		);

		$force = isset(
			$assoc_args['force']
		);

		$cache_file = $target_dir
			. '/'
			. self::CACHE_FILENAME;

		$cache = $this->load_cache(
			$cache_file
		);

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
				$resize_settings,
				$optimization_signature,
				$force,
				$sync
			);

			return;
		}

		$api_key = $this->get_api_key();
		$local_ready = $this->is_local_optimizer_ready();

		if (
			$api_key
			&& ! function_exists( 'curl_init' )
		) {
			\WP_CLI::warning(
				'PHP cURL is unavailable. TinyPNG will be skipped.'
			);

			$api_key = null;
		}

		if (
			! $api_key
			&& ! $local_ready
		) {
			$local_ready = $this->ensure_local_optimizer();
		}

		if (
			! $api_key
			&& ! $local_ready
		) {
			\WP_CLI::error(
				'No image optimization engine is available.'
			);
		}

		if (
			! is_dir( $target_dir )
			&& ! mkdir(
				$target_dir,
				0755,
				true
			)
		) {
			\WP_CLI::error(
				'Could not create output directory.'
			);
		}

		\WP_CLI::log(
			"Source: {$source_dir}"
		);

		\WP_CLI::log(
			"Output: {$target_dir}"
		);

		\WP_CLI::log(
			'Extensions: '
			. implode(
				',',
				$extensions
			)
		);

		if ( $resize_settings['enabled'] ) {
			\WP_CLI::log(
				sprintf(
					'Resize: max %d × %d px',
					$resize_settings['max_width'],
					$resize_settings['max_height']
				)
			);
		} else {
			\WP_CLI::log(
				'Resize: disabled'
			);
		}

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
		$resized = 0;
		$skipped = 0;
		$failed = 0;

		$original_size = 0;
		$output_size = 0;

		foreach (
			$source_files
				as $cache_key => $file
		) {
			$source_file = $file['source'];
			$relative = $file['relative'];
			$source_hash = $file['hash'];

			$target_file = $target_dir
				. '/'
				. $relative;

			if (
				! $force
				&& file_exists( $target_file )
				&& isset( $cache[ $cache_key ] )
				&& $this->cache_matches(
					$cache[ $cache_key ],
					$source_hash,
					$optimization_signature
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

			$will_resize = $this->should_resize(
				$file,
				$resize_settings
			);

			$target_dimensions = $will_resize
				? $this->get_target_dimensions(
					$file,
					$resize_settings
				)
				: null;

			try {
				$engine = $this->optimize_image_with_fallback(
					$source_file,
					$target_file,
					$file['extension'],
					$api_key,
					$relative,
					$resize_settings,
					$will_resize
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

				if ( $will_resize ) {
					$resized++;
				}

				$cache[ $cache_key ] = [
					'hash' => $source_hash,
					'engine' => $engine,
					'signature' => $optimization_signature,
				];

				$this->save_cache(
					$cache_file,
					$cache
				);

				$resize_label = '';

				if (
					$will_resize
					&& $target_dimensions
				) {
					$resize_label = sprintf(
						' [%d×%d → %d×%d]',
						$file['width'],
						$file['height'],
						$target_dimensions['width'],
						$target_dimensions['height']
					);
				}

				\WP_CLI::log(
					sprintf(
						'✓ %s%s  %s → %s (-%s)',
						$relative,
						$resize_label,
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

		$this->print_summary(
			count( $source_files ),
			$optimized,
			$resized,
			$skipped,
			$failed,
			$removed,
			$original_size,
			$output_size,
			$sync
		);
	}

	/**
	 * Optimize with TinyPNG and fall back locally when needed.
	 */
	private function optimize_image_with_fallback(
		$source,
		$target,
		$extension,
		$api_key,
		$relative,
		$resize_settings,
		$will_resize
	) {
		if (
			$api_key
			&& ! $this->tinypng_disabled_for_run
		) {
			$tinypng_source = $source;
			$temp_source = null;

			try {
				if ( $will_resize ) {
					$temp_source = $this->create_resized_temporary_image(
						$source,
						$extension,
						$resize_settings
					);

					$tinypng_source = $temp_source;
				}

				$this->optimize_image_tinypng(
					$tinypng_source,
					$target,
					$api_key
				);

				if (
					$temp_source
					&& file_exists( $temp_source )
				) {
					@unlink( $temp_source );
				}

				return 'tinypng';
			} catch ( Tiny_Png_Optimization_Exception $e ) {
				if (
					$temp_source
					&& file_exists( $temp_source )
				) {
					@unlink( $temp_source );
				}

				if (
					$e->should_disable_for_run()
				) {
					$this->tinypng_disabled_for_run = true;

					if (
						! $this->tinypng_fallback_notice_shown
					) {
						\WP_CLI::warning(
							'TinyPNG is unavailable ('
							. $e->getMessage()
							. '). Switching to the free local optimizer.'
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

		if (
			! $this->ensure_local_optimizer()
		) {
			throw new \RuntimeException(
				'Could not initialize the local optimizer.'
			);
		}

		$this->optimize_image_local(
			$source,
			$target,
			$extension,
			$resize_settings
		);

		return 'local';
	}

	/**
	 * Create a resized high-quality intermediate image
	 * before passing it to TinyPNG.
	 */
	private function create_resized_temporary_image(
		$source,
		$extension,
		$resize_settings
	) {
		if (
			! $this->ensure_local_optimizer()
		) {
			throw new \RuntimeException(
				'Could not initialize the local image resizer.'
			);
		}

		$temp_file = tempnam(
			sys_get_temp_dir(),
			'wp-optimize-'
		);

		if ( false === $temp_file ) {
			throw new \RuntimeException(
				'Could not create temporary image.'
			);
		}

		@unlink( $temp_file );

		$this->run_local_optimizer(
			'resize',
			$source,
			$temp_file,
			$extension,
			$resize_settings
		);

		if ( ! file_exists( $temp_file ) ) {
			throw new \RuntimeException(
				'Could not create resized temporary image.'
			);
		}

		return $temp_file;
	}

	/**
	 * Optimize an image using TinyPNG.
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
				$error ?: 'Connection error.',
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
						[
							401,
							403,
							429,
						],
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
	 * Optimize an image locally.
	 */
	private function optimize_image_local(
		$source,
		$target,
		$extension,
		$resize_settings
	) {
		$temp_target = $target
			. '.tmp-'
			. bin2hex(
				random_bytes( 6 )
			);

		$this->run_local_optimizer(
			'optimize',
			$source,
			$temp_target,
			$extension,
			$resize_settings
		);

		if ( ! file_exists( $temp_target ) ) {
			throw new \RuntimeException(
				'Local optimization failed.'
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

		if (
			$optimized_size >= $source_size
			&& ! $this->should_force_resized_output(
				$source,
				$resize_settings
			)
		) {
			@unlink( $temp_target );

			if (
				! copy(
					$source,
					$target
				)
			) {
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

		if (
			! rename(
				$temp_target,
				$target
			)
		) {
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
	 * Execute the local Sharp script.
	 */
	private function run_local_optimizer(
		$mode,
		$source,
		$target,
		$extension,
		$resize_settings
	) {
		if (
			! $this->ensure_local_optimizer()
		) {
			throw new \RuntimeException(
				'Could not initialize the local optimizer.'
			);
		}

		$max_width = $resize_settings['enabled']
			? $resize_settings['max_width']
			: 0;

		$max_height = $resize_settings['enabled']
			? $resize_settings['max_height']
			: 0;

		$command = sprintf(
			'node %s %s %s %s %s %d %d 2>&1',
			escapeshellarg(
				$this->get_local_optimizer_script()
			),
			escapeshellarg( $mode ),
			escapeshellarg( $source ),
			escapeshellarg( $target ),
			escapeshellarg( $extension ),
			$max_width,
			$max_height
		);

		$output = [];
		$status = 0;

		exec(
			$command,
			$output,
			$status
		);

		if ( 0 !== $status ) {
			$message = ! empty( $output )
				? trim(
					implode(
						' ',
						$output
					)
				)
				: 'Local image processing failed.';

			if ( file_exists( $target ) ) {
				@unlink( $target );
			}

			throw new \RuntimeException(
				$message
			);
		}
	}

	/**
	 * Check whether a larger local output should still
	 * be kept because the dimensions were reduced.
	 */
	private function should_force_resized_output(
		$source,
		$resize_settings
	) {
		if ( ! $resize_settings['enabled'] ) {
			return false;
		}

		$dimensions = $this->get_image_dimensions(
			$source
		);

		if ( ! $dimensions ) {
			return false;
		}

		return $dimensions['width']
			> $resize_settings['max_width']
			|| $dimensions['height']
			> $resize_settings['max_height'];
	}

	/**
	 * Parse resize settings.
	 */
	private function get_resize_settings(
		$assoc_args
	) {
		$no_resize = isset(
			$assoc_args['no-resize']
		);

		if (
			$no_resize
			&& (
				isset( $assoc_args['max-width'] )
				|| isset( $assoc_args['max-height'] )
			)
		) {
			\WP_CLI::error(
				'--no-resize cannot be combined with --max-width or --max-height.'
			);
		}

		if ( $no_resize ) {
			return [
				'enabled' => false,
				'max_width' => null,
				'max_height' => null,
			];
		}

		$max_width = $this->parse_dimension_option(
			$assoc_args['max-width'] ?? null,
			self::DEFAULT_MAX_WIDTH,
			'max-width'
		);

		$max_height = $this->parse_dimension_option(
			$assoc_args['max-height'] ?? null,
			self::DEFAULT_MAX_HEIGHT,
			'max-height'
		);

		return [
			'enabled' => true,
			'max_width' => $max_width,
			'max_height' => $max_height,
		];
	}

	/**
	 * Validate a dimension CLI option.
	 */
	private function parse_dimension_option(
		$value,
		$default,
		$name
	) {
		if (
			null === $value
			|| '' === $value
		) {
			return $default;
		}

		if (
			! ctype_digit(
				(string) $value
			)
			|| (int) $value < 1
		) {
			\WP_CLI::error(
				"--{$name} must be a positive integer."
			);
		}

		return (int) $value;
	}

	/**
	 * Get image dimensions.
	 */
	private function get_image_dimensions(
		$source
	) {
		$dimensions = @getimagesize(
			$source
		);

		if (
			false === $dimensions
			|| empty( $dimensions[0] )
			|| empty( $dimensions[1] )
		) {
			return null;
		}

		return [
			'width' => (int) $dimensions[0],
			'height' => (int) $dimensions[1],
		];
	}

	/**
	 * Check whether an image needs resizing.
	 */
	private function should_resize(
		$file,
		$resize_settings
	) {
		if (
			! $resize_settings['enabled']
			|| empty( $file['width'] )
			|| empty( $file['height'] )
		) {
			return false;
		}

		return $file['width']
			> $resize_settings['max_width']
			|| $file['height']
			> $resize_settings['max_height'];
	}

	/**
	 * Calculate proportional target dimensions.
	 */
	private function get_target_dimensions(
		$file,
		$resize_settings
	) {
		if (
			empty( $file['width'] )
			|| empty( $file['height'] )
		) {
			return null;
		}

		$ratio = min(
			$resize_settings['max_width']
				/ $file['width'],
			$resize_settings['max_height']
				/ $file['height'],
			1
		);

		return [
			'width' => max(
				1,
				(int) round(
					$file['width'] * $ratio
				)
			),
			'height' => max(
				1,
				(int) round(
					$file['height'] * $ratio
				)
			),
		];
	}

	/**
	 * Generate the cache signature for the current settings.
	 */
	private function get_optimization_signature(
		$resize_settings
	) {
		if ( ! $resize_settings['enabled'] ) {
			return sprintf(
				'v%d|resize:none',
				self::PROCESSING_VERSION
			);
		}

		return sprintf(
			'v%d|resize:%dx%d',
			self::PROCESSING_VERSION,
			$resize_settings['max_width'],
			$resize_settings['max_height']
		);
	}

	/**
	 * Check a cache entry.
	 */
	private function cache_matches(
		$cache_entry,
		$source_hash,
		$optimization_signature
	) {
		if ( ! is_array( $cache_entry ) ) {
			return false;
		}

		if (
			empty( $cache_entry['hash'] )
			|| empty( $cache_entry['signature'] )
		) {
			return false;
		}

		return hash_equals(
			$cache_entry['hash'],
			$source_hash
		)
			&& hash_equals(
				$cache_entry['signature'],
				$optimization_signature
			);
	}

	/**
	 * Ensure the local optimizer is available.
	 */
	private function ensure_local_optimizer() {
		if (
			$this->is_local_optimizer_ready()
		) {
			$this->sync_local_optimizer_script();

			return true;
		}

		\WP_CLI::log(
			'Local optimizer is not installed. Setting it up automatically...'
		);

		$this->install_local_optimizer();

		return $this->is_local_optimizer_ready();
	}

	/**
	 * Keep the runtime script synchronized after package updates.
	 */
	private function sync_local_optimizer_script() {
		$source_script = dirname( __DIR__ )
			. DIRECTORY_SEPARATOR
			. 'resources'
			. DIRECTORY_SEPARATOR
			. self::LOCAL_OPTIMIZER_FILENAME;

		$target_script = $this->get_local_optimizer_script();

		if ( ! file_exists( $source_script ) ) {
			throw new \RuntimeException(
				'Local optimizer script is missing from the package.'
			);
		}

		$needs_copy = ! file_exists(
			$target_script
		);

		if (
			! $needs_copy
			&& hash_file( 'sha256', $source_script )
				!== hash_file( 'sha256', $target_script )
		) {
			$needs_copy = true;
		}

		if (
			$needs_copy
			&& ! copy(
				$source_script,
				$target_script
			)
		) {
			throw new \RuntimeException(
				'Could not update the local optimizer script.'
			);
		}
	}

	/**
	 * Install Sharp locally.
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

		if (
			! $this->command_exists( 'npm' )
		) {
			\WP_CLI::error(
				'npm is required for the local optimizer.'
			);
		}

		$runtime_dir = $this->get_local_runtime_dir();

		if (
			! is_dir( $runtime_dir )
			&& ! mkdir(
				$runtime_dir,
				0755,
				true
			)
		) {
			\WP_CLI::error(
				'Could not create the local optimizer directory.'
			);
		}

		$this->sync_local_optimizer_script();

		$package_json = [
			'name' => 'wp-cli-optimize-images-local-runtime',
			'private' => true,
			'dependencies' => [
				'sharp' => self::SHARP_VERSION,
			],
		];

		$json = json_encode(
			$package_json,
			JSON_PRETTY_PRINT
				| JSON_UNESCAPED_SLASHES
		);

		$package_json_path = $runtime_dir
			. DIRECTORY_SEPARATOR
			. 'package.json';

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

		if (
			! chdir( $runtime_dir )
		) {
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
				chdir(
					$original_dir
				);
			}
		}

		if (
			0 !== $status
			|| ! $this->is_local_optimizer_ready()
		) {
			$message = ! empty( $output )
				? implode(
					PHP_EOL,
					$output
				)
				: 'npm install failed.';

			\WP_CLI::error(
				"Could not install the local optimizer.\n{$message}"
			);
		}

		\WP_CLI::success(
			'Local optimizer installed.'
		);
	}

	/**
	 * Determine whether TinyPNG should be disabled
	 * for the remainder of this run.
	 */
	private function is_global_tinypng_failure(
		$status,
		$error,
		$message
	) {
		if (
			in_array(
				$status,
				[
					401,
					403,
					429,
				],
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
	 * Capture TinyPNG usage.
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
	 * Dry-run output.
	 */
	private function run_dry_run(
		$source_dir,
		$target_dir,
		$source_files,
		$stale_files,
		$cache,
		$extensions,
		$resize_settings,
		$optimization_signature,
		$force,
		$sync
	) {
		\WP_CLI::log(
			"Source: {$source_dir}"
		);

		\WP_CLI::log(
			"Output: {$target_dir}"
		);

		\WP_CLI::log(
			'Extensions: '
			. implode(
				',',
				$extensions
			)
		);

		if ( $resize_settings['enabled'] ) {
			\WP_CLI::log(
				sprintf(
					'Resize: max %d × %d px',
					$resize_settings['max_width'],
					$resize_settings['max_height']
				)
			);
		} else {
			\WP_CLI::log(
				'Resize: disabled'
			);
		}

		\WP_CLI::log(
			'Mode: dry-run'
		);

		\WP_CLI::log( '' );

		$would_optimize = 0;
		$would_resize = 0;
		$unchanged = 0;
		$total_size = 0;

		foreach (
			$source_files
				as $cache_key => $file
		) {
			$target_file = $target_dir
				. '/'
				. $file['relative'];

			$total_size += $file['size'];

			if (
				! $force
				&& file_exists( $target_file )
				&& isset( $cache[ $cache_key ] )
				&& $this->cache_matches(
					$cache[ $cache_key ],
					$file['hash'],
					$optimization_signature
				)
			) {
				\WP_CLI::log(
					"↷ {$file['relative']} (unchanged)"
				);

				$unchanged++;

				continue;
			}

			$resize_label = '';

			if (
				$this->should_resize(
					$file,
					$resize_settings
				)
			) {
				$target_dimensions = $this->get_target_dimensions(
					$file,
					$resize_settings
				);

				$would_resize++;

				if ( $target_dimensions ) {
					$resize_label = sprintf(
						' [%d×%d → %d×%d]',
						$file['width'],
						$file['height'],
						$target_dimensions['width'],
						$target_dimensions['height']
					);
				}
			}

			\WP_CLI::log(
				"+ {$file['relative']}{$resize_label} (would optimize)"
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
		\WP_CLI::log( 'Dry run' );
		\WP_CLI::log( '' );

		\WP_CLI::log(
			sprintf(
				'  %-18s %d',
				'Found:',
				count( $source_files )
			)
		);

		\WP_CLI::log(
			sprintf(
				'  %-18s %d',
				'Would optimize:',
				$would_optimize
			)
		);

		\WP_CLI::log(
			sprintf(
				'  %-18s %d',
				'Would resize:',
				$would_resize
			)
		);

		\WP_CLI::log(
			sprintf(
				'  %-18s %d',
				'Unchanged:',
				$unchanged
			)
		);

		if ( $sync ) {
			\WP_CLI::log(
				sprintf(
					'  %-18s %d',
					'Would remove:',
					count( $stale_files )
				)
			);
		}

		\WP_CLI::log(
			sprintf(
				'  %-18s %s',
				'Source size:',
				$this->format_bytes(
					$total_size
				)
			)
		);

		\WP_CLI::log( '' );

		\WP_CLI::success(
			'Dry run complete. No files were changed.'
		);
	}

	/**
	 * Final summary.
	 */
	private function print_summary(
		$found,
		$optimized,
		$resized,
		$skipped,
		$failed,
		$removed,
		$original_size,
		$output_size,
		$sync
	) {
		$total_saved = max(
			0,
			$original_size - $output_size
		);

		$saved_percent = $original_size > 0
			? round(
				( $total_saved / $original_size ) * 100
			)
			: 0;

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Optimization complete' );
		\WP_CLI::log( '' );

		\WP_CLI::log( 'Files' );

		\WP_CLI::log(
			sprintf(
				'  %-12s %d',
				'Found:',
				$found
			)
		);

		\WP_CLI::log(
			sprintf(
				'  %-12s %d',
				'Optimized:',
				$optimized
			)
		);

		\WP_CLI::log(
			sprintf(
				'  %-12s %d',
				'Resized:',
				$resized
			)
		);

		\WP_CLI::log(
			sprintf(
				'  %-12s %d',
				'Skipped:',
				$skipped
			)
		);

		if ( $sync ) {
			\WP_CLI::log(
				sprintf(
					'  %-12s %d',
					'Removed:',
					$removed
				)
			);
		}

		\WP_CLI::log(
			sprintf(
				'  %-12s %d',
				'Failed:',
				$failed
			)
		);

		if ( $optimized > 0 ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Size' );

			\WP_CLI::log(
				sprintf(
					'  %-12s %s',
					'Before:',
					$this->format_bytes(
						$original_size
					)
				)
			);

			\WP_CLI::log(
				sprintf(
					'  %-12s %s',
					'After:',
					$this->format_bytes(
						$output_size
					)
				)
			);

			\WP_CLI::log(
				sprintf(
					'  %-12s %s (%d%%)',
					'Saved:',
					$this->format_bytes(
						$total_saved
					),
					$saved_percent
				)
			);
		}

		if (
			null !== $this->tinypng_compression_count
		) {
			$remaining = max(
				0,
				self::TINIFY_FREE_MONTHLY_COMPRESSIONS
					- $this->tinypng_compression_count
			);

			\WP_CLI::log( '' );
			\WP_CLI::log( 'TinyPNG' );

			\WP_CLI::log(
				sprintf(
					'  %-12s %d / %d',
					'Used:',
					$this->tinypng_compression_count,
					self::TINIFY_FREE_MONTHLY_COMPRESSIONS
				)
			);

			\WP_CLI::log(
				sprintf(
					'  %-12s %d free compressions',
					'Remaining:',
					$remaining
				)
			);
		}

		\WP_CLI::log( '' );

		if ( $failed > 0 ) {
			\WP_CLI::warning(
				sprintf(
					'Completed with %d failed image%s.',
					$failed,
					1 === $failed
						? ''
						: 's'
				)
			);

			return;
		}

		\WP_CLI::success(
			'Images optimized successfully.'
		);
	}

	/**
	 * Collect source images.
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

			$dimensions = $this->get_image_dimensions(
				$source_file
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
				'width' => $dimensions['width'] ?? null,
				'height' => $dimensions['height'] ?? null,
			];
		}

		ksort( $files );

		return $files;
	}

	/**
	 * Find stale output files.
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
	 * Remove stale files.
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
	 * Prune stale cache entries.
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
			$contents = scandir( $path );

			if (
				is_array( $contents )
				&& 2 === count( $contents )
			) {
				@rmdir( $path );
			}
		}
	}

	/**
	 * Parse extensions.
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
	 * Check absolute path.
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
	 * Normalize relative path.
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
	 * Check whether a path is within another path.
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
	 * Get API key.
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

		if (
			! file_exists( $config_file )
		) {
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
			|| empty(
				$config['tinify_api_key']
			)
		) {
			return null;
		}

		return trim(
			$config['tinify_api_key']
		);
	}

	/**
	 * Get config path.
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
	 * Get local runtime directory.
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
	 * Get local optimizer script.
	 */
	private function get_local_optimizer_script() {
		return $this->get_local_runtime_dir()
			. '/'
			. self::LOCAL_OPTIMIZER_FILENAME;
	}

	/**
	 * Check local optimizer.
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
	 * Check executable availability.
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
	 * Load cache.
	 */
	private function load_cache(
		$cache_file
	) {
		if (
			! file_exists( $cache_file )
		) {
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
	 * Save cache.
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

		if (
			false === $json
			|| false === file_put_contents(
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
	 * Format bytes.
	 */
	private function format_bytes(
		$bytes
	) {
		if (
			$bytes >= 1024 * 1024
		) {
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
		'shortdesc' => 'Optimize and resize images for the web.',
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
				'type' => 'assoc',
				'name' => 'max-width',
				'description' => 'Maximum image width in pixels. Default: 2880.',
				'optional' => true,
			],
			[
				'type' => 'assoc',
				'name' => 'max-height',
				'description' => 'Maximum image height in pixels. Default: 2880.',
				'optional' => true,
			],
			[
				'type' => 'flag',
				'name' => 'no-resize',
				'description' => 'Disable automatic image resizing.',
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
				'description' => 'Ignore cache and process all selected images.',
				'optional' => true,
			],
		],
		'longdesc' => <<<'EOT'
## EXAMPLES

    wp optimize-images configure

    wp optimize-images status

    wp optimize-images ./images

    wp optimize-images ./images --max-width=1920

    wp optimize-images ./images --max-width=1920 --max-height=1920

    wp optimize-images ./images --no-resize

    wp optimize-images ./images --output=./dist/images

    wp optimize-images ./images --extensions=jpg,png

    wp optimize-images ./images --dry-run

    wp optimize-images ./images --force

    wp optimize-images sync ./images

    wp optimize-images sync ./images --dry-run
EOT,
	]
);