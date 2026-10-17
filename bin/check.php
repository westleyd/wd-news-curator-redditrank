<?php
// Verify credentials and the read path end to end (3 requests). Usage:
//   REDDIT_CLIENT_ID=... REDDIT_CLIENT_SECRET=... REDDIT_USERNAME=... php bin/check.php [subreddit ...]
require __DIR__ . '/../src/reddit.php';

if (!reddit_configured()) {
    fwrite(STDERR, "Set REDDIT_CLIENT_ID and REDDIT_CLIENT_SECRET first.\n");
    exit(1);
}
echo 'User-Agent: ' . reddit_user_agent() . "\n";
try {
    reddit_token(true);
    echo "OAuth token: ok (app-only, read access)\n";
    foreach (array_slice($argv, 1) ?: ['worldnews'] as $sub) {
        $records = reddit_hot($sub, 5);
        echo "r/$sub: " . count($records) . " posts kept; fields per post: " . implode(', ', array_keys($records[0] ?? [])) . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
