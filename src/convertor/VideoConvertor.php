<?php

namespace Simp\VideoPhp\convertor;

use Simp\VideoPhp\binaries\Binary;

class VideoConvertor
{
    protected Binary $binary;

    public function __construct(Binary $binary)
    {
        $this->binary = $binary;
    }

    /**
     * Convert a video to a different format
     *
     * @param string $inputPath Path to the input video
     * @param string $outputPath Path to the output video
     * @param string|null $videoCodec e.g., libx264, libx265
     * @param string|null $audioCodec e.g., aac, mp3
     * @param callable|null $progressCallback Receives progress float 0-100
     * @return void
     */
    public function convert(string $inputPath, string $outputPath, ?string $videoCodec = null, ?string $audioCodec = null, callable $progressCallback = null): void
    {
        if (!file_exists($inputPath)) {
            throw new \RuntimeException("Input video does not exist: {$inputPath}");
        }

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            throw new \RuntimeException("Failed to create output directory: {$outputDir}");
        }

        $ffmpeg = $this->binary->getFFmpeg();

        $cmd = escapeshellcmd($ffmpeg) . " -i " . escapeshellarg($inputPath);

        if ($videoCodec) {
            $cmd .= " -c:v {$videoCodec}";
        }

        if ($audioCodec) {
            $cmd .= " -c:a {$audioCodec}";
        }

        $cmd .= " " . escapeshellarg($outputPath) . " 2>&1";

        $duration = $this->getVideoDuration($inputPath);

        $this->runProcess($cmd, $duration, $progressCallback);
    }

    /**
     * Get video duration in seconds using ffprobe
     */
    protected function getVideoDuration(string $inputPath): float
    {
        $ffprobe = $this->binary->getFFprobe();
        $cmd = escapeshellcmd($ffprobe) . " -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputPath);
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0 || empty($output)) {
            throw new \RuntimeException("FFprobe failed to get video duration. Command: {$cmd}");
        }

        return (float) $output[0];
    }

    /**
     * Run a command using proc_open with optional progress callback
     */
    protected function runProcess(string $cmd, float $totalDuration, callable $progressCallback = null): void
    {
        $descriptors = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to start process: {$cmd}");
        }

        // Set non-blocking
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdoutBuffer = '';
        $stderrBuffer = '';

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            $numChanged = stream_select($read, $write, $except, 0, 200000); // 0.2s

            if ($numChanged === false) {
                break;
            }

            foreach ($read as $r) {
                $line = stream_get_contents($r);
                if ($r === $pipes[1]) {
                    $stdoutBuffer .= $line;
                } else {
                    $stderrBuffer .= $line;
                    // Parse FFmpeg time= progress
                    if (preg_match_all('/time=(\d+):(\d+):(\d+\.?\d*)/', $line, $matches, PREG_SET_ORDER)) {
                        $last = end($matches);
                        $hours = (float)$last[1];
                        $minutes = (float)$last[2];
                        $seconds = (float)$last[3];
                        $currentTime = $hours * 3600 + $minutes * 60 + $seconds;
                        $progress = min(($currentTime / $totalDuration) * 100, 100);
                        if ($progressCallback) {
                            $progressCallback($progress);
                        }
                    }
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnVar = proc_close($process);

        if ($returnVar !== 0) {
            throw new \RuntimeException("FFmpeg process failed with exit code {$returnVar}: {$cmd}");
        }
    }

}
