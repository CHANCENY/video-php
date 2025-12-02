<?php

namespace Simp\VideoPhp\watermark;

use Simp\VideoPhp\binaries\Binary;
use Simp\VideoPhp\fonts\Fonts;

class VideoWaterMarker
{
    protected Binary $binary;

    public function __construct(Binary $binary)
    {
        $this->binary = $binary;
    }

    /**
     * Add an image watermark to a video
     *
     * @param string $inputVideo
     * @param string $outputVideo
     * @param string $watermarkImage Path to the watermark image
     * @param string $position Position: top-left, top-right, bottom-left, bottom-right, center
     * @param float $opacity Opacity between 0.0 and 1.0
     * @return void
     */
    public function addImageWatermark(
        string $inputVideo,
        string $outputVideo,
        string $watermarkImage,
        string $position = 'bottom-right',
        float $opacity = 1.0
    ): void {
        if (!file_exists($inputVideo)) {
            throw new \RuntimeException("Input video does not exist: {$inputVideo}");
        }
        if (!file_exists($watermarkImage)) {
            throw new \RuntimeException("Watermark image does not exist: {$watermarkImage}");
        }

        $outputDir = dirname($outputVideo);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            throw new \RuntimeException("Failed to create output directory: {$outputDir}");
        }

        $ffmpeg = $this->binary->getFFmpeg();

        $overlayPosition = match ($position) {
            'top-left' => '10:10',
            'top-right' => 'main_w-overlay_w-10:10',
            'bottom-left' => '10:main_h-overlay_h-10',
            'bottom-right' => 'main_w-overlay_w-10:main_h-overlay_h-10',
            'center' => '(main_w-overlay_w)/2:(main_h-overlay_h)/2',
            default => 'main_w-overlay_w-10:main_h-overlay_h-10',
        };

        $opacityFilter = $opacity < 1.0 ? "format=rgba, colorchannelmixer=aa={$opacity}" : "";

        $filter = $opacityFilter !== "" ? "[1:v]{$opacityFilter}[wm];[0:v][wm]overlay={$overlayPosition}" : "overlay={$overlayPosition}";

        $cmd = escapeshellcmd($ffmpeg) . " -i " . escapeshellarg($inputVideo) .
            " -i " . escapeshellarg($watermarkImage) .
            " -filter_complex " . escapeshellarg($filter) .
            " -codec:a copy " . escapeshellarg($outputVideo) . " 2>&1";

        $this->runProcess($cmd);
    }

    /**
     * Add a text watermark to a video
     *
     * @param string $inputVideo
     * @param string $outputVideo
     * @param string $text Text to display
     * @param string $position Position: top-left, top-right, bottom-left, bottom-right, center
     * @param int $fontSize
     * @param string $fontColor Color in hex (e.g., "white", "red", "#FF0000")
     * @param string $fontFile
     * @param float $opacity
     * @return void
     */
    public function addTextWatermark(
        string $inputVideo,
        string $outputVideo,
        string $text,
        string $position = 'bottom-right',
        int $fontSize = 24,
        string $fontColor = 'white',
        string $fontFile = Fonts::MONTSERRAT_BOLD,
        float $opacity = 1.0
    ): void {
        // Validate input video
        if (!file_exists($inputVideo)) {
            throw new \RuntimeException("Input video does not exist: {$inputVideo}");
        }

        // Validate font file
        if (!file_exists($fontFile)) {
            throw new \RuntimeException("Font file does not exist: {$fontFile}");
        }

        // Ensure output directory exists
        $outputDir = dirname($outputVideo);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true)) {
            throw new \RuntimeException("Failed to create output directory: {$outputDir}");
        }

        $ffmpeg = $this->binary->getFFmpeg();

        // Determine position
        [$x, $y] = match ($position) {
            'top-left'      => ['100', '100'],
            'top-right'     => ['w-text_w-100', '100'],
            'bottom-left'   => ['100', 'h-text_h-100'],
            'bottom-right'  => ['w-text_w-100', 'h-text_h-100'],
            'center'        => ['(w-text_w)/2', '(h-text_h)/2'],
            default         => ['100', '100'],
        };

        // Strip out special characters from text
        // Keep only letters, numbers, spaces, and basic punctuation (.,!?)
        $safeText = preg_replace("/[^a-zA-Z0-9 \.,!?]/", "", $text);

        // Apply opacity if < 1
        $fontColorStr = $opacity < 1.0 ? "{$fontColor}@{$opacity}" : $fontColor;

        // Construct drawtext filter
        $filter = "drawtext=text='{$safeText}':x={$x}:y={$y}:fontfile={$fontFile}:fontsize={$fontSize}:fontcolor={$fontColorStr}";

        // Build the command
        $cmd = sprintf(
            '"%s" -i "%s" -y -vf "%s" "%s"',
            $ffmpeg,
            $inputVideo,
            $filter,
            $outputVideo
        );

        // Execute
        $this->runProcess($cmd);
    }


    /**
     * Run a shell process safely
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
            throw new \RuntimeException("FFmpeg watermark process failed with exit code {$returnVar}: {$cmd}");
        }
    }
}
