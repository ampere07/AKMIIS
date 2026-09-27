<?php

namespace Tests\Unit;

use App\Support\PulloutCategory;
use PHPUnit\Framework\TestCase;

/**
 * When a service order pullout closes the customer's portal login.
 *
 * `users.active` is the column the sign-in gates on, and a service order saved
 * with a pullout repair category and the visit marked Done is what reaches
 * attemptPullout() and sets it to 0.
 *
 * The rule is called here directly, because it lives in App\Support\
 * PulloutCategory rather than inline in a controller. Both service order
 * controllers are also read to confirm they still ask PulloutCategory rather
 * than having grown their own copy of the rule again — that is what stops this
 * file passing while the real gate drifts.
 *
 * Ported from ATSS's PortalLoginLifecycleTest, without its job-order approval
 * half: AKMIIS does not re-enable an existing login on approval.
 *
 * No database: nothing is written, read or migrated.
 */
class PulloutCategoryTest extends TestCase
{
    private const API_CONTROLLER = __DIR__ . '/../../app/Http/Controllers/Api/ServiceOrderApiController.php';
    private const WEB_CONTROLLER = __DIR__ . '/../../app/Http/Controllers/ServiceOrderController.php';

    // ── the rule itself ──────────────────────────────────────────────────

    /**
     * @dataProvider pulloutSpellings
     */
    public function test_any_spelling_of_pullout_closes_a_done_visit(string $category): void
    {
        $this->assertTrue(
            PulloutCategory::deactivatesPortalLogin($category, 'Done'),
            sprintf('"%s" is a pullout and did not close the login', $category)
        );
    }

    public static function pulloutSpellings(): array
    {
        return [
            'one word'            => ['Pullout'],
            'two words'           => ['Pull Out'],
            'lower case'          => ['pullout'],
            'lower, spaced'       => ['pull out'],
            'shouted'             => ['PULLOUT'],
            'shouted and spaced'  => ['PULL OUT'],
            'for, one word'       => ['For Pullout'],
            'for, two words'      => ['For Pull Out'],
            'for, lower'          => ['for pullout'],
            'for, lower spaced'   => ['for pull out'],
            'padded'              => ['   Pullout   '],
            'doubled spacing'     => ['for  pull  out'],
            'tabbed'              => ["for\tpull\tout"],
        ];
    }

    /**
     * @dataProvider unfinishedVisits
     */
    public function test_a_pullout_that_is_not_done_leaves_the_login_open(?string $visitStatus): void
    {
        $this->assertFalse(PulloutCategory::deactivatesPortalLogin('Pullout', $visitStatus));
    }

    public static function unfinishedVisits(): array
    {
        return [
            'in progress' => ['In Progress'],
            'reschedule'  => ['Reschedule'],
            'failed'      => ['Failed'],
            'nothing yet' => [''],
            'never set'   => [null],
            // "Done" inside another word must not satisfy it.
            'not done'    => ['Not Done'],
        ];
    }

    public function test_a_done_visit_is_recognised_however_it_is_spelled(): void
    {
        $this->assertTrue(PulloutCategory::deactivatesPortalLogin('Pullout', 'done'));
        $this->assertTrue(PulloutCategory::deactivatesPortalLogin('Pullout', 'DONE'));
        $this->assertTrue(PulloutCategory::deactivatesPortalLogin('Pullout', '  Done  '));
    }

    /**
     * @dataProvider otherCategories
     */
    public function test_no_other_repair_category_closes_the_login(?string $category): void
    {
        $this->assertFalse(
            PulloutCategory::deactivatesPortalLogin($category, 'Done'),
            sprintf('"%s" closed the portal login and only a pullout may', (string) $category)
        );
    }

    public static function otherCategories(): array
    {
        // Every option the two edit modals offer, plus the empty cases.
        return [
            ['None'], ['Fiber Relaying'], ['Migrate'], ['others'],
            ['Reactivate'], ['Reactivation'], ['Reboot/Reconfig Router'],
            ['Relocate Router'], ['Relocate'], ['Replace Patch Cord'],
            ['Replace Router'], ['Resplice'], ['Transfer LCP/NAP/PORT'],
            ['Update Vlan'], [''], [null],
            // Near misses that are not the instruction.
            ['Pulled Out'], ['Pullout Request'], ['No Pullout'],
        ];
    }

    public function test_the_concern_column_is_not_part_of_the_rule(): void
    {
        // A ticket raised by AutoDisconnectService carries concern 'for pullout'
        // and no category. Closing it as something else must leave the login
        // alone — the category is the only thing consulted.
        $this->assertFalse(PulloutCategory::deactivatesPortalLogin('Reboot/Reconfig Router', 'Done'));
        $this->assertFalse(PulloutCategory::deactivatesPortalLogin('', 'Done'));
    }

    // ── the shipped controllers still ask that rule ──────────────────────

    /**
     * @dataProvider serviceOrderControllers
     */
    public function test_the_trigger_asks_pullout_category(string $path): void
    {
        $src = file_get_contents($path);

        $this->assertStringContainsString(
            '$isPulloutVisitDone = \App\Support\PulloutCategory::deactivatesPortalLogin(',
            $src,
            'the trigger no longer asks PulloutCategory'
        );
        $this->assertStringNotContainsString(
            '$pulloutConcern',
            $src,
            'the concern column is being read into the pullout decision again'
        );
        $this->assertStringNotContainsString(
            '$pulloutCategories',
            $src,
            'a local copy of the category list has come back'
        );
    }

    /**
     * @dataProvider serviceOrderControllers
     */
    public function test_the_re_entry_guard_asks_the_same_rule(string $path): void
    {
        // The guard stops a re-save running the pullout twice. Asking a broader
        // question than the trigger would suppress pullouts that have not
        // happened yet; asking a narrower one would run them twice.
        $this->assertStringContainsString(
            '$isAlreadyPulloutDone = \App\Support\PulloutCategory::deactivatesPortalLogin(',
            file_get_contents($path),
            'the re-entry guard no longer asks PulloutCategory'
        );
    }

    /**
     * @dataProvider serviceOrderControllers
     */
    public function test_the_decision_is_read_back_off_the_saved_row(string $path): void
    {
        // Not from the request: a request that merely claims Done must not
        // disable a login the row never recorded as pulled out.
        $this->assertStringContainsString(
            "\$pulloutRow = DB::table('service_orders')->where('id', \$id)->first();",
            file_get_contents($path),
            'the pullout decision is no longer taken from the saved row'
        );
    }

    /**
     * @dataProvider serviceOrderControllers
     */
    public function test_deactivation_happens_behind_that_gate_and_nowhere_else(string $path): void
    {
        $src = file_get_contents($path);

        $this->assertSame(
            1,
            substr_count($src, "update(['active' => 0])"),
            'a second path in this controller disables the portal login'
        );
        $this->assertSame(
            1,
            substr_count($src, '$this->attemptPullout('),
            'attemptPullout is reached from more than one place'
        );
    }

    public static function serviceOrderControllers(): array
    {
        return [
            'mobile API' => [self::API_CONTROLLER],
            'web'        => [self::WEB_CONTROLLER],
        ];
    }

    // ── re-saving ────────────────────────────────────────────────────────

    public function test_re_saving_a_finished_pullout_does_not_run_it_again(): void
    {
        // Nothing about the category or the visit changed, so the row looked
        // pulled-out before the write and still does. Guard and trigger agree,
        // and agreeing is what makes it skip.
        $before = PulloutCategory::deactivatesPortalLogin('Pullout', 'Done');
        $after  = PulloutCategory::deactivatesPortalLogin('Pullout', 'Done');

        $this->assertTrue($before, 'the guard did not see the finished pullout');
        $this->assertTrue($after);
    }

    public function test_naming_a_finished_visit_a_pullout_still_fires(): void
    {
        // An auto-raised ticket: concern 'for pullout', no category, visit
        // already Done. The technician now names it a pullout.
        $before = PulloutCategory::deactivatesPortalLogin('', 'Done');
        $after  = PulloutCategory::deactivatesPortalLogin('Pull Out', 'Done');

        $this->assertFalse($before, 'the guard treated a non-pullout as already done');
        $this->assertTrue($after, 'naming it a pullout did not fire the pullout');
    }
}
