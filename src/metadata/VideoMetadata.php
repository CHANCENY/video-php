<?php

namespace Simp\VideoPhp\metadata;

use Simp\VideoPhp\binaries\Binary;

class VideoMetadata
{
    protected Binary $binary;

    public function __construct(Binary $binary)
    {
        $this->binary = $binary;
    }

    /**
     * Get full metadata for a video as an associative array
     *
     * @param string $videoPath
     * @return array
     */
    public function getMetadata(string $videoPath): array
    {
        if (!file_exists($videoPath)) {
            throw new \RuntimeException("Video file does not exist: {$videoPath}");
        }

        $ffprobe = $this->binary->getFFprobe();
        $cmd = escapeshellcmd($ffprobe) . " -v quiet -print_format json -show_format -show_streams " . escapeshellarg($videoPath);

        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0 || empty($output)) {
            throw new \RuntimeException("FFprobe failed to read metadata for: {$videoPath}");
        }

        $json = implode("\n", $output);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to parse ffprobe output: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Get basic summary of video
     *
     * @param string $videoPath
     * @return array
     */
    public function getSummary(string $videoPath): array
    {
        $meta = $this->getMetadata($videoPath);

        $videoStream = null;
        $audioStream = null;

        if (!empty($meta['streams'])) {
            foreach ($meta['streams'] as $stream) {
                if ($stream['codec_type'] === 'video' && !$videoStream) {
                    $videoStream = $stream;
                } elseif ($stream['codec_type'] === 'audio' && !$audioStream) {
                    $audioStream = $stream;
                }
            }
        }

        return [
            'filename' => basename($videoPath),
            'format' => $meta['format']['format_name'] ?? null,
            'duration' => isset($meta['format']['duration']) ? (float)$meta['format']['duration'] : null,
            'bit_rate' => isset($meta['format']['bit_rate']) ? (int)$meta['format']['bit_rate'] : null,
            'size' => isset($meta['format']['size']) ? (int)$meta['format']['size'] : null,
            'video' => $videoStream ? [
                'codec' => $videoStream['codec_name'] ?? null,
                'width' => $videoStream['width'] ?? null,
                'height' => $videoStream['height'] ?? null,
                'fps' => isset($videoStream['r_frame_rate']) ? $this->frameRateToFloat($videoStream['r_frame_rate']) : null,
            ] : null,
            'audio' => $audioStream ? [
                'codec' => $audioStream['codec_name'] ?? null,
                'channels' => $audioStream['channels'] ?? null,
                'sample_rate' => isset($audioStream['sample_rate']) ? (int)$audioStream['sample_rate'] : null,
            ] : null,
        ];
    }

    /**
     * Convert FFmpeg frame rate string to float
     */
    protected function frameRateToFloat(string $rFrameRate): float
    {
        if (strpos($rFrameRate, '/') !== false) {
            [$num, $den] = explode('/', $rFrameRate);
            if ((float)$den != 0) {
                return (float)$num / (float)$den;
            }
        }
        return (float)$rFrameRate;
    }
}
