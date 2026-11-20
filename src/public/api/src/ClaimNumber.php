<?php
/**
 * Consecutive claim numbering (Art. 5, D.S. 011-2011-PCM): no gaps, no
 * duplicates. The counter is protected by an exclusive lock.
 *
 * Fail-closed: if the counter cannot be read or written reliably, no number is
 * issued (an exception is thrown). A missing or corrupt counter is rebuilt from
 * the highest archived sheet of the current year, never silently restarted at 1.
 */

declare(strict_types=1);

final class ClaimNumber
{
    private string $file;
    private string $storage;

    public function __construct(string $storage)
    {
        if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) {
            throw new RuntimeException('Cannot create the storage directory.');
        }
        $this->storage = $storage;
        $this->file = $storage . '/counter.dat';
    }

    /** Next number, persisted. Format: LR-2026-000001 (series restarts every year). */
    public function next(): string
    {
        $year = (int) date('Y');
        $fp = fopen($this->file, 'c+');
        if ($fp === false) {
            throw new RuntimeException('Cannot open the claim counter.');
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException('Cannot lock the claim counter.');
            }
            $raw = stream_get_contents($fp);
            if ($raw === false) {
                throw new RuntimeException('Cannot read the claim counter.');
            }
            $state = json_decode($raw, true);
            $valid = is_array($state) && isset($state['year'], $state['number'])
                && is_int($state['year']) && is_int($state['number']) && $state['number'] >= 0;

            if ($valid && $state['year'] === $year) {
                $last = $state['number'];
            } elseif ($valid && $state['year'] < $year) {
                $last = 0; // legitimate yearly reset
            } else {
                // Missing, empty, unparsable or future-year counter: recover from the archive.
                $last = 0;
            }
            // Never go below what is already archived (also covers a stale counter).
            $number = max($last, $this->highestArchived($year)) + 1;

            $payload = json_encode(['year' => $year, 'number' => $number]);
            if ($payload === false || !ftruncate($fp, 0) || !rewind($fp)) {
                throw new RuntimeException('Cannot reset the claim counter.');
            }
            $written = fwrite($fp, $payload);
            if ($written !== strlen($payload) || !fflush($fp)) {
                throw new RuntimeException('Cannot persist the claim counter (short write).');
            }

            return sprintf('LR-%d-%06d', $year, $number);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /** Highest number archived for the year (storage/hojas/<year>/LR-<year>-NNNNNN.pdf|json). */
    private function highestArchived(int $year): int
    {
        $dir = $this->storage . '/hojas/' . $year;
        if (!is_dir($dir)) {
            return 0;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            throw new RuntimeException('Cannot scan the archive directory.');
        }
        $max = 0;
        foreach ($entries as $name) {
            if (preg_match('/^LR-' . $year . '-(\d{6,})\.(?:pdf|json)$/', $name, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return $max;
    }
}
