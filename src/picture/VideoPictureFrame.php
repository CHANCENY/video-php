<?php

namespace Simp\VideoPhp\picture;

use Simp\VideoPhp\binaries\Binary;

class VideoPictureFrame
{
    protected Binary $binary;

    public function __construct(Binary $binary)
    {
        $this->binary = $binary;
    }

    /**
     * Extract a single frame at a specific timestamp
     *
     * @param string $inputPath
     * @param string $outputPath
     * @param float $time Timestamp in seconds
     * @param int|null $width Optional width for scaling
     * @param int|null $height Optional height for scaling
     * @param string $format Output format: 'png' or 'jpg'
     */
    public function extractFrame(
        string $inputPath,
        string $outputPath,
        float $time,
        ?int $width = null,
        ?int $height = null,
        string $format = 'png'
    ): void {
        $this->validateInput($inputPath, $outputPath);

        $ext = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg'])) {
            $outputPath .= '.' . $format;
        }

        $ffmpeg = $this->binary->getFFmpeg();

        $vf = '';
        if ($width || $height) {
            $w = $width ?? -1;
            $h = $height ?? -1;
            $vf = " -vf scale={$w}:{$h} ";
        }

        $cmd = escapeshellcmd($ffmpeg) . " -ss {$time} -i " . escapeshellarg($inputPath) .
            $vf . " -frames:v 1 " . escapeshellarg($outputPath) . " 2>&1";

        $this->runProcess($cmd);
    }

    /**
     * Extract multiple frames at intervals
     *
     * @param string $inputPath
     * @param string $outputDir
     * @param float $interval Seconds between frames
     * @param int|null $width Optional width for scaling
     * @param int|null $height Optional height for scaling
     * @param string $format Output format: 'png' or 'jpg'
     * @return array List of generated frame file paths
     */
    public function extractFramesByInterval(
        string $inputPath,
        string $outputDir,
        float $interval = 1.0,
        ?int $width = null,
        ?int $height = null,
        string $format = 'png'
    ): array {
        $this->validateInput($inputPath, $outputDir);

        $vf = '';
        if ($width || $height) {
            $w = $width ?? -1;
            $h = $height ?? -1;
            $vf = " -vf scale={$w}:{$h} ";
        }

        $ffmpeg = $this->binary->getFFmpeg();
        $outputPattern = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "frame_%04d.{$format}";

        $cmd = escapeshellcmd($ffmpeg) . " -i " . escapeshellarg($inputPath) .
            $vf . " -vf fps=1/{$interval} " . escapeshellarg($outputPattern) . " 2>&1";

        $this->runProcess($cmd);

        $frames = glob(rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "frame_*.{$format}");
        sort($frames);

        return $frames;
    }

    protected function validateInput(string $inputPath, string $outputPathOrDir): void
    {
        if (!file_exists($inputPath)) {
            throw new \RuntimeException("Input video does not exist: {$inputPath}");
        }

        $dir = is_dir($outputPathOrDir) ? $outputPathOrDir : dirname($outputPathOrDir);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }

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
                stream_get_contents($r);
            }

            $status = proc_get_status($process);
            if (!$status['running']) break;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $returnVar = proc_close($process);

        if ($returnVar !== 0) {
            throw new \RuntimeException("FFmpeg process failed with exit code {$returnVar}: {$cmd}");
        }
    }

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

    /**
     * Extract frames based on scene changes
     *
     * @param string $inputPath Path to input video
     * @param string $outputDir Directory to save frames
     * @param float $threshold Scene change threshold (default 0.4, range 0.0-1.0)
     * @param int|null $width Optional width for scaling
     * @param int|null $height Optional height for scaling
     * @param string $format Output format: 'png' or 'jpg'
     * @return array List of generated frame file paths
     */
    public function extractFramesBySceneChange(
        string $inputPath,
        string $outputDir,
        float $threshold = 0.4,
        ?int $width = null,
        ?int $height = null,
        string $format = 'png'
    ): array {
        $this->validateInput($inputPath, $outputDir);

        $vf = "select='gt(scene,{$threshold})'";
        if ($width || $height) {
            $w = $width ?? -1;
            $h = $height ?? -1;
            $vf .= ",scale={$w}:{$h}";
        }

        $ffmpeg = $this->binary->getFFmpeg();
        $outputPattern = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "scene_%04d.{$format}";

        $cmd = escapeshellcmd($ffmpeg) . " -i " . escapeshellarg($inputPath) .
            " -vf \"" . $vf . "\" -frames:v 100 " . escapeshellarg($outputPattern) . " 2>&1";

        $this->runProcess($cmd);

        $frames = glob(rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . "scene_*.{$format}");
        sort($frames);

        return $frames;
    }

}
