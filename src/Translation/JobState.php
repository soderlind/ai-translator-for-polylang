<?php
/**
 * Read-only snapshot of a translation job.
 *
 * @package AiTranslatorForPolylang
 */

declare(strict_types=1);

namespace AiTranslatorForPolylang\Translation;

/**
 * What is known about a translation draft's job, and what that means.
 */
final class JobState {

	public const PENDING    = 'pending';
	public const PROCESSING = 'processing';
	public const DONE       = 'done';
	public const FAILED     = 'failed';

	/**
	 * Seconds without a heartbeat after which an unfinished job is considered dead.
	 *
	 * A heartbeat is written before every AI request, so a live job is only this quiet
	 * if a single request hangs far beyond the request timeout.
	 */
	public const STALE_AFTER = 15 * 60;

	/**
	 * Constructor.
	 *
	 * @param string               $status   One of the status constants.
	 * @param array<string,string> $language Target language ( code, name, locale ).
	 * @param string               $error    Error message of a failed job.
	 * @param int                  $updated  Unix time of the last status change or heartbeat, 0 if unknown.
	 */
	public function __construct(
		public readonly string $status,
		public readonly array $language,
		public readonly string $error,
		public readonly int $updated
	) {}

	/**
	 * Name of the target language, e.g. "Norsk bokmål".
	 *
	 * @return string
	 */
	public function language_name(): string {
		return (string) ( $this->language['name'] ?? '' );
	}

	/**
	 * Whether the job is waiting or being translated.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return self::PENDING === $this->status || self::PROCESSING === $this->status;
	}

	/**
	 * Whether an active job has had no heartbeat for so long that it is presumed dead.
	 *
	 * @param int|null $now Unix time to compare with. Defaults to now.
	 * @return bool
	 */
	public function is_stale( ?int $now = null ): bool {
		return $this->is_active() && $this->updated > 0 && ( $now ?? time() ) - $this->updated > self::STALE_AFTER;
	}

	/**
	 * Whether the job has ended, successfully or not.
	 *
	 * @return bool
	 */
	public function is_finished(): bool {
		return self::DONE === $this->status || self::FAILED === $this->status;
	}

	/**
	 * Whether the job may be queued again: it failed, or it is stale.
	 *
	 * @return bool
	 */
	public function can_retry(): bool {
		return self::FAILED === $this->status || $this->is_stale();
	}
}
