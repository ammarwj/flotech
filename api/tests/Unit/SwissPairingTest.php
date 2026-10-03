<?php

namespace Tests\Unit;

use App\Support\SwissPairing;
use PHPUnit\Framework\TestCase;

class SwissPairingTest extends TestCase
{
    /** @param array<int, array{0: string, 1: string}> $pairs */
    private function keys(array $pairs): array
    {
        return array_map(fn ($p) => SwissPairing::pairKey($p[0], $p[1]), $pairs);
    }

    /** @param array<int, string> $keys */
    private function met(array $keys): array
    {
        return array_fill_keys($keys, true);
    }

    public function test_an_untouched_field_pairs_neighbours_in_standings_order(): void
    {
        $result = SwissPairing::pair(['a', 'b', 'c', 'd'], []);

        $this->assertSame(0, $result['rematches']);
        $this->assertSame([['a', 'b'], ['c', 'd']], $result['pairs']);
    }

    public function test_backtracking_beats_the_plain_fold_on_a_deadlocking_field(): void
    {
        // The greedy fold would pair a-b and c-d. Both have been played, so a
        // pairer that only scans forward either repeats them or gives up; the
        // valid answer (a-c, b-d) needs it to undo its first choice.
        $met = $this->met([
            SwissPairing::pairKey('a', 'b'),
            SwissPairing::pairKey('c', 'd'),
            SwissPairing::pairKey('a', 'd'),
            SwissPairing::pairKey('b', 'c'),
        ]);

        $fold = [];
        $order = ['a', 'b', 'c', 'd'];
        for ($i = 0; $i + 1 < count($order); $i += 2) {
            $fold[] = [$order[$i], $order[$i + 1]];
        }

        $result = SwissPairing::pair($order, $met);

        // Compared against what the fold would have produced: asserting only
        // "no rematches" would pass on a pairer that returned no pairs at all.
        $this->assertSame(0, $result['rematches']);
        $this->assertCount(2, $result['pairs']);
        $this->assertNotEquals($this->keys($fold), $this->keys($result['pairs']));
        $this->assertSame(
            [SwissPairing::pairKey('a', 'c'), SwissPairing::pairKey('b', 'd')],
            $this->keys($result['pairs']),
        );
    }

    public function test_an_exhausted_field_reports_rematches_rather_than_failing(): void
    {
        // Everyone has met everyone: there is no unmet pairing left to find.
        $keys = [];
        foreach (['a', 'b', 'c', 'd'] as $x) {
            foreach (['a', 'b', 'c', 'd'] as $y) {
                if ($x !== $y) {
                    $keys[] = SwissPairing::pairKey($x, $y);
                }
            }
        }

        $result = SwissPairing::pair(['a', 'b', 'c', 'd'], $this->met($keys));

        $this->assertCount(2, $result['pairs']);
        $this->assertSame(count($result['pairs']), $result['rematches']);
    }

    public function test_the_bye_goes_to_the_lowest_ranked_of_those_who_have_had_fewest(): void
    {
        $order = ['a', 'b', 'c', 'd', 'e'];

        // Nobody has sat out yet: the bye drops to the bottom of the table.
        $this->assertSame('e', SwissPairing::byeTeam($order, []));

        // Same order, same call — only the history differs. Comparing the two
        // is what proves the count is read at all: asserting one answer would
        // pass on an implementation that always returned the last entrant.
        $this->assertSame('d', SwissPairing::byeTeam($order, ['e' => 1]));
        $this->assertSame('a', SwissPairing::byeTeam($order, ['b' => 1, 'c' => 1, 'd' => 1, 'e' => 1]));
    }

    public function test_an_even_field_takes_no_bye(): void
    {
        $this->assertNull(SwissPairing::byeTeam(['a', 'b', 'c', 'd'], []));
    }

    public function test_the_side_with_fewer_home_fixtures_hosts(): void
    {
        // Level counts: the better-ranked entrant hosts.
        $this->assertSame(['a', 'b'], SwissPairing::sides('a', 'b', []));
        $this->assertSame(['a', 'b'], SwissPairing::sides('a', 'b', ['a' => 1, 'b' => 1]));

        // Same pair, same ranking — only the history differs.
        $this->assertSame(['b', 'a'], SwissPairing::sides('a', 'b', ['a' => 2, 'b' => 1]));
    }

    public function test_pair_key_is_order_free(): void
    {
        $this->assertSame(SwissPairing::pairKey('a', 'b'), SwissPairing::pairKey('b', 'a'));
    }
}
