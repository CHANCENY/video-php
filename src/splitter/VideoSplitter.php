<?php

namespace Simp\VideoPhp\splitter;

use Simp\VideoPhp\binaries\Binary;
use Simp\VideoPhp\helper\Helper;

class VideoSplitter
{
    use Helper;

    protected Binary $binary;

    public function __construct(Binary $binary)
    {
        $this->binary = $binary;
    }

    /**
     * Split video by duration using proc_open
     */
    public function splitByDuration(string $inputPath, string $outputDir, int $segmentDuration = 60, callable $progressCallback = null): array
    {
        $this->validateInput($inputPath, $outputDir);
        $duration = $this->getVideoDuration($inputPath);
        $ffmpeg = $this->binary->getFFmpeg();
        $outputPattern = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'segment_%03d.mp4';

        $cmd = escapeshellcmd($ffmpeg) . " -i " . escapeshellarg($inputPath) .
            " -c copy -map 0 -segment_time {$segmentDuration} -f segment " .
            escapeshellarg($outputPattern);

        $this->runProcess($cmd, $duration, $progressCallback);

        return $this->getSegments($outputDir);
    }

    /**
     * Split video into N equal segments using proc_open
     */
    public function splitBySegments(string $inputPath, string $outputDir, int $numSegments, callable $progressCallback = null): array
    {
        $duration = $this->getVideoDuration($inputPath);
        $segmentDuration = ceil($duration / $numSegments);
        return $this->splitByDuration($inputPath, $outputDir, $segmentDuration, $progressCallback);
    }

    /**
     * Split video by exact timestamps using proc_open
     */
    public function splitByTimestamps(string $inputPath, string $outputDir, array $segments, callable $progressCallback = null): array
    {
        $this->validateInput($inputPath, $outputDir);
        $ffmpeg = $this->binary->getFFmpeg();
        $outputFiles = [];

        foreach ($segments as $index => $segment) {
            $start = $segment['start'] ?? 0;
            $end = $segment['end'] ?? 0;
            if ($end <= $start) {
                throw new \InvalidArgumentException("Segment end time must be greater than start time. Segment index: {$index}");
            }

            $duration = $end - $start;
            $outputFile = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . sprintf('segment_%03d.mp4', $index + 1);
            $cmd = escapeshellcmd($ffmpeg) . " -ss {$start} -i " . escapeshellarg($inputPath) .
                " -t {$duration} -c copy " . escapeshellarg($outputFile);

            $this->runProcess($cmd, $duration, fn($progress) => $progressCallback && $progressCallback($progress, $index + 1));
            $outputFiles[] = $outputFile;
        }

        return $outputFiles;
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

        stream_set_blocking($pipes[2], false); // stderr non-blocking

        while (true) {
            $status = proc_get_status($process);
            $output = stream_get_contents($pipes[2]);
            if ($output && preg_match_all('/time=(\d+):(\d+):(\d+\.?\d*)/', $output, $matches, PREG_SET_ORDER)) {
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

            if (!$status['running']) {
                break;
            }

            usleep(100_000); // 0.1s delay to avoid CPU spin
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnVar = proc_close($process);

        if ($returnVar !== 0) {
            throw new \RuntimeException("Process failed with exit code {$returnVar}: {$cmd}");
        }
    }

    /**
     * Get video duration in seconds
     */
    protected function getVideoDuration(string $inputPath): float
    {
        $ffprobe = $this->binary->getFFprobe();
        $cmd = escapeshellcmd($ffprobe) . " -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($inputPath);
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0 || empty($output)) {
            throw new \RuntimeException("FFprobe failed to get video duration. Command: {$cmd}");
        }

        return (float)$output[0];
    }

    protected function validateInput(string $inputPath, string $outputDir): void
    {
        if (!file_exists($inputPath)) {
            throw new \RuntimeException("Input video does not exist: {$inputPath}");
        }
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            throw new \RuntimeException("Failed to create output directory: {$outputDir}");
        }
    }

    protected function getSegments(string $outputDir): array
    {
        $files = glob(rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'segment_*.mp4');
        sort($files);
        return $files;
    }
}
