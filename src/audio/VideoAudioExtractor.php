<?php

namespace Simp\VideoPhp\audio;

use Simp\VideoPhp\binaries\Binary;

class VideoAudioExtractor
{
    protected Binary $binary;

    public function __construct(Binary $binary)
    {
        $this->binary = $binary;
    }

    /**
     * Extract audio from a video
     *
     * @param string $inputVideo Path to video
     * @param string $outputAudio Path to output audio
     * @param string|null $format Optional format (mp3, aac, wav, etc.)
     * @param int|null $bitrate Optional bitrate in kbps
     * @return void
     */
    public function extract(
        string $inputVideo,
        string $outputAudio,
        ?string $format = null,
        ?int $bitrate = null
    ): void {
        if (!file_exists($inputVideo)) {
            throw new \RuntimeException("Input video does not exist: {$inputVideo}");
        }

        $outputDir = dirname($outputAudio);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            throw new \RuntimeException("Failed to create output directory: {$outputDir}");
        }

        $ffmpeg = $this->binary->getFFmpeg();

        // Determine codec and format from output extension if format not provided
        $ext = pathinfo($outputAudio, PATHINFO_EXTENSION);
        $format = $format ?? $ext;

        $codec = match (strtolower($format)) {
            'mp3' => 'libmp3lame',
            'aac' => 'aac',
            'wav' => 'pcm_s16le',
            'flac' => 'flac',
            default => null, // use default codec
        };

        $bitrateOption = $bitrate ? "-b:a {$bitrate}k" : "";

        $cmd = escapeshellcmd($ffmpeg) . " -i " . escapeshellarg($inputVideo) .
            " -vn " . // disable video
            ($codec ? "-c:a {$codec}" : "") . " " .
            $bitrateOption . " " .
            escapeshellarg($outputAudio) . " 2>&1";

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
            throw new \RuntimeException("FFmpeg audio extraction failed with exit code {$returnVar}: {$cmd}");
        }
    }
}
