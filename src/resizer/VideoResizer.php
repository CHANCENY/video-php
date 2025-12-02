<?php

namespace Simp\VideoPhp\resizer;

use Simp\VideoPhp\binaries\Binary;

class VideoResizer
{
    protected Binary $binary;

    public function __construct(Binary $binary)
    {
        $this->binary = $binary;
    }

    /**
     * Resize a video
     *
     * @param string $inputVideo
     * @param string $outputVideo
     * @param int|null $width Target width
     * @param int|null $height Target height
     * @param bool $keepAspectRatio Keep original aspect ratio
     * @return void
     */
    public function resize(
        string $inputVideo,
        string $outputVideo,
        ?int $width = null,
        ?int $height = null,
        bool $keepAspectRatio = true
    ): void {
        if (!file_exists($inputVideo)) {
            throw new \RuntimeException("Input video does not exist: {$inputVideo}");
        }

        $outputDir = dirname($outputVideo);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            throw new \RuntimeException("Failed to create output directory: {$outputDir}");
        }

        $ffmpeg = $this->binary->getFFmpeg();

        if (!$width && !$height) {
            throw new \InvalidArgumentException("Width or height must be provided for resizing.");
        }

        // Build scale filter
        $scale = '';
        if ($keepAspectRatio) {
            $w = $width ?? -1;
            $h = $height ?? -1;
            $scale = "scale={$w}:{$h}:force_original_aspect_ratio=decrease";
        } else {
            $w = $width ?? 'iw';
            $h = $height ?? 'ih';
            $scale = "scale={$w}:{$h}";
        }

        $cmd = escapeshellcmd($ffmpeg) . " -i " . escapeshellarg($inputVideo) .
            " -vf " . escapeshellarg($scale) .
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
            throw new \RuntimeException("FFmpeg resize process failed with exit code {$returnVar}: {$cmd}");
        }
    }
}
