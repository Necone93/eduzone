<?php
// Sedam punih dana od predaje; nepoznato vreme ne otključava istoriju.
const TEST_HISTORY_DELAY = 7 * 24 * 60 * 60;

function test_history_visible(?int $submittedAt, ?int $now = null): bool {
    return $submittedAt !== null && ($now ?? time()) >= $submittedAt + TEST_HISTORY_DELAY;
}

function test_result_key(int $testId, int $attempt, ?string $assignKey): string {
    return $testId . ':' . $attempt . ':' . ($assignKey ?? '');
}

function test_details_visible(?int $submittedAt, ?int $grantedAt, ?int $now = null): bool {
    $now = $now ?? time();
    $hasGrant = $grantedAt !== null && $grantedAt <= $now && $now - $grantedAt <= 30 * 60;
    return $hasGrant || test_history_visible($submittedAt, $now);
}
