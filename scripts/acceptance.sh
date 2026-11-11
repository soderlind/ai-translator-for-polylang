#!/usr/bin/env bash
# End-to-end acceptance test for AI Translator for Polylang.
# Uses real AI requests. Creates its own fixtures and removes them on exit.
# See docs/acceptance-test.md.
set -u

PLUGIN_DIR=$(cd "$(dirname "$0")/.." && pwd)
WP_PATH=${AITFP_WP_PATH:-$(cd "$PLUGIN_DIR/../../.." && pwd)}
URL=${AITFP_URL:-http://plugins.local/translate}
SRC_LANG=${AITFP_SOURCE_LANG:-en}
TGT_LANG=${AITFP_TARGET_LANG:-nb}
read -r -a WP_CMD <<<"${AITFP_WP:-wp}"

cd "$WP_PATH" || { echo "WordPress not found at $WP_PATH (set AITFP_WP_PATH)"; exit 1; }
TMP=$(mktemp -d)

wpc() {
	local err n
	err=$(mktemp)
	"${WP_CMD[@]}" --url="$URL" "$@" 2>"$err"
	# Some wrappers (e.g. the Local by Flywheel one) echo "[site] wp <args>" to stderr first; drop it.
	if head -1 "$err" | grep -qE '^\[[^]]+\] wp '; then
		n=$(printf '%s\n' "[x] wp --url=$URL $*" | wc -l)
		tail -n +$((n+1)) "$err"
	else
		cat "$err"
	fi
	rm -f "$err"
}

AT_USER=${AITFP_USER:-$(wpc user list --role=administrator --field=user_login --number=1 | tail -1)}
AT_UID=$(wpc user get "$AT_USER" --field=ID | tail -1)
case "$AT_UID" in ''|*[!0-9]*) echo "Could not resolve admin user '$AT_USER' (set AITFP_USER)"; exit 1;; esac
export AITFP_AT_SRC=$SRC_LANG AITFP_AT_TGT=$TGT_LANG AITFP_AT_UID=$AT_UID
ev() { wpc eval "$1" --user="$AT_USER"; }

echo "Site: $URL  user: $AT_USER  languages: $SRC_LANG -> $TGT_LANG"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  PASS  $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  FAIL  $1"; [ -n "${2:-}" ] && echo "        -> $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "expected [$3], got [$2]"; fi; }
has()  { if grep -qF -- "$3" <<<"$2"; then ok "$1"; else bad "$1" "missing [$3] in: $(head -c 400 <<<"$2")"; fi; }

LOG=wp-content/debug.log
LOG_START=$( [ -f $LOG ] && wc -c <$LOG || echo 0 )

echo "== Fixtures"
FIX=$(ev '
$cat_en = wp_insert_term("AITFP AT Garden", "category"); $cat_nb = wp_insert_term("AITFP AT Hage", "category");
pll_set_term_language($cat_en["term_id"], getenv("AITFP_AT_SRC")); pll_set_term_language($cat_nb["term_id"], getenv("AITFP_AT_TGT"));
pll_save_term_translations([getenv("AITFP_AT_SRC") => $cat_en["term_id"], getenv("AITFP_AT_TGT") => $cat_nb["term_id"]]);
$content = <<<HTML
<!-- wp:heading -->
<h2 class="wp-block-heading">Planting tomatoes</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Tomatoes need <strong>full sun</strong> and <a href="https://example.com/soil">well-drained soil</a>.</p>
<!-- /wp:paragraph -->

<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Water every morning</li>
<!-- /wp:list-item -->

<!-- wp:list-item -->
<li>Feed every two weeks</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list --></div>
<!-- /wp:group -->

<!-- wp:image {"sizeSlug":"large"} -->
<figure class="wp-block-image size-large"><img src="https://example.com/tomato.jpg" alt="Red tomatoes on the vine"/></figure>
<!-- /wp:image -->

<!-- wp:separator -->
<hr class="wp-block-separator has-alpha-channel-opacity"/>
<!-- /wp:separator -->

<!-- wp:shortcode -->
[gallery ids="1,2"]
<!-- /wp:shortcode -->

<!-- wp:paragraph -->
<p>Harvest when the fruit is deep red and slightly soft.</p>
<!-- /wp:paragraph -->
HTML;
$ids = [];
foreach (["A" => "Growing tomatoes in pots", "B" => "Composting for beginners", "C" => "Pruning roses in spring", "D" => "Saving seeds", "E" => "Mulching basics"] as $k => $title) {
	$id = wp_insert_post(wp_slash(["post_title" => "AITFP AT $title", "post_excerpt" => "A short guide about $title.", "post_content" => $content, "post_status" => "publish", "post_category" => [$cat_en["term_id"]]]));
	pll_set_post_language($id, getenv("AITFP_AT_SRC"));
	$ids[] = "$k=$id";
}
echo implode(" ", $ids), " CATEN=", $cat_en["term_id"], " CATNB=", $cat_nb["term_id"];
')
eval "$FIX"
NBNAME=$(ev "echo PLL()->model->get_language('$TGT_LANG')->name;")
echo "  sources: A=$A B=$B C=$C D=$D E=$E  categories: $SRC_LANG=$CATEN $TGT_LANG=$CATNB"

cleanup() {
	echo "== Cleanup"
	ev "
	foreach ([$A,$B,$C,$D,$E] as \$src) { foreach (pll_get_post_translations(\$src) as \$p) { wp_delete_post(\$p, true); } wp_delete_post(\$src, true); }
	wp_delete_term($CATEN, 'category'); wp_delete_term($CATNB, 'category');
	WP_Session_Tokens::get_instance($AT_UID)->destroy('${TOKEN:-none}');
	echo 'cleaned';"
	rm -rf "$TMP"
}
trap cleanup EXIT

# Helper: dump job state of a post as key=value lines.
state() {
	ev "\$p = get_post($1); \$src = (int) get_post_meta($1, '_aitfp_source', true);
	echo 'status=', get_post_meta($1, '_aitfp_status', true), PHP_EOL;
	echo 'error=', get_post_meta($1, '_aitfp_error', true), PHP_EOL;
	echo 'post_status=', \$p ? \$p->post_status : '', PHP_EOL;
	echo 'lang=', pll_get_post_language($1), PHP_EOL;
	echo 'linked=', \$src && pll_get_post(\$src, '$TGT_LANG') === $1 ? 1 : 0, PHP_EOL;
	echo 'title=', \$p->post_title, PHP_EOL;
	echo 'slug=', \$p->post_name, PHP_EOL;
	echo 'title_changed=', \$src && get_post(\$src)->post_title !== \$p->post_title ? 1 : 0, PHP_EOL;
	echo 'excerpt_changed=', \$src && get_post(\$src)->post_excerpt !== \$p->post_excerpt ? 1 : 0, PHP_EOL;
	echo 'content_changed=', \$src && get_post(\$src)->post_content !== \$p->post_content ? 1 : 0, PHP_EOL;
	echo 'blocks_equal=', \$src && AiTranslatorForPolylang\Translation\ContentChunker::block_signature(get_post(\$src)->post_content) === AiTranslatorForPolylang\Translation\ContentChunker::block_signature(\$p->post_content) ? 1 : 0, PHP_EOL;
	echo 'urls_kept=', str_contains(\$p->post_content, 'https://example.com/soil') && str_contains(\$p->post_content, 'https://example.com/tomato.jpg') && str_contains(\$p->post_content, '[gallery ids=\"1,2\"]') ? 1 : 0, PHP_EOL;
	echo 'parses=', count(array_filter(parse_blocks(\$p->post_content), fn(\$b) => \$b['blockName'])), PHP_EOL;
	echo 'cats=', implode(',', wp_get_post_categories($1)), PHP_EOL;
	echo 'cron=', wp_next_scheduled('aitfp_translate_post', [$1]) ? 1 : 0, PHP_EOL;"
}
get() { grep "^$1=" <<<"$2" | head -1 | cut -d= -f2-; }
wait_done() { # $1 post id, $2 seconds
	local s
	for _ in $(seq 1 "$2"); do s=$(ev "echo get_post_meta($1, '_aitfp_status', true);"); [ "$s" = done ] || [ "$s" = failed ] && break; sleep 1; done
	echo "$s"
}

echo "== 1. wp aitfp translate (synchronous)"
OUT=$(wpc aitfp translate "$A" --language="$TGT_LANG" --user="$AT_USER")
has "translate reports success" "$OUT" "Success: Translated post"
T1=$(ev "echo pll_get_post($A, '$TGT_LANG');")
S=$(state "$T1")
check "status is done"                "$(get status "$S")" done
check "no error stored"               "$(get error "$S")" ""
check "translation is a draft"        "$(get post_status "$S")" draft
check "language is target"                "$(get lang "$S")" "$TGT_LANG"
check "linked to source"              "$(get linked "$S")" 1
check "title translated"              "$(get title_changed "$S")" 1
check "excerpt translated"            "$(get excerpt_changed "$S")" 1
check "content translated"            "$(get content_changed "$S")" 1
check "block structure preserved"     "$(get blocks_equal "$S")" 1
check "URLs and shortcode untouched"  "$(get urls_kept "$S")" 1
check "top-level blocks parse (7)"    "$(get parses "$S")" 7
check "category swapped to target term"   "$(get cats "$S")" "$CATNB"
check "no cron event left"            "$(get cron "$S")" 0
echo "        title: $(get title "$S")  slug: $(get slug "$S")"

echo "== 2. wp aitfp translate errors"
OUT=$(wpc aitfp translate "$A" --language="$TGT_LANG" --user="$AT_USER");  has "already translated"        "$OUT" "already translated into every language"
OUT=$(wpc aitfp translate "$B" --language=xx --user="$AT_USER");  has "unknown language lists target" "$OUT" "Choose one of: $TGT_LANG"
OUT=$(wpc aitfp translate "$B" --language="$TGT_LANG");             has "requires --user"           "$OUT" "Pass --user=<admin>"
OUT=$(wpc aitfp translate 99999999 --language="$TGT_LANG" --user="$AT_USER"); has "missing post"           "$OUT" "Post not found"
OUT=$(wpc aitfp run "$A" --user="$AT_USER");                       has "run on non-job post"      "$OUT" "Nothing to translate"
check "no translation created by failed calls" "$(ev "echo pll_get_post($B, '$TGT_LANG') ?: 0;")" 0

echo "== 3. wp aitfp translate --async (spawn_cron while DISABLE_WP_CRON)"
OUT=$(wpc aitfp translate "$B" --language="$TGT_LANG" --user="$AT_USER" --async)
has "async reports queued" "$OUT" "queued AI translation to"
T2=$(ev "echo pll_get_post($B, '$TGT_LANG');")
check "draft linked immediately" "$( [ -n "$T2" ] && [ "$T2" != 0 ] && echo 1)" 1
ST=$(wait_done "$T2" 120)
if [ "$ST" = done ]; then ok "spawned cron finished the job"; else
	bad "spawned cron finished the job" "status [$ST] after 120s; running cron manually"
	wpc cron event run aitfp_translate_post >/dev/null
fi
S=$(state "$T2")
check "async status done"            "$(get status "$S")" done
check "async block structure kept"   "$(get blocks_equal "$S")" 1
check "async content translated"     "$(get content_changed "$S")" 1

echo "== 4. wp aitfp run (re-run)"
OUT=$(wpc aitfp run "$T1" --user="$AT_USER")
has "re-run succeeds" "$OUT" "Success: Translated post $T1"
check "re-run status done" "$(ev "echo get_post_meta($T1, '_aitfp_status', true);")" done

echo "== 5. Worker lock"
LOCKQ() { ev "global \$wpdb; echo \$wpdb->get_var(\$wpdb->prepare(\"SELECT COUNT(*) FROM {\$wpdb->options} WHERE option_name LIKE %s\", '_aitfp_lock_%'));"; }
check "no lock rows left after runs" "$(LOCKQ)" 0
setlock() { ev "global \$wpdb; \$wpdb->replace(\$wpdb->options, ['option_name' => '_aitfp_lock_$T1', 'option_value' => 'other|' . (time() - $2), 'autoload' => 'off']);" >/dev/null; }
setlock "$T1" 0
BEFORE=$(ev "echo get_post($T1)->post_modified_gmt;")
OUT=$(wpc aitfp run "$T1" --user="$AT_USER")
has "held lock blocks a second worker" "$OUT" "already being translated"
check "blocked worker changed nothing" "$(ev "echo get_post($T1)->post_modified_gmt;")" "$BEFORE"
setlock "$T1" 1000
OUT=$(wpc aitfp run "$T1" --user="$AT_USER")
has "stale lock is taken over" "$OUT" "Success: Translated post $T1"
check "lock released after takeover" "$(LOCKQ)" 0
cat >"$TMP/steal-lock.php" <<'PHP'
<?php
WP_CLI::add_wp_hook( 'aitfp_prompt_builder', static function ( $builder, $field ) {
	global $wpdb;
	if ( 'content' === $field ) {
		$wpdb->update( $wpdb->options, [ 'option_value' => 'other|' . time() ], [ 'option_name' => '_aitfp_lock_' . (int) getenv( 'AT_POST' ) ] );
	}
	return $builder;
}, 10, 2 );
PHP
BEFORE=$(ev "echo get_post($T1)->post_modified_gmt;")
OUT=$(AT_POST=$T1 wpc aitfp run "$T1" --user="$AT_USER" --require="$TMP/steal-lock.php")
has "worker that lost its lock stops" "$OUT" "Another worker took over"
check "worker that lost its lock did not save" "$(ev "echo get_post($T1)->post_modified_gmt;")" "$BEFORE"
check "new owner's lock left in place" "$(LOCKQ)" 1
ev "global \$wpdb; \$wpdb->delete(\$wpdb->options, ['option_name' => '_aitfp_lock_$T1']);" >/dev/null
cat >"$TMP/log-schedule.php" <<'PHP'
<?php
WP_CLI::add_wp_hook( 'schedule_event', static function ( $event ) {
	if ( $event && 'aitfp_translate_post' === $event->hook ) {
		file_put_contents( getenv( 'AT_LOG' ), 'scheduled' );
	}
	return $event;
} );
PHP
: >"$TMP/schedule.log"
AT_LOG="$TMP/schedule.log" wpc aitfp translate "$D" --language="$TGT_LANG" --user="$AT_USER" --require="$TMP/log-schedule.php" >/dev/null
check "synchronous translate never schedules cron" "$(cat "$TMP/schedule.log")" ""
check "synchronous translate finished" "$(ev "echo get_post_meta(pll_get_post($D, '$TGT_LANG'), '_aitfp_status', true);")" done
ev "wp_delete_post(pll_get_post($D, '$TGT_LANG'), true);" >/dev/null

echo "== 6. Edit during translation is not overwritten"
cat >"$TMP/edit-midway.php" <<'PHP'
<?php
WP_CLI::add_wp_hook( 'aitfp_prompt_builder', static function ( $builder, $field ) {
	static $done = false;
	if ( 'content' === $field && ! $done ) {
		$done = true;
		global $wpdb;
		$id = (int) getenv( 'AT_POST' );
		$wpdb->update( $wpdb->posts, [ 'post_title' => 'Manual edit', 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ], [ 'ID' => $id ] );
	}
	return $builder;
}, 10, 2 );
PHP
OUT=$(AT_POST=$T1 wpc aitfp run "$T1" --user="$AT_USER" --require="$TMP/edit-midway.php")
has "run reports edit conflict" "$OUT" "edited while it was being translated"
S=$(state "$T1")
check "status failed"            "$(get status "$S")" failed
check "manual edit kept"         "$(get title "$S")" "Manual edit"
has   "error message stored"     "$(get error "$S")" "edited while it was being translated"

echo "== 7. Admin: post states and row actions"
OUT=$(ev "\$p = get_post($T1); echo implode('|', apply_filters('display_post_states', [], \$p)), PHP_EOL, implode('|', array_keys(apply_filters('post_row_actions', [], \$p)));")
has "failed state label"     "$OUT" "Translation failed"
has "retry row action"       "$OUT" "aitfp_retry"
check "no retry on source post" "$(ev "echo (int) isset(apply_filters('post_row_actions', [], get_post($A))['aitfp_retry']);")" 0

echo "== 8. Admin HTTP flow (+ link, notice, retry, nonce checks)"
AUTH=$(ev '
$exp = time() + HOUR_IN_SECONDS; $token = WP_Session_Tokens::get_instance((int) getenv("AITFP_AT_UID"))->create($exp);
$logged = wp_generate_auth_cookie((int) getenv("AITFP_AT_UID"), $exp, "logged_in", $token); $secure = str_starts_with(admin_url(), "https:"); $auth = wp_generate_auth_cookie((int) getenv("AITFP_AT_UID"), $exp, $secure ? "secure_auth" : "auth", $token);
echo "TOKEN=", $token, PHP_EOL, "LOGGED=\"", $logged, "\"", PHP_EOL, "COOKIE=\"", $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE, "=", rawurlencode($auth), "; ", LOGGED_IN_COOKIE, "=", rawurlencode($logged), "\"", PHP_EOL;
$_COOKIE[LOGGED_IN_COOKIE] = $logged; wp_set_current_user((int) getenv("AITFP_AT_UID"));
$lang = PLL()->model->get_language(getenv("AITFP_AT_TGT"));
foreach (["C" => '"$C"', "D" => '"$D"'] as $k => $id) { echo "PLUS_$k=\"", apply_filters("pll_get_new_post_translation_link", admin_url("post-new.php?from_post=$id&new_lang=" . getenv("AITFP_AT_TGT")), $lang, $id), "\"", PHP_EOL; }
echo "PLUS_BADNONCE=\"", admin_url("admin.php?action=aitfp_translate&post='"$E"'&language=" . getenv("AITFP_AT_TGT") . "&_wpnonce=deadbeef"), "\"", PHP_EOL;
')
eval "$AUTH"
export TOKEN
has "+ link points at aitfp_translate" "$PLUS_C" "action=aitfp_translate"
REF="$URL/wp-admin/edit.php"
curlc() { curl -sk -o "$TMP/body" -w '%{http_code} %{redirect_url}' -H "Cookie: $COOKIE" -e "$REF" "$@"; }

R=$(curlc "$PLUS_C")
has "+ click redirects to post list" "$R" "302 $URL/wp-admin/edit.php?aitfp_queued="
T3=${R##*aitfp_queued=}
check "+ created linked target draft" "$(ev "echo pll_get_post($C, '$TGT_LANG');")" "$T3"

curl -sk -o "$TMP/list" -H "Cookie: $COOKIE" "$URL/wp-admin/edit.php?aitfp_queued=$T3&lang=all"
has "queued notice shown"   "$(cat "$TMP/list")" "is being translated to $NBNAME"
ST=$(wait_done "$T3" 120)
check "admin-queued job done via spawned cron" "$ST" done
S=$(state "$T3")
check "admin job blocks preserved"   "$(get blocks_equal "$S")" 1
check "admin job category swapped"   "$(get cats "$S")" "$CATNB"

R=$(curlc "$PLUS_C")
has "second + for same language rejected" "$R" "400"
R=$(curlc "$PLUS_BADNONCE")
has "bad nonce rejected" "$R" "403"
check "bad nonce created nothing" "$(ev "echo pll_get_post($E, '$TGT_LANG') ?: 0;")" 0
R=$(curl -sk -o /dev/null -w '%{http_code} %{redirect_url}' "$PLUS_D")
has "logged-out request bounced to login" "$R" "wp-login.php"
check "logged-out request created nothing" "$(ev "echo pll_get_post($D, '$TGT_LANG') ?: 0;")" 0

RETRY=$(ev "\$_COOKIE[LOGGED_IN_COOKIE] = '$LOGGED'; wp_set_current_user($AT_UID); echo html_entity_decode(wp_nonce_url(admin_url('admin.php?action=aitfp_retry&post=$T1'), 'aitfp_retry_$T1'));")
curl -sk -o "$TMP/list" -H "Cookie: $COOKIE" "$URL/wp-admin/edit.php?lang=all&post_status=draft"
has "post list shows failed label" "$(cat "$TMP/list")" "Translation failed"
if grep -qE "action=aitfp_retry(&amp;|&#038;|&)post=$T1&" "$TMP/list"; then ok "post list shows retry link"; else bad "post list shows retry link" "$(grep -o 'aitfp_retry[^"]*' "$TMP/list" | head -3)"; fi
R=$(curlc "$RETRY")
has "retry redirects to post list" "$R" "302 $URL/wp-admin/edit.php?aitfp_queued=$T1"
ST=$(wait_done "$T1" 120)
check "retried job done" "$ST" done
S=$(state "$T1")
check "retry replaced manual-edit title" "$( [ "$(get title "$S")" != "Manual edit" ] && echo 1)" 1
check "retry block structure kept"       "$(get blocks_equal "$S")" 1
R=$(curlc "$RETRY")
has "retry refused once done" "$R" "403"

echo "== 9. Deleting a queued translation unschedules it"
cat >"$TMP/no-spawn.php" <<'PHP'
<?php
WP_CLI::add_wp_hook( 'aitfp_spawn_cron', '__return_false' );
PHP
wpc aitfp translate "$E" --language="$TGT_LANG" --user="$AT_USER" --async --require="$TMP/no-spawn.php" >/dev/null
T5=$(ev "echo pll_get_post($E, '$TGT_LANG');")
check "event scheduled" "$(ev "echo wp_next_scheduled('aitfp_translate_post', [$T5]) ? 1 : 0;")" 1
check "status pending"  "$(ev "echo get_post_meta($T5, '_aitfp_status', true);")" pending
ev "wp_delete_post($T5, true);" >/dev/null
check "event removed on delete" "$(ev "echo wp_next_scheduled('aitfp_translate_post', [$T5]) ? 1 : 0;")" 0

echo "== 10. PHP log"
NEW=$( [ -f $LOG ] && tail -c +$((LOG_START+1)) $LOG | grep -iE 'ai-translator-for-polylang|aitfp' || true)
if [ -z "$NEW" ]; then ok "no PHP notices/errors from the plugin"; else bad "no PHP notices/errors from the plugin" "$NEW"; fi

echo
echo "RESULT: $PASS passed, $FAIL failed"
