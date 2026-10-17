# wd-news-curator-redditrank

The Reddit-facing part of a personal news-curation tool, published so Reddit can review exactly what it requests and keeps.

**Short version:** a single-user, non-commercial tool reads the public "hot" listing of eight subreddits a few times a day, keeps only each
post's title, link and popularity counts, and uses those counts as one small signal for how widely a news story is being discussed. It is
read-only, stores no usernames or post text, and deletes Reddit-derived data after 30 days.

Public description page: https://sdhome.greenwestllc.com/wd-news/info

## What it requests

| Item | Detail |
|---|---|
| Authentication | App-only OAuth (`client_credentials`). No user login, no user context, no write scopes. |
| Endpoint | `GET https://oauth.reddit.com/r/<subreddit>/hot?limit=40&raw_json=1` and the token endpoint. Nothing else. |
| Subreddits | r/worldnews, r/politics, r/news, r/science, r/technology, r/MachineLearning, r/LocalLLaMA, r/economics |
| Volume | 8 listing requests per collection run, 9 runs a day: about 72 requests a day (roughly 3 an hour), far below Reddit's rate limits. |
| Rate limits | Reads `X-Ratelimit-*` headers and waits when the remaining budget is low; after any HTTP 429 or 403 it stops for the rest of the run. |
| User-Agent | `linux:wd-news-curator-redditrank:0.1 (by /u/<owner>)` |

It never posts, votes, comments, sends messages, moderates, or reads comments.

## What it keeps

Per post: title, link, score, comment count, upvote ratio, permalink, creation time (`src/reddit.php`, `reddit_minimal_records`).
It does **not** read or keep authors, usernames, user IDs, post bodies, comments or flair; `tests/test_minimal.php` checks that none of those
can reach the output. Stickied and NSFW posts and posts under 20 points are skipped.

**Retention:** Reddit-derived rows are deleted after 30 days, or, if one reached a digest or received feedback, reduced to a placeholder with
the title replaced and every Reddit field cleared (`src/retention.php`, run weekly).

## How the data is used

The score and comment count feed a bounded popularity boost (at most +60%, `popularity_boost`) in ranking news events for one person's
private digest. Coverage breadth across many non-Reddit news outlets counts for far more, so no single post can dominate. The digest links to
the original publishers' articles, not to Reddit. Reddit data is not shared, sold, published, used to train or fine-tune any model, or used to
build a dataset.

## Run it

```
REDDIT_CLIENT_ID=... REDDIT_CLIENT_SECRET=... REDDIT_USERNAME=<owner> php bin/check.php worldnews   # 3 requests
php tests/test_minimal.php                                                                         # offline
```

Requires PHP 8.1+ with curl. MIT licensed.
