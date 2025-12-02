<?php

namespace Simp\VideoPhp\subtitle;

use Simp\VideoPhp\binaries\Binary;

class SubtitleAdder
{
    protected Binary $binary;

    public function __construct(Binary $binary)
    {
        $this->binary = $binary;
    }

    /**
     * Add subtitles to a video (hardcode/burn-in)
     *
     * @param string $inputVideo Path to input video
     * @param string $subtitleFile Path to subtitle file (SRT, ASS)
     * @param string $outputVideo Path to output video
     * @return void
     */
    public function addSubtitles(string $inputVideo, string $subtitleFile, string $outputVideo): void
    {
        if (!file_exists($inputVideo)) {
            throw new \RuntimeException("Input video does not exist: {$inputVideo}");
        }

        if (!file_exists($subtitleFile)) {
            throw new \RuntimeException("Subtitle file does not exist: {$subtitleFile}");
        }

        $outputDir = dirname($outputVideo);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            throw new \RuntimeException("Failed to create output directory: {$outputDir}");
        }

        $ffmpeg = $this->binary->getFFmpeg();

        // Build command with subtitles filter
        $filter = "subtitles=" . escapeshellarg($subtitleFile);

        $cmd = escapeshellcmd($ffmpeg) . " -i " . escapeshellarg($inputVideo) .
            " -vf " . escapeshellarg($filter) .
            " -c:a copy " . escapeshellarg($outputVideo) . " 2>&1";

        $this->runProcess($cmd);
    }

    /**
     * Run shell process safely
     */
    protected function runProcess(string $cmd): void
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
                stream_get_contents($r); // discard output
            }

            $status = proc_get_status($process);
            if (!$status['running']) break;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnVar = proc_close($process);

        if ($returnVar !== 0) {
            throw new \RuntimeException("FFmpeg subtitle process failed with exit code {$returnVar}: {$cmd}");
        }
    }
}
