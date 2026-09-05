<?php
/**
 * Minimal POT extractor.
 *
 * Dev-only maintenance script. Scans every .php and .js file in the
 * plugin source tree (excluding node_modules and the compiled block
 * output) for WordPress translation calls — `__()`, `_e()`, `_x()`,
 * `_n()`, `_nx()`, and their `esc_*` variants — and writes
 * languages/timeslate.pot.
 *
 * Usage (run from the plugin root):
 *   php tools/make-pot.php
 *
 * Excluded from the shipped zip by tools/package.sh.
 *
 * @package Timeslate
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

// ---------- File discovery ------------------------------------------------

$exclude_dirs = array(
	$root . '/node_modules',
	$root . '/assets/blocks/build',
	$root . '/assets/vendor',
	$root . '/tools',
	$root . '/.git',
	$root . '/.claude',
	$root . '/.github',
	$root . '/languages',
);

$files = array();
$iter  = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		static function ( $current ) use ( $exclude_dirs ) {
			$path = $current->getPathname();
			foreach ( $exclude_dirs as $ex ) {
				if ( $path === $ex || str_starts_with( $path, $ex . '/' ) ) {
					return false;
				}
			}
			return true;
		}
	)
);

foreach ( $iter as $file ) {
	if ( ! $file->isFile() ) {
		continue;
	}
	$ext = strtolower( $file->getExtension() );
	if ( in_array( $ext, array( 'php', 'js', 'jsx' ), true ) ) {
		$files[] = $file->getPathname();
	}
}
sort( $files );

// ---------- Extraction ----------------------------------------------------

/**
 * One translation record — keyed by msgctxt|msgid (msgid_plural implicit via type).
 */
$entries = array();

function record( array &$entries, string $key, array $entry, string $file, int $line ): void {
	if ( ! isset( $entries[ $key ] ) ) {
		$entries[ $key ] = $entry;
		$entries[ $key ]['refs'] = array();
	}
	$entries[ $key ]['refs'][] = sprintf( '%s:%d', $file, $line );
}

$patterns = array(
	// Single-form: __, _e, esc_html__, esc_html_e, esc_attr__, esc_attr_e.
	array(
		'regex' => '/\b(?:esc_html__|esc_attr__|esc_html_e|esc_attr_e|__|_e)\s*\(\s*(["\'])((?:\\\\.|(?!\1).)*?)\1\s*,\s*(["\'])timeslate\3\s*\)/',
		'type'  => 'single',
	),
	// With context: _x, esc_html_x, esc_attr_x, _ex.
	array(
		'regex' => '/\b(?:esc_html_x|esc_attr_x|_ex|_x)\s*\(\s*(["\'])((?:\\\\.|(?!\1).)*?)\1\s*,\s*(["\'])((?:\\\\.|(?!\3).)*?)\3\s*,\s*(["\'])timeslate\5\s*\)/',
		'type'  => 'context',
	),
	// Plural: _n.
	array(
		'regex' => '/\b_n\s*\(\s*(["\'])((?:\\\\.|(?!\1).)*?)\1\s*,\s*(["\'])((?:\\\\.|(?!\3).)*?)\3\s*,[^,]+,\s*(["\'])timeslate\5\s*\)/',
		'type'  => 'plural',
	),
	// Plural with context: _nx.
	array(
		'regex' => '/\b_nx\s*\(\s*(["\'])((?:\\\\.|(?!\1).)*?)\1\s*,\s*(["\'])((?:\\\\.|(?!\3).)*?)\3\s*,[^,]+,\s*(["\'])((?:\\\\.|(?!\5).)*?)\5\s*,\s*(["\'])timeslate\7\s*\)/',
		'type'  => 'plural-context',
	),
);

foreach ( $files as $path ) {
	$rel = str_replace( $root . '/', '', $path );
	$src = (string) file_get_contents( $path );

	foreach ( $patterns as $p ) {
		if ( ! preg_match_all( $p['regex'], $src, $matches, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}

		$match_count = count( $matches[0] );
		for ( $i = 0; $i < $match_count; $i++ ) {
			$offset = $matches[0][ $i ][1];
			$line   = substr_count( substr( $src, 0, $offset ), "\n" ) + 1;

			switch ( $p['type'] ) {
				case 'single':
					$msgid = stripcslashes( $matches[2][ $i ][0] );
					record( $entries, $msgid, array( 'msgid' => $msgid ), $rel, $line );
					break;
				case 'context':
					$msgid   = stripcslashes( $matches[2][ $i ][0] );
					$msgctxt = stripcslashes( $matches[4][ $i ][0] );
					$key     = $msgctxt . "\x04" . $msgid;
					record( $entries, $key, array( 'msgctxt' => $msgctxt, 'msgid' => $msgid ), $rel, $line );
					break;
				case 'plural':
					$msgid        = stripcslashes( $matches[2][ $i ][0] );
					$msgid_plural = stripcslashes( $matches[4][ $i ][0] );
					$key          = $msgid . "\x05" . $msgid_plural;
					record( $entries, $key, array( 'msgid' => $msgid, 'msgid_plural' => $msgid_plural ), $rel, $line );
					break;
				case 'plural-context':
					$msgid        = stripcslashes( $matches[2][ $i ][0] );
					$msgid_plural = stripcslashes( $matches[4][ $i ][0] );
					$msgctxt      = stripcslashes( $matches[6][ $i ][0] );
					$key          = $msgctxt . "\x04" . $msgid . "\x05" . $msgid_plural;
					record( $entries, $key, array( 'msgctxt' => $msgctxt, 'msgid' => $msgid, 'msgid_plural' => $msgid_plural ), $rel, $line );
					break;
			}
		}
	}
}

ksort( $entries );

// ---------- POT output ----------------------------------------------------

$now    = gmdate( 'Y-m-d H:i+0000' );
$header = <<<POT
# Copyright (C) 2026 George Semaan
# This file is distributed under the GNU General Public License v2 or later.
msgid ""
msgstr ""
"Project-Id-Version: Timeslate 1.0.0\\n"
"Report-Msgid-Bugs-To: \\n"
"POT-Creation-Date: {$now}\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"
"X-Domain: timeslate\\n"

POT;

$pot = $header;
foreach ( $entries as $entry ) {
	$pot .= "\n";
	foreach ( $entry['refs'] as $ref ) {
		$pot .= "#: {$ref}\n";
	}
	if ( isset( $entry['msgctxt'] ) ) {
		$pot .= 'msgctxt "' . addcslashes( $entry['msgctxt'], "\"\\" ) . "\"\n";
	}
	$pot .= 'msgid "' . addcslashes( $entry['msgid'], "\"\\" ) . "\"\n";
	if ( isset( $entry['msgid_plural'] ) ) {
		$pot .= 'msgid_plural "' . addcslashes( $entry['msgid_plural'], "\"\\" ) . "\"\n";
		$pot .= "msgstr[0] \"\"\n";
		$pot .= "msgstr[1] \"\"\n";
	} else {
		$pot .= "msgstr \"\"\n";
	}
}

$out = $root . '/languages/timeslate.pot';
file_put_contents( $out, $pot );

printf(
	"Wrote %s  (%d entries, %d files scanned)\n",
	str_replace( $root . '/', '', $out ),
	count( $entries ),
	count( $files )
);
