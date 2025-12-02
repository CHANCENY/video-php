<?php

namespace Simp\VideoPhp\compressor;

use Simp\VideoPhp\binaries\Binary;

class VideoCompressor
{
    protected Binary $binary;

    public function __construct(Binary $binary)
    {
        $this->binary = $binary;
    }

    /**
     * Compress a video
     *
     * @param string $inputVideo
     * @param string $outputVideo
     * @param int $crf Quality (0-51, lower is better, default 23)
     * @param string $preset Compression preset (ultrafast, superfast, veryfast, faster, fast, medium, slow, slower, veryslow)
     * @return void
     */
    public function compress(
        string $inputVideo,
        string $outputVideo,
        int $crf = 23,
        string $preset = 'medium'
    ): void {
        if (!file_exists($inputVideo)) {
            throw new \RuntimeException("Input video does not exist: {$inputVideo}");
        }

        $outputDir = dirname($outputVideo);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            throw new \RuntimeException("Failed to create output directory: {$outputDir}");
        }

        $ffmpeg = $this->binary->getFFmpeg();

        // Use H.264 codec for good compression
        $cmd = escapeshellcmd($ffmpeg) . " -i " . escapeshellarg($inputVideo) .
            " -vcodec libx264 -preset " . escapeshellarg($preset) .
            " -crf " . intval($crf) . " -c:a copy " .
            escapeshellarg($outputVideo) . " 2>&1";

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
            throw new \RuntimeException("FFmpeg compression failed with exit code {$returnVar}: {$cmd}");
        }
    }
}
