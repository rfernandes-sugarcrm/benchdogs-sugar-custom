<?php

/**
 * Rolls a quote's line items up into the two numbers an opportunity actually needs: what the customer has committed to, and what is still winnable.
 */
class ErpQuoteLineRollup
{
    /** Every line stands alone: open = every unordered line. */
    public const POLICY_SUM = 'sum';

    /** Lines sharing a group are alternatives; count the largest still open. */
    public const POLICY_MAX = 'max';

    /** Lines sharing a group are alternatives; count the smallest still open. */
    public const POLICY_MIN = 'min';

    public const POLICIES = [self::POLICY_SUM, self::POLICY_MAX, self::POLICY_MIN];

    /**
     * 'price' => float, 'ordered' => bool, 'governing' => bool, 'origin' => string]. 'governing' and 'origin' are both optional, and their absent values.
     * @param array  $lines
     * @param string $policy
     * @return array{ordered:
     */
    public static function compute(array $lines, string $policy = self::POLICY_SUM): array
    {
        if (!in_array($policy, self::POLICIES, true)) {
            $policy = self::POLICY_SUM;
        }

        $ordered = 0.0;
        $openFlat = 0.0;
        // Two partitions of the same lines, and the reason is a real conflict between two rules, not fastidiousness. $groups is the legacy partition.
        $groups = [];
        $pinGroups = [];
        $anyPin = false;

        foreach ($lines as $i => $line) {
            $value = self::lineValue($line);
            $key = self::groupKey($line, $i);
            $pinKey = self::pinGroupKey($line, $i);

            if (!isset($groups[$key])) {
                $groups[$key] = self::emptyGroup();
            }
            if (!isset($pinGroups[$pinKey])) {
                $pinGroups[$pinKey] = self::emptyGroup();
            }

            // Recorded before the ordered short-circuit below, which is the whole point.
            if (!empty($line['governing'])) {
                $pinGroups[$pinKey]['pinned'] = true;
                $anyPin = true;
            }

            if (!empty($line['ordered'])) {
                $ordered += $value;
                $groups[$key]['settled'] = true;
                $pinGroups[$pinKey]['settled'] = true;
                continue;
            }

            $openFlat += $value;
            $groups[$key]['values'][] = $value;
            $pinGroups[$pinKey]['values'][] = $value;

            // Only an open pin has an open value to contribute.
            if (!empty($line['governing'])) {
                $pinGroups[$pinKey]['pinnedOpen'][] = $value;
            }
        }

        if (!$anyPin) {
            // Nothing is pinned anywhere - and this block is the original code, unchanged, reached by every tenant that never writes erp_governing.
            if ($policy === self::POLICY_SUM) {
                $open = $openFlat;
            } else {
                $open = 0.0;
                foreach ($groups as $group) {
                    // A group with an ordered line is decided.
                    if ($group['settled'] || $group['values'] === []) {
                        continue;
                    }
                    $open += $policy === self::POLICY_MAX
                        ? max($group['values'])
                        : min($group['values']);
                }
            }
        } else {
            // At least one pin exists.
            $open = 0.0;
            foreach ($pinGroups as $group) {
                if ($group['pinned']) {
                    // A fact beats a pin.
                    $open += $group['settled'] ? 0.0 : array_sum($group['pinnedOpen']);
                    continue;
                }
                $open += self::openByPolicy($group, $policy);
            }
        }

        $ordered = round($ordered, 2);
        $open = round($open, 2);

        return [
            'ordered' => $ordered,
            'open' => $open,
            'total' => round($ordered + $open, 2),
        ];
    }

    /** What one line is worth: quantity x price, less the concession the seller gave on it. (G89, decision 59) */
    private static function lineValue(array $line): float
    {
        $gross = (float) ($line['quantity'] ?? 0) * (float) ($line['price'] ?? 0);
        $discount = $line['discount'] ?? null;

        return is_numeric($discount) ? $gross - (float) $discount : $gross;
    }

    /**
     * One unpinned group's open value under the configured policy - today's rule, per group.
     * @param array{values:
     */
    private static function openByPolicy(array $group, string $policy): float
    {
        if ($policy === self::POLICY_SUM) {
            return (float) array_sum($group['values']);
        }

        if ($group['settled'] || $group['values'] === []) {
            return 0.0;
        }

        return (float) ($policy === self::POLICY_MAX
            ? max($group['values'])
            : min($group['values']));
    }

    /**
     * A line with no group identifier is ungroupable, not a member of one big blank group.
     * @param int|string $index
     */
    private static function groupKey(array $line, $index): string
    {
        $group = trim((string) ($line['group'] ?? ''));

        return $group === '' ? '#ungrouped-' . $index : 'g:' . $group;
    }

    /**
     * The group key the pin path uses: groupKey(), case-folded.
     * @param int|string $index
     */
    private static function pinGroupKey(array $line, $index): string
    {
        $group = trim((string) ($line['group'] ?? ''));

        if ($group === '') {
            return '#ungrouped-' . $index;
        }

        $folded = function_exists('mb_strtoupper')
            ? mb_strtoupper($group, 'UTF-8')
            : strtoupper($group);

        // Origin is part of the key.
        $origin = trim((string) ($line['origin'] ?? ''));

        return 'g:' . $origin . '|' . $folded;
    }

    /** @return array{values: */
    private static function emptyGroup(): array
    {
        return ['values' => [], 'settled' => false, 'pinned' => false, 'pinnedOpen' => []];
    }
}
