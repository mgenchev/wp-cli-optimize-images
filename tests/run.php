<?php

define( 'WP_CLI', true );

class WP_CLI {
    public static $commands = [];

    public static function add_command( $name, $callable, $args = [] ) {
        self::$commands[ $name ] = [ $callable, $args ];
    }

    public static function log( $message = '' ) {}
    public static function warning( $message ) {}
    public static function success( $message ) {}
    public static function error( $message ) {
        throw new RuntimeException( $message );
    }
}

require dirname( __DIR__ ) . '/src/OptimizeImagesCommand.php';

$tests = 0;
$failures = 0;

function check( $condition, $message ) {
    global $tests, $failures;
    $tests++;

    if ( ! $condition ) {
        $failures++;
        fwrite( STDERR, "FAIL: {$message}\n" );
    }
}

check( isset( WP_CLI::$commands['optimize-images'] ), 'Command is registered.' );

$class = new ReflectionClass( 'OptimizeImages\\Optimize_Images_Command' );
$instance = $class->newInstanceWithoutConstructor();
$compatibility = $class->getMethod( 'get_node_compatibility' );
$compatibility->setAccessible( true );

$result = $compatibility->invoke( $instance, '14.21.3' );
check( false === $result['compatible'], 'Node.js 14 is rejected.' );
check( false !== strpos( $result['message'], '20.9.0+' ), 'Node.js incompatibility message includes the minimum version.' );
check( false !== strpos( $result['message'], '14.21.3' ), 'Node.js incompatibility message includes the detected version.' );

$result = $compatibility->invoke( $instance, '20.8.9' );
check( false === $result['compatible'], 'Node.js below 20.9.0 is rejected.' );

$result = $compatibility->invoke( $instance, '20.9.0' );
check( true === $result['compatible'], 'Node.js 20.9.0 is accepted.' );

$result = $compatibility->invoke( $instance, '22.16.0' );
check( true === $result['compatible'], 'Node.js 22 is accepted.' );

$result = $compatibility->invoke( $instance, null );
check( false === $result['compatible'], 'Missing Node.js is rejected.' );
check( false !== strpos( $result['message'], 'not found in PATH' ), 'Missing Node.js has a clear error message.' );

$source = file_get_contents( dirname( __DIR__ ) . '/src/OptimizeImagesCommand.php' );
$optimize_start = strpos( $source, 'private function optimize( $directory, $assoc_args, $sync )' );
$optimize_end = strpos( $source, 'private function prepare_tinypng_jobs', $optimize_start );
$optimize_source = substr( $source, $optimize_start, $optimize_end - $optimize_start );

$global_environment_pos = strpos( $optimize_source, '$this->assert_processing_environment( false )' );
$cache_pos = strpos( $optimize_source, '$cache = $this->load_cache( $cache_file )' );
$environment_pos = strpos( $optimize_source, '$this->assert_processing_environment( $requires_local_optimizer )' );
$progress_pos = strpos( $optimize_source, '$this->start_progress( $processable, (bool) $api_key )' );
$ensure_pos = strpos( $optimize_source, '$this->ensure_local_optimizer();' );

check( false !== $global_environment_pos && false !== $cache_pos && $global_environment_pos < $cache_pos, 'Node.js compatibility is checked before cache filtering can return Everything is up to date.' );
check( false !== $environment_pos && false !== $progress_pos && $environment_pos < $progress_pos, 'Environment compatibility is checked before the progress display starts.' );
check( false !== $ensure_pos && false !== $progress_pos && $ensure_pos < $progress_pos, 'Required local optimizer setup happens before the progress display starts.' );
check( false !== strpos( $optimize_source, 'static fn( $job ) => ! empty( $job[\'will_resize\'] ) || ! empty( $job[\'will_convert\'] )' ), 'Resize/conversion jobs are recognized as requiring the local optimizer.' );

$ensure_start = strpos( $source, 'private function ensure_local_optimizer()' );
$ensure_end = strpos( $source, 'private function sync_local_optimizer_script()', $ensure_start );
$ensure_source = substr( $source, $ensure_start, $ensure_end - $ensure_start );
check( false !== strpos( $ensure_source, '$this->assert_processing_environment( true )' ), 'Local optimizer setup revalidates the required environment.' );
check( false !== strpos( $ensure_source, '$this->assert_local_optimizer_runtime_compatible()' ), 'Installed local optimizer runtime is verified before use.' );

$runtime_start = strpos( $source, 'private function check_local_optimizer_runtime()' );
$runtime_end = strpos( $source, 'private function run_environment_process', $runtime_start );
$runtime_source = substr( $source, $runtime_start, $runtime_end - $runtime_start );
check( false !== strpos( $runtime_source, 'require("sharp")' ), 'Runtime check loads Sharp.' );
check( false !== strpos( $runtime_source, 'require("svgo")' ), 'Runtime check loads SVGO.' );
check( false !== strpos( $runtime_source, "'-e'" ), 'Runtime compatibility check uses Node directly without shell-built inline commands.' );

check( false !== strpos( $source, 'curl_multi_init()' ), 'Existing concurrent TinyPNG implementation is preserved.' );
check( false !== strpos( $source, 'private const TINYPNG_CONCURRENCY = 3;' ), 'Existing TinyPNG concurrency remains unchanged.' );
check( false !== strpos( $source, "[ 'proc_open', function_exists( 'proc_open' ) ? 'enabled' : 'disabled' ]" ), 'Status reports proc_open availability.' );
check( false !== strpos( $source, "(incompatible; requires ' . self::MINIMUM_NODE_VERSION . '+)'" ), 'Status marks incompatible Node.js versions.' );

printf( "%d tests, %d failures\n", $tests, $failures );
exit( $failures > 0 ? 1 : 0 );
