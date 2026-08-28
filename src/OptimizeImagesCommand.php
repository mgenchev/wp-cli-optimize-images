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

	private const VERSION = '1.1.0';
	private const MAX_DIMENSION = 20000;

	private const SUPPORTED_EXTENSIONS = [
		'jpg',
		'jpeg',
		'png',
		'webp',
		'avif',
		'svg',
	];

	private const CONFIG_FILENAME = 'optimize-images.json';
	private const PROJECT_CONFIG_FILENAME = '.optimize-images.json';
	private const CACHE_FILENAME = '.optimize-images-cache.json';
	private const LOCAL_RUNTIME_DIRNAME = 'optimize-images-local';
	private const LOCAL_OPTIMIZER_FILENAME = 'local-optimizer.cjs';
	private const SHARP_VERSION = '^0.35.0';
	private const SVGO_VERSION = '^4.1.0';
	private const MINIMUM_NODE_VERSION = '20.9.0';
	private const DEFAULT_PRESET = 'default';
	private const DEFAULT_MAX_WIDTH = 2880;
	private const DEFAULT_MAX_HEIGHT = 2880;
	private const DEFAULT_QUALITY = 80;
	private const PRESETS = [
		'web' => [
			'max_width' => 1920,
			'max_height' => 1920,
			'quality' => 75,
		],
		'default' => [
			'max_width' => 2880,
			'max_height' => 2880,
			'quality' => 80,
		],
		'retina' => [
			'max_width' => 3840,
			'max_height' => 3840,
			'quality' => 85,
		],
	];
	private const SUPPORTED_OUTPUT_FORMATS = [ 'webp' ];
	private const AUDIT_LARGE_FILE_BYTES = 1048576;
	private const AUDIT_TOP_FILES = 10;
	private const TINIFY_FREE_MONTHLY_COMPRESSIONS = 500;
	private const PROCESSING_VERSION = 3;
	private const TINYPNG_CONCURRENCY = 3;
	private const MAX_LOCAL_BATCH_CONCURRENCY = 4;

	private $tinypng_disabled_for_run = false;
	private $tinypng_fallback_notice_shown = false;
	private $tinypng_compression_count = null;
	private $progress_active = false;
	private $progress_completed = [];
	private $progress_weights = [];
	private $progress_jobs = [];
	private $progress_engine_samples = [];
	private $progress_total_weight = 0.0;
	private $progress_completed_weight = 0.0;
	private $progress_total_count = 0;
	private $progress_started_at = 0.0;
	private $progress_last_rendered_at = 0.0;
	private $progress_rendered = false;
	private $temp_artifacts = [];
	private $shutdown_cleanup_registered = false;
	private $active_process = null;

	public function __invoke( $args, $assoc_args ) {
		$this->register_shutdown_cleanup();

		$action = $args[0] ?? null;

		if ( 'version' === $action ) {
			$this->version();
			return;
		}

		if ( 'configure' === $action ) {
			$this->configure();
			return;
		}

		if ( 'status' === $action ) {
			$this->status();
			return;
		}

		if ( 'audit' === $action ) {
			$directory = $args[1] ?? null;

			if ( ! $directory ) {
				\WP_CLI::error( 'Please provide an images directory. Example: wp optimize-images audit ./images' );
			}

			$this->audit( $directory, $assoc_args );
			return;
		}

		if ( 'sync' === $action ) {
			$directory = $args[1] ?? null;

			if ( ! $directory ) {
				\WP_CLI::error( 'Please provide an images directory. Example: wp optimize-images sync ./images' );
			}

			$this->optimize( $directory, $assoc_args, true );
			return;
		}

		if ( ! $action ) {
			\WP_CLI::error( 'Please provide an images directory.' );
		}

		$this->optimize( $action, $assoc_args, false );
	}


	private function version() {
		\WP_CLI::log( 'WP-CLI Optimize Images ' . self::VERSION );
	}

	private function configure() {
		\WP_CLI::log( 'TinyPNG API configuration' );
		\WP_CLI::log( '' );
		fwrite( STDOUT, 'Enter TinyPNG API key: ' );

		$api_key = trim( (string) fgets( STDIN ) );

		if ( '' === $api_key ) {
			\WP_CLI::error( 'API key cannot be empty.' );
		}

		$config_file = $this->get_config_file();
		$config_dir = dirname( $config_file );

		if ( ! is_dir( $config_dir ) && ! mkdir( $config_dir, 0755, true ) ) {
			\WP_CLI::error( 'Could not create WP-CLI configuration directory.' );
		}

		$json = json_encode(
			[ 'tinify_api_key' => $api_key ],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		if ( false === $json || false === file_put_contents( $config_file, $json ) ) {
			\WP_CLI::error( 'Could not save configuration.' );
		}

		@chmod( $config_file, 0600 );
		\WP_CLI::success( "TinyPNG API key saved to {$config_file}" );
	}

	private function status() {
		$config_file = $this->get_config_file();
		$environment_key = getenv( 'TINIFY_API_KEY' );
		$stored_key = $this->get_stored_api_key();
		$node_version = $this->get_node_version();
		$local_ready = $this->is_local_optimizer_ready();
		$sharp_version = $this->get_local_dependency_version( 'sharp' );
		$svgo_version = $this->get_local_dependency_version( 'svgo' );

		if ( $environment_key ) {
			$key_status = 'configured via TINIFY_API_KEY';
		} elseif ( $stored_key ) {
			$key_status = 'configured';
		} else {
			$key_status = 'not configured';
		}

		\WP_CLI::log( 'WP-CLI Optimize Images ' . self::VERSION );
		\WP_CLI::log( '' );

		$rows = [
			[ 'PHP', PHP_VERSION ],
			[ 'cURL', function_exists( 'curl_init' ) ? 'enabled' : 'disabled' ],
			[ 'Node.js', $node_version ?: 'not found' ],
			[ 'TinyPNG', $key_status ],
			[ 'Sharp', $sharp_version ? 'ready (' . $sharp_version . ')' : 'not installed' ],
			[ 'SVGO', $svgo_version ? 'ready (' . $svgo_version . ')' : 'not installed' ],
			[
				'Default resize',
				sprintf( '%d × %d px', self::DEFAULT_MAX_WIDTH, self::DEFAULT_MAX_HEIGHT ),
			],
			[ 'Default quality', self::DEFAULT_QUALITY . '%' ],
			[ 'Default preset', self::DEFAULT_PRESET ],
			[ 'Presets', 'web, default, retina' ],
			[ 'Formats', implode( ', ', self::SUPPORTED_EXTENSIONS ) ],
			[ 'Global config', $config_file ],
			[ 'Project config', self::PROJECT_CONFIG_FILENAME . ' (optional)' ],
		];

		foreach ( $rows as $row ) {
			\WP_CLI::log(
				sprintf(
					'  %-18s %s',
					$row[0],
					$row[1]
				)
			);
		}

		\WP_CLI::log( '' );

		if (
			$environment_key
			|| $stored_key
			|| $local_ready
			|| ( $node_version && $this->command_exists( 'npm' ) )
		) {
			\WP_CLI::success( 'Ready.' );
			return;
		}

		\WP_CLI::warning( 'Node.js and npm are required when TinyPNG is not available.' );
	}

	private function audit( $directory, $assoc_args ) {
		$source_dir = $this->resolve_source_directory( $directory );
		$assoc_args = $this->resolve_processing_args( $source_dir, $assoc_args );
		$extensions = $this->get_extensions( $assoc_args['extensions'] ?? null );
		$resize_settings = $this->get_resize_settings( $assoc_args );
		$quality = $this->get_quality( $assoc_args, false );
		$output_format = $this->get_output_format( $assoc_args );
		$source_files = $this->collect_source_files( $source_dir, $extensions );
		$this->prepare_output_paths( $source_files, $output_format );

		$total_size = 0;
		$raster_size = 0;
		$raster_count = 0;
		$svg_count = 0;
		$large_count = 0;
		$oversized_count = 0;
		$convert_count = 0;
		$formats = [];
		$largest = [];

		foreach ( $source_files as &$file ) {
			$total_size += $file['size'];

			if ( $file['size'] > self::AUDIT_LARGE_FILE_BYTES ) {
				$large_count++;
			}

			if ( ! isset( $formats[ $file['extension'] ] ) ) {
				$formats[ $file['extension'] ] = [
					'count' => 0,
					'size' => 0,
				];
			}

			$formats[ $file['extension'] ]['count']++;
			$formats[ $file['extension'] ]['size'] += $file['size'];

			if ( 'svg' === $file['extension'] ) {
				$svg_count++;
			} else {
				if ( ! empty( $file['will_convert'] ) ) {
					$convert_count++;
				}

				$raster_count++;
				$raster_size += $file['size'];
				$this->hydrate_image_dimensions( $file, [
					'enabled' => true,
					'max_width' => self::DEFAULT_MAX_WIDTH,
					'max_height' => self::DEFAULT_MAX_HEIGHT,
				] );

				if (
					$resize_settings['enabled']
					&& $this->should_resize( $file, $resize_settings )
				) {
					$oversized_count++;
				}
			}

			$largest[] = $file;
		}
		unset( $file );

		usort(
			$largest,
			static fn( $a, $b ) => $b['size'] <=> $a['size']
		);

		$largest = array_slice( $largest, 0, self::AUDIT_TOP_FILES );
		ksort( $formats );

		$estimate = $this->estimate_audit_processing_time(
			$raster_count,
			$raster_size,
			$svg_count,
			$oversized_count,
			$convert_count
		);

		\WP_CLI::log( "Source: {$source_dir}" );
		\WP_CLI::log( 'Extensions: ' . implode( ',', $extensions ) );
		\WP_CLI::log( 'Preset: ' . $assoc_args['preset'] );
		\WP_CLI::log( 'Output format: ' . ( $output_format ?: 'original' ) );
		\WP_CLI::log( sprintf( 'Local quality: %d%%', $quality ) );

		if ( $resize_settings['enabled'] ) {
			\WP_CLI::log(
				sprintf(
					'Resize threshold: %d × %d px',
					$resize_settings['max_width'],
					$resize_settings['max_height']
				)
			);
		} else {
			\WP_CLI::log( 'Resize threshold: disabled' );
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Audit' );
		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( '  %-20s %d', 'Files', count( $source_files ) ) );
		\WP_CLI::log( sprintf( '  %-20s %d', 'Raster', $raster_count ) );
		\WP_CLI::log( sprintf( '  %-20s %d', 'SVG', $svg_count ) );
		\WP_CLI::log( sprintf( '  %-20s %s', 'Total size', $this->format_bytes( $total_size ) ) );
		\WP_CLI::log( sprintf( '  %-20s %d', 'Over 1 MB', $large_count ) );

		if ( $resize_settings['enabled'] ) {
			\WP_CLI::log( sprintf( '  %-20s %d', 'Would resize', $oversized_count ) );
		}

		if ( $output_format ) {
			\WP_CLI::log( sprintf( '  %-20s %d', 'Would convert', $convert_count ) );
		}

		\WP_CLI::log( sprintf( '  %-20s %s', 'Estimated time', $estimate ) );
		\WP_CLI::log( '  Estimate is approximate and depends on API/network speed.' );

		if ( ! empty( $formats ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Formats' );

			foreach ( $formats as $extension => $data ) {
				\WP_CLI::log(
					sprintf(
						'  %-8s %4d  %s',
						strtoupper( $extension ),
						$data['count'],
						$this->format_bytes( $data['size'] )
					)
				);
			}
		}

		if ( ! empty( $largest ) ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( sprintf( 'Largest files (top %d)', count( $largest ) ) );

			foreach ( $largest as $file ) {
				$details = 'svg' === $file['extension']
					? 'SVG'
					: (
						! empty( $file['width'] ) && ! empty( $file['height'] )
							? sprintf( '%d×%d', $file['width'], $file['height'] )
							: 'dimensions unknown'
					);

				\WP_CLI::log(
					sprintf(
						'  %s  %s  %s',
						$this->format_bytes( $file['size'] ),
						$details,
						$file['relative']
					)
				);
			}
		}

		\WP_CLI::log( '' );
		\WP_CLI::success( 'Audit complete. No files were changed.' );
	}

	private function optimize( $directory, $assoc_args, $sync ) {
		$source_dir = $this->resolve_source_directory( $directory );
		$assoc_args = $this->resolve_processing_args( $source_dir, $assoc_args );
		$extensions = $this->get_extensions( $assoc_args['extensions'] ?? null );
		$resize_settings = $this->get_resize_settings( $assoc_args );
		$quality = $this->get_quality( $assoc_args );
		$output_format = $this->get_output_format( $assoc_args );
		$target_dir = $this->get_target_dir( $source_dir, $assoc_args['output'] ?? null );

		if ( $this->is_same_or_child_path( $target_dir, $source_dir ) ) {
			\WP_CLI::error( 'The output directory cannot be inside the input directory.' );
		}

		$dry_run = isset( $assoc_args['dry-run'] );
		$force = isset( $assoc_args['force'] );
		$cache_file = $target_dir . '/' . self::CACHE_FILENAME;
		$cache = $this->load_cache( $cache_file );
		$source_files = $this->collect_source_files( $source_dir, $extensions );
		$this->prepare_output_paths( $source_files, $output_format );
		$stale_files = $sync
			? $this->find_stale_files( $target_dir, $source_files, $extensions, $output_format )
			: [];

		if ( $dry_run ) {
			$this->run_dry_run(
				$source_dir,
				$target_dir,
				$source_files,
				$stale_files,
				$cache,
				$extensions,
				$resize_settings,
				$quality,
				$output_format,
				$assoc_args['preset'],
				$force,
				$sync
			);
			return;
		}

		$api_key = $this->get_api_key();

		if ( $api_key && ! function_exists( 'curl_init' ) ) {
			\WP_CLI::warning( 'PHP cURL is unavailable. TinyPNG will be skipped.' );
			$api_key = null;
		}

		if ( ! $api_key && ! $this->is_local_optimizer_ready() ) {
			$this->ensure_local_optimizer();
		}

		if ( ! is_dir( $target_dir ) && ! mkdir( $target_dir, 0755, true ) ) {
			\WP_CLI::error( 'Could not create output directory.' );
		}

		if ( ! is_writable( $target_dir ) ) {
			\WP_CLI::error( 'Output directory is not writable.' );
		}

		\WP_CLI::log( "Source: {$source_dir}" );
		\WP_CLI::log( "Output: {$target_dir}" );
		\WP_CLI::log( 'Extensions: ' . implode( ',', $extensions ) );
		\WP_CLI::log( 'Preset: ' . $assoc_args['preset'] );
		\WP_CLI::log( 'Output format: ' . ( $output_format ?: 'original' ) );
		\WP_CLI::log(
			$resize_settings['enabled']
				? sprintf( 'Resize: max %d × %d px', $resize_settings['max_width'], $resize_settings['max_height'] )
				: 'Resize: disabled'
		);
		\WP_CLI::log( sprintf( 'Local quality: %d%%', $quality ) );
		\WP_CLI::log( '' );

		$removed = 0;
		$cache_dirty = false;

		if ( $sync ) {
			$removed = $this->remove_stale_files( $stale_files, $cache );
			$pruned = $this->prune_cache( $cache, $source_files, $extensions );
			$this->clean_empty_directories( $target_dir );
			$cache_dirty = $removed > 0 || $pruned > 0;
		}

		$pending = [];
		$skipped = 0;

		foreach ( $source_files as $cache_key => $file ) {
			$target_file = $target_dir . '/' . $file['target_relative'];
			$optimization_signature = $this->get_optimization_signature(
				$resize_settings,
				$quality,
				$file['extension'],
				$output_format
			);

			if (
				! $force
				&& file_exists( $target_file )
				&& isset( $cache[ $cache_key ] )
			) {
				$cache_entry = $cache[ $cache_key ];

				if ( $this->cache_matches( $cache_entry, $file, $optimization_signature ) ) {
					if ( $cache_entry !== $cache[ $cache_key ] ) {
						$cache[ $cache_key ] = $cache_entry;
						$cache_dirty = true;
					}

					$skipped++;
					continue;
				}
			}

			$this->hydrate_image_dimensions( $file, $resize_settings );

			$target_file_dir = dirname( $target_file );

			if ( ! is_dir( $target_file_dir ) && ! mkdir( $target_file_dir, 0755, true ) ) {
				$pending[ $cache_key ] = [
					'file' => $file,
					'target' => $target_file,
					'signature' => $optimization_signature,
					'error' => 'Could not create output directory.',
				];
				continue;
			}

			$will_resize = $this->should_resize( $file, $resize_settings );

			$pending[ $cache_key ] = [
				'file' => $file,
				'target' => $target_file,
				'signature' => $optimization_signature,
				'output_extension' => $file['output_extension'],
				'will_convert' => $file['will_convert'],
				'will_resize' => $will_resize,
				'target_dimensions' => $will_resize
					? $this->get_target_dimensions( $file, $resize_settings )
					: null,
			];
		}

		$error_jobs = array_filter(
			$pending,
			static fn( $job ) => ! empty( $job['error'] )
		);

		$processable = array_filter(
			$pending,
			static fn( $job ) => empty( $job['error'] )
		);

		if ( empty( $processable ) && empty( $error_jobs ) ) {
			if ( $cache_dirty ) {
				$this->save_cache( $cache_file, $cache );
			}

			$this->print_up_to_date_summary(
				count( $source_files ),
				$skipped,
				$removed,
				$sync
			);
			$this->cleanup_temp_artifacts();
			return;
		}

		if ( $skipped > 0 ) {
			\WP_CLI::log(
				sprintf(
					'↷ %d unchanged file%s skipped',
					$skipped,
					1 === $skipped ? '' : 's'
				)
			);
			\WP_CLI::log( '' );
		}

		$results = [];


		foreach ( $pending as $cache_key => $job ) {
			if ( ! empty( $job['error'] ) ) {
				$results[ $cache_key ] = [
					'success' => false,
					'error' => $job['error'],
				];
			}
		}

		if ( ! empty( $processable ) ) {
			$this->start_progress( $processable, (bool) $api_key );

			try {
				$svg_jobs = array_filter(
					$processable,
					static fn( $job ) => 'svg' === $job['file']['extension']
				);

				$raster_jobs = array_filter(
					$processable,
					static fn( $job ) => 'svg' !== $job['file']['extension']
				);

				if ( ! empty( $svg_jobs ) ) {
					$results = array_replace(
						$results,
						$this->optimize_local_batch( $svg_jobs, $resize_settings, $quality )
					);
				}

				if ( ! empty( $raster_jobs ) ) {
					if ( $api_key ) {
						$tinypng_jobs = $this->prepare_tinypng_jobs(
							$raster_jobs,
							$resize_settings
						);

						$tinypng_results = $this->optimize_tinypng_batch(
							$tinypng_jobs['jobs'],
							$api_key
						);

						$results = array_replace( $results, $tinypng_results['results'] );
						$fallback_keys = $tinypng_results['fallback_keys'];

						$this->cleanup_temp_directory( $tinypng_jobs['temp_dir'] );

						if ( ! empty( $fallback_keys ) ) {
							$fallback_jobs = array_intersect_key(
								$raster_jobs,
								array_fill_keys( $fallback_keys, true )
							);

							$local_results = $this->optimize_local_batch(
								$fallback_jobs,
								$resize_settings,
								$quality
							);

							$results = array_replace( $results, $local_results );
						}
					} else {
						$results = array_replace(
							$results,
							$this->optimize_local_batch( $raster_jobs, $resize_settings, $quality )
						);
					}
				}
			} finally {
				$this->finish_progress();
			}
		}

		$optimized = 0;
		$resized = 0;
		$converted = 0;
		$failed = 0;
		$original_size = 0;
		$output_size = 0;
		$file_results = [];

		foreach ( $pending as $cache_key => $job ) {
			$file = $job['file'];
			$result = $results[ $cache_key ] ?? [
				'success' => false,
				'error' => 'Image processing did not return a result.',
			];

			if ( empty( $result['success'] ) ) {
				\WP_CLI::warning( "{$file['relative']}: {$result['error']}" );
				$failed++;
				continue;
			}

			$before = $file['size'];
			$after = filesize( $job['target'] );

			if ( false === $after ) {
				\WP_CLI::warning( "{$file['relative']}: Could not read optimized image size." );
				$failed++;
				continue;
			}

			try {
				$source_hash = $this->get_file_hash( $file );
			} catch ( \Exception $e ) {
				\WP_CLI::warning( "{$file['relative']}: {$e->getMessage()}" );
				$failed++;
				continue;
			}

			$saved = max( 0, $before - $after );
			$optimized++;
			$original_size += $before;
			$output_size += $after;

			if ( ! empty( $job['will_resize'] ) ) {
				$resized++;
			}

			if ( ! empty( $job['will_convert'] ) ) {
				$converted++;
			}

			$cache[ $cache_key ] = [
				'hash' => $source_hash,
				'signature' => $job['signature'],
				'size' => $file['size'],
				'mtime' => $file['mtime'],
			];
			$cache_dirty = true;

			$resize_label = '—';

			if ( ! empty( $job['will_resize'] ) && ! empty( $job['target_dimensions'] ) ) {
				$resize_label = sprintf(
					'%d×%d → %d×%d',
					$file['width'],
					$file['height'],
					$job['target_dimensions']['width'],
					$job['target_dimensions']['height']
				);
			}

			$display_file = ! empty( $job['will_convert'] )
				? $file['relative'] . ' → ' . $file['target_relative']
				: $file['relative'];

			$file_results[] = [
				'File' => $this->truncate_table_value( $display_file, 50 ),
				'Resize' => $resize_label,
				'Before' => $this->format_bytes( $before ),
				'After' => $this->format_bytes( $after ),
				'Saved' => $before > 0
					? round( ( $saved / $before ) * 100 ) . '%'
					: '0%',
			];
		}

		if ( $cache_dirty ) {
			$this->save_cache( $cache_file, $cache );
		}

		$this->print_file_results( $file_results );

		$this->print_summary(
			count( $source_files ),
			$optimized,
			$resized,
			$converted,
			$skipped,
			$failed,
			$removed,
			$original_size,
			$output_size,
			$sync
		);
	}

	private function prepare_tinypng_jobs( $jobs, $resize_settings ) {
		$prepared = [];
		$temp_dir = null;
		$resize_jobs = [];

		foreach ( $jobs as $cache_key => $job ) {
			if ( empty( $job['will_resize'] ) && empty( $job['will_convert'] ) ) {
				$prepared[ $cache_key ] = [
					'source' => $job['file']['source'],
					'target' => $job['target'],
					'relative' => $job['file']['relative'],
				];
				continue;
			}

			if ( null === $temp_dir ) {
				$temp_dir = $this->create_temp_directory();
			}

			$temp_file = $temp_dir
				. '/'
				. hash( 'sha256', $cache_key )
				. '.'
				. $job['output_extension'];

			$resize_jobs[ $cache_key ] = [
				'mode' => 'resize',
				'input' => $job['file']['source'],
				'output' => $temp_file,
				'extension' => $job['output_extension'],
				'max_width' => ! empty( $job['will_resize'] ) ? $resize_settings['max_width'] : 0,
				'max_height' => ! empty( $job['will_resize'] ) ? $resize_settings['max_height'] : 0,
			];
		}

		if ( ! empty( $resize_jobs ) ) {
			$resize_results = $this->run_local_batch( $resize_jobs, false );

			foreach ( $resize_jobs as $cache_key => $resize_job ) {
				$result = $resize_results[ $cache_key ] ?? null;

				if ( ! $result || empty( $result['success'] ) ) {
					$prepared[ $cache_key ] = [
						'source' => $jobs[ $cache_key ]['file']['source'],
						'target' => $jobs[ $cache_key ]['target'],
						'relative' => $jobs[ $cache_key ]['file']['relative'],
						'preprocess_error' => $result['error'] ?? 'Local resize failed.',
					];
					continue;
				}

				$prepared[ $cache_key ] = [
					'source' => $resize_job['output'],
					'target' => $jobs[ $cache_key ]['target'],
					'relative' => $jobs[ $cache_key ]['file']['relative'],
				];
			}
		}

		return [
			'jobs' => $prepared,
			'temp_dir' => $temp_dir,
		];
	}

	private function optimize_tinypng_batch( $jobs, $api_key ) {
		$results = [];
		$fallback_keys = [];
		$remaining_jobs = $jobs;

		foreach ( array_chunk( $jobs, self::TINYPNG_CONCURRENCY, true ) as $chunk ) {
			if ( $this->tinypng_disabled_for_run ) {
				$fallback_keys = array_merge( $fallback_keys, array_keys( $chunk ) );
				continue;
			}

			foreach ( $chunk as $cache_key => $job ) {
				if ( empty( $job['preprocess_error'] ) ) {
					$this->start_progress_job( $cache_key, 'tinypng' );
				}
			}

			$upload_results = $this->tinypng_upload_chunk( $chunk, $api_key );
			$download_jobs = [];
			$global_failure = null;

			foreach ( $upload_results as $cache_key => $upload_result ) {
				if ( ! empty( $upload_result['success'] ) ) {
					$download_jobs[ $cache_key ] = [
						'url' => $upload_result['url'],
						'target' => $chunk[ $cache_key ]['target'],
					];
					continue;
				}

				$fallback_keys[] = $cache_key;

				if ( ! empty( $upload_result['global_failure'] ) && null === $global_failure ) {
					$global_failure = $upload_result['error'];
				}
			}

			if ( null !== $global_failure ) {
				$this->disable_tinypng_for_run( $global_failure );
			}

			if ( ! empty( $download_jobs ) ) {
				$download_results = $this->tinypng_download_chunk( $download_jobs, $api_key );

				foreach ( $download_results as $cache_key => $download_result ) {
					if ( ! empty( $download_result['success'] ) ) {
						$results[ $cache_key ] = [ 'success' => true ];
						continue;
					}

					$fallback_keys[] = $cache_key;

					if ( ! empty( $download_result['global_failure'] ) ) {
						$this->disable_tinypng_for_run( $download_result['error'] );
					}
				}
			}

			foreach ( array_keys( $chunk ) as $cache_key ) {
				unset( $remaining_jobs[ $cache_key ] );
			}
		}

		if ( $this->tinypng_disabled_for_run && ! empty( $remaining_jobs ) ) {
			$fallback_keys = array_merge( $fallback_keys, array_keys( $remaining_jobs ) );
		}

		$fallback_keys = array_values( array_unique( $fallback_keys ) );

		return [
			'results' => $results,
			'fallback_keys' => $fallback_keys,
		];
	}

	private function tinypng_upload_chunk( $jobs, $api_key ) {
		$multi = curl_multi_init();
		$handles = [];
		$results = [];

		foreach ( $jobs as $cache_key => $job ) {
			if ( ! empty( $job['preprocess_error'] ) ) {
				$results[ $cache_key ] = [
					'success' => false,
					'error' => $job['preprocess_error'],
					'global_failure' => false,
				];
				continue;
			}

			$data = file_get_contents( $job['source'] );

			if ( false === $data ) {
				$results[ $cache_key ] = [
					'success' => false,
					'error' => 'Could not read source image.',
					'global_failure' => false,
				];
				continue;
			}

			$ch = curl_init( 'https://api.tinify.com/shrink' );
			curl_setopt_array(
				$ch,
				[
					CURLOPT_USERPWD => 'api:' . $api_key,
					CURLOPT_POST => true,
					CURLOPT_POSTFIELDS => $data,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HEADER => true,
					CURLOPT_CONNECTTIMEOUT => 20,
					CURLOPT_TIMEOUT => 120,
				]
			);

			curl_multi_add_handle( $multi, $ch );
			$handles[ $cache_key ] = $ch;
		}

		$this->execute_curl_multi( $multi );

		foreach ( $handles as $cache_key => $ch ) {
			$response = curl_multi_getcontent( $ch );
			$curl_error = curl_error( $ch );
			$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$header_size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
			$headers = is_string( $response ) ? substr( $response, 0, $header_size ) : '';
			$body = is_string( $response ) ? substr( $response, $header_size ) : '';

			$this->capture_tinypng_compression_count( $headers );

			if ( $curl_error ) {
				$results[ $cache_key ] = [
					'success' => false,
					'error' => $curl_error,
					'global_failure' => true,
				];
			} elseif ( 201 !== $status ) {
				$error = json_decode( $body, true );
				$message = is_array( $error ) && ! empty( $error['message'] )
					? $error['message']
					: 'TinyPNG compression failed.';

				$results[ $cache_key ] = [
					'success' => false,
					'error' => $message,
					'global_failure' => $this->is_global_tinypng_failure( $status, $error, $message ),
				];
			} elseif ( preg_match( '/^Location:\s*(.+)$/mi', $headers, $matches ) ) {
				$results[ $cache_key ] = [
					'success' => true,
					'url' => trim( $matches[1] ),
				];
			} else {
				$results[ $cache_key ] = [
					'success' => false,
					'error' => 'TinyPNG output URL was not returned.',
					'global_failure' => true,
				];
			}

			curl_multi_remove_handle( $multi, $ch );
			curl_close( $ch );
		}

		curl_multi_close( $multi );
		return $results;
	}

	private function tinypng_download_chunk( $jobs, $api_key ) {
		$multi = curl_multi_init();
		$handles = [];
		$results = [];

		foreach ( $jobs as $cache_key => $job ) {
			$ch = curl_init( $job['url'] );
			curl_setopt_array(
				$ch,
				[
					CURLOPT_USERPWD => 'api:' . $api_key,
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HEADER => true,
					CURLOPT_CONNECTTIMEOUT => 20,
					CURLOPT_TIMEOUT => 120,
				]
			);

			curl_multi_add_handle( $multi, $ch );
			$handles[ $cache_key ] = $ch;
		}

		$this->execute_curl_multi( $multi );

		foreach ( $handles as $cache_key => $ch ) {
			$response = curl_multi_getcontent( $ch );
			$curl_error = curl_error( $ch );
			$status = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$header_size = curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
			$headers = is_string( $response ) ? substr( $response, 0, $header_size ) : '';
			$image = is_string( $response ) ? substr( $response, $header_size ) : '';

			$this->capture_tinypng_compression_count( $headers );

			if ( $curl_error ) {
				$results[ $cache_key ] = [
					'success' => false,
					'error' => $curl_error,
					'global_failure' => true,
				];
			} elseif ( 200 !== $status ) {
				$results[ $cache_key ] = [
					'success' => false,
					'error' => 'Could not download optimized image.',
					'global_failure' => $status >= 500 || in_array( $status, [ 401, 403, 429 ], true ),
				];
			} else {
				$temp_target = $this->create_target_temp_file(
					$jobs[ $cache_key ]['target']
				);

				if ( false === file_put_contents( $temp_target, $image ) ) {
					@unlink( $temp_target );
					$this->unregister_temp_artifact( $temp_target );
					$results[ $cache_key ] = [
						'success' => false,
						'error' => 'Could not save optimized image.',
						'global_failure' => false,
					];
				} else {
					try {
						$this->replace_output_file(
							$temp_target,
							$jobs[ $cache_key ]['target']
						);
						$results[ $cache_key ] = [ 'success' => true ];
						$this->tick_progress( $cache_key );
					} catch ( \Exception $e ) {
						@unlink( $temp_target );
						$this->unregister_temp_artifact( $temp_target );
						$results[ $cache_key ] = [
							'success' => false,
							'error' => $e->getMessage(),
							'global_failure' => false,
						];
					}
				}
			}

			curl_multi_remove_handle( $multi, $ch );
			curl_close( $ch );
		}

		curl_multi_close( $multi );
		return $results;
	}

	private function execute_curl_multi( $multi ) {
		$running = null;

		do {
			$status = curl_multi_exec( $multi, $running );
			$this->heartbeat_progress();

			if ( CURLM_OK !== $status ) {
				break;
			}

			if ( $running > 0 ) {
				$selected = curl_multi_select( $multi, 0.25 );
				$this->heartbeat_progress();

				if ( -1 === $selected ) {
					usleep( 10000 );
				}
			}
		} while ( $running > 0 );
	}

	private function disable_tinypng_for_run( $message ) {
		$this->tinypng_disabled_for_run = true;

		if ( $this->tinypng_fallback_notice_shown ) {
			return;
		}

		$this->clear_progress_line();
		\WP_CLI::warning(
			'TinyPNG is unavailable ('
			. $message
			. '). Switching to the free local optimizer.'
		);
		$this->render_progress( true );

		$this->tinypng_fallback_notice_shown = true;
	}

	private function optimize_local_batch( $jobs, $resize_settings, $quality ) {
		if ( empty( $jobs ) ) {
			return [];
		}

		$this->ensure_local_optimizer();
		$batch_jobs = [];

		foreach ( $jobs as $cache_key => $job ) {
			$temp_target = $this->create_target_temp_file( $job['target'] );

			$is_svg = 'svg' === $job['file']['extension'];

			$batch_jobs[ $cache_key ] = [
				'mode' => $is_svg ? 'svg-optimize' : 'optimize',
				'input' => $job['file']['source'],
				'output' => $temp_target,
				'extension' => $job['output_extension'],
				'quality' => $quality,
				'max_width' => ! $is_svg && ! empty( $job['will_resize'] )
					? $resize_settings['max_width']
					: 0,
				'max_height' => ! $is_svg && ! empty( $job['will_resize'] )
					? $resize_settings['max_height']
					: 0,
			];
		}

		$batch_results = $this->run_local_batch( $batch_jobs );
		$results = [];

		foreach ( $batch_jobs as $cache_key => $batch_job ) {
			$result = $batch_results[ $cache_key ] ?? null;
			$job = $jobs[ $cache_key ];

			if ( ! $result || empty( $result['success'] ) || ! file_exists( $batch_job['output'] ) ) {
				if ( file_exists( $batch_job['output'] ) ) {
					@unlink( $batch_job['output'] );
				}
				$this->unregister_temp_artifact( $batch_job['output'] );

				$results[ $cache_key ] = [
					'success' => false,
					'error' => $result['error'] ?? 'Local optimization failed.',
				];
				continue;
			}

			$source_size = $job['file']['size'];
			$optimized_size = filesize( $batch_job['output'] );

			if ( false === $optimized_size ) {
				@unlink( $batch_job['output'] );
				$this->unregister_temp_artifact( $batch_job['output'] );
				$results[ $cache_key ] = [
					'success' => false,
					'error' => 'Could not read local optimizer output.',
				];
				continue;
			}

			if (
				$optimized_size >= $source_size
				&& empty( $job['will_resize'] )
				&& empty( $job['will_convert'] )
			) {
				if ( ! copy( $job['file']['source'], $batch_job['output'] ) ) {
					@unlink( $batch_job['output'] );
					$this->unregister_temp_artifact( $batch_job['output'] );
					$results[ $cache_key ] = [
						'success' => false,
						'error' => 'Could not preserve the original image as output.',
					];
					continue;
				}
			}

			try {
				$this->replace_output_file(
					$batch_job['output'],
					$job['target']
				);
			} catch ( \Exception $e ) {
				@unlink( $batch_job['output'] );
				$this->unregister_temp_artifact( $batch_job['output'] );
				$results[ $cache_key ] = [
					'success' => false,
					'error' => $e->getMessage(),
				];
				continue;
			}

			$results[ $cache_key ] = [ 'success' => true ];
		}

		return $results;
	}

	private function run_local_batch( $jobs, $track_progress = true ) {
		if ( empty( $jobs ) ) {
			return [];
		}

		$this->ensure_local_optimizer();
		$manifest_file = tempnam( sys_get_temp_dir(), 'wp-optimize-manifest-' );
		$result_file = tempnam( sys_get_temp_dir(), 'wp-optimize-result-' );

		if ( false === $manifest_file || false === $result_file ) {
			throw new \RuntimeException( 'Could not create local optimizer manifest.' );
		}

		$this->register_temp_artifact( $manifest_file );
		$this->register_temp_artifact( $result_file );

		$manifest = [
			'concurrency' => $this->get_local_batch_concurrency(),
			'jobs' => [],
		];

		foreach ( $jobs as $cache_key => $job ) {
			$manifest['jobs'][] = array_merge(
				[ 'id' => (string) $cache_key ],
				$job
			);
		}

		$json = json_encode( $manifest, JSON_UNESCAPED_SLASHES );

		if ( false === $json || false === file_put_contents( $manifest_file, $json ) ) {
			@unlink( $manifest_file );
			@unlink( $result_file );
			$this->unregister_temp_artifact( $manifest_file );
			$this->unregister_temp_artifact( $result_file );
			throw new \RuntimeException( 'Could not write local optimizer manifest.' );
		}

		$command = sprintf(
			'node %s batch %s %s',
			escapeshellarg( $this->get_local_optimizer_script() ),
			escapeshellarg( $manifest_file ),
			escapeshellarg( $result_file )
		);

		$descriptors = [
			0 => [ 'pipe', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		];

		$process = proc_open( $command, $descriptors, $pipes );

		if ( is_resource( $process ) ) {
			$this->active_process = $process;
		}

		if ( ! is_resource( $process ) ) {
			@unlink( $manifest_file );
			@unlink( $result_file );
			$this->unregister_temp_artifact( $manifest_file );
			$this->unregister_temp_artifact( $result_file );
			throw new \RuntimeException( 'Could not start local batch optimizer.' );
		}

		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout_buffer = '';
		$stderr = '';
		$last_status = null;

		$consume_stdout = function ( $chunk ) use ( &$stdout_buffer, $track_progress, $jobs ) {
			if ( '' === $chunk ) {
				return;
			}

			$stdout_buffer .= $chunk;

			while ( false !== ( $newline = strpos( $stdout_buffer, "\n" ) ) ) {
				$line = trim( substr( $stdout_buffer, 0, $newline ) );
				$stdout_buffer = substr( $stdout_buffer, $newline + 1 );

				if ( ! $track_progress ) {
					continue;
				}

				if ( str_starts_with( $line, 'WP_OPTIMIZE_START:' ) ) {
					$cache_key = substr( $line, strlen( 'WP_OPTIMIZE_START:' ) );
					$job = $jobs[ $cache_key ] ?? [];
					$engine = 'svg-optimize' === ( $job['mode'] ?? '' )
						? 'svg'
						: 'local';

					$this->start_progress_job( $cache_key, $engine );
					continue;
				}

				if ( str_starts_with( $line, 'WP_OPTIMIZE_PROGRESS:' ) ) {
					$this->tick_progress( substr( $line, strlen( 'WP_OPTIMIZE_PROGRESS:' ) ) );
				}
			}
		};

		do {
			$last_status = proc_get_status( $process );
			$consume_stdout( (string) stream_get_contents( $pipes[1] ) );
			$stderr .= (string) stream_get_contents( $pipes[2] );

			$this->heartbeat_progress();

			if ( ! $last_status['running'] ) {
				break;
			}

			usleep( 50000 );
		} while ( true );

		$consume_stdout( (string) stream_get_contents( $pipes[1] ) );
		$stderr .= (string) stream_get_contents( $pipes[2] );

		if ( '' !== trim( $stdout_buffer ) && $track_progress ) {
			$line = trim( $stdout_buffer );

			if ( str_starts_with( $line, 'WP_OPTIMIZE_START:' ) ) {
				$cache_key = substr( $line, strlen( 'WP_OPTIMIZE_START:' ) );
				$job = $jobs[ $cache_key ] ?? [];
				$engine = 'svg-optimize' === ( $job['mode'] ?? '' )
					? 'svg'
					: 'local';

				$this->start_progress_job( $cache_key, $engine );
			} elseif ( str_starts_with( $line, 'WP_OPTIMIZE_PROGRESS:' ) ) {
				$this->tick_progress( substr( $line, strlen( 'WP_OPTIMIZE_PROGRESS:' ) ) );
			}
		}

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$exit_code = proc_close( $process );
		$this->active_process = null;

		if (
			-1 === $exit_code
			&& is_array( $last_status )
			&& isset( $last_status['exitcode'] )
			&& $last_status['exitcode'] >= 0
		) {
			$exit_code = $last_status['exitcode'];
		}

		@unlink( $manifest_file );
		$this->unregister_temp_artifact( $manifest_file );

		if ( 0 !== $exit_code || ! file_exists( $result_file ) ) {
			@unlink( $result_file );
			$this->unregister_temp_artifact( $result_file );
			throw new \RuntimeException(
				'' !== trim( $stderr )
					? trim( $stderr )
					: 'Local batch optimization failed.'
			);
		}

		$result_json = file_get_contents( $result_file );
		@unlink( $result_file );
		$this->unregister_temp_artifact( $result_file );
		$decoded = json_decode( (string) $result_json, true );

		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'Local optimizer returned invalid results.' );
		}

		$results = [];

		foreach ( $decoded as $result ) {
			if ( ! is_array( $result ) || ! isset( $result['id'] ) ) {
				continue;
			}

			$results[ $result['id'] ] = [
				'success' => ! empty( $result['success'] ),
				'error' => $result['error'] ?? null,
			];
		}

		return $results;
	}


	private function register_shutdown_cleanup() {
		if ( $this->shutdown_cleanup_registered ) {
			return;
		}

		$this->shutdown_cleanup_registered = true;

		register_shutdown_function(
			function () {
				if ( is_resource( $this->active_process ) ) {
					@proc_terminate( $this->active_process );
				}

				$this->clear_progress_line();
				$this->cleanup_temp_artifacts();
			}
		);

		if (
			function_exists( 'pcntl_async_signals' )
			&& function_exists( 'pcntl_signal' )
			&& defined( 'SIGINT' )
		) {
			pcntl_async_signals( true );
			pcntl_signal(
				SIGINT,
				function () {
					if ( is_resource( $this->active_process ) ) {
						@proc_terminate( $this->active_process );
					}

					$this->clear_progress_line();
					$this->cleanup_temp_artifacts();
					\WP_CLI::warning( 'Interrupted. Temporary files were cleaned up.' );
					exit( 130 );
				}
			);
		}
	}

	private function register_temp_artifact( $path ) {
		if ( $path ) {
			$this->temp_artifacts[ $this->normalize_path( $path ) ] = true;
		}
	}

	private function unregister_temp_artifact( $path ) {
		if ( ! $path ) {
			return;
		}

		unset(
			$this->temp_artifacts[ $this->normalize_path( $path ) ]
		);
	}

	private function cleanup_temp_artifacts() {
		$paths = array_keys( $this->temp_artifacts );

		foreach ( $paths as $path ) {
			if ( is_dir( $path ) ) {
				$this->cleanup_temp_directory( $path );
				continue;
			}

			if ( file_exists( $path ) ) {
				@unlink( $path );
			}

			$this->unregister_temp_artifact( $path );
		}
	}

	private function create_target_temp_file( $target ) {
		$temp_file = $target
			. '.tmp-'
			. bin2hex( random_bytes( 6 ) );

		$this->register_temp_artifact( $temp_file );
		return $temp_file;
	}

	private function replace_output_file( $temp_file, $target_file ) {
		if ( ! file_exists( $temp_file ) ) {
			throw new \RuntimeException( 'Temporary output file does not exist.' );
		}

		if ( ! file_exists( $target_file ) ) {
			if ( ! @rename( $temp_file, $target_file ) ) {
				throw new \RuntimeException( 'Could not move temporary output into place.' );
			}

			$this->unregister_temp_artifact( $temp_file );
			return;
		}

		if ( @rename( $temp_file, $target_file ) ) {
			$this->unregister_temp_artifact( $temp_file );
			return;
		}

		$backup_file = $target_file
			. '.bak-'
			. bin2hex( random_bytes( 6 ) );

		$this->register_temp_artifact( $backup_file );

		if ( ! @rename( $target_file, $backup_file ) ) {
			$this->unregister_temp_artifact( $backup_file );
			throw new \RuntimeException( 'Could not prepare existing output for replacement.' );
		}

		if ( ! @rename( $temp_file, $target_file ) ) {
			@rename( $backup_file, $target_file );
			$this->unregister_temp_artifact( $backup_file );
			throw new \RuntimeException( 'Could not replace existing output file.' );
		}

		$this->unregister_temp_artifact( $temp_file );
		@unlink( $backup_file );
		$this->unregister_temp_artifact( $backup_file );
	}

	private function create_temp_directory() {
		$path = $this->normalize_path(
			sys_get_temp_dir()
			. '/wp-optimize-images-'
			. bin2hex( random_bytes( 8 ) )
		);

		if ( ! mkdir( $path, 0755, true ) ) {
			throw new \RuntimeException( 'Could not create temporary image directory.' );
		}

		$this->register_temp_artifact( $path );
		return $path;
	}

	private function cleanup_temp_directory( $directory ) {
		if ( ! $directory || ! is_dir( $directory ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			$file->isDir()
				? @rmdir( $file->getPathname() )
				: @unlink( $file->getPathname() );
		}

		@rmdir( $directory );
		$this->unregister_temp_artifact( $directory );
	}

	private function ensure_local_optimizer() {
		if ( $this->is_local_optimizer_ready() ) {
			$this->sync_local_optimizer_script();
			return true;
		}

		\WP_CLI::log( 'Local optimizer is not installed. Setting it up automatically...' );
		$this->install_local_optimizer();
		return $this->is_local_optimizer_ready();
	}

	private function sync_local_optimizer_script() {
		$source_script = dirname( __DIR__ )
			. DIRECTORY_SEPARATOR
			. 'resources'
			. DIRECTORY_SEPARATOR
			. self::LOCAL_OPTIMIZER_FILENAME;
		$target_script = $this->get_local_optimizer_script();

		if ( ! file_exists( $source_script ) ) {
			throw new \RuntimeException( 'Local optimizer script is missing from the package.' );
		}

		$needs_copy = ! file_exists( $target_script );

		if (
			! $needs_copy
			&& hash_file( 'sha256', $source_script ) !== hash_file( 'sha256', $target_script )
		) {
			$needs_copy = true;
		}

		if ( $needs_copy && ! copy( $source_script, $target_script ) ) {
			throw new \RuntimeException( 'Could not update the local optimizer script.' );
		}
	}

	private function install_local_optimizer() {
		$node_version = $this->get_node_version();

		if ( ! $node_version ) {
			\WP_CLI::error( 'Node.js is required for the local optimizer.' );
		}

		if ( version_compare( $node_version, self::MINIMUM_NODE_VERSION, '<' ) ) {
			\WP_CLI::error(
				sprintf(
					'Node.js %s+ is required. Current version: %s',
					self::MINIMUM_NODE_VERSION,
					$node_version
				)
			);
		}

		if ( ! $this->command_exists( 'npm' ) ) {
			\WP_CLI::error( 'npm is required for the local optimizer.' );
		}

		$runtime_dir = $this->get_local_runtime_dir();

		if ( ! is_dir( $runtime_dir ) && ! mkdir( $runtime_dir, 0755, true ) ) {
			\WP_CLI::error( 'Could not create the local optimizer directory.' );
		}

		$this->sync_local_optimizer_script();
		$package_json = [
			'name' => 'wp-cli-optimize-images-local-runtime',
			'private' => true,
			'dependencies' => [
				'sharp' => self::SHARP_VERSION,
				'svgo' => self::SVGO_VERSION,
			],
		];
		$json = json_encode( $package_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$package_json_path = $runtime_dir . DIRECTORY_SEPARATOR . 'package.json';

		if ( false === $json || false === file_put_contents( $package_json_path, $json ) ) {
			\WP_CLI::error( 'Could not create the local optimizer package.json.' );
		}

		\WP_CLI::log( 'Installing local image optimizer...' );
		$original_dir = getcwd();

		if ( ! chdir( $runtime_dir ) ) {
			\WP_CLI::error( 'Could not enter the local optimizer directory.' );
		}

		$npm_command = '\\' === DIRECTORY_SEPARATOR ? 'npm.cmd' : 'npm';
		$command = sprintf( '%s install --omit=dev --no-audit --no-fund 2>&1', $npm_command );
		$output = [];
		$status = 0;

		try {
			exec( $command, $output, $status );
		} finally {
			if ( $original_dir ) {
				chdir( $original_dir );
			}
		}

		if ( 0 !== $status || ! $this->is_local_optimizer_ready() ) {
			$message = ! empty( $output ) ? implode( PHP_EOL, $output ) : 'npm install failed.';
			\WP_CLI::error( "Could not install the local optimizer.\n{$message}" );
		}

		\WP_CLI::success( 'Local optimizer installed.' );
	}

	private function is_global_tinypng_failure( $status, $error, $message ) {
		if ( in_array( $status, [ 401, 403, 429 ], true ) || $status >= 500 ) {
			return true;
		}

		$error_name = is_array( $error ) && ! empty( $error['error'] )
			? strtolower( (string) $error['error'] )
			: '';
		$message = strtolower( (string) $message );

		return str_contains( $error_name, 'account' )
			|| str_contains( $error_name, 'too many' )
			|| str_contains( $message, 'compression limit' )
			|| str_contains( $message, 'limit reached' )
			|| str_contains( $message, 'monthly limit' );
	}

	private function capture_tinypng_compression_count( $headers ) {
		if ( preg_match( '/^Compression-Count:\s*(\d+)/mi', $headers, $matches ) ) {
			$count = (int) $matches[1];
			$this->tinypng_compression_count = null === $this->tinypng_compression_count
				? $count
				: max( $this->tinypng_compression_count, $count );
		}
	}

	private function run_dry_run(
		$source_dir,
		$target_dir,
		$source_files,
		$stale_files,
		$cache,
		$extensions,
		$resize_settings,
		$quality,
		$output_format,
		$preset,
		$force,
		$sync
	) {
		\WP_CLI::log( "Source: {$source_dir}" );
		\WP_CLI::log( "Output: {$target_dir}" );
		\WP_CLI::log( 'Extensions: ' . implode( ',', $extensions ) );
		\WP_CLI::log( 'Preset: ' . $preset );
		\WP_CLI::log( 'Output format: ' . ( $output_format ?: 'original' ) );
		\WP_CLI::log(
			$resize_settings['enabled']
				? sprintf( 'Resize: max %d × %d px', $resize_settings['max_width'], $resize_settings['max_height'] )
				: 'Resize: disabled'
		);
		\WP_CLI::log( sprintf( 'Local quality: %d%%', $quality ) );
		\WP_CLI::log( 'Mode: dry-run' );
		\WP_CLI::log( '' );

		$would_optimize = 0;
		$would_resize = 0;
		$would_convert = 0;
		$unchanged = 0;
		$total_size = 0;

		foreach ( $source_files as $cache_key => $file ) {
			$target_file = $target_dir . '/' . $file['target_relative'];
			$total_size += $file['size'];
			$optimization_signature = $this->get_optimization_signature(
				$resize_settings,
				$quality,
				$file['extension'],
				$output_format
			);

			if (
				! $force
				&& file_exists( $target_file )
				&& isset( $cache[ $cache_key ] )
			) {
				$cache_entry = $cache[ $cache_key ];

				if ( $this->cache_matches( $cache_entry, $file, $optimization_signature ) ) {
					\WP_CLI::log( "↷ {$file['relative']} (unchanged)" );
					$unchanged++;
					continue;
				}
			}

			$this->hydrate_image_dimensions( $file, $resize_settings );
			$resize_label = '';

			if ( ! empty( $file['will_convert'] ) ) {
				$would_convert++;
			}

			if ( $this->should_resize( $file, $resize_settings ) ) {
				$target_dimensions = $this->get_target_dimensions( $file, $resize_settings );
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

			$display_file = ! empty( $file['will_convert'] )
				? $file['relative'] . ' → ' . $file['target_relative']
				: $file['relative'];

			\WP_CLI::log( "+ {$display_file}{$resize_label} (would optimize)" );
			$would_optimize++;
		}

		if ( $sync ) {
			foreach ( $stale_files as $relative => $path ) {
				\WP_CLI::log( "- {$relative} (would remove)" );
			}
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Dry run' );
		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( '  %-18s %d', 'Found:', count( $source_files ) ) );
		\WP_CLI::log( sprintf( '  %-18s %d', 'Would optimize:', $would_optimize ) );
		\WP_CLI::log( sprintf( '  %-18s %d', 'Would resize:', $would_resize ) );

		if ( $output_format ) {
			\WP_CLI::log( sprintf( '  %-18s %d', 'Would convert:', $would_convert ) );
		}

		\WP_CLI::log( sprintf( '  %-18s %d', 'Unchanged:', $unchanged ) );

		if ( $sync ) {
			\WP_CLI::log( sprintf( '  %-18s %d', 'Would remove:', count( $stale_files ) ) );
		}

		\WP_CLI::log( sprintf( '  %-18s %s', 'Source size:', $this->format_bytes( $total_size ) ) );
		\WP_CLI::log( '' );
		\WP_CLI::success( 'Dry run complete. No files were changed.' );
	}

	private function start_progress( $jobs, $tinypng_available ) {
		if ( empty( $jobs ) ) {
			return;
		}

		if ( class_exists( '\\cli\\Shell' ) && \cli\Shell::isPiped() ) {
			return;
		}

		$this->progress_active = true;
		$this->progress_completed = [];
		$this->progress_weights = [];
		$this->progress_jobs = [];
		$this->progress_engine_samples = [];
		$this->progress_total_weight = 0.0;
		$this->progress_completed_weight = 0.0;
		$this->progress_total_count = count( $jobs );
		$this->progress_started_at = microtime( true );
		$this->progress_last_rendered_at = 0.0;
		$this->progress_rendered = false;

		foreach ( $jobs as $cache_key => $job ) {
			$cache_key = (string) $cache_key;
			$weight = $this->get_progress_weight( $job );
			$extension = $job['file']['extension'] ?? '';
			$engine = 'svg' === $extension
				? 'svg'
				: ( $tinypng_available ? 'tinypng' : 'local' );

			$this->progress_weights[ $cache_key ] = $weight;
			$this->progress_jobs[ $cache_key ] = [
				'engine' => $engine,
				'weight' => $weight,
				'started_at' => null,
				'completed_at' => null,
			];
			$this->progress_total_weight += $weight;
		}

		$this->render_progress( true );
	}

	private function get_progress_weight( $job ) {
		$size = max(
			1,
			(int) ( $job['file']['size'] ?? 1 )
		);

		$size_mb = max(
			0.01,
			$size / 1048576
		);

		if ( 'svg' === ( $job['file']['extension'] ?? '' ) ) {
			return 0.90 + min(
				0.25,
				log( 1 + $size_mb, 2 ) * 0.08
			);
		}

		$weight = 1.0 + min(
			0.50,
			log( 1 + $size_mb, 2 ) * 0.15
		);

		if ( ! empty( $job['will_resize'] ) ) {
			$weight *= 1.08;
		}

		return $weight;
	}

	private function start_progress_job( $cache_key, $engine ) {
		if ( ! $this->progress_active ) {
			return;
		}

		$cache_key = (string) $cache_key;

		if (
			! isset( $this->progress_jobs[ $cache_key ] )
			|| isset( $this->progress_completed[ $cache_key ] )
		) {
			return;
		}

		/*
		 * A TinyPNG failure can restart the same job through the local fallback.
		 * Reset the timer so failed network time does not pollute local samples.
		 */
		$this->progress_jobs[ $cache_key ]['engine'] = $engine;
		$this->progress_jobs[ $cache_key ]['started_at'] = microtime( true );
		$this->progress_jobs[ $cache_key ]['completed_at'] = null;
		$this->render_progress( true );
	}

	private function tick_progress( $cache_key ) {
		if ( ! $this->progress_active ) {
			return;
		}

		$cache_key = (string) $cache_key;

		if ( isset( $this->progress_completed[ $cache_key ] ) ) {
			return;
		}

		$now = microtime( true );
		$job = $this->progress_jobs[ $cache_key ] ?? null;

		if ( is_array( $job ) ) {
			$started_at = $job['started_at'] ?? null;
			$engine = $job['engine'] ?? 'local';
			$weight = max( 0.0001, (float) ( $job['weight'] ?? 1.0 ) );

			if ( is_numeric( $started_at ) && $started_at > 0 ) {
				$duration = max( 0.001, $now - $started_at );
				$seconds_per_weight = $duration / $weight;

				if ( ! isset( $this->progress_engine_samples[ $engine ] ) ) {
					$this->progress_engine_samples[ $engine ] = [];
				}

				$this->progress_engine_samples[ $engine ][] = $seconds_per_weight;

				if ( count( $this->progress_engine_samples[ $engine ] ) > 20 ) {
					array_shift( $this->progress_engine_samples[ $engine ] );
				}
			}

			$this->progress_jobs[ $cache_key ]['completed_at'] = $now;
		}

		$this->progress_completed[ $cache_key ] = true;
		$this->progress_completed_weight += $this->progress_weights[ $cache_key ] ?? 1.0;
		$this->render_progress( true );
	}

	private function get_progress_engine_rate( $engine ) {
		$samples = $this->progress_engine_samples[ $engine ] ?? [];

		if ( empty( $samples ) ) {
			return null;
		}

		sort( $samples, SORT_NUMERIC );
		$count = count( $samples );
		$middle = intdiv( $count, 2 );

		if ( 1 === $count % 2 ) {
			return (float) $samples[ $middle ];
		}

		return (
			(float) $samples[ $middle - 1 ]
			+ (float) $samples[ $middle ]
		) / 2;
	}

	private function get_progress_engine_slots( $engine ) {
		if ( 'tinypng' === $engine ) {
			return self::TINYPNG_CONCURRENCY;
		}

		return $this->get_local_batch_concurrency();
	}

	private function get_critical_path_eta() {
		if ( ! $this->progress_active ) {
			return null;
		}

		$completed_count = count( $this->progress_completed );

		if ( $completed_count >= $this->progress_total_count ) {
			return 0.0;
		}

		$minimum_completed = $this->progress_total_count <= 8
			? min( 3, $this->progress_total_count )
			: min( 2, $this->progress_total_count );

		if ( $completed_count < $minimum_completed ) {
			return null;
		}

		$now = microtime( true );
		$engine_jobs = [];

		foreach ( $this->progress_jobs as $cache_key => $job ) {
			if ( isset( $this->progress_completed[ $cache_key ] ) ) {
				continue;
			}

			$engine = $job['engine'] ?? 'local';

			if ( ! isset( $engine_jobs[ $engine ] ) ) {
				$engine_jobs[ $engine ] = [
					'active' => [],
					'queued' => [],
				];
			}

			$rate = $this->get_progress_engine_rate( $engine );

			if ( null === $rate ) {
				return null;
			}

			$predicted = max(
				0.25,
				$rate * max( 0.0001, (float) ( $job['weight'] ?? 1.0 ) )
			);
			$started_at = $job['started_at'] ?? null;

			if ( is_numeric( $started_at ) && $started_at > 0 ) {
				$elapsed = max( 0.0, $now - $started_at );

				/*
				 * Count down normally. If a job outlives its estimate, keep only a
				 * small tail buffer instead of letting ETA explode upwards.
				 */
				$remaining = $predicted - $elapsed;

				if ( $remaining <= 0 ) {
					$remaining = min(
						5.0,
						max( 1.0, $predicted * 0.20 )
					);
				}

				$engine_jobs[ $engine ]['active'][] = $remaining;
			} else {
				$engine_jobs[ $engine ]['queued'][] = $predicted;
			}
		}

		$total_eta = 0.0;

		foreach ( $engine_jobs as $engine => $group ) {
			$slots = max( 1, $this->get_progress_engine_slots( $engine ) );
			$slot_loads = array_fill( 0, $slots, 0.0 );
			$active = $group['active'];
			$queued = $group['queued'];

			/*
			 * Active jobs already occupy workers. Put the longest remaining jobs
			 * first to produce a stable upper-bound approximation of the phase.
			 */
			rsort( $active, SORT_NUMERIC );

			foreach ( array_slice( $active, 0, $slots ) as $index => $remaining ) {
				$slot_loads[ $index ] = $remaining;
			}

			foreach ( $queued as $duration ) {
				$slot_index = array_search( min( $slot_loads ), $slot_loads, true );

				if ( false === $slot_index ) {
					$slot_index = 0;
				}

				$slot_loads[ $slot_index ] += $duration;
			}

			$total_eta += max( $slot_loads );
		}

		return max( 0.0, $total_eta );
	}

	private function heartbeat_progress() {
		$this->render_progress();
	}

	private function render_progress( $force = false ) {
		if ( ! $this->progress_active ) {
			return;
		}

		$now = microtime( true );

		if (
			! $force
			&& $now - $this->progress_last_rendered_at < 1.0
		) {
			return;
		}

		$this->progress_last_rendered_at = $now;

		$elapsed = max(
			0.0,
			$now - $this->progress_started_at
		);

		$completed_count = count( $this->progress_completed );
		$total_weight = max( 0.0001, $this->progress_total_weight );
		$completed_weight = min(
			$total_weight,
			$this->progress_completed_weight
		);
		$fraction = min(
			1.0,
			$completed_weight / $total_weight
		);
		$percent = (int) floor( $fraction * 100 );
		$bar_width = 20;
		$filled = (int) round( $fraction * $bar_width );
		$filled = max( 0, min( $bar_width, $filled ) );
		$bar = str_repeat( '█', $filled )
			. str_repeat( '░', $bar_width - $filled );

		$eta = $this->get_critical_path_eta();
		if ( $completed_count >= $this->progress_total_count ) {
			$percent = 100;
			$bar = str_repeat( '█', $bar_width );
		}

		$spinner_frames = [ '|', '/', '-', '\\' ];
		$spinner_index = (int) floor( $elapsed * 4 ) % count( $spinner_frames );
		$spinner = $spinner_frames[ $spinner_index ];

		$eta_value = null === $eta
			? 'calculating...'
			: '~' . $this->format_progress_duration( (int) ceil( $eta ) );

		if ( $completed_count >= $this->progress_total_count ) {
			$eta_value = '0:00';
		}

		$lines = [
			sprintf(
				'Optimizing images · %d/%d',
				$completed_count,
				$this->progress_total_count
			),
			sprintf(
				'%s  %d%%',
				$bar,
				$percent
			),
			sprintf(
				'%s elapsed · ETA %s %s',
				$this->format_progress_duration( (int) floor( $elapsed ) ),
				$eta_value,
				$spinner
			),
		];

		if ( $this->progress_rendered ) {
			$this->erase_progress_lines();
		}

		fwrite(
			STDOUT,
			implode( PHP_EOL, $lines )
		);

		$this->progress_rendered = true;
	}

	private function erase_progress_lines() {
		/*
		 * The cursor sits on the third progress line. Clear it, move up,
		 * clear the second line, then move up and clear the first line.
		 */
		fwrite(
			STDOUT,
			"\r\033[2K"
			. "\033[1A\r\033[2K"
			. "\033[1A\r\033[2K"
		);
	}

	private function clear_progress_line() {
		if (
			! $this->progress_active
			|| ! $this->progress_rendered
		) {
			return;
		}

		$this->erase_progress_lines();
		$this->progress_rendered = false;
	}

	private function finish_progress() {
		if ( ! $this->progress_active ) {
			return;
		}

		foreach ( array_keys( $this->progress_weights ) as $cache_key ) {
			$this->progress_completed[ $cache_key ] = true;
		}

		$this->progress_completed_weight = $this->progress_total_weight;
		$this->render_progress( true );
		fwrite( STDOUT, PHP_EOL );

		$this->progress_active = false;
		$this->progress_completed = [];
		$this->progress_weights = [];
		$this->progress_jobs = [];
		$this->progress_engine_samples = [];
		$this->progress_total_weight = 0.0;
		$this->progress_completed_weight = 0.0;
		$this->progress_total_count = 0;
		$this->progress_started_at = 0.0;
		$this->progress_last_rendered_at = 0.0;
		$this->progress_rendered = false;
	}

	private function format_progress_duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );
		$hours = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$remaining_seconds = $seconds % 60;

		if ( $hours > 0 ) {
			return sprintf(
				'%d:%02d:%02d',
				$hours,
				$minutes,
				$remaining_seconds
			);
		}

		return sprintf(
			'%d:%02d',
			$minutes,
			$remaining_seconds
		);
	}

	private function print_file_results( $file_results ) {
		if ( empty( $file_results ) ) {
			return;
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Results' );

		\WP_CLI\Utils\format_items(
			'table',
			$file_results,
			[
				'File',
				'Resize',
				'Before',
				'After',
				'Saved',
			]
		);
	}

	private function truncate_table_value( $value, $max_length ) {
		if ( strlen( $value ) <= $max_length ) {
			return $value;
		}

		$extension = pathinfo( $value, PATHINFO_EXTENSION );
		$suffix = $extension ? '.' . $extension : '';
		$available = max( 1, $max_length - strlen( $suffix ) - 1 );

		return substr( $value, 0, $available ) . '…' . $suffix;
	}


	private function print_up_to_date_summary( $found, $skipped, $removed, $sync ) {
		\WP_CLI::log( '' );

		if ( 0 === $found ) {
			\WP_CLI::success( 'No supported files found.' );
			return;
		}

		\WP_CLI::log( 'Nothing to optimize.' );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Files' );
		\WP_CLI::log( sprintf( '  %d total', $found ) );
		\WP_CLI::log( sprintf( '  %d unchanged', $skipped ) );

		if ( $sync ) {
			\WP_CLI::log( sprintf( '  %d removed', $removed ) );
		}

		\WP_CLI::log( '' );
		\WP_CLI::success( 'Everything is up to date.' );
	}

	private function print_summary(
		$found,
		$optimized,
		$resized,
		$converted,
		$skipped,
		$failed,
		$removed,
		$original_size,
		$output_size,
		$sync
	) {
		$total_saved = max( 0, $original_size - $output_size );
		$saved_percent = $original_size > 0
			? round( ( $total_saved / $original_size ) * 100 )
			: 0;
		$separator = str_repeat( '─', 28 );

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Summary' );
		\WP_CLI::log( $separator );
		\WP_CLI::log( '' );

		\WP_CLI::log( 'Files' );
		\WP_CLI::log( sprintf( '  %d total', $found ) );
		\WP_CLI::log( sprintf( '  %d optimized', $optimized ) );
		\WP_CLI::log( sprintf( '  %d resized', $resized ) );

		if ( $converted > 0 ) {
			\WP_CLI::log( sprintf( '  %d converted', $converted ) );
		}

		\WP_CLI::log( sprintf( '  %d skipped', $skipped ) );

		if ( $sync ) {
			\WP_CLI::log( sprintf( '  %d removed', $removed ) );
		}

		\WP_CLI::log( sprintf( '  %d failed', $failed ) );

		if ( $optimized > 0 ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Size' );
			\WP_CLI::log(
				sprintf(
					'  %s → %s',
					$this->format_bytes( $original_size ),
					$this->format_bytes( $output_size )
				)
			);
			\WP_CLI::log(
				sprintf(
					'  Saved %s (%d%%)',
					$this->format_bytes( $total_saved ),
					$saved_percent
				)
			);
		}

		if ( null !== $this->tinypng_compression_count ) {
			$remaining = max(
				0,
				self::TINIFY_FREE_MONTHLY_COMPRESSIONS - $this->tinypng_compression_count
			);

			\WP_CLI::log( '' );
			\WP_CLI::log( 'TinyPNG' );
			\WP_CLI::log(
				sprintf(
					'  %d / %d used',
					$this->tinypng_compression_count,
					self::TINIFY_FREE_MONTHLY_COMPRESSIONS
				)
			);
			\WP_CLI::log( sprintf( '  %d remaining', $remaining ) );
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( $separator );
		\WP_CLI::log( '' );

		if ( $failed > 0 ) {
			$this->cleanup_temp_artifacts();
			\WP_CLI::warning(
				sprintf(
					'Completed with %d failed image%s.',
					$failed,
					1 === $failed ? '' : 's'
				)
			);
			\WP_CLI::halt( 1 );
		}

		$this->cleanup_temp_artifacts();
		\WP_CLI::success( 'Images optimized successfully.' );
	}

	private function collect_source_files( $source_dir, $extensions ) {
		$files = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source_dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower( $file->getExtension() );

			if ( ! in_array( $extension, $extensions, true ) ) {
				continue;
			}

			$source_file = $this->normalize_path( $file->getPathname() );
			$relative = $this->normalize_relative_path(
				substr( $source_file, strlen( $source_dir ) + 1 )
			);

			$files[ $relative ] = [
				'source' => $source_file,
				'relative' => $relative,
				'extension' => $extension,
				'size' => $file->getSize(),
				'mtime' => $file->getMTime(),
				'hash' => null,
				'width' => null,
				'height' => null,
				'target_relative' => $relative,
				'output_extension' => $extension,
				'will_convert' => false,
			];
		}

		ksort( $files );
		return $files;
	}

	private function find_stale_files( $target_dir, $source_files, $extensions, $output_format = null ) {
		if ( ! is_dir( $target_dir ) ) {
			return [];
		}

		$stale = [];
		$expected_targets = [];

		foreach ( $source_files as $source_file ) {
			$expected_targets[ $source_file['target_relative'] ] = true;
		}

		$scan_extensions = $extensions;

		if ( $output_format ) {
			$scan_extensions[] = $output_format;
			$scan_extensions = array_values( array_unique( $scan_extensions ) );
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $target_dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || self::CACHE_FILENAME === $file->getFilename() ) {
				continue;
			}

			$extension = strtolower( $file->getExtension() );

			if ( ! in_array( $extension, $scan_extensions, true ) ) {
				continue;
			}

			$target_file = $this->normalize_path( $file->getPathname() );
			$relative = $this->normalize_relative_path(
				substr( $target_file, strlen( $target_dir ) + 1 )
			);

			if ( ! isset( $expected_targets[ $relative ] ) ) {
				$stale[ $relative ] = $target_file;
			}
		}

		ksort( $stale );
		return $stale;
	}

	private function remove_stale_files( $stale_files, &$cache ) {
		$removed = 0;

		foreach ( $stale_files as $relative => $path ) {
			if ( file_exists( $path ) && ! unlink( $path ) ) {
				\WP_CLI::warning( "{$relative}: Could not remove stale file." );
				continue;
			}

			unset( $cache[ $relative ] );
			\WP_CLI::log( "− {$relative} (removed)" );
			$removed++;
		}

		return $removed;
	}

	private function prune_cache( &$cache, $source_files, $extensions ) {
		$pruned = 0;

		foreach ( array_keys( $cache ) as $relative ) {
			$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );

			if ( ! in_array( $extension, $extensions, true ) ) {
				continue;
			}

			if ( ! isset( $source_files[ $relative ] ) ) {
				unset( $cache[ $relative ] );
				$pruned++;
			}
		}

		return $pruned;
	}

	private function clean_empty_directories( $target_dir ) {
		if ( ! is_dir( $target_dir ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $target_dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isDir() ) {
				continue;
			}

			$contents = scandir( $file->getPathname() );

			if ( is_array( $contents ) && 2 === count( $contents ) ) {
				@rmdir( $file->getPathname() );
			}
		}
	}


	private function resolve_source_directory( $directory ) {
		$source_dir = realpath( $directory );

		if ( ! $source_dir || ! is_dir( $source_dir ) ) {
			\WP_CLI::error( 'Directory does not exist.' );
		}

		if ( ! is_readable( $source_dir ) ) {
			\WP_CLI::error( 'Directory is not readable.' );
		}

		return $this->normalize_path( $source_dir );
	}

	private function estimate_audit_processing_time(
		$raster_count,
		$raster_size,
		$svg_count,
		$oversized_count,
		$convert_count = 0
	) {
		if ( 0 === $raster_count && 0 === $svg_count ) {
			return '~0:00';
		}

		$raster_mb = $raster_size / 1024 / 1024;
		$tinypng_available = (bool) $this->get_api_key()
			&& function_exists( 'curl_init' );

		if ( $tinypng_available ) {
			$waves = (int) ceil(
				$raster_count / self::TINYPNG_CONCURRENCY
			);
			$seconds = ( $waves * 4.0 )
				+ ( $raster_mb * 0.9 )
				+ ( $oversized_count * 0.75 )
				+ ( $convert_count * 0.6 )
				+ ( $svg_count * 0.12 );
		} else {
			$workers = max( 1, $this->get_local_batch_concurrency() );
			$seconds = (
				( $raster_mb * 0.8 )
				+ ( $raster_count * 0.7 )
				+ ( $svg_count * 0.2 )
			) / min( $workers, max( 1, $raster_count + $svg_count ) );
		}

		$seconds = max( 2.0, $seconds );
		$minimum = max( 1, (int) floor( $seconds * 0.7 ) );
		$maximum = max( $minimum + 1, (int) ceil( $seconds * 1.4 ) );

		return sprintf(
			'~%s–%s',
			$this->format_progress_duration( $minimum ),
			$this->format_progress_duration( $maximum )
		);
	}


	private function resolve_processing_args( $source_dir, $assoc_args ) {
		$project_config = $this->get_project_config( $source_dir );
		$project_preset = $project_config['preset'] ?? self::DEFAULT_PRESET;
		$this->validate_preset( $project_preset );
		$preset = self::PRESETS[ $project_preset ];

		$resolved = [
			'preset' => $project_preset,
			'max-width' => $preset['max_width'],
			'max-height' => $preset['max_height'],
			'quality' => $preset['quality'],
		];

		$config_map = [
			'quality' => 'quality',
			'max_width' => 'max-width',
			'max_height' => 'max-height',
			'format' => 'format',
			'extensions' => 'extensions',
		];

		foreach ( $config_map as $config_key => $arg_key ) {
			if ( array_key_exists( $config_key, $project_config ) ) {
				$resolved[ $arg_key ] = $project_config[ $config_key ];
			}
		}

		if ( isset( $assoc_args['preset'] ) ) {
			$cli_preset = strtolower( trim( (string) $assoc_args['preset'] ) );
			$this->validate_preset( $cli_preset );
			$resolved['preset'] = $cli_preset;
			$resolved['max-width'] = self::PRESETS[ $cli_preset ]['max_width'];
			$resolved['max-height'] = self::PRESETS[ $cli_preset ]['max_height'];
			$resolved['quality'] = self::PRESETS[ $cli_preset ]['quality'];
		}

		foreach ( $assoc_args as $key => $value ) {
			if ( 'preset' === $key ) {
				continue;
			}

			$resolved[ $key ] = $value;
		}

		return $resolved;
	}

	private function get_project_config( $source_dir ) {
		$config_file = $source_dir . '/' . self::PROJECT_CONFIG_FILENAME;

		if ( ! file_exists( $config_file ) ) {
			return [];
		}

		if ( ! is_readable( $config_file ) ) {
			\WP_CLI::error( 'Project config is not readable: ' . $config_file );
		}

		$contents = file_get_contents( $config_file );
		$config = false !== $contents ? json_decode( $contents, true ) : null;

		if ( ! is_array( $config ) ) {
			\WP_CLI::error( 'Project config contains invalid JSON: ' . $config_file );
		}

		$allowed = [
			'preset',
			'format',
			'quality',
			'max_width',
			'max_height',
			'extensions',
		];

		if ( isset( $config['preset'] ) && ! is_string( $config['preset'] ) ) {
			\WP_CLI::error( 'Project config preset must be a string.' );
		}

		if ( isset( $config['format'] ) && ! is_string( $config['format'] ) ) {
			\WP_CLI::error( 'Project config format must be a string.' );
		}

		if ( isset( $config['extensions'] ) && ! is_array( $config['extensions'] ) && ! is_string( $config['extensions'] ) ) {
			\WP_CLI::error( 'Project config extensions must be an array or comma-separated string.' );
		}

		$unknown = array_diff( array_keys( $config ), $allowed );

		if ( ! empty( $unknown ) ) {
			\WP_CLI::error(
				'Unknown project config option(s): ' . implode( ', ', $unknown )
			);
		}

		return $config;
	}

	private function validate_preset( $preset ) {
		$preset = strtolower( trim( (string) $preset ) );

		if ( ! isset( self::PRESETS[ $preset ] ) ) {
			\WP_CLI::error(
				'Unsupported preset: '
				. $preset
				. '. Available: '
				. implode( ', ', array_keys( self::PRESETS ) )
			);
		}
	}

	private function get_output_format( $assoc_args ) {
		if ( empty( $assoc_args['format'] ) ) {
			return null;
		}

		$format = strtolower( ltrim( trim( (string) $assoc_args['format'] ), '.' ) );

		if ( 'original' === $format ) {
			return null;
		}

		if ( ! in_array( $format, self::SUPPORTED_OUTPUT_FORMATS, true ) ) {
			\WP_CLI::error(
				'Unsupported output format: '
				. $format
				. '. Supported: '
				. implode( ', ', self::SUPPORTED_OUTPUT_FORMATS )
			);
		}

		return $format;
	}

	private function prepare_output_paths( &$source_files, $output_format ) {
		$targets = [];

		foreach ( $source_files as $cache_key => &$file ) {
			$output_extension = 'svg' === $file['extension'] || ! $output_format
				? $file['extension']
				: $output_format;
			$target_relative = $file['relative'];

			if ( $output_extension !== $file['extension'] ) {
				$target_relative = preg_replace(
					'/\.[^.\/]+$/',
					'.' . $output_extension,
					$file['relative']
				);
			}

			$target_key = '\\' === DIRECTORY_SEPARATOR
				? strtolower( $target_relative )
				: $target_relative;

			if ( isset( $targets[ $target_key ] ) ) {
				\WP_CLI::error(
					'Output filename collision: '
					. $targets[ $target_key ]
					. ' and '
					. $file['relative']
					. ' would both become '
					. $target_relative
				);
			}

			$targets[ $target_key ] = $file['relative'];
			$file['target_relative'] = $target_relative;
			$file['output_extension'] = $output_extension;
			$file['will_convert'] = 'svg' !== $file['extension']
				&& $output_extension !== $file['extension'];
		}
		unset( $file );
	}

	private function get_resize_settings( $assoc_args ) {
		$no_resize = isset( $assoc_args['no-resize'] );

		if ( $no_resize ) {
			return [
				'enabled' => false,
				'max_width' => null,
				'max_height' => null,
			];
		}

		return [
			'enabled' => true,
			'max_width' => $this->parse_dimension_option(
				$assoc_args['max-width'] ?? null,
				self::DEFAULT_MAX_WIDTH,
				'max-width'
			),
			'max_height' => $this->parse_dimension_option(
				$assoc_args['max-height'] ?? null,
				self::DEFAULT_MAX_HEIGHT,
				'max-height'
			),
		];
	}

	private function parse_dimension_option( $value, $default, $name ) {
		if ( null === $value || '' === $value ) {
			return $default;
		}

		if ( ! ctype_digit( (string) $value ) || (int) $value < 1 ) {
			\WP_CLI::error( "--{$name} must be a positive integer." );
		}

		if ( (int) $value > self::MAX_DIMENSION ) {
			\WP_CLI::error(
				sprintf(
					'--%s cannot exceed %d pixels.',
					$name,
					self::MAX_DIMENSION
				)
			);
		}

		return (int) $value;
	}

	private function get_image_dimensions( $source ) {
		$dimensions = @getimagesize( $source );

		if ( false === $dimensions || empty( $dimensions[0] ) || empty( $dimensions[1] ) ) {
			return null;
		}

		return [
			'width' => (int) $dimensions[0],
			'height' => (int) $dimensions[1],
		];
	}

	private function hydrate_image_dimensions( &$file, $resize_settings ) {
		if (
			'svg' === $file['extension']
			|| ! $resize_settings['enabled']
			|| null !== $file['width']
			|| null !== $file['height']
		) {
			return;
		}

		$dimensions = $this->get_image_dimensions( $file['source'] );

		if ( ! $dimensions ) {
			return;
		}

		$file['width'] = $dimensions['width'];
		$file['height'] = $dimensions['height'];
	}

	private function should_resize( $file, $resize_settings ) {
		if (
			! $resize_settings['enabled']
			|| empty( $file['width'] )
			|| empty( $file['height'] )
		) {
			return false;
		}

		return $file['width'] > $resize_settings['max_width']
			|| $file['height'] > $resize_settings['max_height'];
	}

	private function get_target_dimensions( $file, $resize_settings ) {
		if (
			! $resize_settings['enabled']
			|| empty( $file['width'] )
			|| empty( $file['height'] )
		) {
			return null;
		}

		$ratio = min(
			$resize_settings['max_width'] / $file['width'],
			$resize_settings['max_height'] / $file['height'],
			1
		);

		return [
			'width' => max( 1, (int) round( $file['width'] * $ratio ) ),
			'height' => max( 1, (int) round( $file['height'] * $ratio ) ),
		];
	}

	private function get_optimization_signature( $resize_settings, $quality, $extension, $output_format = null ) {
		if ( 'svg' === $extension ) {
			return sprintf( 'v%d|svg', self::PROCESSING_VERSION );
		}

		$format_suffix = $output_format ? '|format:' . $output_format : '';

		if ( ! $resize_settings['enabled'] ) {
			return sprintf(
				'v%d|resize:none|quality:%d%s',
				self::PROCESSING_VERSION,
				$quality,
				$format_suffix
			);
		}

		return sprintf(
			'v%d|resize:%dx%d|quality:%d%s',
			self::PROCESSING_VERSION,
			$resize_settings['max_width'],
			$resize_settings['max_height'],
			$quality,
			$format_suffix
		);
	}

	private function get_quality( $assoc_args, $warn = true ) {
		$value = $assoc_args['quality'] ?? self::DEFAULT_QUALITY;

		if (
			! ctype_digit( (string) $value )
			|| (int) $value < 1
			|| (int) $value > 100
		) {
			\WP_CLI::error( '--quality must be an integer between 1 and 100.' );
		}

		$quality = (int) $value;

		if ( $warn && $quality < 40 ) {
			\WP_CLI::warning(
				'Local quality below 40 may cause visible compression artifacts.'
			);
		}

		return $quality;
	}

	private function get_file_hash( &$file ) {
		if ( ! empty( $file['hash'] ) ) {
			return $file['hash'];
		}

		$hash = hash_file( 'sha256', $file['source'] );

		if ( false === $hash ) {
			throw new \RuntimeException( "Could not hash source file: {$file['relative']}" );
		}

		$file['hash'] = $hash;
		return $hash;
	}

	private function cache_matches( &$cache_entry, &$file, $optimization_signature ) {
		if (
			! is_array( $cache_entry )
			|| empty( $cache_entry['hash'] )
			|| empty( $cache_entry['signature'] )
			|| ! hash_equals( $cache_entry['signature'], $optimization_signature )
		) {
			return false;
		}

		if (
			isset( $cache_entry['size'], $cache_entry['mtime'] )
			&& (int) $cache_entry['size'] === (int) $file['size']
			&& (int) $cache_entry['mtime'] === (int) $file['mtime']
		) {
			return true;
		}

		$source_hash = $this->get_file_hash( $file );

		if ( ! hash_equals( $cache_entry['hash'], $source_hash ) ) {
			return false;
		}

		$cache_entry['size'] = $file['size'];
		$cache_entry['mtime'] = $file['mtime'];

		return true;
	}

	private function get_extensions( $extensions ) {
		if ( ! $extensions ) {
			return self::SUPPORTED_EXTENSIONS;
		}

		$extension_values = is_array( $extensions )
			? $extensions
			: explode( ',', strtolower( (string) $extensions ) );

		$extensions = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $extension ) => ltrim( trim( strtolower( (string) $extension ) ), '.' ),
						$extension_values
					)
				)
			)
		);

		if ( empty( $extensions ) ) {
			\WP_CLI::error( 'No valid extensions were provided.' );
		}

		$unsupported = array_diff( $extensions, self::SUPPORTED_EXTENSIONS );

		if ( ! empty( $unsupported ) ) {
			\WP_CLI::error(
				'Unsupported extensions: '
				. implode( ', ', $unsupported )
				. '. Supported: '
				. implode( ', ', self::SUPPORTED_EXTENSIONS )
			);
		}

		return $extensions;
	}

	private function get_target_dir( $source_dir, $output ) {
		if ( ! $output ) {
			return $this->normalize_path( dirname( $source_dir ) . '/optimized-images' );
		}

		$output = trim( $output );

		if ( ! $this->is_absolute_path( $output ) ) {
			$output = getcwd() . '/' . $output;
		}

		return $this->normalize_path( $output );
	}

	private function is_absolute_path( $path ) {
		$path = str_replace( '\\', '/', $path );
		return 1 === preg_match( '/^[a-zA-Z]:\//', $path ) || str_starts_with( $path, '/' );
	}

	private function normalize_path( $path ) {
		$path = str_replace( '\\', '/', $path );
		$drive = '';

		if ( preg_match( '/^([a-zA-Z]:)(.*)$/', $path, $matches ) ) {
			$drive = strtoupper( $matches[1] );
			$path = $matches[2];
		}

		$is_absolute = str_starts_with( $path, '/' );
		$parts = explode( '/', $path );
		$normalized = [];

		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}

			if ( '..' === $part ) {
				array_pop( $normalized );
				continue;
			}

			$normalized[] = $part;
		}

		$result = implode( '/', $normalized );

		if ( $drive ) {
			return $drive . '/' . $result;
		}

		return $is_absolute ? '/' . $result : $result;
	}

	private function normalize_relative_path( $path ) {
		return ltrim( str_replace( '\\', '/', $path ), '/' );
	}

	private function is_same_or_child_path( $path, $parent ) {
		$path = rtrim( $path, '/' );
		$parent = rtrim( $parent, '/' );

		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$path = strtolower( $path );
			$parent = strtolower( $parent );
		}

		return $path === $parent || str_starts_with( $path, $parent . '/' );
	}

	private function get_api_key() {
		$environment_key = getenv( 'TINIFY_API_KEY' );
		return $environment_key ? trim( $environment_key ) : $this->get_stored_api_key();
	}

	private function get_stored_api_key() {
		$config_file = $this->get_config_file();

		if ( ! file_exists( $config_file ) ) {
			return null;
		}

		$contents = file_get_contents( $config_file );
		$config = false !== $contents ? json_decode( $contents, true ) : null;

		return is_array( $config ) && ! empty( $config['tinify_api_key'] )
			? trim( $config['tinify_api_key'] )
			: null;
	}

	private function get_config_file() {
		$home_dir = \WP_CLI\Utils\get_home_dir();
		return $this->normalize_path(
			rtrim( $home_dir, '/\\' ) . '/.wp-cli/' . self::CONFIG_FILENAME
		);
	}

	private function get_local_runtime_dir() {
		$home_dir = \WP_CLI\Utils\get_home_dir();
		return $this->normalize_path(
			rtrim( $home_dir, '/\\' ) . '/.wp-cli/' . self::LOCAL_RUNTIME_DIRNAME
		);
	}

	private function get_local_optimizer_script() {
		return $this->get_local_runtime_dir() . '/' . self::LOCAL_OPTIMIZER_FILENAME;
	}


	private function get_local_dependency_version( $package ) {
		$package_file = $this->get_local_runtime_dir()
			. '/node_modules/'
			. $package
			. '/package.json';

		if ( ! file_exists( $package_file ) ) {
			return null;
		}

		$contents = file_get_contents( $package_file );
		$data = false !== $contents
			? json_decode( $contents, true )
			: null;

		return is_array( $data ) && ! empty( $data['version'] )
			? (string) $data['version']
			: null;
	}

	private function is_local_optimizer_ready() {
		$runtime_dir = $this->get_local_runtime_dir();
		$node_version = $this->get_node_version();

		return file_exists( $this->get_local_optimizer_script() )
			&& file_exists( $runtime_dir . '/node_modules/sharp/package.json' )
			&& file_exists( $runtime_dir . '/node_modules/svgo/package.json' )
			&& $node_version
			&& version_compare( $node_version, self::MINIMUM_NODE_VERSION, '>=' );
	}

	private function get_local_batch_concurrency() {
		$cores = $this->get_cpu_core_count();

		return max(
			1,
			min( self::MAX_LOCAL_BATCH_CONCURRENCY, $cores )
		);
	}

	private function get_cpu_core_count() {
		$windows_cores = getenv( 'NUMBER_OF_PROCESSORS' );

		if ( $windows_cores && ctype_digit( (string) $windows_cores ) ) {
			return max( 1, (int) $windows_cores );
		}

		$commands = '\\' === DIRECTORY_SEPARATOR
			? []
			: [ 'getconf _NPROCESSORS_ONLN 2>/dev/null', 'nproc 2>/dev/null', 'sysctl -n hw.ncpu 2>/dev/null' ];

		foreach ( $commands as $command ) {
			$output = [];
			$status = 0;
			exec( $command, $output, $status );

			if ( 0 !== $status || empty( $output[0] ) ) {
				continue;
			}

			$value = trim( $output[0] );

			if ( ctype_digit( $value ) && (int) $value > 0 ) {
				return (int) $value;
			}
		}

		return self::MAX_LOCAL_BATCH_CONCURRENCY;
	}

	private function get_node_version() {
		$output = [];
		$status = 0;
		exec( 'node --version 2>&1', $output, $status );

		if ( 0 !== $status || empty( $output[0] ) ) {
			return null;
		}

		$version = ltrim( trim( $output[0] ), 'vV' );
		return preg_match( '/^\d+\.\d+\.\d+/', $version ) ? $version : null;
	}

	private function command_exists( $command ) {
		$output = [];
		$status = 0;
		$check_command = '\\' === DIRECTORY_SEPARATOR
			? 'where ' . escapeshellarg( $command ) . ' 2>NUL'
			: 'command -v ' . escapeshellarg( $command ) . ' 2>/dev/null';
		exec( $check_command, $output, $status );
		return 0 === $status && ! empty( $output );
	}

	private function load_cache( $cache_file ) {
		if ( ! file_exists( $cache_file ) ) {
			return [];
		}

		$contents = file_get_contents( $cache_file );
		$data = false !== $contents ? json_decode( $contents, true ) : null;
		return is_array( $data ) ? $data : [];
	}


	private function save_cache( $cache_file, $cache ) {
		$cache_dir = dirname( $cache_file );

		if ( ! is_dir( $cache_dir ) && ! mkdir( $cache_dir, 0755, true ) ) {
			throw new \RuntimeException( 'Could not create cache directory.' );
		}

		$json = json_encode(
			$cache,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		if ( false === $json ) {
			throw new \RuntimeException( 'Could not encode optimization cache.' );
		}

		$temp_file = $this->create_target_temp_file( $cache_file );

		if ( false === file_put_contents( $temp_file, $json, LOCK_EX ) ) {
			@unlink( $temp_file );
			$this->unregister_temp_artifact( $temp_file );
			throw new \RuntimeException( 'Could not write optimization cache.' );
		}

		try {
			$this->replace_output_file( $temp_file, $cache_file );
		} catch ( \Exception $e ) {
			@unlink( $temp_file );
			$this->unregister_temp_artifact( $temp_file );
			throw new \RuntimeException(
				'Could not save optimization cache: ' . $e->getMessage()
			);
		}
	}

	private function format_bytes( $bytes ) {
		if ( $bytes >= 1024 * 1024 ) {
			return round( $bytes / 1024 / 1024, 2 ) . ' MB';
		}

		if ( $bytes >= 1024 ) {
			return round( $bytes / 1024, 2 ) . ' KB';
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
				'description' => 'Image directory, or configure/status/version/audit/sync command.',
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
				'description' => 'Comma-separated extensions, e.g. jpg,png,svg.',
				'optional' => true,
			],
			[
				'type' => 'assoc',
				'name' => 'preset',
				'description' => 'Processing preset: web, default or retina.',
				'optional' => true,
			],
			[
				'type' => 'assoc',
				'name' => 'format',
				'description' => 'Raster output format: webp or original.',
				'optional' => true,
			],
			[
				'type' => 'assoc',
				'name' => 'quality',
				'description' => 'Local raster quality from 1 to 100. Overrides the selected preset.',
				'optional' => true,
			],
			[
				'type' => 'assoc',
				'name' => 'max-width',
				'description' => 'Maximum image width in pixels. Overrides the selected preset.',
				'optional' => true,
			],
			[
				'type' => 'assoc',
				'name' => 'max-height',
				'description' => 'Maximum image height in pixels. Overrides the selected preset.',
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

    wp optimize-images version

    wp optimize-images audit ./images

    wp optimize-images ./images

    wp optimize-images ./images --preset=web

    wp optimize-images ./images --preset=retina

    wp optimize-images ./images --format=webp

    wp optimize-images ./images --format=original

    wp optimize-images ./images --quality=70

    wp optimize-images ./images --max-width=1920

    wp optimize-images ./images --max-width=1920 --max-height=1920

    wp optimize-images ./images --no-resize

    wp optimize-images ./images --output=./dist/images

    wp optimize-images ./images --extensions=jpg,png,svg

    wp optimize-images ./images --dry-run

    wp optimize-images ./images --force

    wp optimize-images sync ./images

    wp optimize-images sync ./images --dry-run
EOT,
	]
);