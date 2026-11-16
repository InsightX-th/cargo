<?php
/**
 * ISX_Job::running() — the single-flight guard behind "one export at a time"
 * (ajax_export_start() follows a running export, run_scheduled_backup() skips).
 * Real class, minimal WordPress stubs. Run: php tests/backup/job.php
 */

$root = sys_get_temp_dir() . '/isx_job_test_' . getmypid();
define( 'ABSPATH', $root . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ISX_STORAGE_PATH', $root . '/storage' );
define( 'ISX_DEFAULT_STORAGE_PATH', $root . '/storage' );
define( 'ISX_LEGACY_STORAGE_PATH', $root . '/legacy' );

function untrailingslashit( $s ) { return rtrim( (string) $s, '/\\' ); }
function wp_mkdir_p( $dir ) { return @mkdir( $dir, 0777, true ) || is_dir( $dir ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function __( $t, $d = '' ) { return $t; }
function get_current_user_id() { return 7; }
function wp_generate_password( $n = 12 ) { return substr( str_repeat( bin2hex( random_bytes( 32 ) ), 2 ), 0, $n ); }
class ISX_Logger { public static function __callStatic( $n, $a ) {} }
class ISX_Export { public static function abort_pending_upload( $job ) {} }

require dirname( __DIR__, 2 ) . '/modules/backup/includes/class-isx-job.php';

$pass = 0;
$fail = 0;
function t( $name, $cond ) {
	global $pass, $fail;
	$cond ? $pass++ : $fail++;
	echo ( $cond ? '  PASS ' : '  FAIL ' ) . $name . "\n";
}
/** Rewrite a job's state on disk the way run_step() would. */
function put_state( ISX_Job $job, array $changes ) {
	$file  = $job->dir() . '/state.json';
	$state = json_decode( file_get_contents( $file ), true );
	file_put_contents( $file, json_encode( array_merge( $state, $changes ) ) );
}

t( 'R1 nothing running on an empty storage dir', ISX_Job::running( 'export', 300 ) === null );

$a = ISX_Job::create( 'export' );
t( 'R2 a just-created export counts as running', $a && ISX_Job::running( 'export', 300 ) && ISX_Job::running( 'export', 300 )->id() === $a->id() );
t( 'R3 …but not as a running import', ISX_Job::running( 'import', 300 ) === null );

put_state( $a, array( 'step' => 'files', 'heartbeat' => time() - 10 ) );
t( 'R4 mid-export with a fresh heartbeat is running', ISX_Job::running( 'export', 300 ) !== null );

put_state( $a, array( 'heartbeat' => time() - 301 ) );
t( 'R5 a stale heartbeat (dead worker) no longer blocks', ISX_Job::running( 'export', 300 ) === null );

put_state( $a, array( 'heartbeat' => time(), 'step' => 'done' ) );
t( 'R6 a finished export is not running', ISX_Job::running( 'export', 300 ) === null );

$b = ISX_Job::create( 'export' );
touch( $b->dir() . '/.cancel' );
t( 'R7 a cancelled export is not running', ISX_Job::running( 'export', 300 ) === null );

$c = ISX_Job::create( 'export' );
t( 'R8 the secret needed to poll it comes back with the job', ISX_Job::running( 'export', 300 )->get( 'secret' ) === $c->get( 'secret' ) && strlen( (string) $c->get( 'secret' ) ) === 32 );

t( 'R9 a job records who staged it (import start is bound to its creator)', (int) $c->get( 'creator' ) === 7 );

exec( 'rm -rf ' . escapeshellarg( $root ) );
echo "\n========================================\nTOTAL: $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
