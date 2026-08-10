<?php
/**
 * Tests for AKAI_Queue's pure scheduling and retry policy.
 *
 * @package autokeywordsai
 */

require_once dirname( __DIR__ ) . '/includes/class-akai-queue.php';

// --- Stagger maths ----------------------------------------------------------
// At 10 rpm the spacing is 6 seconds, so the Nth product runs 6N seconds out.
akai_assert_same( 'the first product runs immediately', 1000, AKAI_Queue::stagger_timestamp( 0, 10, 1000 ) );
akai_assert_same( 'the second product is spaced by 6s at 10rpm', 1006, AKAI_Queue::stagger_timestamp( 1, 10, 1000 ) );
akai_assert_same( 'the tenth product lands at +54s at 10rpm', 1054, AKAI_Queue::stagger_timestamp( 9, 10, 1000 ) );
akai_assert_same( 'at 60rpm the spacing is 1s', 1005, AKAI_Queue::stagger_timestamp( 5, 60, 1000 ) );
akai_assert_same( 'at 1rpm the spacing is 60s', 1120, AKAI_Queue::stagger_timestamp( 2, 1, 1000 ) );
// A nonsense rpm must not divide by zero or schedule everything at once.
akai_assert_same( 'rpm of 0 is treated as 1', 1060, AKAI_Queue::stagger_timestamp( 1, 0, 1000 ) );
// Above 60rpm the spacing floors at 1s rather than collapsing to 0.
akai_assert_same( 'spacing never drops below 1s', 1003, AKAI_Queue::stagger_timestamp( 3, 600, 1000 ) );

// --- Retry policy -----------------------------------------------------------
$limited = AKAI_Queue::decide_retry( 'akai_rate_limited', 1 );
akai_assert_same( 'a rate limit retries', 'retry', $limited['action'] );
akai_assert_same( 'a rate limit waits RATE_LIMIT_DELAY', AKAI_Queue::RATE_LIMIT_DELAY, $limited['delay'] );
akai_assert_same( 'a rate limit does not consume an attempt', 1, $limited['attempt'] );

// Rate limiting is expected on free tiers, so it must never exhaust the attempt budget.
$limited_late = AKAI_Queue::decide_retry( 'akai_rate_limited', 9 );
akai_assert_same( 'a rate limit still retries after many attempts', 'retry', $limited_late['action'] );

$http_first = AKAI_Queue::decide_retry( 'akai_http_error', 1 );
akai_assert_same( 'a first http error retries', 'retry', $http_first['action'] );
akai_assert_same( 'a retried http error waits RETRY_DELAY', AKAI_Queue::RETRY_DELAY, $http_first['delay'] );
akai_assert_same( 'a retried http error consumes an attempt', 2, $http_first['attempt'] );

$http_last = AKAI_Queue::decide_retry( 'akai_http_error', AKAI_Queue::MAX_ATTEMPTS );
akai_assert_same( 'http errors give up at MAX_ATTEMPTS', 'give_up', $http_last['action'] );

// A malformed response is deterministic — retrying it just burns quota.
akai_assert_same( 'a bad response gives up immediately', 'give_up', AKAI_Queue::decide_retry( 'akai_bad_response', 1 )['action'] );
akai_assert_same( 'a missing key gives up immediately', 'give_up', AKAI_Queue::decide_retry( 'akai_no_key', 1 )['action'] );
akai_assert_same( 'an unknown code gives up', 'give_up', AKAI_Queue::decide_retry( 'something_else', 1 )['action'] );
