<?php
// Offline tests (no network, no credentials): php tests/test_minimal.php
require __DIR__ . '/../src/reddit.php';

$fail = 0;
function check(bool $ok, string $what): void
{
    global $fail;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $fail += $ok ? 0 : 1;
}

$listing = ['data' => ['children' => [
    ['data' => ['title' => 'Central bank holds rates', 'score' => 900, 'num_comments' => 120, 'upvote_ratio' => 0.94, 'permalink' => '/r/economics/comments/abc/x/',
                'url' => 'https://example.com/story', 'created_utc' => 1760000000, 'author' => 'someone', 'selftext' => 'body text', 'is_self' => false,
                'author_fullname' => 't2_abc', 'link_flair_text' => 'News']],
    ['data' => ['title' => 'Self post', 'score' => 200, 'num_comments' => 5, 'permalink' => '/r/science/comments/def/y/', 'url' => 'https://www.reddit.com/r/science/comments/def/y/', 'is_self' => true, 'author' => 'other']],
    ['data' => ['title' => 'Sticky thread', 'score' => 999, 'stickied' => true, 'permalink' => '/r/news/comments/s/']],
    ['data' => ['title' => 'NSFW', 'score' => 999, 'over_18' => true, 'permalink' => '/r/news/comments/n/']],
    ['data' => ['title' => 'Low score', 'score' => 3, 'permalink' => '/r/news/comments/l/']],
]]];
$rows = reddit_minimal_records($listing);
check(count($rows) === 2, 'stickied, NSFW and low-score posts are skipped');
check($rows[0]['url'] === 'https://example.com/story' && $rows[1]['url'] === 'https://www.reddit.com/r/science/comments/def/y/', 'link posts keep the article URL; self posts keep the permalink');
check(array_keys($rows[0]) === ['title', 'url', 'score', 'comments', 'upvote_ratio', 'permalink', 'created_utc'], 'only the minimal fields are kept');
$flat = json_encode($rows);
check(!str_contains($flat, 'someone') && !str_contains($flat, 'other') && !str_contains($flat, 'body text') && !str_contains($flat, 't2_abc') && !str_contains($flat, 'News'),
    'no username, post body, user id or flair appears in the output');
check(popularity_boost(0, 0) === 1.0 && popularity_boost(10_000_000, 1_000_000) <= 1.6 + 1e-9, 'popularity boost is bounded at +60%');
$threw = false;
try {
    reddit_hot('bad name/../x');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
check($threw, 'invalid subreddit names are rejected before any request');
check(str_contains(reddit_user_agent(), 'by /u/'), 'User-Agent follows Reddit\'s format');
exit($fail ? 1 : 0);
