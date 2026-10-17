<?php
// 30-day retention for Reddit-derived rows, as run weekly in the full application (SQLite). Shown here so the behaviour can be reviewed.
// The application stores each Reddit post as a "story" row (title, link, counts). After the retention period:
//   - rows that never reached a digest or a piece of feedback are deleted;
//   - rows that did are kept only as a placeholder: the title is replaced, the link removed, and every Reddit-derived field cleared.

function purge_reddit_data(PDO $pdo, int $days = 30): array
{
    $cut = gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);
    $pdo->beginTransaction();
    $del = $pdo->prepare("DELETE FROM stories WHERE fetched_at < ? AND source_id IN (SELECT id FROM sources WHERE kind='reddit')
                          AND NOT EXISTS (SELECT 1 FROM digest_items d WHERE d.story_id=stories.id)
                          AND NOT EXISTS (SELECT 1 FROM feedback f WHERE f.story_id=stories.id)");
    $del->execute([$cut]);
    $scrub = $pdo->prepare("UPDATE stories SET title='[removed after retention period]', title_norm='', summary=NULL, url=url_hash,
                              embedding=NULL, byline=NULL, submitter=NULL
                            WHERE fetched_at < ? AND source_id IN (SELECT id FROM sources WHERE kind='reddit') AND title <> '[removed after retention period]'");
    $scrub->execute([$cut]);
    $clear = $pdo->prepare("UPDATE stories SET reddit_score=0, reddit_comments=0, reddit_permalink=NULL, upvote_ratio=NULL
                            WHERE fetched_at < ? AND (reddit_score <> 0 OR reddit_comments <> 0 OR reddit_permalink IS NOT NULL OR upvote_ratio IS NOT NULL)");
    $clear->execute([$cut]);
    $pdo->commit();
    return ['deleted' => $del->rowCount(), 'scrubbed' => $scrub->rowCount(), 'cleared' => $clear->rowCount()];
}
