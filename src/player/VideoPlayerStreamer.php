<?php

namespace Simp\VideoPhp\player;

/**
 * Class VideoStreamer
 *
 * Streams a local video file with HTTP Range support (forward/backward seeking)
 * Usage:
 *   $streamer = new VideoStreamer();
 *   $streamer->stream(__DIR__ . '/videos/playlist/playlist/segment_000.mp4');
 */
class VideoPlayerStreamer
{
    protected int $bufferSize;

    public function __construct(int $bufferSize = 8192)
    {
        $this->bufferSize = max(1024, $bufferSize); // default 8KB
    }

    /**
     * Stream video file to browser with HTTP Range support
     */
    public function stream(string $filePath): void
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            header("HTTP/1.1 404 Not Found");
            exit("File not found");
        }

        $fp = fopen($filePath, 'rb');
        $size = filesize($filePath);
        $start = 0;
        $end = $size - 1;
        $length = $size;

        header('Content-Type: video/mp4');
        header('Accept-Ranges: bytes');

        if (isset($_SERVER['HTTP_RANGE'])) {
            $range = $_SERVER['HTTP_RANGE'];
            $c_start = $start;
            $c_end = $end;

            list(, $range) = explode('=', $range, 2);

            if (strpos($range, ',') !== false) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header("Content-Range: bytes $start-$end/$size");
                exit;
            }

            if ($range === '-') {
                $c_start = $size - intval(substr($range, 1));
            } else {
                $range = explode('-', $range);
                $c_start = intval($range[0]);
                $c_end = isset($range[1]) && is_numeric($range[1]) ? intval($range[1]) : $end;
            }

            $c_end = min($c_end, $end);

            if ($c_start > $c_end || $c_start > $size - 1 || $c_end >= $size) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header("Content-Range: bytes $start-$end/$size");
                exit;
            }

            $start = $c_start;
            $end = $c_end;
            $length = $end - $start + 1;
            fseek($fp, $start);
            header('HTTP/1.1 206 Partial Content');
        }

        header("Content-Range: bytes $start-$end/$size");
        header("Content-Length: $length");

        while (!feof($fp) && ($pos = ftell($fp)) <= $end) {
            $readSize = $this->bufferSize;
            if ($pos + $readSize > $end) {
                $readSize = $end - $pos + 1;
            }

            set_time_limit(0);
            echo fread($fp, $readSize);
            flush();
        }

        fclose($fp);
        exit();
    }
}
