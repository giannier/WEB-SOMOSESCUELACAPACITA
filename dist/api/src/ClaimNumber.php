<?php
/**
 * Consecutive claim numbering (Art. 5, D.S. 011-2011-PCM): no gaps, no
 * duplicates. The counter is protected by an exclusive lock.
 */

declare(strict_types=1);

final class ClaimNumber
{
    private string $file;

    public function __construct(string $storage)
    {
        if (!is_dir($storage)) {
            mkdir($storage, 0750, true);
        }
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
            $state = json_decode(stream_get_contents($fp) ?: '', true);
            $lastYear = is_array($state) ? (int) ($state['year'] ?? 0) : 0;
            $lastNum = is_array($state) ? (int) ($state['number'] ?? 0) : 0;
            $number = $lastYear === $year ? $lastNum + 1 : 1;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode(['year' => $year, 'number' => $number]));
            fflush($fp);

            return sprintf('LR-%d-%06d', $year, $number);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
