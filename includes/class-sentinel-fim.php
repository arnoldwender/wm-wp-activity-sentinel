<?php
/**
 * WM Suite Activity Sentinel — File Integrity Monitor (FIM)
 *
 * Compares the WordPress tree against the official checksum catalogue published by
 * api.wordpress.org, and the WM Suite plugins against a baseline whose fingerprint is sealed
 * into the HMAC chain. Core files have an external source of truth, so a modification is provable;
 * the suite plugins have none, so the baseline stands in for one — and because the baseline's
 * fingerprint is written into a chained log entry, editing the baseline afterwards to hide a change
 * breaks that entry's own verification.
 *
 * Every method that decides anything is pure and takes the tree it walks as an argument, so the
 * standalone suite runs the whole monitor against a fixture directory with no WordPress, no network
 * and no MySQL. Only fetch_catalogue() touches the network.
 *
 * @package WM_Activity_Sentinel
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Sentinel_FIM {

	/** The official catalogue. Returns {"checksums":{"relative/path":"md5"}} for a known version. */
	public const CHECKSUM_ENDPOINT = 'https://api.wordpress.org/core/checksums/1.0/';

	/** Cron hook for the continuous scan. */
	public const CRON_HOOK = 'wm_sentinel_fim_scan';

	/** Where the WM Suite baseline and the last report live. */
	public const BASELINE_OPTION  = 'wm_sentinel_fim_baseline';
	public const LAST_SCAN_OPTION = 'wm_sentinel_fim_last_scan';

	/** Catalogue cache, so a daily scan and an admin page load do not both hit the API. */
	public const CATALOGUE_TRANSIENT = 'wm_sentinel_fim_catalogue_';

	/** Seconds the catalogue stays cached (12 h) and seconds the API has to answer. */
	private const CATALOGUE_TTL = 43200;
	private const HTTP_TIMEOUT  = 15;

	/**
	 * Directories under ABSPATH that WordPress owns completely: every file in them is either in the
	 * catalogue or has no business being there. wp-content is deliberately absent — the site owns it.
	 */
	public const CORE_DIRECTORIES = [ 'wp-admin', 'wp-includes' ];

	/**
	 * Extensions the web server can execute. An unexpected .txt in wp-includes is noise from a
	 * backup tool; an unexpected .php is how a web shell looks.
	 */
	public const EXECUTABLE_EXTENSIONS = [ 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar', 'inc' ];

	/**
	 * Files whose absence is a hardening practice, not an incident. Reporting them as missing
	 * trains the operator to ignore the report, which is how a monitor stops working.
	 *
	 * A localized package renames the readme — the de_DE 7.1 catalogue carries both `readme.html`
	 * and `liesmich.html` — so the rule is "a catalogue entry at the root ending in .html" rather
	 * than a list of names that would go stale per locale (measured 2026-09-16).
	 */
	public const HARDENING_REMOVALS = [ 'readme.html', 'license.txt', 'wp-config-sample.php' ];

	/** File types of the WM Suite plugins the baseline covers: what runs or renders. */
	public const SUITE_EXTENSIONS = [ 'php', 'js', 'css' ];

	/**
	 * Directories inside a plugin that are development artifacts and never part of what is
	 * deployed. `vendor/` is deliberately absent: Composer dependencies are executed through an
	 * autoloader and a backdoor placed there is exactly what the baseline is for.
	 *
	 * Measured 2026-09-16 on a local WordPress install: without this list the baseline held 47,085 files and
	 * 7.4 MB in one wp_options row, of which 46,443 were under node_modules, vendor or .git.
	 */
	public const SUITE_EXCLUDED_DIRS = [ 'node_modules', '.git', '.github', '.svn', '.idea', '.vscode' ];

	/**
	 * Above this many files the baseline is refused rather than stored.
	 *
	 * A manifest that quietly grows into megabytes is read and rewritten by every scan, and nobody
	 * notices until the daily cron times out. Refusing loudly is the honest failure.
	 */
	public const MAX_BASELINE_FILES = 20000;

	/** Prefix of the WM Suite plugin directories. */
	public const SUITE_PREFIX = 'wm-';

	/** Most paths written into one chained log entry, so a compromised tree cannot write a megabyte row. */
	public const MAX_FINDINGS = 200;

	/** Event types this monitor writes into the chain. */
	public const EVENT_CLEAN   = 'fim_scan_completed';
	public const EVENT_FINDING = 'fim_integrity_finding';
	public const EVENT_SEALED  = 'fim_baseline_sealed';

	/**
	 * Object type of every FIM entry.
	 *
	 * Not 'file': that type is filtered by wm_sentinel_log_plugins, so switching off plugin logging
	 * would silence a modified wp-includes. No switch covers 'integrity', so is_module_permitted()
	 * lets it through and only the severity level applies.
	 */
	public const OBJECT_TYPE = 'integrity';

	// -----------------------------------------------------------------------------------------
	// Catalogue
	// -----------------------------------------------------------------------------------------

	/**
	 * The official catalogue for one version and locale.
	 *
	 * @return array{checksums: array<string, string>, error: ?string, cached: bool}
	 */
	public static function fetch_catalogue( string $version, string $locale, bool $use_cache = true ): array {
		$cache_key = self::CATALOGUE_TRANSIENT . md5( $version . '|' . $locale );

		if ( $use_cache ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) && ! empty( $cached['checksums'] ) && is_array( $cached['checksums'] ) ) {
				return [ 'checksums' => $cached['checksums'], 'error' => null, 'cached' => true ];
			}
		}

		$url = self::CHECKSUM_ENDPOINT . '?' . http_build_query( [ 'version' => $version, 'locale' => $locale ] );

		$response = wp_remote_get( $url, [
			'timeout'    => self::HTTP_TIMEOUT,
			'user-agent' => 'WM Activity Sentinel/' . ( defined( 'WM_SENTINEL_VERSION' ) ? WM_SENTINEL_VERSION : '0.0.0' ),
		] );

		if ( is_wp_error( $response ) ) {
			return [
				'checksums' => [],
				'error'     => __( 'api.wordpress.org war nicht erreichbar; die Dateien konnten nicht gegen den offiziellen Katalog geprüft werden.', 'wm-activity-sentinel' ),
				'cached'    => false,
			];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return [
				'checksums' => [],
				'error'     => sprintf(
					/* translators: %d: HTTP status code */
					__( 'api.wordpress.org antwortete mit HTTP %d; es wurde kein Katalog geladen.', 'wm-activity-sentinel' ),
					$code
				),
				'cached'    => false,
			];
		}

		$parsed = self::parse_catalogue( (string) wp_remote_retrieve_body( $response ), $version );

		if ( null === $parsed['error'] ) {
			set_transient( $cache_key, [ 'checksums' => $parsed['checksums'] ], self::CATALOGUE_TTL );
		}

		return $parsed + [ 'cached' => false ];
	}

	/**
	 * Turn the API body into a path => md5 map, or into an error.
	 *
	 * @return array{checksums: array<string, string>, error: ?string}
	 */
	public static function parse_catalogue( string $body, string $version ): array {
		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || ! array_key_exists( 'checksums', $decoded ) ) {
			return [
				'checksums' => [],
				'error'     => __( 'Die Antwort von api.wordpress.org enthielt keinen Prüfsummen-Katalog.', 'wm-activity-sentinel' ),
			];
		}

		$checksums = $decoded['checksums'];

		// A version the API does not know is answered with HTTP 200 and "checksums": false (measured
		// 2026-09-16 against ?version=99.9.9). Taken for an empty catalogue it would run a scan over
		// zero files and report zero findings — a clean bill of health for a tree nobody checked.
		if ( ! is_array( $checksums ) ) {
			return [
				'checksums' => [],
				'error'     => sprintf(
					/* translators: %s: WordPress version */
					__( 'Für WordPress %s liegt bei api.wordpress.org kein Prüfsummen-Katalog vor (etwa bei einer Entwicklungs- oder Nightly-Version). Die Kerndateien wurden nicht geprüft.', 'wm-activity-sentinel' ),
					$version
				),
			];
		}

		// Some locales answer with the map nested under the version.
		if ( isset( $checksums[ $version ] ) && is_array( $checksums[ $version ] ) ) {
			$checksums = $checksums[ $version ];
		}

		$clean = [];
		foreach ( $checksums as $path => $hash ) {
			if ( ! is_string( $path ) || ! is_string( $hash ) || 1 !== preg_match( '/^[a-f0-9]{32}$/i', $hash ) ) {
				continue;
			}
			$relative = self::normalise_relative_path( $path );
			if ( '' === $relative ) {
				continue;
			}
			$clean[ $relative ] = strtolower( $hash );
		}

		if ( [] === $clean ) {
			return [
				'checksums' => [],
				'error'     => __( 'Der geladene Katalog enthielt keine verwertbaren Prüfsummen.', 'wm-activity-sentinel' ),
			];
		}

		return [ 'checksums' => $clean, 'error' => null ];
	}

	/**
	 * A catalogue entry is a path relative to ABSPATH and nothing else.
	 *
	 * The catalogue arrives over the network, so a poisoned or hijacked response could name
	 * `../../../etc/passwd` and have the scanner read outside the installation. Anything that leaves
	 * the tree, is absolute or carries a backslash is dropped rather than repaired.
	 */
	public static function normalise_relative_path( string $path ): string {
		$path = str_replace( '\\', '/', trim( $path ) );

		if ( '' === $path || str_starts_with( $path, '/' ) || str_contains( $path, "\0" ) ) {
			return '';
		}
		if ( 1 === preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
			return '';
		}
		if ( 1 === preg_match( '#^[a-zA-Z]:#', $path ) ) {
			return '';
		}

		return $path;
	}

	// -----------------------------------------------------------------------------------------
	// Core scan
	// -----------------------------------------------------------------------------------------

	/**
	 * Compare the installed core files against the catalogue.
	 *
	 * wp-content is skipped: the catalogue lists the bundled themes and Akismet, which a site may
	 * legitimately update, replace or delete, so flagging them would produce a permanent red screen
	 * on a healthy install. `wp core verify-checksums` skips them for the same reason.
	 *
	 * @param array<string, string> $checksums Catalogue as path => md5.
	 * @param string                $root      Installation root, with a trailing slash.
	 * @return array{checked:int, modified:list<string>, missing:list<string>, hardening_removals:list<string>}
	 */
	public static function scan_core( array $checksums, string $root ): array {
		$checked   = 0;
		$modified  = [];
		$missing   = [];
		$hardening = [];

		foreach ( $checksums as $relative => $expected ) {
			if ( str_starts_with( $relative, 'wp-content/' ) ) {
				continue;
			}

			$path = $root . $relative;

			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				if ( self::is_hardening_removal( $relative ) ) {
					$hardening[] = $relative;
				} else {
					$missing[] = $relative;
				}
				continue;
			}

			++$checked;

			$actual = md5_file( $path );
			if ( ! is_string( $actual ) || ! hash_equals( $expected, strtolower( $actual ) ) ) {
				$modified[] = $relative;
			}
		}

		sort( $modified );
		sort( $missing );
		sort( $hardening );

		return [
			'checked'            => $checked,
			'modified'           => $modified,
			'missing'            => $missing,
			'hardening_removals' => $hardening,
		];
	}

	/**
	 * Executable files sitting in wp-admin or wp-includes that the catalogue does not list.
	 *
	 * This is the half of the scan that finds a web shell: a backdoor does not modify a core file,
	 * it adds one, and a comparison that only walks the catalogue never looks at it.
	 *
	 * @param array<string, string> $checksums Catalogue as path => md5.
	 * @param string                $root      Installation root, with a trailing slash.
	 * @return list<string>
	 */
	public static function scan_unexpected( array $checksums, string $root ): array {
		$unexpected = [];

		foreach ( self::CORE_DIRECTORIES as $directory ) {
			$base = $root . $directory;
			if ( ! is_dir( $base ) ) {
				continue;
			}

			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
					continue;
				}
				if ( ! in_array( strtolower( $file->getExtension() ), self::EXECUTABLE_EXTENSIONS, true ) ) {
					continue;
				}

				$relative = self::relative_to( $root, str_replace( '\\', '/', (string) $file->getPathname() ) );
				if ( '' === $relative || isset( $checksums[ $relative ] ) ) {
					continue;
				}

				$unexpected[] = $relative;
			}
		}

		sort( $unexpected );

		return $unexpected;
	}

	/**
	 * Whether a missing catalogue entry is routine hardening rather than a finding.
	 *
	 * Beyond the named files, any readme at the root: a localized package ships a translated one
	 * (`liesmich.html` on de_DE) next to `readme.html`, and hardening guides tell operators to
	 * delete whichever they have. Only the root is covered — an .html missing from wp-admin or
	 * wp-includes is a finding.
	 */
	public static function is_hardening_removal( string $relative ): bool {
		if ( in_array( $relative, self::HARDENING_REMOVALS, true ) ) {
			return true;
		}

		return ! str_contains( $relative, '/' ) && str_ends_with( strtolower( $relative ), '.html' );
	}

	/**
	 * Strip the root from an absolute path, or return '' when the path is not inside it.
	 */
	public static function relative_to( string $root, string $absolute ): string {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/';
		return str_starts_with( $absolute, $root ) ? substr( $absolute, strlen( $root ) ) : '';
	}

	// -----------------------------------------------------------------------------------------
	// WM Suite suite baseline
	// -----------------------------------------------------------------------------------------

	/**
	 * SHA-256 of every executable or rendered file in the installed WM Suite plugins.
	 *
	 * @param string $plugins_dir Plugin directory, with a trailing slash.
	 * @return array<string, string> Relative path (including the plugin folder) => sha256.
	 */
	public static function capture_suite_baseline( string $plugins_dir ): array {
		$manifest = [];

		foreach ( self::suite_directories( $plugins_dir ) as $plugin ) {
			$base = $plugins_dir . $plugin;

			// The filter prunes rather than skips: without it the walk descends into every
			// node_modules tree before discarding it, which is most of the run time.
			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
					static function ( SplFileInfo $current ): bool {
						return ! $current->isDir() || ! in_array( $current->getFilename(), self::SUITE_EXCLUDED_DIRS, true );
					}
				),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
					continue;
				}
				if ( ! in_array( strtolower( $file->getExtension() ), self::SUITE_EXTENSIONS, true ) ) {
					continue;
				}

				$relative = self::relative_to( $plugins_dir, str_replace( '\\', '/', (string) $file->getPathname() ) );
				if ( '' === $relative ) {
					continue;
				}

				$hash = hash_file( 'sha256', (string) $file->getPathname() );
				if ( is_string( $hash ) ) {
					$manifest[ $relative ] = $hash;
				}
			}
		}

		ksort( $manifest );

		return $manifest;
	}

	/**
	 * The WM Suite plugin folders present in a plugin directory.
	 *
	 * @return list<string>
	 */
	public static function suite_directories( string $plugins_dir ): array {
		if ( ! is_dir( $plugins_dir ) ) {
			return [];
		}

		$found = [];
		foreach ( (array) scandir( $plugins_dir ) as $entry ) {
			$entry = (string) $entry;
			if ( '.' === $entry || '..' === $entry || ! str_starts_with( $entry, self::SUITE_PREFIX ) ) {
				continue;
			}
			if ( is_dir( $plugins_dir . $entry ) ) {
				$found[] = $entry;
			}
		}

		sort( $found );

		return $found;
	}

	/**
	 * Compare a stored baseline against the tree as it stands now.
	 *
	 * @param array<string, string> $baseline    Stored manifest.
	 * @param string                $plugins_dir Plugin directory, with a trailing slash.
	 * @return array{checked:int, modified:list<string>, missing:list<string>, added:list<string>}
	 */
	public static function compare_suite_baseline( array $baseline, string $plugins_dir ): array {
		$current = self::capture_suite_baseline( $plugins_dir );

		$modified = [];
		$missing  = [];

		foreach ( $baseline as $relative => $expected ) {
			if ( ! isset( $current[ $relative ] ) ) {
				$missing[] = (string) $relative;
				continue;
			}
			if ( ! hash_equals( (string) $expected, $current[ $relative ] ) ) {
				$modified[] = (string) $relative;
			}
		}

		$added = array_values( array_diff( array_keys( $current ), array_keys( $baseline ) ) );

		sort( $modified );
		sort( $missing );
		sort( $added );

		return [
			'checked'  => count( $current ),
			'modified' => $modified,
			'missing'  => $missing,
			'added'    => $added,
		];
	}

	/**
	 * Deterministic fingerprint of a manifest, so the chain can carry one value instead of the map.
	 *
	 * @param array<string, string> $manifest Manifest.
	 */
	public static function manifest_fingerprint( array $manifest ): string {
		ksort( $manifest );
		return hash( 'sha256', (string) wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Capture the baseline, store it and seal its fingerprint into the chain.
	 *
	 * The stored baseline lives in wp_options, where whoever modified a plugin file could rewrite it
	 * to match. The chained entry is what stops that being silent: it carries the fingerprint the
	 * baseline had when it was taken, and rewriting that entry invalidates its own hash.
	 *
	 * @param int $max Most files the baseline may hold; above it nothing is stored.
	 * @return array{files:int, fingerprint:string, log_id:int|false, error:?string}
	 */
	public static function seal_suite_baseline( string $plugins_dir, int $max = self::MAX_BASELINE_FILES ): array {
		$manifest = self::capture_suite_baseline( $plugins_dir );

		if ( count( $manifest ) > $max ) {
			return [
				'files'       => count( $manifest ),
				'fingerprint' => '',
				'log_id'      => false,
				'error'       => sprintf(
					/* translators: 1: number of files found, 2: configured maximum */
					__( 'Die Basislinie wurde nicht gespeichert: %1$d Dateien überschreiten das Maximum von %2$d. Eine Basislinie dieser Größe wird bei jeder Prüfung gelesen und neu geschrieben. Prüfen Sie, ob in einem Plugin-Verzeichnis Entwicklungsdateien liegen, die dort nicht hingehören.', 'wm-activity-sentinel' ),
					count( $manifest ),
					$max
				),
			];
		}

		$fingerprint = self::manifest_fingerprint( $manifest );

		update_option( self::BASELINE_OPTION, $manifest, false );

		$log_id = WM_Sentinel_Logger::log(
			null,
			self::EVENT_SEALED,
			self::OBJECT_TYPE,
			null,
			'notice',
			[
				'files'       => count( $manifest ),
				'fingerprint' => $fingerprint,
				'plugins'     => self::suite_directories( $plugins_dir ),
			]
		);

		return [ 'files' => count( $manifest ), 'fingerprint' => $fingerprint, 'log_id' => $log_id, 'error' => null ];
	}

	/**
	 * Whether the stored baseline still matches the fingerprint the chain recorded for it.
	 *
	 * @param array<string, string> $baseline Stored baseline.
	 * @return string 'sealed', 'unsealed' (never captured) or 'broken' (edited since).
	 */
	public static function baseline_seal_status( array $baseline ): string {
		$sealed = self::last_sealed_fingerprint();

		if ( null === $sealed ) {
			return 'unsealed';
		}

		return hash_equals( $sealed, self::manifest_fingerprint( $baseline ) ) ? 'sealed' : 'broken';
	}

	/**
	 * The fingerprint carried by the newest sealing entry in the chain, or null when there is none.
	 */
	public static function last_sealed_fingerprint(): ?string {
		$entries = WM_Sentinel_Database::get_logs( [
			'event_type' => self::EVENT_SEALED,
			'limit'      => 1,
			'orderby'    => 'log_id',
			'order'      => 'DESC',
		] );

		$row = $entries[0] ?? null;
		if ( ! $row ) {
			return null;
		}

		$context = json_decode( (string) ( $row->context ?? '' ), true );
		$value   = is_array( $context ) ? (string) ( $context['fingerprint'] ?? '' ) : '';

		return '' === $value ? null : $value;
	}

	// -----------------------------------------------------------------------------------------
	// Orchestration
	// -----------------------------------------------------------------------------------------

	/**
	 * Run the whole monitor and record the result in the chain.
	 *
	 * @param string  $trigger     'cron', 'manual' or 'cli'.
	 * @param ?string $root        Installation root; ABSPATH when null.
	 * @param ?string $plugins_dir Plugin directory; WP_PLUGIN_DIR when null.
	 * @return array<string, mixed> The report.
	 */
	public static function run( string $trigger = 'cron', ?string $root = null, ?string $plugins_dir = null ): array {
		$root        = self::with_slash( $root ?? (string) ABSPATH );
		$plugins_dir = self::with_slash( $plugins_dir ?? ( defined( 'WP_PLUGIN_DIR' ) ? (string) WP_PLUGIN_DIR : $root . 'wp-content/plugins' ) );

		$version = self::wordpress_version();
		$locale  = self::installed_package_locale();

		$catalogue = self::fetch_catalogue( $version, $locale );

		$core = [
			'checked'            => 0,
			'modified'           => [],
			'missing'            => [],
			'hardening_removals' => [],
			'unexpected'         => [],
			'error'              => $catalogue['error'],
		];

		if ( null === $catalogue['error'] ) {
			$scanned = self::scan_core( $catalogue['checksums'], $root );

			$core = [
				'checked'            => $scanned['checked'],
				'modified'           => $scanned['modified'],
				'missing'            => $scanned['missing'],
				'hardening_removals' => $scanned['hardening_removals'],
				'unexpected'         => self::scan_unexpected( $catalogue['checksums'], $root ),
				'error'              => null,
			];
		}

		$suite = [ 'checked' => 0, 'modified' => [], 'missing' => [], 'added' => [], 'seal' => 'unsealed' ];

		if ( (bool) get_option( 'wm_sentinel_fim_scan_suite', 1 ) ) {
			$baseline = get_option( self::BASELINE_OPTION, [] );
			$baseline = is_array( $baseline ) ? $baseline : [];

			if ( [] === $baseline ) {
				$suite['seal'] = 'unsealed';
			} else {
				$suite         = self::compare_suite_baseline( $baseline, $plugins_dir );
				$suite['seal'] = self::baseline_seal_status( $baseline );
			}
		}

		$report = [
			'ran_at'  => gmdate( 'Y-m-d H:i:s' ),
			'trigger' => $trigger,
			'version' => $version,
			'locale'  => $locale,
			'core'    => $core,
			'suite'   => $suite,
		];

		$report['severity'] = self::severity_for( $report );
		$report['findings'] = self::count_findings( $report );

		update_option( self::LAST_SCAN_OPTION, $report, false );

		$report['log_id'] = WM_Sentinel_Logger::log(
			null,
			'info' === $report['severity'] ? self::EVENT_CLEAN : self::EVENT_FINDING,
			self::OBJECT_TYPE,
			null,
			$report['severity'],
			self::log_context( $report )
		);

		return $report;
	}

	/**
	 * What the cron calls. Kept separate so the schedule can be switched off without unhooking.
	 */
	public static function run_scheduled(): void {
		if ( ! (bool) get_option( 'wm_sentinel_fim_enabled', 1 ) ) {
			return;
		}

		self::run( 'cron' );
	}

	/**
	 * The severity of a report.
	 *
	 * A modified core file, an unexpected executable inside wp-admin or wp-includes, and a baseline
	 * that no longer matches its seal are the three states that mean somebody wrote to the tree.
	 * A catalogue that could not be loaded is a warning and never an all-clear: not having looked is
	 * not the same as having found nothing.
	 *
	 * @param array<string, mixed> $report Report without severity.
	 */
	public static function severity_for( array $report ): string {
		$core  = (array) $report['core'];
		$suite = (array) $report['suite'];

		if ( [] !== (array) $core['modified'] || [] !== (array) $core['unexpected'] || 'broken' === ( $suite['seal'] ?? '' ) ) {
			return 'critical';
		}

		if ( [] !== (array) $core['missing'] || [] !== (array) $suite['modified'] || [] !== (array) $suite['missing'] || null !== $core['error'] ) {
			return 'warning';
		}

		if ( [] !== (array) $suite['added'] ) {
			return 'notice';
		}

		return 'info';
	}

	/**
	 * How many paths the report names.
	 *
	 * @param array<string, mixed> $report Report.
	 */
	public static function count_findings( array $report ): int {
		$core  = (array) $report['core'];
		$suite = (array) $report['suite'];

		return count( (array) $core['modified'] )
			+ count( (array) $core['missing'] )
			+ count( (array) $core['unexpected'] )
			+ count( (array) $suite['modified'] )
			+ count( (array) $suite['missing'] )
			+ count( (array) $suite['added'] );
	}

	/**
	 * The report as a log context: counts always, paths capped.
	 *
	 * @param array<string, mixed> $report Report.
	 * @return array<string, mixed>
	 */
	public static function log_context( array $report ): array {
		$core  = (array) $report['core'];
		$suite = (array) $report['suite'];

		$context = [
			'trigger'          => (string) $report['trigger'],
			'wp_version'       => (string) $report['version'],
			'locale'           => (string) $report['locale'],
			'core_checked'     => (int) $core['checked'],
			'core_modified'    => self::cap( (array) $core['modified'] ),
			'core_missing'     => self::cap( (array) $core['missing'] ),
			'core_unexpected'  => self::cap( (array) $core['unexpected'] ),
			'suite_checked'    => (int) $suite['checked'],
			'suite_modified'   => self::cap( (array) $suite['modified'] ),
			'suite_missing'    => self::cap( (array) $suite['missing'] ),
			'suite_added'      => self::cap( (array) $suite['added'] ),
			'baseline_seal'    => (string) ( $suite['seal'] ?? 'unsealed' ),
			'findings'         => (int) $report['findings'],
		];

		if ( null !== $core['error'] ) {
			$context['catalogue_error'] = (string) $core['error'];
		}

		return $context;
	}

	/**
	 * At most MAX_FINDINGS paths, with a marker when more were found.
	 *
	 * @param list<string> $paths Paths.
	 * @return list<string>
	 */
	private static function cap( array $paths ): array {
		if ( count( $paths ) <= self::MAX_FINDINGS ) {
			return array_values( $paths );
		}

		$kept   = array_slice( array_values( $paths ), 0, self::MAX_FINDINGS );
		$kept[] = sprintf( '… +%d', count( $paths ) - self::MAX_FINDINGS );

		return $kept;
	}

	/**
	 * The last stored report, or null when nothing has run yet.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last_report(): ?array {
		$report = get_option( self::LAST_SCAN_OPTION, null );

		return is_array( $report ) && isset( $report['core'] ) ? $report : null;
	}

	/**
	 * Schedule or unschedule the daily scan to match the setting.
	 */
	public static function sync_schedule(): void {
		$enabled   = (bool) get_option( 'wm_sentinel_fim_enabled', 1 );
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( $enabled && ! $scheduled ) {
			wp_schedule_event( time() + 300, 'daily', self::CRON_HOOK );
			return;
		}

		if ( ! $enabled && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	private static function with_slash( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' ) . '/';
	}

	private static function wordpress_version(): string {
		return (string) get_bloginfo( 'version' );
	}

	/**
	 * The locale of the INSTALLED PACKAGE, which is what the catalogue is keyed by.
	 *
	 * Not `get_locale()`. That is the display language, and the two part company constantly: a site
	 * whose admin is German but which runs the en_US package has no `$wp_local_package`, and
	 * comparing it against the de_DE catalogue reports `wp-includes/version.php` (the localized
	 * build defines `$wp_local_package` in it), `wp-config-sample.php` and the localized
	 * `liesmich.html` as findings on a tree where nothing is wrong. Measured 2026-09-16 on
	 * a local WordPress install: three findings, all three false, and the three md5s matched the en_US catalogue
	 * exactly. A monitor with three false positives on a clean install gets switched off.
	 *
	 * WordPress writes `$wp_local_package` into `wp-includes/version.php` for localized builds
	 * only; its absence means en_US.
	 */
	public static function installed_package_locale(): string {
		global $wp_local_package;

		if ( isset( $wp_local_package ) && is_string( $wp_local_package ) && '' !== $wp_local_package ) {
			return $wp_local_package;
		}

		if ( defined( 'WP_LOCAL_PACKAGE' ) && '' !== (string) WP_LOCAL_PACKAGE ) {
			return (string) WP_LOCAL_PACKAGE;
		}

		return 'en_US';
	}
}
