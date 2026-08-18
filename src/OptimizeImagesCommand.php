<?php

namespace OptimizeImages;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
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

	/**
	 * Optimize images using the TinyPNG API.
	 *
	 * ## OPTIONS
	 *
	 * <directory>
	 * : Directory containing the original images, or "configure".
	 *
	 * [--force]
	 * : Re-optimize all images, ignoring cache.
	 *
	 * ## EXAMPLES
	 *
	 *     wp optimize-images configure
	 *
	 *     wp optimize-images ./images
	 *
	 *     wp optimize-images ./images --force
	 */
	public function __invoke( $args, $assoc_args ) {
		$command = $args[0] ?? null;

		if ( 'configure' === $command ) {
			$this->configure();

			return;
		}

		$this->optimize( $args, $assoc_args );
	}

	/**
	 * Configure the TinyPNG API key.
	 */
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
	 * Optimize images.
	 */
	private function optimize( $args, $assoc_args ) {
		$directory = $args[0] ?? null;
		$force     = isset( $assoc_args['force'] );

		if ( ! $directory ) {
			\WP_CLI::error(
				'Please provide an images directory.'
			);
		}

		$source_dir = realpath( $directory );

		if ( ! $source_dir || ! is_dir( $source_dir ) ) {
			\WP_CLI::error(
				'Directory does not exist.'
			);
		}

		$api_key = $this->get_api_key();

		if ( ! $api_key ) {
			\WP_CLI::error(
				'TinyPNG API key is not configured. Run: wp optimize-images configure'
			);
		}

		if ( ! function_exists( 'curl_init' ) ) {
			\WP_CLI::error(
				'PHP cURL extension is required.'
			);
		}

		$target_dir = dirname( $source_dir )
			. DIRECTORY_SEPARATOR
			. 'optimized-images';

		$cache_file = $target_dir
			. DIRECTORY_SEPARATOR
			. '.optimize-images-cache.json';

		if (
			! is_dir( $target_dir )
			&& ! mkdir( $target_dir, 0755, true )
		) {
			\WP_CLI::error(
				'Could not create output directory.'
			);
		}

		$cache = $this->load_cache( $cache_file );

		\WP_CLI::log( "Source: {$source_dir}" );
		\WP_CLI::log( "Output: {$target_dir}" );
		\WP_CLI::log( '' );

		$optimized     = 0;
		$skipped       = 0;
		$failed        = 0;
		$original_size = 0;
		$output_size   = 0;

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
					self::SUPPORTED_EXTENSIONS,
					true
				)
			) {
				continue;
			}

			$source_file = $file->getPathname();

			$relative = substr(
				$source_file,
				strlen( $source_dir ) + 1
			);

			$cache_key = str_replace(
				'\\',
				'/',
				$relative
			);

			$target_file = $target_dir
				. DIRECTORY_SEPARATOR
				. $relative;

			$source_hash = hash_file(
				'sha256',
				$source_file
			);

			if (
				! $force
				&& file_exists( $target_file )
				&& isset( $cache[ $cache_key ] )
				&& hash_equals(
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

			$before = filesize( $source_file );

			try {
				$this->optimize_image(
					$source_file,
					$target_file,
					$api_key
				);

				$after = filesize( $target_file );
				$saved = max( 0, $before - $after );

				$original_size += $before;
				$output_size   += $after;

				$optimized++;

				$cache[ $cache_key ] = $source_hash;

				$this->save_cache(
					$cache_file,
					$cache
				);

				\WP_CLI::log(
					sprintf(
						'✓ %s  %s → %s (-%s)',
						$relative,
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

		\WP_CLI::success(
			sprintf(
				'Optimized: %d | Skipped: %d | Failed: %d | Saved: %s',
				$optimized,
				$skipped,
				$failed,
				$this->format_bytes(
					max(
						0,
						$original_size - $output_size
					)
				)
			)
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
			return trim( $environment_key );
		}

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
	 * Get global package config file.
	 */
	private function get_config_file() {
		$home_dir = \WP_CLI\Utils\get_home_dir();

		return rtrim(
			$home_dir,
			'/\\'
		)
			. DIRECTORY_SEPARATOR
			. '.wp-cli'
			. DIRECTORY_SEPARATOR
			. self::CONFIG_FILENAME;
	}

	/**
	 * Optimize an image through TinyPNG.
	 */
	private function optimize_image(
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
				CURLOPT_USERPWD        => 'api:' . $api_key,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => file_get_contents( $source ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER         => true,
			]
		);

		$response = curl_exec( $ch );

		if ( false === $response ) {
			$error = curl_error( $ch );

			curl_close( $ch );

			throw new \Exception( $error );
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

		if ( 201 !== $status ) {
			$error = json_decode(
				$body,
				true
			);

			throw new \Exception(
				$error['message']
					?? 'TinyPNG compression failed.'
			);
		}

		if (
			! preg_match(
				'/^Location:\s*(.+)$/mi',
				$headers,
				$matches
			)
		) {
			throw new \Exception(
				'TinyPNG output URL was not returned.'
			);
		}

		$output_url = trim(
			$matches[1]
		);

		$ch = curl_init( $output_url );

		curl_setopt_array(
			$ch,
			[
				CURLOPT_USERPWD        => 'api:' . $api_key,
				CURLOPT_RETURNTRANSFER => true,
			]
		);

		$image = curl_exec( $ch );

		if ( false === $image ) {
			$error = curl_error( $ch );

			curl_close( $ch );

			throw new \Exception( $error );
		}

		$status = curl_getinfo(
			$ch,
			CURLINFO_HTTP_CODE
		);

		curl_close( $ch );

		if ( 200 !== $status ) {
			throw new \Exception(
				'Could not download optimized image.'
			);
		}

		if (
			false === file_put_contents(
				$target,
				$image
			)
		) {
			throw new \Exception(
				'Could not save optimized image.'
			);
		}
	}

	/**
	 * Load optimization cache.
	 */
	private function load_cache( $cache_file ) {
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
		array $cache
	) {
		$json = json_encode(
			$cache,
			JSON_PRETTY_PRINT
				| JSON_UNESCAPED_SLASHES
		);

		if ( false === $json ) {
			throw new \Exception(
				'Could not encode optimization cache.'
			);
		}

		if (
			false === file_put_contents(
				$cache_file,
				$json
			)
		) {
			throw new \Exception(
				'Could not save optimization cache.'
			);
		}
	}

	/**
	 * Format bytes for CLI output.
	 */
	private function format_bytes( $bytes ) {
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
	]
);