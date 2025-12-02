<?php

namespace Simp\VideoPhp\batch;

use Simp\VideoPhp\binaries\Binary;
use Simp\VideoPhp\video\Video;

class PlayListBatchProcessor
{
    protected Binary $binary;

    public function __construct(Binary $binary)
    {
        $this->binary = $binary;
    }

    public function process(string $videoPath, array $rules): string
    {
        // ---- VALIDATION ----
        if (empty($rules['convert_to_mp4']) || empty($rules['convert_to_mp4']['enabled'])) {
            throw new \InvalidArgumentException("Convert rule is required and must be enabled.");
        }

        if (!empty($rules['convert_to_mp4']['format'])
            && $rules['convert_to_mp4']['format'] !== 'mp4') {
            throw new \InvalidArgumentException("Only mp4 format is supported for now.");
        }

        $metadata = Video::metadata()->getSummary($videoPath);

        $convertFlag = false;
        $splitFlag   = false;

        if (!empty($metadata)) {

            // check if original video format includes mp4
            $list = explode(",", $metadata['format']);
            if (!in_array('mp4', $list)) {
                $convertFlag = true;
            }

            // ---- Split ----
            if (empty($rules['split']) || empty($rules['split']['enabled'])) {
                throw new \InvalidArgumentException("Split rule is required and must be enabled.");
            }

            if (empty($rules['split']['duration_per_clip'])) {
                throw new \InvalidArgumentException("Duration per clip rule is required.");
            }

            if (empty($rules['split']['output_folder'])) {
                throw new \InvalidArgumentException("Split output folder is required.");
            }

            if (!is_dir($rules['split']['output_folder'])) {
                mkdir($rules['split']['output_folder'], 0755, true);
            }

            if ($metadata['duration'] > $rules['split']['duration_per_clip']) {
                $splitFlag = true;
            }

            // ---- Frames ----
            if (empty($rules['frames']) || empty($rules['frames']['enabled'])) {
                throw new \InvalidArgumentException("Frames rule is required and must be enabled.");
            }

            if (empty($rules['frames']['output_folder'])) {
                throw new \InvalidArgumentException("Frames output folder is required.");
            }

            if (!is_dir($rules['frames']['output_folder'])) {
                mkdir($rules['frames']['output_folder'], 0755, true);
            }

            if (empty($rules['frames']['interval_seconds'])) {
                throw new \InvalidArgumentException("Interval seconds rule is required.");
            }

            if (empty($rules['output_file'])) {
                throw new \InvalidArgumentException("Output permanent directory rule is required.");
            }
        }

        // ---- Ensure final folder exists ----
        if (!is_dir($rules['output_file'])) {
            mkdir($rules['output_file'], 0755, true);
        }

        // ---- CONVERT TO MP4 ----
        if ($convertFlag) {
            $tempVideoFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('video_') . ".mp4";

            Video::convertor()->convert(
                $videoPath,
                $tempVideoFile,
                $rules['convert_to_mp4']['video_codec'] ?? null,
                $rules['convert_to_mp4']['audio_codec'] ?? null,
            );

            $videoPath = $tempVideoFile;
        }

        // ---- SPLIT ----
        if ($splitFlag) {
            Video::splitter()->splitByDuration(
                $videoPath,
                $rules['split']['output_folder'],
                $rules['split']['duration_per_clip']
            );
        }

        // Get a segment list
        $segmentsVideos = $this->scanOrdered($rules['split']['output_folder']);

        // if no split happened
        if (empty($segmentsVideos)) {
            $segmentsVideos[] = $videoPath;
        }

        // ---- FRAMES ----
        $pictureFrames = [];

        Video::picture()->extractFramesByInterval(
            $videoPath,
            $rules['frames']['output_folder'],
            intval($rules['frames']['interval_seconds'])
        );
        $frames = $this->scanOrdered($rules['frames']['output_folder']);
        foreach ($frames as $frame) {
            $pictureFrames[] = $frame;
        }

        // ---- MOVE FILES TO PERMANENT ----
        $finalSegments = $this->moveToPermanent($segmentsVideos, $rules['output_file']);
        $finalFrames   = $this->moveToPermanent($pictureFrames, $rules['output_file']);

        // ---- CREATE PLAYLIST ----
        $manifestFile = $rules['output_file'] . DIRECTORY_SEPARATOR . "playlist.m3u8";
        $this->createHLSManifest($manifestFile, $finalSegments);

        // ---- CREATE JSON CUSTOM MANIFEST ----
        $jsonFile = $rules['output_file'] . DIRECTORY_SEPARATOR . "playlist.json";
        $this->createJsonManifest($jsonFile, $finalSegments, $finalFrames);

        return $manifestFile;
    }

    /**
     * Move files to the permanent output folder.
     */
    protected function moveToPermanent(array $files, string $permanentFolder): array
    {
        $finalList = [];

        // Ensure destination exists
        if (!is_dir($permanentFolder)) {
            if (!mkdir($permanentFolder, 0755, true) && !is_dir($permanentFolder)) {
                throw new \RuntimeException("Unable to create directory: {$permanentFolder}");
            }
        }

        // Check writability early (best-effort)
        if (!is_writable($permanentFolder)) {
            error_log("moveToPermanent: target folder not writable: {$permanentFolder}");
            // still proceed and attempt per-file operations
        }

        foreach ($files as $path) {
            if (!file_exists($path)) {
                error_log("moveToPermanent: source missing: {$path}");
                continue;
            }

            $filename = basename($path);
            $target   = $permanentFolder . DIRECTORY_SEPARATOR . $filename;

            // if target exists, generate a unique name
            if (file_exists($target)) {
                $nameOnly = pathinfo($filename, PATHINFO_FILENAME);
                $ext = pathinfo($filename, PATHINFO_EXTENSION);
                $counter = 1;
                do {
                    $candidate = $permanentFolder . DIRECTORY_SEPARATOR . $nameOnly . '_' . $counter . ($ext !== '' ? '.' . $ext : '');
                    $counter++;
                } while (file_exists($candidate));
                $target = $candidate;
            }

            // Try to rename first (fast). If it fails, fallback to copy+unlink (handles cross-device)
            if (@rename($path, $target) === false) {
                $renameErr = error_get_last();
                error_log("moveToPermanent: rename failed for {$path} -> {$target}. Err: " . json_encode($renameErr));

                // Attempt copy
                if (@copy($path, $target) === false) {
                    $copyErr = error_get_last();
                    error_log("moveToPermanent: copy failed for {$path} -> {$target}. Err: " . json_encode($copyErr));
                    continue; // skip this file
                }

                // Remove original after successful copy
                if (!@unlink($path)) {
                    error_log("moveToPermanent: unlink failed for {$path} after copy. Keep original.");
                    // we still add the target path since copy succeeded
                }
            }

            $finalList[] = $target;
        }

        return $finalList;
    }

    /**
     * Generate HLS playlist.m3u8 (simple VOD playlist).
     */
    protected function createHLSManifest(string $file, array $segments): void
    {
        $content = "#EXTM3U\n";
        $content .= "#EXT-X-VERSION:3\n";
        $content .= "#EXT-X-TARGETDURATION:10\n";
        $content .= "#EXT-X-MEDIA-SEQUENCE:0\n\n";

        foreach ($segments as $seg) {
            $content .= "#EXTINF:10.0,\n";
            $content .= basename($seg) . "\n";
        }

        $content .= "#EXT-X-ENDLIST";

        file_put_contents($file, $content);
    }

    /**
     * Generate a JSON playlist with full metadata.
     */
    protected function createJsonManifest(string $file, array $segments, array $frames): void
    {
        $manifest = [
            "version"  => 1,
            "segments" => array_map(fn($p) => basename($p), $segments),
            "frames"   => array_map(fn($p) => basename($p), $frames),
            "created_at" => date('c'),
        ];

        file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT));
    }

    /**
     * Scan directory in natural order.
     */
    protected function scanOrdered(string $dir): array
    {
        if (!is_dir($dir)) return [];

        $list = array_diff(scandir($dir), ['.', '..']);
        natsort($list);

        $result = [];
        foreach ($list as $f) {
            $result[] = $dir . DIRECTORY_SEPARATOR . $f;
        }
        return $result;
    }
}
