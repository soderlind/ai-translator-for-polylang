<?php
/**
 * Tests for JobState and TranslationJob::state().
 *
 * @package AiTranslatorForPolylang\Tests
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Tests;

use AiTranslatorForPolylang\Translation\JobState;
use AiTranslatorForPolylang\Translation\TranslationJob;
use AiTranslatorForPolylang\Translation\Translator;
use Brain\Monkey\Functions;

final class JobStateTest extends TestCase {

	public function test_only_failed_or_stale_unfinished_jobs_can_be_retried(): void {
		$now   = 1_000_000;
		$fresh = $now - JobState::STALE_AFTER;
		$old   = $now - JobState::STALE_AFTER - 1;

		$this->assertFalse( $this->state( JobState::PROCESSING, $fresh )->is_stale( $now ) );
		$this->assertTrue( $this->state( JobState::PROCESSING, $old )->is_stale( $now ) );
		$this->assertTrue( $this->state( JobState::PENDING, $old )->is_stale( $now ) );
		$this->assertFalse( $this->state( JobState::DONE, $old )->is_stale( $now ), 'A finished job is never stale.' );
		$this->assertFalse( $this->state( JobState::PROCESSING, 0 )->is_stale( $now ), 'No heartbeat recorded yet.' );

		$this->assertTrue( $this->state( JobState::FAILED, time() )->can_retry() );
		$this->assertFalse( $this->state( JobState::PROCESSING, time() )->can_retry() );
		$this->assertTrue( $this->state( JobState::PROCESSING, 1 )->can_retry() );
		$this->assertFalse( $this->state( JobState::DONE, 1 )->can_retry() );
	}

	public function test_job_reads_its_state_from_post_meta(): void {
		$meta = [
			7 => [
				TranslationJob::META_STATUS   => 'failed',
				TranslationJob::META_LANGUAGE => [ 'code' => 'de', 'name' => 'Deutsch', 'locale' => 'de_DE' ],
				TranslationJob::META_ERROR    => 'Timed out.',
				TranslationJob::META_UPDATED  => '1700000000',
			],
			8 => [ TranslationJob::META_STATUS => 'bogus' ],
		];
		Functions\when( 'get_post_meta' )->alias( static fn( $id, $key ) => $meta[ $id ][ $key ] ?? '' );
		$generator = new FakeGenerator();
		$job       = new TranslationJob( new Translator( $generator ), $generator );

		$state = $job->state( 7 );

		$this->assertSame( [ 'failed', 'Deutsch', 'Timed out.', 1700000000 ], [ $state->status, $state->language_name(), $state->error, $state->updated ] );
		$this->assertTrue( $state->is_finished() );
		$this->assertNull( $job->state( 8 ) );
		$this->assertNull( $job->state( 9 ) );
	}

	private function state( string $status, int $updated ): JobState {
		return new JobState( $status, [], '', $updated );
	}
}
