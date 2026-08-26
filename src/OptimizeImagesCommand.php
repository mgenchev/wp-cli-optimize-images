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
	private const PROCESSING_VERSION = 2;
	private const TINYPNG_CONCURRENCY = 3;
	private const LOCAL_BATCH_CONCURRENCY = 4;

	private $tinypng_disabled_for_run = false;
	private $tinypng_fallback_notice_shown = false;
	private $tinypng_compression_count = null;

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
		\WP_CLI::log( 'cURL: ' . ( function_exists( 'curl_init' ) ? 'enabled' : 'disabled' ) );
		\WP_CLI::log( 'TinyPNG API key: ' . $key_status );
		\WP_CLI::log( 'Node.js: ' . ( $node_version ?: 'not found' ) );
		\WP_CLI::log( 'Local optimizer: ' . ( $local_ready ? 'ready' : 'not installed' ) );
		\WP_CLI::log( sprintf( 'Default resize: %d × %d px', self::DEFAULT_MAX_WIDTH, self::DEFAULT_MAX_HEIGHT ) );
		\WP_CLI::log( 'Supported extensions: ' . implode( ', ', self::SUPPORTED_EXTENSIONS ) );
		\WP_CLI::log( 'Config: ' . $config_file );
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

	private function optimize( $directory, $assoc_args, $sync ) {
		$source_dir = realpath( $directory );

		if ( ! $source_dir || ! is_dir( $source_dir ) ) {
			\WP_CLI::error( 'Directory does not exist.' );
		}

		$source_dir = $this->normalize_path( $source_dir );
		$extensions = $this->get_extensions( $assoc_args['extensions'] ?? null );
		$resize_settings = $this->get_resize_settings( $assoc_args );
		$optimization_signature = $this->get_optimization_signature( $resize_settings );
		$target_dir = $this->get_target_dir( $source_dir, $assoc_args['output'] ?? null );

		if ( $this->is_same_or_child_path( $target_dir, $source_dir ) ) {
			\WP_CLI::error( 'The output directory cannot be inside the input directory.' );
		}

		$dry_run = isset( $assoc_args['dry-run'] );
		$force = isset( $assoc_args['force'] );
		$cache_file = $target_dir . '/' . self::CACHE_FILENAME;
		$cache = $this->load_cache( $cache_file );
		$source_files = $this->collect_source_files( $source_dir, $extensions );
		$stale_files = $sync
			? $this->find_stale_files( $target_dir, $source_files, $extensions )
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
				$optimization_signature,
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

		\WP_CLI::log( "Source: {$source_dir}" );
		\WP_CLI::log( "Output: {$target_dir}" );
		\WP_CLI::log( 'Extensions: ' . implode( ',', $extensions ) );
		\WP_CLI::log(
			$resize_settings['enabled']
				? sprintf( 'Resize: max %d × %d px', $resize_settings['max_width'], $resize_settings['max_height'] )
				: 'Resize: disabled'
		);
		\WP_CLI::log( '' );

		$removed = 0;

		if ( $sync ) {
			$removed = $this->remove_stale_files( $stale_files, $cache );
			$this->prune_cache( $cache, $source_files, $extensions );
			$this->clean_empty_directories( $target_dir );
			$this->save_cache( $cache_file, $cache );
		}

		$pending = [];
		$skipped = 0;

		foreach ( $source_files as $cache_key => $file ) {
			$target_file = $target_dir . '/' . $file['relative'];

			if (
				! $force
				&& file_exists( $target_file )
				&& isset( $cache[ $cache_key ] )
				&& $this->cache_matches( $cache[ $cache_key ], $file['hash'], $optimization_signature )
			) {
				\WP_CLI::log( "↷ {$file['relative']} (unchanged)" );
				$skipped++;
				continue;
			}

			$target_file_dir = dirname( $target_file );

			if ( ! is_dir( $target_file_dir ) && ! mkdir( $target_file_dir, 0755, true ) ) {
				$pending[ $cache_key ] = [
					'file' => $file,
					'target' => $target_file,
					'error' => 'Could not create output directory.',
				];
				continue;
			}

			$pending[ $cache_key ] = [
				'file' => $file,
				'target' => $target_file,
				'will_resize' => $this->should_resize( $file, $resize_settings ),
				'target_dimensions' => $this->get_target_dimensions( $file, $resize_settings ),
			];
		}

		$results = [];
		$processable = array_filter(
			$pending,
			static fn( $job ) => empty( $job['error'] )
		);

		foreach ( $pending as $cache_key => $job ) {
			if ( ! empty( $job['error'] ) ) {
				$results[ $cache_key ] = [
					'success' => false,
					'error' => $job['error'],
				];
			}
		}

		if ( ! empty( $processable ) ) {
			if ( $api_key ) {
				$tinypng_jobs = $this->prepare_tinypng_jobs(
					$processable,
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
						$processable,
						array_fill_keys( $fallback_keys, true )
					);

					$local_results = $this->optimize_local_batch(
						$fallback_jobs,
						$resize_settings
					);

					$results = array_replace( $results, $local_results );
				}
			} else {
				$results = array_replace(
					$results,
					$this->optimize_local_batch( $processable, $resize_settings )
				);
			}
		}

		$optimized = 0;
		$resized = 0;
		$failed = 0;
		$original_size = 0;
		$output_size = 0;

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

			$saved = max( 0, $before - $after );
			$optimized++;
			$original_size += $before;
			$output_size += $after;

			if ( ! empty( $job['will_resize'] ) ) {
				$resized++;
			}

			$cache[ $cache_key ] = [
				'hash' => $file['hash'],
				'signature' => $optimization_signature,
			];

			$resize_label = '';

			if ( ! empty( $job['will_resize'] ) && ! empty( $job['target_dimensions'] ) ) {
				$resize_label = sprintf(
					' [%d×%d → %d×%d]',
					$file['width'],
					$file['height'],
					$job['target_dimensions']['width'],
					$job['target_dimensions']['height']
				);
			}

			\WP_CLI::log(
				sprintf(
					'✓ %s%s  %s → %s (-%s)',
					$file['relative'],
					$resize_label,
					$this->format_bytes( $before ),
					$this->format_bytes( $after ),
					$before > 0 ? round( ( $saved / $before ) * 100 ) . '%' : '0%'
				)
			);
		}

		$this->save_cache( $cache_file, $cache );

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

	private function prepare_tinypng_jobs( $jobs, $resize_settings ) {
		$prepared = [];
		$temp_dir = null;
		$resize_jobs = [];

		foreach ( $jobs as $cache_key => $job ) {
			if ( empty( $job['will_resize'] ) ) {
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
				. $job['file']['extension'];

			$resize_jobs[ $cache_key ] = [
				'mode' => 'resize',
				'input' => $job['file']['source'],
				'output' => $temp_file,
				'extension' => $job['file']['extension'],
				'max_width' => $resize_settings['max_width'],
				'max_height' => $resize_settings['max_height'],
			];
		}

		if ( ! empty( $resize_jobs ) ) {
			$resize_results = $this->run_local_batch( $resize_jobs );

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
			} elseif ( false === file_put_contents( $jobs[ $cache_key ]['target'], $image ) ) {
				$results[ $cache_key ] = [
					'success' => false,
					'error' => 'Could not save optimized image.',
					'global_failure' => false,
				];
			} else {
				$results[ $cache_key ] = [ 'success' => true ];
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

			if ( CURLM_OK !== $status ) {
				break;
			}

			if ( $running > 0 ) {
				$selected = curl_multi_select( $multi, 1.0 );

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

		\WP_CLI::warning(
			'TinyPNG is unavailable ('
			. $message
			. '). Switching to the free local optimizer.'
		);

		$this->tinypng_fallback_notice_shown = true;
	}

	private function optimize_local_batch( $jobs, $resize_settings ) {
		if ( empty( $jobs ) ) {
			return [];
		}

		$this->ensure_local_optimizer();
		$batch_jobs = [];

		foreach ( $jobs as $cache_key => $job ) {
			$temp_target = $job['target']
				. '.tmp-'
				. bin2hex( random_bytes( 6 ) );

			$batch_jobs[ $cache_key ] = [
				'mode' => 'optimize',
				'input' => $job['file']['source'],
				'output' => $temp_target,
				'extension' => $job['file']['extension'],
				'max_width' => $resize_settings['enabled'] ? $resize_settings['max_width'] : 0,
				'max_height' => $resize_settings['enabled'] ? $resize_settings['max_height'] : 0,
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
				$results[ $cache_key ] = [
					'success' => false,
					'error' => 'Could not read local optimizer output.',
				];
				continue;
			}

			if ( $optimized_size >= $source_size && empty( $job['will_resize'] ) ) {
				@unlink( $batch_job['output'] );

				if ( ! copy( $job['file']['source'], $job['target'] ) ) {
					$results[ $cache_key ] = [
						'success' => false,
						'error' => 'Could not save local optimizer output.',
					];
					continue;
				}

				$results[ $cache_key ] = [ 'success' => true ];
				continue;
			}

			if ( file_exists( $job['target'] ) && ! unlink( $job['target'] ) ) {
				@unlink( $batch_job['output'] );
				$results[ $cache_key ] = [
					'success' => false,
					'error' => 'Could not replace existing optimized image.',
				];
				continue;
			}

			if ( ! rename( $batch_job['output'], $job['target'] ) ) {
				if ( ! copy( $batch_job['output'], $job['target'] ) ) {
					@unlink( $batch_job['output'] );
					$results[ $cache_key ] = [
						'success' => false,
						'error' => 'Could not save local optimizer output.',
					];
					continue;
				}

				@unlink( $batch_job['output'] );
			}

			$results[ $cache_key ] = [ 'success' => true ];
		}

		return $results;
	}

	private function run_local_batch( $jobs ) {
		if ( empty( $jobs ) ) {
			return [];
		}

		$this->ensure_local_optimizer();
		$manifest_file = tempnam( sys_get_temp_dir(), 'wp-optimize-manifest-' );
		$result_file = tempnam( sys_get_temp_dir(), 'wp-optimize-result-' );

		if ( false === $manifest_file || false === $result_file ) {
			throw new \RuntimeException( 'Could not create local optimizer manifest.' );
		}

		$manifest = [
			'concurrency' => self::LOCAL_BATCH_CONCURRENCY,
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
			throw new \RuntimeException( 'Could not write local optimizer manifest.' );
		}

		$command = sprintf(
			'node %s batch %s %s 2>&1',
			escapeshellarg( $this->get_local_optimizer_script() ),
			escapeshellarg( $manifest_file ),
			escapeshellarg( $result_file )
		);

		$output = [];
		$status = 0;
		exec( $command, $output, $status );
		@unlink( $manifest_file );

		if ( 0 !== $status || ! file_exists( $result_file ) ) {
			@unlink( $result_file );
			throw new \RuntimeException(
				! empty( $output )
					? trim( implode( ' ', $output ) )
					: 'Local batch optimization failed.'
			);
		}

		$result_json = file_get_contents( $result_file );
		@unlink( $result_file );
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

	private function create_temp_directory() {
		$path = $this->normalize_path(
			sys_get_temp_dir()
			. '/wp-optimize-images-'
			. bin2hex( random_bytes( 8 ) )
		);

		if ( ! mkdir( $path, 0755, true ) ) {
			throw new \RuntimeException( 'Could not create temporary image directory.' );
		}

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
		$optimization_signature,
		$force,
		$sync
	) {
		\WP_CLI::log( "Source: {$source_dir}" );
		\WP_CLI::log( "Output: {$target_dir}" );
		\WP_CLI::log( 'Extensions: ' . implode( ',', $extensions ) );
		\WP_CLI::log(
			$resize_settings['enabled']
				? sprintf( 'Resize: max %d × %d px', $resize_settings['max_width'], $resize_settings['max_height'] )
				: 'Resize: disabled'
		);
		\WP_CLI::log( 'Mode: dry-run' );
		\WP_CLI::log( '' );

		$would_optimize = 0;
		$would_resize = 0;
		$unchanged = 0;
		$total_size = 0;

		foreach ( $source_files as $cache_key => $file ) {
			$target_file = $target_dir . '/' . $file['relative'];
			$total_size += $file['size'];

			if (
				! $force
				&& file_exists( $target_file )
				&& isset( $cache[ $cache_key ] )
				&& $this->cache_matches( $cache[ $cache_key ], $file['hash'], $optimization_signature )
			) {
				\WP_CLI::log( "↷ {$file['relative']} (unchanged)" );
				$unchanged++;
				continue;
			}

			$resize_label = '';

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

			\WP_CLI::log( "+ {$file['relative']}{$resize_label} (would optimize)" );
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
		\WP_CLI::log( sprintf( '  %-18s %d', 'Unchanged:', $unchanged ) );

		if ( $sync ) {
			\WP_CLI::log( sprintf( '  %-18s %d', 'Would remove:', count( $stale_files ) ) );
		}

		\WP_CLI::log( sprintf( '  %-18s %s', 'Source size:', $this->format_bytes( $total_size ) ) );
		\WP_CLI::log( '' );
		\WP_CLI::success( 'Dry run complete. No files were changed.' );
	}

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
		$total_saved = max( 0, $original_size - $output_size );
		$saved_percent = $original_size > 0
			? round( ( $total_saved / $original_size ) * 100 )
			: 0;

		\WP_CLI::log( '' );
		\WP_CLI::log( 'Optimization complete' );
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Files' );
		\WP_CLI::log( sprintf( '  %-12s %d', 'Found:', $found ) );
		\WP_CLI::log( sprintf( '  %-12s %d', 'Optimized:', $optimized ) );
		\WP_CLI::log( sprintf( '  %-12s %d', 'Resized:', $resized ) );
		\WP_CLI::log( sprintf( '  %-12s %d', 'Skipped:', $skipped ) );

		if ( $sync ) {
			\WP_CLI::log( sprintf( '  %-12s %d', 'Removed:', $removed ) );
		}

		\WP_CLI::log( sprintf( '  %-12s %d', 'Failed:', $failed ) );

		if ( $optimized > 0 ) {
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Size' );
			\WP_CLI::log( sprintf( '  %-12s %s', 'Before:', $this->format_bytes( $original_size ) ) );
			\WP_CLI::log( sprintf( '  %-12s %s', 'After:', $this->format_bytes( $output_size ) ) );
			\WP_CLI::log( sprintf( '  %-12s %s (%d%%)', 'Saved:', $this->format_bytes( $total_saved ), $saved_percent ) );
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
					'  %-12s %d / %d',
					'Used:',
					$this->tinypng_compression_count,
					self::TINIFY_FREE_MONTHLY_COMPRESSIONS
				)
			);
			\WP_CLI::log( sprintf( '  %-12s %d free compressions', 'Remaining:', $remaining ) );
		}

		\WP_CLI::log( '' );

		if ( $failed > 0 ) {
			\WP_CLI::warning(
				sprintf(
					'Completed with %d failed image%s.',
					$failed,
					1 === $failed ? '' : 's'
				)
			);
			return;
		}

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
			$dimensions = $this->get_image_dimensions( $source_file );

			$files[ $relative ] = [
				'source' => $source_file,
				'relative' => $relative,
				'extension' => $extension,
				'size' => filesize( $source_file ),
				'hash' => hash_file( 'sha256', $source_file ),
				'width' => $dimensions['width'] ?? null,
				'height' => $dimensions['height'] ?? null,
			];
		}

		ksort( $files );
		return $files;
	}

	private function find_stale_files( $target_dir, $source_files, $extensions ) {
		if ( ! is_dir( $target_dir ) ) {
			return [];
		}

		$stale = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $target_dir, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || self::CACHE_FILENAME === $file->getFilename() ) {
				continue;
			}

			$extension = strtolower( $file->getExtension() );

			if ( ! in_array( $extension, $extensions, true ) ) {
				continue;
			}

			$target_file = $this->normalize_path( $file->getPathname() );
			$relative = $this->normalize_relative_path(
				substr( $target_file, strlen( $target_dir ) + 1 )
			);

			if ( ! isset( $source_files[ $relative ] ) ) {
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
		foreach ( array_keys( $cache ) as $relative ) {
			$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );

			if ( ! in_array( $extension, $extensions, true ) ) {
				continue;
			}

			if ( ! isset( $source_files[ $relative ] ) ) {
				unset( $cache[ $relative ] );
			}
		}
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

	private function get_resize_settings( $assoc_args ) {
		$no_resize = isset( $assoc_args['no-resize'] );

		if (
			$no_resize
			&& ( isset( $assoc_args['max-width'] ) || isset( $assoc_args['max-height'] ) )
		) {
			\WP_CLI::error( '--no-resize cannot be combined with --max-width or --max-height.' );
		}

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

	private function get_optimization_signature( $resize_settings ) {
		if ( ! $resize_settings['enabled'] ) {
			return sprintf( 'v%d|resize:none', self::PROCESSING_VERSION );
		}

		return sprintf(
			'v%d|resize:%dx%d',
			self::PROCESSING_VERSION,
			$resize_settings['max_width'],
			$resize_settings['max_height']
		);
	}

	private function cache_matches( $cache_entry, $source_hash, $optimization_signature ) {
		if ( ! is_array( $cache_entry ) || empty( $cache_entry['hash'] ) || empty( $cache_entry['signature'] ) ) {
			return false;
		}

		return hash_equals( $cache_entry['hash'], $source_hash )
			&& hash_equals( $cache_entry['signature'], $optimization_signature );
	}

	private function get_extensions( $extensions ) {
		if ( ! $extensions ) {
			return self::SUPPORTED_EXTENSIONS;
		}

		$extensions = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $extension ) => ltrim( trim( $extension ), '.' ),
						explode( ',', strtolower( $extensions ) )
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

	private function is_local_optimizer_ready() {
		$runtime_dir = $this->get_local_runtime_dir();
		$node_version = $this->get_node_version();

		return file_exists( $this->get_local_optimizer_script() )
			&& file_exists( $runtime_dir . '/node_modules/sharp/package.json' )
			&& $node_version
			&& version_compare( $node_version, self::MINIMUM_NODE_VERSION, '>=' );
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

		$json = json_encode( $cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $json || false === file_put_contents( $cache_file, $json ) ) {
			throw new \RuntimeException( 'Could not save optimization cache.' );
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