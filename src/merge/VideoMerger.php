<?php

namespace Simp\VideoPhp\merge;

use Simp\VideoPhp\binaries\Binary;

class VideoMerger
{
    protected Binary $binary;

    public function __construct(Binary $binary)
    {
        $this->binary = $binary;
    }

    /**
     * Merge multiple videos into a single video.
     *
     * @param array $videoFiles Array of video file paths
     * @param string $outputPath Output video path
     * @param bool $reencode Set to true to re-encode if formats differ
     * @param callable|null $progressCallback Receives progress float 0-100
     * @return void
     */
    public function merge(array $videoFiles, string $outputPath, bool $reencode = false, callable $progressCallback = null): void
    {
        if (empty($videoFiles)) {
            throw new \InvalidArgumentException("No video files provided for merging.");
        }

        foreach ($videoFiles as $file) {
            if (!file_exists($file)) {
                throw new \RuntimeException("Video file does not exist: {$file}");
            }
        }

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            throw new \RuntimeException("Failed to create output directory: {$outputDir}");
        }

        $ffmpeg = $this->binary->getFFmpeg();

        if (!$reencode) {
            // Use FFmpeg concat demuxer (fast, requires same codec & format)
            $tmpListFile = tempnam(sys_get_temp_dir(), 'ffmpeg_merge_');
            $listContent = '';
            foreach ($videoFiles as $file) {
                $listContent .= "file '" . addslashes(realpath($file)) . "'\n";
            }
            file_put_contents($tmpListFile, $listContent);

            $cmd = escapeshellcmd($ffmpeg) . " -f concat -safe 0 -i " . escapeshellarg($tmpListFile) .
                " -c copy " . escapeshellarg($outputPath) . " 2>&1";

            $this->runProcess($cmd, $progressCallback);

            unlink($tmpListFile);
        } else {
            // Re-encode all videos into same format/codecs
            $inputStr = '';
            $filterComplex = '';
            $i = 0;
            foreach ($videoFiles as $file) {
                $inputStr .= " -i " . escapeshellarg($file);
                $filterComplex .= "[$i:v:0][$i:a:0]";
                $i++;
            }
            $filterComplex .= "concat=n={$i}:v=1:a=1[outv][outa]";

            $cmd = escapeshellcmd($ffmpeg) . $inputStr .
                " -filter_complex " . escapeshellarg($filterComplex) .
                " -map [outv] -map [outa] " . escapeshellarg($outputPath) . " 2>&1";

            $this->runProcess($cmd, $progressCallback);
        }
    }

    /**
     * Run a shell process safely with optional progress callback
     */
    protected function runProcess(string $cmd, callable $progressCallback = null): void
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to start process: {$cmd}");
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $numChanged = stream_select($read, $write, $except, 0, 200000);

            if ($numChanged === false) break;

            foreach ($read as $r) {
                $line = stream_get_contents($r);
                // Optional: parse duration/progress if needed
                if ($progressCallback && preg_match_all('/time=(\d+):(\d+):(\d+\.?\d*)/', $line, $matches, PREG_SET_ORDER)) {
                    $last = end($matches);
                    $hours = (float)$last[1];
                    $minutes = (float)$last[2];
                    $seconds = (float)$last[3];
                    $currentTime = $hours * 3600 + $minutes * 60 + $seconds;
                    // Progress estimation is rough for re-encode
                    $progressCallback(min($currentTime / 1 * 100, 100));
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) break;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnVar = proc_close($process);

        if ($returnVar !== 0) {
            throw new \RuntimeException("FFmpeg merge process failed with exit code {$returnVar}: {$cmd}");
        }
    }
}
