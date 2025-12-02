<?php

namespace Simp\VideoPhp\video;

use Simp\VideoPhp\audio\VideoAudioExtractor;
use Simp\VideoPhp\batch\PlayListBatchProcessor;
use Simp\VideoPhp\binaries\Binary;
use Simp\VideoPhp\compressor\VideoCompressor;
use Simp\VideoPhp\convertor\VideoConvertor;
use Simp\VideoPhp\merge\VideoMerger;
use Simp\VideoPhp\metadata\VideoMetadata;
use Simp\VideoPhp\picture\VideoPictureFrame;
use Simp\VideoPhp\resizer\VideoResizer;
use Simp\VideoPhp\splitter\VideoSplitter;
use Simp\VideoPhp\subtitle\SubtitleAdder;
use Simp\VideoPhp\watermark\VideoWaterMarker;

class Video
{
    public VideoSplitter $splitter;
    public VideoConvertor $convertor;
    public VideoPictureFrame $picture;
    public VideoResizer $resizer;
    public VideoWaterMarker $waterMarker;
    public VideoMetadata $metadata;
    public VideoMerger $merger;
    public VideoAudioExtractor $audioExtractor;
    public VideoCompressor $compressor;
    public SubtitleAdder $subtitleAdder;
    public PlayListBatchProcessor $playlistBatchProcessor;

    public function __construct(?string $arch = null)
    {
        $build = new Binary($arch);
        $this->splitter = new VideoSplitter($build);
        $this->convertor = new VideoConvertor($build);
        $this->picture = new VideoPictureFrame($build);
        $this->resizer = new VideoResizer($build);
        $this->waterMarker = new VideoWaterMarker($build);
        $this->metadata = new VideoMetadata($build);
        $this->merger = new VideoMerger($build);
        $this->audioExtractor = new VideoAudioExtractor($build);
        $this->compressor = new VideoCompressor($build);
        $this->subtitleAdder = new SubtitleAdder($build);
        $this->playlistBatchProcessor = new PlayListBatchProcessor($build);
    }

    public static function splitter(?string $arch = null): VideoSplitter
    {
        return new Video($arch)->splitter;
    }

    public static function convertor(?string $arch = null): VideoConvertor
    {
        return new Video($arch)->convertor;
    }

    public static function picture(?string $arch = null): VideoPictureFrame
    {
        return new Video($arch)->picture;
    }

    public static function resizer(?string $arch = null): VideoResizer
    {
        return new Video($arch)->resizer;
    }

    public static function waterMarker(?string $arch = null): VideoWaterMarker
    {
        return new Video($arch)->waterMarker;
    }

    public static function metadata(?string $arch = null): VideoMetadata
    {
        return new Video($arch)->metadata;
    }

    public static function merger(?string $arch = null): VideoMerger
    {
        return new Video($arch)->merger;
    }

    public static function audioExtractor(?string $arch = null): VideoAudioExtractor
    {
        return new Video($arch)->audioExtractor;
    }

    public static function compressor(?string $arch = null): VideoCompressor
    {
        return new Video($arch)->compressor;
    }

    public static function subtitleAdder(?string $arch = null): SubtitleAdder
    {
        return new Video($arch)->subtitleAdder;
    }

    public static function playlistBatchProcessor(?string $arch = null): PlayListBatchProcessor
    {
        return new Video($arch)->playlistBatchProcessor;
    }

}