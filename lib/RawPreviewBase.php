<?php

namespace OCA\CameraRawPreviews;


use Exception;
use Imagick;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IImage;
use OCP\Image;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;
use OCP\IConfig;

class RawPreviewBase
{
    protected $converter;
    protected $driver;
    protected $logger;
    protected $appName;
    protected $tmpFiles = [];
    protected $config;

    public function __construct(IConfig $config, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->appName = 'camerarawpreviews';
    }

    /**
     * @return string
     */
    public function getMimeType(): string
    {
        return '/^((image\/x-dcraw)|(image\/x-indesign)|(image\/avif))(;+.*)*$/';
    }

    /**
     * @param FileInfo $file
     * @return bool
     */
    public function isAvailable(FileInfo $file): bool
    {
        if (strtolower($file->getExtension()) === 'tiff' && !$this->isTiffCompatible()) {
            return false;
        }

        return $file->getSize() > 0;
    }

    protected function getThumbnailInternal(File $file, int $maxX, int $maxY): ?IImage
    {
        try {
            $localPath = $this->getLocalFile($file);
        } catch (Exception $e) {
            $this->logger->warning($e->getMessage(), ['app' => $this->appName, 'exception' => $e]);
            return null;
        }

        if (exif_imagetype($localPath) === IMAGETYPE_AVIF) {
            return $this->getAVIFPreview($localPath, $maxX, $maxY);
        }

        try {
            $tagData = $this->getBestPreviewTag($localPath);
            $previewTag = $tagData['tag'];

            if ($previewTag === 'SourceFile') {
                // load the original file as fallback when TIFF has no preview embedded
                $previewImageTmpPath = $localPath;
            } else {
                $previewImageTmpPath = sys_get_temp_dir() . '/' . md5($localPath . uniqid()) . '.' . $tagData['ext'];
                $this->tmpFiles[] = $previewImageTmpPath;

                //extract preview image using exiftool to file
                shell_exec($this->getConverter() . "  -ignoreMinorErrors -b -" . $previewTag . " " . $this->escapeShellArg($localPath) . ' > ' . $this->escapeShellArg($previewImageTmpPath));
                if (filesize($previewImageTmpPath) < 100) {
                    throw new Exception('Unable to extract valid preview data');
                }

                //update previewImageTmpPath  with orientation data
                shell_exec($this->getConverter() . ' -ignoreMinorErrors -TagsFromFile ' . $this->escapeShellArg($localPath) . ' -orientation -overwrite_original ' . $this->escapeShellArg($previewImageTmpPath));
            }

            $image = new Image;

            // we have checked for tiff support in getBestPreviewTag
            if ($tagData['ext'] === 'tiff') {
                $imagick = new Imagick($previewImageTmpPath);
                $imagick->autoOrient();
                $imagick->setImageFormat('jpg');
                $image->loadFromData($imagick->getImageBlob());
            } else {
                $image->loadFromFile($previewImageTmpPath);
            }

            $image->fixOrientation();
            $image->scaleDownToFit($maxX, $maxY);
            $this->cleanTmpFiles();

            //check if image object is valid
            if (!$image->valid()) {
                return null;
            }
            return $image;
        } catch (Exception $e) {
            $this->logger->warning($e->getMessage(), ['app' => $this->appName, 'exception' => $e]);

            $this->cleanTmpFiles();
            return null;
        }
    }

    /**
     * Get a path to either the local file or temporary file
     *
     * @param File $file
     * @return string
     * @throws LockedException
     * @throws NotFoundException
     * @throws NotPermittedException
     */
    private function getLocalFile(File $file): string
    {
        $useTempFile = $file->isEncrypted() || !$file->getStorage()->isLocal();
        if ($useTempFile) {
            $absPath = \OC::$server->getTempManager()->getTemporaryFile();
            $content = $file->fopen('r');
            file_put_contents($absPath, $content);
            $this->tmpFiles[] = $absPath;
            return $absPath;
        } else {
            return $file->getStorage()->getLocalFile($file->getInternalPath());
        }
    }

    /**
     * @param string $tmpPath
     * @return array
     * @throws Exception
     */
    private function getBestPreviewTag(string $tmpPath): array
    {

        $cmd = $this->getConverter() . " -json -preview:all -FileType " . $this->escapeShellArg($tmpPath);
        $json = shell_exec($cmd);
        // get all available previews and the file type
        $previewData = json_decode($json, true);
        $fileType = $previewData[0]['FileType'] ?? 'n/a';

        // potential tags in priority
        $tagsToCheck = [
            'JpgFromRaw',
            'PageImage',
            'PreviewImage',
            'OtherImage',
            'ThumbnailImage',
        ];

        // tiff tags that need extra checks
        $tiffTagsToCheck = [
            'PreviewTIFF',
            'ThumbnailTIFF'
        ];

        // return at first found tag
        foreach ($tagsToCheck as $tag) {
            if (!isset($previewData[0][$tag])) {
                continue;
            }
            return ['tag' => $tag, 'ext' => 'jpg'];
        }

        // we know we can handle TIFF files directly
        if ($fileType === 'TIFF' && $this->isTiffCompatible()) {
            return ['tag' => 'SourceFile', 'ext' => 'tiff'];
        }

        // extra logic for tiff previews
        foreach ($tiffTagsToCheck as $tag) {
            if (!isset($previewData[0][$tag])) {
                continue;
            }
            if (!$this->isTiffCompatible()) {
                throw new Exception('Needs imagick to extract TIFF previews');
            }
            return ['tag' => $tag, 'ext' => 'tiff'];
        }
        throw new Exception('Unable to find preview data: ' . $cmd . ' -> ' . $json);
    }

    /**
     * @return string
     * @throws Exception
     */
    private function getConverter()
    {
        if (!is_null($this->converter)) {
            return $this->converter;
        }

        $exifToolPath = realpath(__DIR__ . '/../vendor/exiftool/exiftool');

        if (strpos(php_uname("m"), 'x86') === 0 && php_uname("s") === "Linux") {
            // exiftool.bin is a static perl binary which looks up the exiftool script it self.
            $perlBin = $exifToolPath . '/exiftool.bin';
            $perlBinIsExecutable = is_executable($perlBin);

            if (!$perlBinIsExecutable && is_writable($perlBin)) {
                $perlBinIsExecutable = chmod($perlBin, 0744);
            }
            if ($perlBinIsExecutable) {
                $this->converter = $perlBin;
                return $this->converter;
            }
        }

        $exifToolScript = $exifToolPath . '/exiftool';

        $perlBin = \OC_Helper::findBinaryPath('perl');
        if (!is_null($perlBin)) {
            $this->converter = $perlBin . ' ' . $exifToolScript;
            return $this->converter;
        }

        $perlBin = exec("command -v perl");
        if (!empty($perlBin)) {
            $this->converter = $perlBin . ' ' . $exifToolScript;
            return $this->converter;
        }

        throw new Exception('No perl executable found. Camera Raw Previews app will not work.');
    }

    /**
     * @return bool
     */
    private function isTiffCompatible(): bool
    {
        return extension_loaded('imagick') && count(\Imagick::queryformats('TIFF')) > 0;
    }

    private function escapeShellArg($arg): string
    {
        return "'" . str_replace("'", "'\\''", $arg) . "'";
    }

    /**
     * Clean any generated temporary files
     */
    private function cleanTmpFiles()
    {
        foreach ($this->tmpFiles as $tmpFile) {
            unlink($tmpFile);
        }

        $this->tmpFiles = [];
    }

    private function getAVIFPreview(string $imagePath, int $maxX, int $maxY): ?IImage
    {
        //Tried using GD for rotation, but it's not needed as imagick detects it correctly.
        /*
        if (!(imagetypes() & IMG_AVIF)) {
            $this->logger->debug('OC_Image->loadFromFile, AVIF images not supported: ' . $imagePath, ['app' => 'core']);
            return null;
        }

        $gdImage = @imagecreatefromavif($imagePath);
        if (!$gdImage) {
            $this->logger->warning('Failed to create image from AVIF: ' . $imagePath, ['app' => $this->appName]);
            return null;
        }

        // Optional: apply rotation from irot box
        $fp = fopen($imagePath, 'rb');
        if ($fp) {
            $data = fread($fp, 512);
            fclose($fp);

            if ($data !== false) {
                $pos = strpos($data, 'irot');
                if ($pos !== false && ($pos + 4 < strlen($data))) {
                    $rotationByte = ord($data[$pos + 4]);
                    $rotation = match ($rotationByte) {
                        1 => 90,
                        2 => 180,
                        3 => 270,
                        default => 0,
                    };
                    if ($rotation !== 0 && function_exists('imagerotate')) {
                        $rotated = imagerotate($gdImage, $rotation, 0);
                        if ($rotated) {
                            imagedestroy($gdImage);
                            $gdImage = $rotated;
                        }
                    }
                }
            }
        }

        // OCP\Image convert
        ob_start();
        imagejpeg($gdImage, null, 90);
        $imageData = ob_get_clean();
        imagedestroy($gdImage);

        $image = new \OCP\Image();
        $image->loadFromData($imageData);
        $image->scaleDownToFit($maxX, $maxY);
        if (!$image->valid()) {
            $this->logger->warning('Invalid OCP image created from AVIF: ' . $imagePath, ['app' => $this->appName]);
            return null;
        }

        return $image;
        */

        try {
            $imagick = new \Imagick($imagePath);
            $imagick->autoOrient();
            $format = $this->getPreviewFormat();
            $imagick->setImageFormat($format);
            $imagick->setImageCompressionQuality($this->getQuality($format));

            $image = new \OCP\Image();
            $image->loadFromData($imagick->getImageBlob());
            $imagick->clear();

            if (!$image->valid()) {
                $this->logger->warning('Invalid OCP image created from AVIF: ' . $imagePath, ['app' => $this->appName]);
                return null;
            }

            $image->scaleDownToFit($maxX, $maxY);
            return $image;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to process AVIF with Imagick: ' . $e->getMessage(), [
                'app' => $this->appName,
                'file' => $imagePath,
            ]);
            return null;
        }
    }

    protected function getQuality(string $format): int
    {
        if ($format === 'jpg') {
            $format = 'jpeg_quality';
        }

        switch ($format) {
            case 'jpg':
                $format = 'jpeg_quality';
                break;
            case 'webp':
                $format = 'webp_quality';
                break;
            case 'avif':
                $format = 'avif_quality';
                break;
            default:
                $format = 'jpeg_quality';
                break;
        }

        $quality = (int) $this->config->getAppValue('core', $format, '90');
        return min(100, max(10, $quality));
    }

    protected function getPreviewFormat(): string
    {
        return $this->config->getSystemValueString('preview_format', '');
    }
}
