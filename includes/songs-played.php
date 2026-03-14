<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * Ultimate Member custom field support for "Songs I Play".
 *
 * Features:
 * - Select2-powered song picker
 * - JSON storage in user meta
 * - Profile table renderer
 * - Song update webhook
 * - Seed tools for starter song sets
 */

// Capture the previous songs_played value before WordPress updates it.
add_action('update_user_meta', 'fnf_um_songs_played_capture_previous_meta', 10, 4);

function fnf_um_songs_played_capture_previous_meta( $meta_id, $user_id, $meta_key, $_meta_value ) {
    if ( $meta_key !== 'songs_played' ) {
        return;
    }

    global $wpdb;

    $old = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->usermeta} WHERE umeta_id = %d LIMIT 1",
            (int) $meta_id
        )
    );
    $old = is_string($old) ? $old : '';

    $prev_meta = fnf_um_songs_played_get_prev_meta_store();

    if ( ! isset($prev_meta[$user_id]) ) {
        $prev_meta[$user_id] = array();
    }

    if ( ! isset($prev_meta[$user_id]['songs_played']) ) {
        $prev_meta[$user_id]['songs_played'] = $old;
    }

    fnf_um_songs_played_set_prev_meta_store($prev_meta);
}

function fnf_um_songs_played_get_prev_meta_store() {
    if ( ! isset($GLOBALS['fnf_um_songs_played_prev_meta']) || ! is_array($GLOBALS['fnf_um_songs_played_prev_meta']) ) {
        $GLOBALS['fnf_um_songs_played_prev_meta'] = array();
    }

    return $GLOBALS['fnf_um_songs_played_prev_meta'];
}

function fnf_um_songs_played_set_prev_meta_store( $store ) {
    $GLOBALS['fnf_um_songs_played_prev_meta'] = is_array($store) ? $store : array();
}


if (!function_exists('fnf_songs_json_sanitize')) {
    function fnf_songs_json_sanitize(string $raw): string {
		$orig_raw = $raw;
	
		// 0) Undo HTML entities
		$raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	
		// 1) Pre-repair at the TEXT level so JSON can decode:
		//    - decode stray uXXXX
		//    - escape inner " inside the values of title/artist
		$raw = fnf__decode_loose_unicode_escapes($raw);
		foreach (['title','artist'] as $f) {
			$raw = fnf__escape_quotes_in_field($raw, $f);
		}
	
		// 2) Decode JSON now (should succeed even when titles contain "like this")
		$arr = json_decode($raw, true);
		if (is_array($arr)) {
			$changed_fields = 0;
	
			// 3) Field-level cleanup (accents, mojibake), then optional Unicode normalization
			foreach ($arr as &$row) {
				foreach (['title','artist','genre'] as $f) {
					if (!isset($row[$f]) || !is_string($row[$f])) continue;
					$after = fnf__fix_text_mojibake($row[$f]);
					if ($after !== $row[$f]) { $row[$f] = $after; $changed_fields++; }
				}
			}
			unset($row);
	
			if (class_exists('Normalizer')) {
				foreach ($arr as &$row) {
					foreach (['title','artist','genre'] as $f) {
						if (isset($row[$f]) && is_string($row[$f])) {
							$row[$f] = Normalizer::normalize($row[$f], Normalizer::FORM_C);
						}
					}
				}
				unset($row);
			}
	
			if (defined('FNF_SONGS_DEBUG') && FNF_SONGS_DEBUG) {
				//error_log('[songs_sanitize] ok; fixed_fields='.$changed_fields.' in_len='.strlen($orig_raw).' out_len='.strlen(wp_json_encode($arr)));
			}
	
			// Return canonical JSON
			return wp_json_encode($arr);
		}
	
		// 3b) Still invalid? Log and return original so callers can decide how to handle.
		if (defined('FNF_SONGS_DEBUG') && FNF_SONGS_DEBUG) {
			//error_log('[songs_sanitize] still invalid JSON after repair: '.json_last_error_msg());
		}
		return $orig_raw;
	}
		
	/**
	 * Fix common text mangles in values (e.g., 'Sisqu00f3' -> 'Sisqó', 'BeyoncÃ©' -> 'Beyoncé').
	 */
	function fnf__fix_text_mojibake(string $s): string {
		$orig = $s;
	
		// 1) Decode U+XXXX (with or without '+', any length 4..6)
		$s = preg_replace_callback('/U\+?([0-9A-Fa-f]{4,6})/', function($m){
			$hex = strtoupper($m[1]);
			return html_entity_decode('&#x'.$hex.';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}, $s);
	
		// 2) Decode \uXXXX and bare uXXXX (case-insensitive)
		$s = preg_replace_callback('/\\\\?u([0-9A-Fa-f]{4})/i', function($m){
			$hex = $m[1];
			$bin = pack('H*', $hex);
			if (function_exists('mb_convert_encoding')) {
				$utf = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
				if ($utf !== false && $utf !== '') return $utf;
			}
			if (function_exists('iconv')) {
				$utf = @iconv('UTF-16BE','UTF-8//IGNORE',$bin);
				if ($utf !== false && $utf !== '') return $utf;
			}
			return $m[0];
		}, $s);
	
		// 3) Repair common UTF-8-over-Windows-1252 mojibake (e.g., "BeyoncÃ©")
		// Heuristic: presence of 'Ã' followed by a high-bit char is a strong signal.
		// PRE-CLEAN: if string isn't valid UTF-8, drop invalid sequences (prevents regex warnings)
		if (function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) {
			if (function_exists('iconv')) {
				$tmp = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
				if ($tmp !== false) { $s = $tmp; }
			}
		}
		
		// 3) Repair classic UTF-8-shown-as-Latin1/CP1252 mojibake ("BeyoncÃ©", "SisqÃ³", "YoÃ¼")
		if (strpos($s, 'Ã') !== false) {
			// Single pass: Latin-1 round-trip
			$once = @utf8_encode(@utf8_decode($s));
			if ($once !== false && $once !== '') { $s = $once; }
		
			// If still shows artifacts like "ÃƒÂ©", try a second pass (handles double-encoded cases)
			if (strpos($s, 'Ã') !== false) {
				$twice = @utf8_encode(@utf8_decode($s));
				if ($twice !== false && $twice !== '') { $s = $twice; }
			}
		}

	
		if (defined('FNF_SONGS_DEBUG') && FNF_SONGS_DEBUG && $s !== $orig) {
			//error_log('[songs_fix] "' . $orig . '" -> "' . $s . '"');
		}
		return $s;
	}

	function fnf__decode_loose_unicode_escapes(string $s): string {
		return preg_replace_callback(
			'/\\\\?u([0-9a-fA-F]{4})/',
			function ($m) {
				$hex = $m[1];
	
				// Convert 4-hex-unit (UTF-16 code unit) to UTF-8
				// Prefer mbstring; fall back to iconv if needed
				$bin = pack('H*', $hex);
	
				if (function_exists('mb_convert_encoding')) {
					$utf = @mb_convert_encoding($bin, 'UTF-8', 'UTF-16BE');
					if ($utf !== false && $utf !== '') {
						return $utf;
					}
				}
				if (function_exists('iconv')) {
					$utf = @iconv('UTF-16BE', 'UTF-8//IGNORE', $bin);
					if ($utf !== false && $utf !== '') {
						return $utf;
					}
				}
	
				// Fallback: return original match unchanged
				return $m[0];
			},
			$s
		);
	}
    // Walks the JSON text and escapes " inside the value for a given key.
    function fnf__escape_quotes_in_field(string $json, string $key): string {
        $needlePattern = '/("'.preg_quote($key,'/').'"\s*:\s*")/u';
        $offset = 0;
        $len = strlen($json);

        while (preg_match($needlePattern, $json, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $prefix      = $m[0][0];
            $prefixPos   = $m[0][1];
            $valueStart  = $prefixPos + strlen($prefix);

            $i   = $valueStart;
            $buf = '';
            $n   = strlen($json);

            // Walk until we determine the true terminating quote of the string.
            while ($i < $n) {
                $ch = $json[$i];

                if ($ch === '\\') { // keep escapes as-is
                    if ($i + 1 < $n) {
                        $buf .= $ch . $json[$i + 1];
                        $i += 2;
                        continue;
                    } else {
                        $buf .= $ch;
                        $i++;
                        continue;
                    }
                }

                if ($ch === '"') {
                    // Peek ahead to see if this " is a terminator: next non-space is , or }
                    $j = $i + 1;
                    while ($j < $n && ($json[$j] === ' ' || $json[$j] === "\n" || $json[$j] === "\r" || $json[$j] === "\t")) {
                        $j++;
                    }
                    if ($j < $n && ($json[$j] === ',' || $json[$j] === '}')) {
                        // This is the real end of the string for this field.
                        break;
                    } else {
                        // This is an inner quote in the value => escape it.
                        $buf .= '\\"';
                        $i++;
                        continue;
                    }
                }

                $buf .= $ch;
                $i++;
            }

            // Replace the original substring (from valueStart up to i-1) with the fixed buffer
            $json = substr($json, 0, $valueStart) . $buf . substr($json, $i);

            // Move offset to continue after the piece we just processed
            $offset = $valueStart + strlen($buf);

            // Safety: if nothing moved, nudge offset to avoid infinite loop
            if ($offset <= $prefixPos) {
                $offset = $prefixPos + 1;
            }
        }

        return $json;
    }
}

// Clean text for display/UI (no DB writes).
if ( ! function_exists('fnf__clean_display_text') ) {
    function fnf__clean_display_text(string $s): string {
        if ($s === '') return '';
        // Decode \uXXXX and loose uXXXX
        if (function_exists('fnf__decode_loose_unicode_escapes')) {
            $s = fnf__decode_loose_unicode_escapes($s);
        }
        // Repair common UTF-8/CP1252 mojibake
        if (function_exists('fnf__fix_text_mojibake')) {
            $s = fnf__fix_text_mojibake($s);
        }
        // Normalize + strip accents (Yoü -> You)
        if (function_exists('remove_accents')) {
            $s = remove_accents($s);
        }
        if (class_exists('Normalizer')) {
            $s = Normalizer::normalize($s, Normalizer::FORM_C);
        }
        return $s;
    }
}


// tolerant reader that extracts real song rows from malformed JSON without touching the DB.
if ( ! function_exists('fnf_songs_loose_parse') ) {
    function fnf_songs_loose_parse(string $raw): array {
        if ($raw === '') return array();

        // 1) Normal decode?
        $arr = json_decode($raw, true);
        if (is_array($arr)) return fnf__normalize_songs_rows($arr);

        // 2) Try to extract the largest valid JSON array of objects that appears anywhere
        $candidates = fnf__extract_json_arrays_candidates($raw);
        $best = array(); $bestScore = -1;
        foreach ($candidates as $sub) {
            $tmp = json_decode($sub, true);
            if (is_array($tmp) && !empty($tmp)) {
                // Score by "how many look like songs"
                $score = 0;
                foreach ($tmp as $x) {
                    if (is_array($x) && (isset($x['title']) || isset($x['artist']))) $score++;
                }
                if ($score > $bestScore) { $best = $tmp; $bestScore = $score; }
            }
        }

        // 3) Also harvest any standalone objects (e.g., trailing {"title":"Cry to Me",...})
        $extras = array();
        foreach (fnf__extract_json_objects_candidates($raw) as $objStr) {
            $o = json_decode($objStr, true);
            if (is_array($o) && (isset($o['title']) || isset($o['artist']))) $extras[] = $o;
        }

        $rows = array_merge($best, $extras);
        return fnf__normalize_songs_rows($rows);
    }

    // Helpers
    function fnf__normalize_songs_rows(array $rows): array {
		$clean = array(); $seen = array();
		foreach ($rows as $r) {
			if (!is_array($r)) continue;
	
			// Raw values
			$t = trim((string)($r['title']  ?? ''));
			$a = trim((string)($r['artist'] ?? ''));
			$g = (string)($r['genre'] ?? '');
	
            // Normalize display text for UI output.
            if (function_exists('fnf__clean_display_text')) {
                $t = fnf__clean_display_text($t);
                $a = fnf__clean_display_text($a);
                $g = fnf__clean_display_text($g);
            }
	
			if ($t === '' && $a === '') continue;
	
			$k = strtolower($t.'|'.$a);
			if (isset($seen[$k])) continue;
			$seen[$k] = 1;
	
			$clean[] = array(
				'title'     => $t,
				'artist'    => $a,
				'genre'     => $g,
				'year'      => (string)($r['year']      ?? ''),
				'decade'    => (string)($r['decade']    ?? ''),
				'source'    => (string)($r['source']    ?? ''),
				'source_id' => (string)($r['source_id'] ?? ''),
			);
		}
		usort($clean, function($x,$y){ return strcasecmp($x['title'] ?? '', $y['title'] ?? ''); });
		return $clean;
	}


    function fnf__extract_json_arrays_candidates(string $s): array {
        $out = array();
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] !== '[') continue;
            // Fast check: next non-space should be '{'
            $j = $i + 1; while ($j < $len && ctype_space($s[$j])) $j++;
            if ($j >= $len || $s[$j] !== '{') continue;
            $sub = fnf__scan_balanced($s, $i, '[', ']');
            if ($sub !== null) $out[] = $sub;
        }
        return $out;
    }

    function fnf__extract_json_objects_candidates(string $s): array {
        $out = array();
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] !== '{') continue;
            $sub = fnf__scan_balanced($s, $i, '{', '}');
            if ($sub !== null) $out[] = $sub;
        }
        return $out;
    }

    // Balanced scanner that respects strings and escapes.
    function fnf__scan_balanced(string $s, int $start, string $open, string $close): ?string {
        $len = strlen($s);
        $depth = 0; $inStr = false; $esc = false;
        for ($i = $start; $i < $len; $i++) {
            $ch = $s[$i];
            if ($inStr) {
                if ($esc) { $esc = false; continue; }
                if ($ch === '\\') { $esc = true; continue; }
                if ($ch === '"')  { $inStr = false; continue; }
                continue;
            }
            if ($ch === '"') { $inStr = true; continue; }
            if ($ch === $open) { $depth++; continue; }
            if ($ch === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($s, $start, $i - $start + 1);
                }
            }
        }
        return null;
    }
}

 

/** 1) Enqueue Select2 + controller + CSS */
add_action('wp_enqueue_scripts', 'fnf_um_songs_played_enqueue_assets');

function fnf_um_songs_played_enqueue_assets() {
    wp_enqueue_script(
        'select2',
        'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
        array('jquery'),
        '4.1.0',
        true
    );

    wp_enqueue_style(
        'select2-css',
        'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
        array(),
        '4.1.0'
    );

    wp_enqueue_style(
        'um-song-picker-css',
        FNF_UM_SONGS_PLAYED_URL . 'assets/css/um-song-picker.css',
        array(),
        FNF_UM_SONGS_PLAYED_VERSION
    );

    wp_enqueue_script(
        'fnf-songs-tab',
        FNF_UM_SONGS_PLAYED_URL . 'assets/js/fnf-songs-tab.js',
        array('jquery', 'select2'),
        FNF_UM_SONGS_PLAYED_VERSION,
        true
    );
}

/** 2) REST endpoint: server-side iTunes lookup normalized for Select2 */
add_action('rest_api_init', 'fnf_um_songs_played_register_song_search_route');

function fnf_um_songs_played_register_song_search_route() {
    register_rest_route('um-songs-played/v1', '/song-search', array(
        'methods'             => 'GET',
        'permission_callback' => '__return_true',
        'callback'            => 'fnf_um_songs_played_song_search_callback',
    ));
}

function fnf_um_songs_played_song_search_callback( WP_REST_Request $req ) {
    $q     = trim((string) $req->get_param('q'));
    $limit = (int) ($req->get_param('limit') ?: 25);

    if ( $limit < 1 || $limit > 50 ) {
        $limit = 25;
    }

    if ( $q === '' ) {
        return new WP_REST_Response( array('results' => array()), 200 );
    }

    $cache_key = 'song_search_' . md5($q . '|' . $limit);
    $cached = get_transient($cache_key);
    if ( $cached ) {
        return new WP_REST_Response($cached, 200);
    }

    $url = add_query_arg(array(
        'term'  => $q,
        'media' => 'music',
        'limit' => $limit,
    ), 'https://itunes.apple.com/search');

    $res = wp_remote_get($url, array(
        'timeout' => 8,
        'headers' => array('Accept' => 'application/json'),
    ));

    if ( is_wp_error($res) ) {
        return new WP_REST_Response(array('results' => array(), 'error' => 'lookup_failed'), 200);
    }

    $json = json_decode(wp_remote_retrieve_body($res), true) ?: array();
    $out  = array();

    if ( ! empty($json['results']) && is_array($json['results']) ) {
        foreach ( $json['results'] as $r ) {
            $title  = isset($r['trackName']) ? $r['trackName'] : '';
            $artist = isset($r['artistName']) ? $r['artistName'] : '';

            if ( $title === '' || $artist === '' ) {
                continue;
            }

            $genre  = isset($r['primaryGenreName']) ? $r['primaryGenreName'] : '';
            $year   = '';
            $decade = '';

            if ( ! empty($r['releaseDate']) ) {
                $year = substr($r['releaseDate'], 0, 4);
                if ( ctype_digit($year) ) {
                    $dec_start = floor((int) $year / 10) * 10;
                    $decade = $dec_start . 's';
                }
            }

            $out[] = array(
                'id'        => (string) ($r['trackId'] ?? ($title . '|' . $artist)),
                'text'      => function_exists('remove_accents')
                    ? remove_accents($title) . ' - ' . remove_accents($artist)
                    : ($title . ' - ' . $artist),
                'title'     => $title,
                'artist'    => $artist,
                'genre'     => $genre,
                'year'      => $year,
                'decade'    => $decade,
                'source'    => 'itunes',
                'source_id' => isset($r['trackId']) ? (string) $r['trackId'] : '',
            );
        }
    }

    $payload = array('results' => $out);
    set_transient($cache_key, $payload, 6 * HOUR_IN_SECONDS);

    return new WP_REST_Response($payload, 200);
}

/** 3) Save route for songs_played */
add_action('rest_api_init', 'fnf_um_songs_played_register_save_route');

function fnf_um_songs_played_register_save_route() {
    register_rest_route('um-songs-played/v1', '/songs-save', array(
        'methods'             => 'POST',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
        'callback'            => 'fnf_um_songs_played_save_callback',
    ));
}

function fnf_um_songs_played_save_callback( WP_REST_Request $req ) {
    $current_user_id = get_current_user_id();
    if ( ! $current_user_id ) {
        return new WP_Error(
            'fnf_songs_not_logged_in',
            'You must be logged in to save songs.',
            array('status' => 403)
        );
    }

    $target_user_id = (int) $req->get_param('user_id');
    if ( $target_user_id <= 0 ) {
        $target_user_id = $current_user_id;
    }

    if ( $target_user_id !== $current_user_id && ! current_user_can('edit_user', $target_user_id) ) {
        return new WP_Error(
            'fnf_songs_forbidden',
            'You do not have permission to edit this user.',
            array('status' => 403)
        );
    }

    $raw_value = $req->get_param('value');
    $raw_value = is_string($raw_value) ? trim($raw_value) : '';

    if ( $raw_value === '' ) {
        $raw_value = '[]';
    }

    $sanitized_json = function_exists('fnf_songs_json_sanitize')
        ? fnf_songs_json_sanitize($raw_value)
        : $raw_value;

    $rows = function_exists('fnf_songs_loose_parse')
        ? fnf_songs_loose_parse($sanitized_json)
        : json_decode($sanitized_json, true);

    if ( ! is_array($rows) ) {
        return new WP_Error(
            'fnf_songs_invalid_json',
            'The songs list could not be saved because the data format was invalid.',
            array('status' => 400)
        );
    }

    $clean_rows = array();
    $seen = array();

    foreach ( $rows as $row ) {
        if ( ! is_array($row) ) {
            continue;
        }

        $title  = trim((string)($row['title'] ?? ''));
        $artist = trim((string)($row['artist'] ?? ''));
        $genre  = trim((string)($row['genre'] ?? ''));
        $year   = trim((string)($row['year'] ?? ''));
        $decade = trim((string)($row['decade'] ?? ''));
        $source = trim((string)($row['source'] ?? 'manual'));
        $source_id = trim((string)($row['source_id'] ?? ''));

        if ( $title === '' && $artist === '' ) {
            continue;
        }

        $key = strtolower($title . '|' . $artist);
        if ( isset($seen[$key]) ) {
            continue;
        }
        $seen[$key] = true;

        $clean_rows[] = array(
            'title'     => $title,
            'artist'    => $artist,
            'genre'     => $genre,
            'year'      => $year,
            'decade'    => $decade,
            'source'    => $source !== '' ? $source : 'manual',
            'source_id' => $source_id,
        );
    }

    usort($clean_rows, function($a, $b) {
        return strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
    });

    $final_json = wp_json_encode(array_values($clean_rows));

    if ( $final_json === false || $final_json === '' ) {
        return new WP_Error(
            'fnf_songs_encode_failed',
            'The songs list could not be saved.',
            array('status' => 500)
        );
    }

    update_user_meta($target_user_id, 'songs_played', $final_json);

    return new WP_REST_Response(array(
        'ok'      => true,
        'user_id' => $target_user_id,
        'count'   => count($clean_rows),
    ), 200);
}

/** 4) Webhook sender when songs_played changes */
function fnf_send_songs_webhook( $user_id, $raw_value, $type = 'new' ) {

	// Normalize type (default to "new" if missing/invalid)
	$type = is_string($type) ? strtolower(trim($type)) : 'new';
	if ($type !== 'delete' && $type !== 'new' && $type !== 'update') {
		$type = 'new';
	}

    // Decode the stored songs payload if possible.
    $songs = array();
    $decoded_ok = false;

    if (is_string($raw_value) && $raw_value !== '') {
        $decoded = json_decode($raw_value, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($decoded)) {
                $songs = $decoded;
                $decoded_ok = true;
            }
        }
    }

    // Ensure $songs is an array of song objects (arrays)
    // If the stored format is something else, we still send an array.
    if (!is_array($songs)) {
        $songs = array();
    }

    // If delete: keep payload small, but ensure each item has at least "title" if possible
    if ($type === 'delete') {
        $filtered = array();

        foreach ($songs as $s) {
            if (is_array($s)) {
                if (isset($s['title']) && trim((string)$s['title']) !== '') {
                    // Keep only allowed keys for delete (add more if Wahid supports them)
                    $filtered[] = array(
                        'title' => (string)$s['title'],
                    );
                }
            } elseif (is_string($s) && trim($s) !== '') {
                // If songs array is just ["Title 1","Title 2"], convert to objects
                $filtered[] = array(
                    'title' => $s,
                );
            }
        }

        $songs = $filtered;
    } else {
        // For non-delete events, pass through the stored song payload as-is.
    }

    // Build payload
    $user = get_userdata( $user_id );

    $payload = array(
        'event'       => 'songs_played.updated',
        'type'        => $type,
        'user'        => array(
            'id'       => $user_id,
            'email'    => $user ? $user->user_email : '',
            'username' => $user ? $user->user_login : '',
            'name'     => $user ? trim($user->first_name . ' ' . $user->last_name) : '',
        ),
        'songs'       => $songs,
        'site'        => home_url(),
        'environment' => defined('FNF_UM_SONGS_PLAYED_ENVIRONMENT') ? FNF_UM_SONGS_PLAYED_ENVIRONMENT : wp_get_environment_type(),
        'updated_at'  => current_time('mysql', true),
    );

    $url = defined('FNF_SONGDROP_WEBHOOK_URL') ? trim((string) FNF_SONGDROP_WEBHOOK_URL) : '';
    $key = defined('FNF_SONGDROP_WEBHOOK_KEY') ? trim((string) FNF_SONGDROP_WEBHOOK_KEY) : '';

    $url = apply_filters('fnf_um_songs_played_webhook_url', $url, $user_id, $type, $songs);
    $key = apply_filters('fnf_um_songs_played_webhook_key', $key, $user_id, $type, $songs);

    if ($url === '' || $key === '') {
        return;
    }

    $args = array(
        'timeout'  => 10,
        'blocking' => true,
        'headers'  => array(
            'Content-Type' => 'application/json',
            'X-API-KEY'    => $key,
        ),
        'body'     => wp_json_encode($payload),
    );
	
	// Log what we are sending (type + count + first title)
	$first_title = '';
	if (!empty($songs) && is_array($songs) && isset($songs[0]) && is_array($songs[0])) {
		$first_title = (string)($songs[0]['title'] ?? '');
	}
	//error_log('[songs_webhook] POST -> '.$url.' user='.$user_id.' type='.$type.' songs_count='.count($songs).' first_title='.$first_title);
	
	$res = wp_remote_post( $url, $args );

	if ( is_wp_error( $res ) ) {
        //error_log('[songs_webhook] POST failed for user ' . $user_id . ': ' . $res->get_error_message());
    } else {
        $code = wp_remote_retrieve_response_code($res);
        $resp = wp_remote_retrieve_body($res);
        //error_log('[songs_webhook] HTTP '.$code.' user '.$user_id.' type='.$type.' respPreview=' . substr($resp, 0, 250));
    }
}

/**
 * Parse songs JSON into a normalized array of rows.
 * Uses fnf_songs_loose_parse() if available, else json_decode().
 */
function fnf__songs_parse_to_rows($json) {
    $json = is_string($json) ? $json : '';
    if ($json === '') return array();

    if (function_exists('fnf_songs_loose_parse')) {
        return fnf_songs_loose_parse($json);
    }

    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : array();
}

/**
 * Build a stable key for a song row.
 * Uses title|artist (case-insensitive) so adds/removes are accurate.
 */
function fnf__song_key($row) {
    $t = is_array($row) ? trim((string)($row['title'] ?? '')) : '';
    $a = is_array($row) ? trim((string)($row['artist'] ?? '')) : '';
    return strtolower($t . '|' . $a);
}

/**
 * Compute added/removed songs between two JSON strings.
 * Returns: array('added' => [...rows...], 'removed' => [...rows...])
 */
function fnf__songs_diff($before_json, $after_json) {
    $before_rows = fnf__songs_parse_to_rows($before_json);
    $after_rows  = fnf__songs_parse_to_rows($after_json);

    $before_map = array();
    foreach ($before_rows as $r) {
        if (!is_array($r)) continue;
        $k = fnf__song_key($r);
        if ($k === '|') continue;
        $before_map[$k] = $r;
    }

    $after_map = array();
    foreach ($after_rows as $r) {
        if (!is_array($r)) continue;
        $k = fnf__song_key($r);
        if ($k === '|') continue;
        $after_map[$k] = $r;
    }

    $added = array();
    foreach ($after_map as $k => $row) {
        if (!isset($before_map[$k])) {
            $added[] = $row;
        }
    }

    $removed = array();
    foreach ($before_map as $k => $row) {
        if (!isset($after_map[$k])) {
            $removed[] = $row;
        }
    }

    return array(
        'added'   => array_values($added),
        'removed' => array_values($removed),
    );
}


/** Fire webhook on add/update of songs_played (DEBUG INSTRUMENTED) */
add_action('added_user_meta', function( $meta_id, $user_id, $meta_key, $meta_value ){
    if ( $meta_key === 'songs_played' ) {
        //error_log('[songs_webhook] added_user_meta fired uid='.$user_id.' meta_id='.$meta_id.' len='.strlen((string)$meta_value).' md5='.md5((string)$meta_value));
        fnf_send_songs_webhook( (int) $user_id, (string) $meta_value, 'new' );
    }
}, 10, 4);

add_action('updated_user_meta', function( $meta_id, $user_id, $meta_key, $meta_value ){
    if ( $meta_key !== 'songs_played' ) return;

    $uid = (int) $user_id;

    // Pull the true previous value captured before the meta update.
    $prev = '';
    $prev_meta = fnf_um_songs_played_get_prev_meta_store();

    if ( isset($prev_meta[$uid]) && array_key_exists('songs_played', $prev_meta[$uid]) ) {
        $prev = (string) $prev_meta[$uid]['songs_played'];

        unset($prev_meta[$uid]['songs_played']);
        if ( empty($prev_meta[$uid]) ) {
            unset($prev_meta[$uid]);
        }

        fnf_um_songs_played_set_prev_meta_store($prev_meta);
    } else {
        $prev = '';
    }
    
	// Use what is actually stored after the update (canonical, post-sanitize)
	$current = (string) get_user_meta($uid, 'songs_played', true);

	if ($prev === '') {
		//error_log('[songs_webhook] WARNING prev is empty (capture failed) -> diff will treat all songs as added');
	}
	
	$diff = fnf__songs_diff($prev, $current);
	
	//error_log('[songs_webhook] updated_user_meta uid='.$uid.' added='.count($diff['added']).' removed='.count($diff['removed']));
	// Send "new" for added songs only
    if (!empty($diff['added'])) {
		$t = (string)($diff['added'][0]['title'] ?? '');
		//error_log('[songs_webhook] sending type=new count='.count($diff['added']).' first_title='.$t);
		fnf_send_songs_webhook($uid, wp_json_encode($diff['added']), 'new');
	}
	
	if (!empty($diff['removed'])) {
		$t = (string)($diff['removed'][0]['title'] ?? '');
		//error_log('[songs_webhook] sending type=delete count='.count($diff['removed']).' first_title='.$t);
		fnf_send_songs_webhook($uid, wp_json_encode($diff['removed']), 'delete');
	}

    // Optional: if you want an update with full list when nothing changed (usually skip)
    // if (empty($diff['added']) && empty($diff['removed'])) { ... }

}, 10, 4);

/** 4) Renderer: JSON - pretty table (sorted by title) */
if ( ! function_exists('fnf_render_songs_table') ) {
    function fnf_render_songs_table( $value ) {
		if ( ! is_string($value) || $value === '' ) return '';
	
		// Parse from malformed storage without modifying the DB
		$rows = function_exists('fnf_songs_loose_parse') ? fnf_songs_loose_parse($value) : array();
		if ( empty($rows) ) return '';
	
		ob_start(); ?>
        <div class="um-songs-played">
            <table class="um-songs">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Artist</th>
                        <th>Genre</th>
                        <th>Year</th>
                        <th>Decade</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $r ): ?>
                        <tr>
                            <td><?php echo esc_html($r['title']  ?? ''); ?></td>
                            <td><?php echo esc_html($r['artist'] ?? ''); ?></td>
                            <td><?php echo esc_html($r['genre']  ?? ''); ?></td>
                            <td><?php echo esc_html($r['year']   ?? ''); ?></td>
                            <td><?php echo esc_html($r['decade'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}

/** 5) Tell UM to display the table instead of raw JSON for the songs_played text field */
add_filter('um_profile_field_filter_hook__text', function( $output, $data ){
    if ( ($data['metakey'] ?? '') !== 'songs_played' ) return $output;

    $value = '';
    if ( isset($data['value']) && is_string($data['value']) ) {
        $value = $data['value'];
    } elseif ( is_string($output) && $output !== '' ) {
        $value = $output;
    } else {
        $uid = isset($data['user_id']) ? (int)$data['user_id'] : ( function_exists('um_profile_id') ? (int) um_profile_id() : 0 );
        if ( $uid ) $value = (string) get_user_meta( $uid, 'songs_played', true );
    }

    return fnf_render_songs_table( $value );
}, 10, 2);

/** 6) Allow markup used by the songs tab shortcode (UM sanitizes output) */
add_filter('um_allowed_tags', function( $tags ){
    $tags['div'] = isset($tags['div']) ? $tags['div'] : array();
    $tags['div']['class'] = true;
    $tags['div']['id'] = true;
    $tags['div']['style'] = true;
    $tags['div']['data-per-page'] = true;
    $tags['div']['data-user-id'] = true;

    $tags['h3'] = array(
        'class' => true,
        'style' => true,
    );

    $tags['p'] = array(
        'class' => true,
        'style' => true,
    );

    $tags['span'] = array(
        'class' => true,
        'id'    => true,
    );

    $tags['table'] = array( 'class' => true, 'id' => true );
    $tags['thead'] = array();
    $tags['tbody'] = array( 'id' => true );
    $tags['tr']    = array();
    $tags['th']    = array( 'class' => true, 'style' => true );
    $tags['td']    = array( 'class' => true, 'colspan' => true );

    $tags['label'] = array(
        'for'   => true,
        'class' => true,
    );

    $tags['input'] = array(
        'type'        => true,
        'id'          => true,
        'name'        => true,
        'value'       => true,
        'class'       => true,
        'placeholder' => true,
        'checked'     => true,
        'style'       => true,
        'data-row-id' => true,
    );

    $tags['select'] = array(
        'id'       => true,
        'name'     => true,
        'class'    => true,
        'style'    => true,
        'multiple' => true,
    );

    $tags['option'] = array(
        'value'    => true,
        'selected' => true,
    );

    $tags['button'] = array(
        'type'            => true,
        'id'              => true,
        'class'           => true,
        'data-page'       => true,
        'data-title-key'  => true,
        'data-row-id'     => true,
        'disabled'        => true,
    );

    $tags['a'] = array(
        'href'  => true,
        'id'    => true,
        'class' => true,
    );

    $tags['em'] = array();

    return $tags;
});

/** 7) If using a Shortcode/Content field in the UM form, parse shortcodes */
add_filter('um_profile_field_filter_hook__html', function( $output, $data ){
    return do_shortcode( $output );
}, 10, 2);
add_filter('um_profile_field_filter_hook__shortcode', function( $output, $data ){
    return do_shortcode( $output );
}, 10, 2);
add_filter('um_profile_field_filter_hook__textarea', function( $output, $data ){
    return do_shortcode( $output );
}, 10, 2);

/** ========= Starter Pack (seed) ========= */

/** Load curated starter songs from the plugin JSON file only */
function fnf_get_seed_songs(){
    static $cache = null;
    if ( $cache !== null ) return $cache;

    $path = FNF_UM_SONGS_PLAYED_DIR . 'assets/data/dueling_piano_top150.json';

    if ( ! file_exists($path) || ! is_readable($path) ) {
        return array();
    }

    $raw = file_get_contents($path);
    if ( ! is_string($raw) || trim($raw) === '' ) {
        return array();
    }

    $data = json_decode($raw, true);
    if ( ! is_array($data) || empty($data) ) {
        return array();
    }

    $out = array();

    foreach ( $data as $r ) {
        if ( ! is_array($r) ) {
            continue;
        }

        $title  = trim((string)($r['title'] ?? ''));
        $artist = trim((string)($r['artist'] ?? ''));

        if ( $title === '' || $artist === '' ) {
            continue;
        }

        $out[] = array(
            'title'     => $title,
            'artist'    => $artist,
            'genre'     => trim((string)($r['genre'] ?? '')),
            'year'      => trim((string)($r['year'] ?? '')),
            'decade'    => trim((string)($r['decade'] ?? '')),
            'source'    => trim((string)($r['source'] ?? 'seed')),
            'source_id' => trim((string)($r['source_id'] ?? '')),
        );
    }

    $cache = array_values($out);
    return $cache;
}

/** Merge helper: add rows not already present (case-insensitive title+artist) */
function fnf_merge_songs($existing_json, $to_add){
    $existing = array();
    if (is_string($existing_json) && $existing_json !== '') {
        $decoded = json_decode($existing_json, true);
        if (is_array($decoded)) $existing = $decoded;
    }
    $have = array();
    foreach ($existing as $r){
        $key = strtolower( trim(($r['title'] ?? '').'|'.($r['artist'] ?? '')) );
        if ($key !== '|') $have[$key] = true;
    }
    foreach ($to_add as $row){
        $key = strtolower( trim(($row['title'] ?? '').'|'.($row['artist'] ?? '')) );
        if ($key === '|') continue;
        if (!isset($have[$key])){
            $existing[] = array(
                'title'     => (string)($row['title']  ?? ''),
                'artist'    => (string)($row['artist'] ?? ''),
                'genre'     => (string)($row['genre']  ?? ''),
                'year'      => (string)($row['year']   ?? ''),
                'decade'    => (string)($row['decade'] ?? ''),
                'source'    => (string)($row['source'] ?? 'seed'),
                'source_id' => (string)($row['source_id'] ?? ''),
            );
            $have[$key] = true;
        }
    }
    return wp_json_encode($existing);
}

/** REST: POST /wp-json/um-songs-played/v1/songs-seed  { "mode": "merge|replace" } (current user) */
add_action('rest_api_init', function(){
    register_rest_route('um-songs-played/v1', '/songs-seed', array(
        'methods'  => 'POST',
        'permission_callback' => function(){ return is_user_logged_in(); },
        'callback' => function( WP_REST_Request $req ){
            $uid = get_current_user_id();
            if (!$uid) return new WP_Error('forbidden', 'Not logged in', array('status'=>403));

            $mode = strtolower((string)$req->get_param('mode'));
            if ($mode !== 'replace') $mode = 'merge';

            $seed = fnf_get_seed_songs();
            if (empty($seed)) return new WP_Error('no_seed','Curated starter songs could not be loaded from the plugin JSON file.', array('status'=>500));

            $current = (string) get_user_meta($uid, 'songs_played', true);
            $new_json = ($mode === 'replace')
                ? wp_json_encode($seed)
                : fnf_merge_songs($current, $seed);
				
			update_user_meta($uid, 'songs_played', $new_json);
			// No direct webhook; 'updated_user_meta' hook will dispatch it.

            return array('ok'=>true, 'mode'=>$mode, 'count'=>count(json_decode($new_json, true) ?: array()));
        }
    ));
});

/** REST: POST /wp-json/um-songs-played/v1/songs-seed-all { "mode": "merge|replace" } (ADMIN ONLY) */
add_action('rest_api_init', function(){
    register_rest_route('um-songs-played/v1', '/songs-seed-all', array(
        'methods'  => 'POST',
        'permission_callback' => function(){ return current_user_can('manage_options'); },
        'callback' => function( WP_REST_Request $req ){
            $mode = strtolower((string)$req->get_param('mode'));
            if ($mode !== 'replace') $mode = 'merge';

            $seed = fnf_get_seed_songs();
            if (empty($seed)) return new WP_Error('no_seed','No seed songs found', array('status'=>500));

            $user_query = new WP_User_Query(array('fields' => array('ID')));
            $ids = wp_list_pluck($user_query->get_results(), 'ID');

            $updated = 0;
            foreach ($ids as $uid){
				$current = (string) get_user_meta($uid, 'songs_played', true);
				$new_json = ($mode === 'replace')
					? wp_json_encode($seed)
					: fnf_merge_songs($current, $seed);
			
				if ($new_json !== $current){
					update_user_meta($uid, 'songs_played', $new_json);
					// No direct webhook; meta hooks will dispatch it.
					$updated++;
				}
			}
            return array('ok'=>true, 'mode'=>$mode, 'users_updated'=>$updated, 'total_users'=>count($ids));
        }
    ));
});

/** Auto-seed *new* users once (if they have nothing yet) */
add_action('user_register', function( $user_id ){
    $current = (string) get_user_meta($user_id, 'songs_played', true);
    if ($current === ''){
        $seed = fnf_get_seed_songs();
        if (!empty($seed)){
            $json = wp_json_encode($seed);
			update_user_meta($user_id, 'songs_played', $json);
        }
    }
}, 10, 1);

if ( ! function_exists('fnf_um_profile_user_id') ) {
    function fnf_um_profile_user_id() {
        if ( function_exists('um_profile_id') ) {
            $pid = (int) um_profile_id();
            if ( $pid ) {
                return $pid;
            }
        }

        return get_current_user_id();
    }
}

/** Shortcode: [fnf_songs_tab] shows compact library view + separate manage mode */
add_shortcode('fnf_songs_tab', function($atts){
    $uid       = fnf_um_profile_user_id();
    $raw_value = (string) get_user_meta($uid, 'songs_played', true);
    $nonce     = wp_create_nonce('wp_rest');

    $rows        = function_exists('fnf_songs_loose_parse') ? fnf_songs_loose_parse($raw_value) : array();
    $editor_json = wp_json_encode(array_values($rows));

    $current  = get_current_user_id();
    $can_edit = $current && ( $current === $uid || current_user_can('edit_users') );
    
    wp_localize_script('fnf-songs-tab', 'FNF_SONGS_TAB', array(
        'canEdit'          => $can_edit ? true : false,
        'nonce'            => $nonce,
        'profileUserId'    => (int) $uid,
        'songs'            => array_values($rows),
        'starterSongs'     => array_values(fnf_get_seed_songs()),
        'perPage'          => 25,
        'songSearchUrl'    => rest_url('um-songs-played/v1/song-search'),
        'songsSaveUrl'     => rest_url('um-songs-played/v1/songs-save'),
        'songsSeedUrl'     => rest_url('um-songs-played/v1/songs-seed'),
        'messages'         => array(
            'noSongsYet'             => 'No songs in your list yet. Use the search box above to add some.',
            'noMatches'              => 'No songs match your current filters.',
            'alreadyInList'          => 'That song is already in your list.',
            'songAdded'              => 'Song added.',
            'songRemoved'            => 'Song removed.',
            'songsRemovedSuffix'     => ' songs removed.',
            'noSongsSelected'        => 'No songs selected.',
            'saveFailed'             => 'We could not save your changes. Please try again.',
            'savePending'            => 'You have unsaved changes. Use Save Changes to keep them.',
            'saveSuccessReload'      => 'Saving complete. Reloading...',
            'seedApplying'           => 'Applying starter songs...',
            'seedFailed'             => 'We could not apply the starter songs. Please try again.',
            'undoDone'               => 'Last change undone.',
            'undoLabel'              => 'Undo last change',
            'duplicateTitleLabel'    => 'Possible duplicate songs',
            'duplicateFilterLabel'   => 'Duplicate review active.',
            'duplicateClearLabel'    => 'Show all songs',
            'reviewLabel'            => 'Review duplicates',
            'clearFilters'           => 'Clear',
            'manageSongs'            => 'Manage Songs',
            'deselectVisible'        => 'Deselect visible',
            'deleteSelected'         => 'Delete selected songs',
            'routeErrorFriendly'     => 'Something went wrong while talking to the server. Please refresh the page and try again.',
            'replaceConfirm'         => 'This will replace your current list with the starter songs. Continue?',
            'mergeModalTitle'        => 'Add curated starter songs',
            'replaceModalTitle'      => 'Replace with curated starter songs',
            'modalConfirmMerge'      => 'Add curated songs',
            'modalConfirmReplace'    => 'Replace my list',
            'modalCancel'            => 'Cancel',
            'mergeExplainer'         => 'This will keep your current list and add only curated starter songs that are not already in your library by exact title and artist.',
            'replaceExplainer'       => 'This will replace your current list with the curated starter library. Songs that are not part of that library will be removed.',
            'savingLabel'            => 'Saving...',
            'applyStarterLabel'      => 'Applying...'
        )
    ));

    ob_start();
    ?>
    <div class="fnf-songs-app" id="fnf-songs-app" data-per-page="25" data-user-id="<?php echo esc_attr($uid); ?>">
        <input type="hidden" id="songs_played_json" name="songs_played_json" value="<?php echo esc_attr($editor_json); ?>" />

        <div class="fnf-songs-header">
            <div class="fnf-songs-header-main">
                <h3 class="fnf-songs-title">Songs I Play Well</h3>
                <p class="fnf-songs-subtitle">Browse your library here. Click Manage Songs to add, remove, and review songs before saving.</p>
            </div>
            <?php if ( $can_edit ) : ?>
                <div class="fnf-songs-header-actions">
                    <button type="button" class="um-button um-button-primary fnf-songs-manage-button" id="fnf-songs-open-manage">Manage Songs</button>
                </div>
            <?php endif; ?>
        </div>

        <div class="fnf-songs-summary" id="fnf-songs-summary"></div>

        <div class="fnf-songs-view-panel" id="fnf-songs-view-panel">
            <div class="fnf-songs-toolbar fnf-songs-toolbar-sticky">
                <div class="fnf-songs-toolbar-row">
                    <div class="fnf-songs-field fnf-songs-field-search">
                        <label for="fnf-songs-view-search">Search</label>
                        <input type="text" id="fnf-songs-view-search" placeholder="Search title, artist, genre, year, or decade" />
                    </div>

                    <div class="fnf-songs-field">
                        <label for="fnf-songs-view-genre">Genre</label>
                        <select id="fnf-songs-view-genre">
                            <option value="">All genres</option>
                        </select>
                    </div>

                    <div class="fnf-songs-field">
                        <label for="fnf-songs-view-decade">Decade</label>
                        <select id="fnf-songs-view-decade">
                            <option value="">All decades</option>
                        </select>
                    </div>

                    <div class="fnf-songs-field">
                        <label for="fnf-songs-view-sort">Sort by</label>
                        <select id="fnf-songs-view-sort">
                            <option value="title_asc">Title A-Z</option>
                            <option value="title_desc">Title Z-A</option>
                            <option value="artist_asc">Artist A-Z</option>
                            <option value="artist_desc">Artist Z-A</option>
                            <option value="year_desc">Year newest</option>
                            <option value="year_asc">Year oldest</option>
                        </select>
                    </div>

                    <div class="fnf-songs-field fnf-songs-field-button">
                        <label>&nbsp;</label>
                        <button type="button" class="um-button um-alt" id="fnf-songs-view-clear">Clear</button>
                    </div>
                </div>
            </div>

            <div class="fnf-songs-results-meta">
                <div id="fnf-songs-view-count"></div>
            </div>

            <div class="fnf-songs-table-shell">
                <table class="um-songs fnf-songs-library-table" id="fnf-songs-view-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Artist</th>
                            <th>Genre</th>
                            <th>Year</th>
                            <th>Decade</th>
                        </tr>
                    </thead>
                    <tbody id="fnf-songs-view-body"></tbody>
                </table>
            </div>

            <div class="fnf-songs-pagination" id="fnf-songs-view-pagination"></div>
        </div>

        <?php if ( $can_edit ) : ?>
            <div class="fnf-songs-manage-panel" id="fnf-songs-manage-panel" style="display:none">
                <div class="fnf-songs-manage-sticky">
                    <div class="fnf-songs-toolbar fnf-songs-toolbar-primary">
                        <div class="fnf-songs-toolbar-row fnf-songs-toolbar-row-manage">
                            <div class="fnf-songs-field fnf-songs-field-search">
                                <label for="songs_played_search">Add a song</label>
                                <select id="songs_played_search" style="width:100%"></select>
                                <p class="fnf-songs-help">Type a title or artist, then click one of the results to add it to your list.</p>
                            </div>

                            <div class="fnf-songs-field fnf-songs-field-button">
                                <label>&nbsp;</label>
                                <button type="button" class="um-button um-button-primary fnf-songs-save-btn" id="fnf-songs-save-top">Save changes</button>
                            </div>

                            <div class="fnf-songs-field fnf-songs-field-button">
                                <label>&nbsp;</label>
                                <button type="button" class="um-button um-alt" id="fnf-songs-cancel-top">Discard changes</button>
                            </div>
                        </div>
                    </div>

                    <div id="fnf-songs-msg" class="fnf-songs-msg"></div>
                    <div id="fnf-songs-dirty" class="fnf-songs-dirty" style="display:none"></div>
                </div>

                <div class="fnf-songs-manage-context" id="fnf-songs-manage-context">
                    <div class="fnf-songs-duplicates" id="fnf-songs-duplicates"></div>

                    <div class="fnf-songs-starter-pack-note">
                        Need help getting started? You can add a curated starter list of popular dueling piano songs to fill in gaps, or replace your current list with that curated list.
                    </div>

                    <div class="fnf-songs-toolbar-row fnf-songs-toolbar-row-manage-actions">
                        <button type="button" class="um-button um-alt fnf-starter-btn fnf-starter-btn-merge" id="fnf-seed-merge">Add curated starter songs</button>
                        <button type="button" class="um-button um-alt fnf-starter-btn fnf-starter-btn-replace" id="fnf-seed-replace">Replace with curated starter songs</button>
                    </div>

                    <div class="fnf-songs-starter-pack-help">
                        Add will keep your current list and only add missing exact starter songs. Replace will overwrite your current list with the curated starter list.
                    </div>

                    <div class="fnf-songs-modal-backdrop" id="fnf-songs-modal-backdrop" style="display:none">
                        <div class="fnf-songs-modal" id="fnf-songs-modal" role="dialog" aria-modal="true" aria-labelledby="fnf-songs-modal-title">
                            <div class="fnf-songs-modal-header">
                                <h4 id="fnf-songs-modal-title" class="fnf-songs-modal-title"></h4>
                            </div>
                            <div class="fnf-songs-modal-body" id="fnf-songs-modal-body"></div>
                            <div class="fnf-songs-modal-actions">
                                <button type="button" class="um-button um-alt" id="fnf-songs-modal-cancel">Cancel</button>
                                <button type="button" class="um-button um-button-primary" id="fnf-songs-modal-confirm">Confirm</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="fnf-songs-toolbar">
                    <div class="fnf-songs-toolbar-row">
                        <div class="fnf-songs-field fnf-songs-field-search">
                            <label for="fnf-songs-manage-search">Search</label>
                            <input type="text" id="fnf-songs-manage-search" placeholder="Search title, artist, genre, year, or decade" />
                        </div>

                        <div class="fnf-songs-field">
                            <label for="fnf-songs-manage-genre">Genre</label>
                            <select id="fnf-songs-manage-genre">
                                <option value="">All genres</option>
                            </select>
                        </div>

                        <div class="fnf-songs-field">
                            <label for="fnf-songs-manage-decade">Decade</label>
                            <select id="fnf-songs-manage-decade">
                                <option value="">All decades</option>
                            </select>
                        </div>

                        <div class="fnf-songs-field">
                            <label for="fnf-songs-manage-sort">Sort by</label>
                            <select id="fnf-songs-manage-sort">
                                <option value="title_asc">Title A-Z</option>
                                <option value="title_desc">Title Z-A</option>
                                <option value="artist_asc">Artist A-Z</option>
                                <option value="artist_desc">Artist Z-A</option>
                                <option value="year_desc">Year newest</option>
                                <option value="year_asc">Year oldest</option>
                            </select>
                        </div>

                        <div class="fnf-songs-field fnf-songs-field-button">
                            <label>&nbsp;</label>
                            <button type="button" class="um-button um-alt" id="fnf-songs-manage-clear">Clear</button>
                        </div>
                    </div>
                </div>

                <div class="fnf-songs-bulkbar">
                    <label class="fnf-songs-checkbox-label">
                        <input type="checkbox" id="fnf-songs-select-all-visible" />
                        <span>Select all visible</span>
                    </label>

                    <button type="button" class="um-button um-alt" id="fnf-songs-clear-selection" style="display:none">Deselect checked songs</button>
                    <button type="button" class="um-button um-alt" id="fnf-songs-remove-selected" style="display:none">Delete selected songs</button>

                    <div class="fnf-songs-selected-count" id="fnf-songs-selected-count">0 selected</div>
                </div>

                <div class="fnf-songs-results-meta">
                    <div id="fnf-songs-manage-count"></div>
                </div>

                <div class="fnf-songs-table-shell fnf-songs-table-shell-manage">
                    <table class="um-songs fnf-songs-library-table fnf-songs-manage-table" id="fnf-songs-manage-table">
                        <thead>
                            <tr>
                                <th class="fnf-songs-col-check"></th>
                                <th>Title</th>
                                <th>Artist</th>
                                <th>Genre</th>
                                <th>Year</th>
                                <th>Decade</th>
                                <th class="fnf-songs-col-action">Action</th>
                            </tr>
                        </thead>
                        <tbody id="fnf-songs-manage-body"></tbody>
                    </table>
                </div>

                <div class="fnf-songs-pagination" id="fnf-songs-manage-pagination"></div>

                <div class="fnf-songs-manage-footer">
                    <button type="button" class="um-button um-button-primary fnf-songs-save-btn" id="fnf-songs-save-bottom">Save changes</button>
                    <button type="button" class="um-button um-alt" id="fnf-songs-cancel-bottom">Discard changes</button>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php

    return ob_get_clean();
});

// End songs webhook and save handlers.