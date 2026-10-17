<?php
// Read-only Reddit client for wd-news-curator-redditrank.
//
// What this does, in full:
//   1. Gets an app-only OAuth token (client_credentials grant). No user login, no user context, no write scopes.
//   2. Reads one public listing per configured subreddit: GET https://oauth.reddit.com/r/<subreddit>/hot?limit=40
//   3. Reduces each post to: title, link, score, comment count, upvote ratio, permalink, created time.
// It never posts, votes, comments, messages or moderates, and it never reads or keeps usernames, post bodies or comments.
//
// Settings (environment): REDDIT_CLIENT_ID, REDDIT_CLIENT_SECRET, REDDIT_USERNAME (the app owner's account; used in the User-Agent),
// optionally REDDIT_USER_AGENT, REDDIT_TOKEN_FILE.

function reddit_env(string $key, string $default = ''): string
{
    $v = getenv($key);
    return $v === false || $v === '' ? $default : $v;
}

/** Reddit asks for "<platform>:<app id>:<version> (by /u/<username>)". */
function reddit_user_agent(): string
{
    $custom = reddit_env('REDDIT_USER_AGENT');
    if ($custom !== '') {
        return $custom;
    }
    return 'linux:wd-news-curator-redditrank:0.1 (by /u/' . reddit_env('REDDIT_USERNAME', 'unknown') . ')';
}

function reddit_configured(): bool
{
    return reddit_env('REDDIT_CLIENT_ID') !== '' && reddit_env('REDDIT_CLIENT_SECRET') !== '';
}

function reddit_token_file(): string
{
    return reddit_env('REDDIT_TOKEN_FILE', sys_get_temp_dir() . '/redditrank_token.json');
}

/** One HTTPS request; returns code, lower-cased response headers and body. Never throws. */
function reddit_http(string $url, array $headers = [], ?array $post = null, ?string $basicAuth = null): array
{
    $responseHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => reddit_user_agent(), CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$responseHeaders) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ]);
    if ($post !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    }
    if ($basicAuth !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
    }
    $body = curl_exec($ch);
    $out = ['code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $responseHeaders, 'body' => (string) $body, 'error' => curl_error($ch)];
    curl_close($ch);
    return $out;
}

/** App-only token, cached on disk until shortly before it expires. */
function reddit_token(bool $forceRefresh = false): string
{
    if (!reddit_configured()) {
        throw new RuntimeException('REDDIT_CLIENT_ID / REDDIT_CLIENT_SECRET are not set');
    }
    $file = reddit_token_file();
    if (!$forceRefresh && is_file($file)) {
        $j = json_decode((string) file_get_contents($file), true);
        if (!empty($j['access_token']) && ($j['expires_at'] ?? 0) > time() + 120) {
            return $j['access_token'];
        }
    }
    $r = reddit_http('https://www.reddit.com/api/v1/access_token', [], ['grant_type' => 'client_credentials'],
        reddit_env('REDDIT_CLIENT_ID') . ':' . reddit_env('REDDIT_CLIENT_SECRET'));
    $j = json_decode($r['body'], true);
    if ($r['code'] !== 200 || empty($j['access_token'])) {
        throw new RuntimeException("Reddit OAuth failed (HTTP {$r['code']})" . ($r['error'] ? ': ' . $r['error'] : ''));
    }
    @file_put_contents($file, json_encode(['access_token' => $j['access_token'], 'expires_at' => time() + (int) ($j['expires_in'] ?? 3600)]));
    @chmod($file, 0600);
    return $j['access_token'];
}

/**
 * GET one path on oauth.reddit.com. Honors Reddit's rate-limit headers, stops for the rest of the run after any 429/403,
 * and refreshes an expired token once.
 */
function reddit_get(string $path): array
{
    static $stopped = null;
    if ($stopped !== null) {
        throw new RuntimeException($stopped);
    }
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $r = reddit_http('https://oauth.reddit.com' . $path, ['Authorization: bearer ' . reddit_token($attempt > 0)]);
        if ($r['code'] === 401 && $attempt === 0) {
            continue;
        }
        if ($r['code'] === 429 || $r['code'] === 403) {
            $stopped = "Reddit refused the request (HTTP {$r['code']}); stopping for this run";
            throw new RuntimeException($stopped);
        }
        if ($r['code'] !== 200) {
            throw new RuntimeException($r['code'] ? "Reddit HTTP {$r['code']}" : 'Reddit request failed: ' . $r['error']);
        }
        $remaining = isset($r['headers']['x-ratelimit-remaining']) ? (float) $r['headers']['x-ratelimit-remaining'] : null;
        $reset = (int) ($r['headers']['x-ratelimit-reset'] ?? 0);
        if ($remaining !== null && $remaining < 2 && $reset > 0) {
            sleep(min($reset, 20));
        }
        $j = json_decode($r['body'], true);
        if (!is_array($j)) {
            throw new RuntimeException('Reddit returned non-JSON');
        }
        return ['body' => $j, 'headers' => $r['headers']];
    }
    throw new RuntimeException('Reddit authentication failed after a token refresh');
}

/**
 * Reduce a listing (the JSON from /r/<sub>/hot) to the minimum: title, link, popularity counts, permalink, created time.
 * Stickied, NSFW and low-score posts are skipped. Deliberately absent: author, selftext, comments, flair, any user information.
 */
function reddit_minimal_records(array $listing, int $minScore = 20): array
{
    $out = [];
    foreach ($listing['data']['children'] ?? [] as $c) {
        $d = $c['data'] ?? [];
        if (!empty($d['stickied']) || !empty($d['over_18']) || empty($d['title']) || (int) ($d['score'] ?? 0) < $minScore) {
            continue;
        }
        $perma = 'https://www.reddit.com' . ($d['permalink'] ?? '');
        $out[] = [
            'title' => $d['title'],
            'url' => !empty($d['is_self']) ? $perma : ($d['url_overridden_by_dest'] ?? $d['url'] ?? $perma),
            'score' => (int) ($d['score'] ?? 0),
            'comments' => (int) ($d['num_comments'] ?? 0),
            'upvote_ratio' => $d['upvote_ratio'] ?? null,
            'permalink' => $perma,
            'created_utc' => gmdate('Y-m-d\TH:i:s\Z', (int) ($d['created_utc'] ?? time())),
        ];
    }
    return $out;
}

/** Fetch the hot listing of one subreddit and return minimal records. One request. */
function reddit_hot(string $subreddit, int $limit = 40): array
{
    if (!preg_match('/^[A-Za-z0-9_]{2,40}$/', $subreddit)) {
        throw new InvalidArgumentException('invalid subreddit name');
    }
    $r = reddit_get('/r/' . rawurlencode($subreddit) . '/hot?limit=' . max(1, min(100, $limit)) . '&raw_json=1');
    return reddit_minimal_records($r['body']);
}

/** The popularity signal the digest uses: bounded, so one viral post cannot outrank an event many outlets cover (max +60%). */
function popularity_boost(int $score, int $comments): float
{
    return 1 + min(0.6, (log10(1 + $score) + 0.5 * log10(1 + $comments)) / 8);
}
