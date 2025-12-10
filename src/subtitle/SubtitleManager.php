<?php

namespace Simp\VideoPhp\subtitle;

use Simp\VideoPhp\binaries\Binary;

class SubtitleManager
{
    protected Binary $binary;

    public function __construct(Binary $binary)
    {
        $this->binary = $binary;
    }

    /**
     * List all subtitle streams in a video
     */
    public function listSubtitles(string $inputVideo): array
    {
        if (!file_exists($inputVideo)) {
            throw new \RuntimeException("Video does not exist: {$inputVideo}");
        }

        $ffprobe = $this->binary->getFFprobe();

        $cmd = escapeshellcmd($ffprobe) . " -v quiet -print_format json -show_streams " . escapeshellarg($inputVideo);

        $output = shell_exec($cmd);
        $data = json_decode($output, true);

        $subtitles = [];

        foreach ($data['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'subtitle') {
                $subtitles[] = [
                    'index' => $stream['index'],
                    'codec' => $stream['codec_name'],
                    'language' => $stream['tags']['language'] ?? 'unknown',
                    'title' => $stream['tags']['title'] ?? null,
                ];
            }
        }

        return $subtitles;
    }

    /**
     * Extract subtitle stream to .srt file
     */
    public function extractSubtitle(string $inputVideo, int $streamIndex, string $outputSubtitle): string
    {
        if (!file_exists($inputVideo)) {
            throw new \RuntimeException("Video does not exist: {$inputVideo}");
        }

        $ffmpeg = $this->binary->getFFmpeg();

        $cmd = escapeshellcmd($ffmpeg)
            . " -i " . escapeshellarg($inputVideo)
            . " -map 0:" . intval($streamIndex)
            . " -c:s srt "
            . escapeshellarg($outputSubtitle)
            . " -y 2>&1";

        shell_exec($cmd);

        if (!file_exists($outputSubtitle)) {
            throw new \RuntimeException("Failed to extract subtitle stream {$streamIndex}");
        }

        return $outputSubtitle;
    }

    /**
     * Convert subtitles between formats (SRT, VTT, ASS)
     */
    public function convertSubtitle(string $inputSubtitle, string $outputSubtitle): void
    {
        if (!file_exists($inputSubtitle)) {
            throw new \RuntimeException("Subtitle file does not exist: {$inputSubtitle}");
        }

        $ffmpeg = $this->binary->getFFmpeg();

        $cmd = escapeshellcmd($ffmpeg)
            . " -i " . escapeshellarg($inputSubtitle)
            . " " . escapeshellarg($outputSubtitle)
            . " -y 2>&1";

        shell_exec($cmd);

        if (!file_exists($outputSubtitle)) {
            throw new \RuntimeException("Subtitle conversion failed: {$outputSubtitle}");
        }
    }

    /**
     * Extract all subtitle streams and return metadata + file path
     */
    public function extractAllWithMetadata(string $inputVideo, string $outputDir): array
    {
        $this->ensureDirectory($outputDir);

        $subtitles = $this->listSubtitles($inputVideo);
        $results = [];

        foreach ($subtitles as $sub) {
            $index     = $sub['index'];
            $language  = $sub['language'] ?: 'unknown';
            $title     = $sub['title'] ?? null;
            $codec     = $sub['codec'];

            // Decide extension based on codec
            $extension =
                ($codec === 'ass') ? 'ass' : (($codec === 'webvtt') ? 'vtt' :
                    'srt'); // default

            $file = rtrim($outputDir, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . "subtitle_{$index}_{$language}.{$extension}";

            // Extract subtitle to the file
            $this->extractSubtitle($inputVideo, $index, $file);

            // Return full metadata
            $results[] = [
                "index"    => $index,
                "language" => $language,
                "title"    => $title,
                "codec"    => $codec,
                "file"     => realpath($file),
            ];
        }

        return $results;
    }

    /**
     * Extract ALL subtitles automatically to folder
     */
    public function extractAll(string $inputVideo, string $outputDir): array
    {
        $this->ensureDirectory($outputDir);

        $subs = $this->listSubtitles($inputVideo);
        $results = [];

        foreach ($subs as $sub) {
            $lang = $sub['language'];
            $index = $sub['index'];

            $file = $outputDir . "/subtitle_{$index}_{$lang}.srt";

            $results[] = $this->extractSubtitle($inputVideo, $index, $file);
        }

        return $results;
    }

    protected function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Failed to create directory: {$dir}");
        }
    }
}
