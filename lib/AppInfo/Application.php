<?php

namespace OCA\CameraRawPreviews\AppInfo;

use OCA\CameraRawPreviews\RawPreviewIProviderV2;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\AppFramework\App;
//use OCP\App\IAppManager;
use OCP\Util;
//use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap
{
    private $appName = 'camerarawpreviews';

    public function __construct()
    {
        parent::__construct($this->appName);
    }

    public function register(IRegistrationContext $context): void
    {
        include_once __DIR__ . '/../../vendor/autoload.php';

        $this->registerProvider($context);
    }

    private function registerScripts(IBootContext $context)
    {

        if (!class_exists('\OCA\Viewer\Event\LoadViewer')) {
            return;
        }

        $eventDispatcher = $context->getServerContainer()->get(IEventDispatcher::class);
        $eventDispatcher->addListener(\OCA\Viewer\Event\LoadViewer::class, function () {
            Util::addInitScript($this->appName, 'register-viewer');  // adds js/script.js
        });
    }

    private function registerProvider(IRegistrationContext $context)
    {
        $server = $this->getContainer()->getServer();
        $mimeTypeDetector = $server->getMimeTypeDetector();
        $mimeTypeDetector->getAllMappings(); // is really needed

        $mimesToDetect = [
            'indd' => ['image/x-indesign'],
            '3fr' => ['image/x-dcraw'],
            'arw' => ['image/x-dcraw'],
            'cr2' => ['image/x-dcraw'],
            'cr3' => ['image/x-dcraw'],
            'crw' => ['image/x-dcraw'],
            'dng' => ['image/x-dcraw'],
            'erf' => ['image/x-dcraw'],
            'fff' => ['image/x-dcraw'],
            'iiq' => ['image/x-dcraw'],
            'kdc' => ['image/x-dcraw'],
            'mrw' => ['image/x-dcraw'],
            'nef' => ['image/x-dcraw'],
            'nrw' => ['image/x-dcraw'],
            'orf' => ['image/x-dcraw'],
            'ori' => ['image/x-dcraw'],
            'pef' => ['image/x-dcraw'],
            'raf' => ['image/x-dcraw'],
            'rw2' => ['image/x-dcraw'],
            'rwl' => ['image/x-dcraw'],
            'sr2' => ['image/x-dcraw'],
            'srf' => ['image/x-dcraw'],
            'srw' => ['image/x-dcraw'],
            'tif' => ['image/x-dcraw'],
            'tiff' => ['image/x-dcraw'],
            'x3f' => ['image/x-dcraw'],
            'avif' => ['image/avif'],
        ];

        $mimeTypeDetector->registerTypeArray($mimesToDetect);
        $context->registerPreviewProvider(RawPreviewIProviderV2::class, '/^((image\/x-dcraw)|(image\/x-indesign)|(image\/avif))(;+.*)*$/');
    }

    public function boot(IBootContext $context): void
    {
        $this->registerScripts($context);

        // Reflection
        /*try {
            // Prüfen ob Photos App geladen ist
            if (class_exists('\OCA\Photos\AppInfo\Application')) {
                $ref = new \ReflectionClass('\OCA\Photos\AppInfo\Application');

                // Konstanten holen
                $consts = $ref->getConstants();

                if (isset($consts['IMAGE_MIMES'])) {
                    $imageMimes = $consts['IMAGE_MIMES'];

                    if (!in_array('image/avif', $imageMimes, true)) {
                        $imageMimes[] = 'image/avif';
                        #$this->logger->info('Added AVIF to Photos IMAGE_MIMES');
                    }

                    // Trick: statisches Property hinzufügen, das dieselben Daten enthält
                    // Damit spätere Aufrufe darauf zugreifen können
                    $refProperty = $ref->getProperty('IMAGE_MIMES');
                    $refProperty->setAccessible(true);
                    $refProperty->setValue(null, $imageMimes);
                }
            }
        } catch (\Throwable $e) {
            #$this->logger->error('Failed to extend Photos IMAGE_MIMES: ' . $e->getMessage());
        }
        */

        // class_alias
        /*
        spl_autoload_register(function ($class) {
            if ($class === 'OCA\\Photos\\AppInfo\\Application') {
                require_once '/var/www/nextcloud/apps/photos/lib/AppInfo/Application.php';
                class_alias($class, 'OCA\\Photos\\AppInfo\\ApplicationOriginal');
                eval('
            namespace OCA\\Photos\\AppInfo;
            class Application extends \\OCA\\Photos\\AppInfo\\ApplicationOriginal {
                public const IMAGE_MIMES = [
                    "image/png", "image/jpeg", "image/heic", "image/avif"
                ];
            }
        ');
            }
        });
        */

        // This autoloader hack dynamically adds AVIF support to the Photos and Memories apps.
        // It runs before the original classes are loaded and replaces them in memory with a patched version.
        // Only works with memories.
        /*
        spl_autoload_register(function ($class) {
            $appsToPatch = [
                // Target class => App ID
                'OCA\\Photos\\AppInfo\\Application'   => 'photos',
                'OCA\\Memories\\AppInfo\\Application' => 'memories',
            ];

            // Check if the class being loaded is one we want to patch
            if (!array_key_exists($class, $appsToPatch)) {
                return;
            }

            $appId = $appsToPatch[$class];
            $appManager = \OC::$server->getAppManager();

            // Only proceed if the target app is enabled
            if (!$appManager->isInstalled($appId)) {
                return;
            }

            // Define the path to the original file
            $appPath = $appManager->getAppPath($appId);
            $originalFile = $appPath . '/lib/AppInfo/Application.php';

            if (!file_exists($originalFile)) {
                return;
            }

            // Load the original class file
            require_once $originalFile;

            // Create an alias for the original class so we can extend it
            $originalClassName = $class . 'Original';
            if (!class_alias($class, $originalClassName)) {
                // Failsafe in case this runs more than once
                return;
            }

            // IMPORTANT: Copy the current list from the target app's file and just add 'image/avif'.
            // This ensures you don't miss any other image types they might add in the future.
            $patchedMimes = [
                'image/png',
                'image/jpeg',
                'image/gif',
                'image/heic',
                'image/heif',
                'image/avif', // Our addition
            ];
            $mimesString = "['" . implode("', '", $patchedMimes) . "']";

            // Use eval() to create a new class definition with the same name,
            // which extends the original and overrides the constant.
            $namespace = substr($class, 0, strrpos($class, '\\'));
            eval("
            namespace " . $namespace . ";
            class Application extends \\" . $originalClassName . " {
                public const IMAGE_MIMES = " . $mimesString . ";
            }
        ");
        }, true, true); // The 'true, true' parameters ensure this autoloader runs first
        */

        //This works, but a command is better.
        /*
        $container = $context->getServerContainer();
        //$appManager = $container->get(IAppManager::class);
        $logger = $container->get(LoggerInterface::class);
        $appManager = \OC::$server->getAppManager();
        $appsToPatch = ['photos', 'memories'];

        foreach ($appsToPatch as $appId) {
            if (!$appManager->isInstalled($appId)) {
                continue;
            }

            try {
                $appPath = $appManager->getAppPath($appId);
                $targetFile = $appPath . '/lib/AppInfo/Application.php';

                if (!file_exists($targetFile) || !is_writable($targetFile)) {
                    $logger->error("Patcher: Target file not found or not writable for $appId: $targetFile. Please check permissions.", ['app' => $this->appName]);
                    continue;
                }

                $content = file_get_contents($targetFile);

                if (strpos($content, "'image/avif'") !== false) {
                    continue;
                }
                
                $pattern = '/(public const IMAGE_MIMES\s*=\s*\[\s*\n)/';
                
                if (preg_match($pattern, $content)) {
                    $replacement = "$1    'image/avif',\n";
                    $newContent = preg_replace($pattern, $replacement, $content, 1);

                    if ($newContent !== null && $newContent !== $content) {
                        if (file_put_contents($targetFile, $newContent) === false) {
                            $logger->error("Failed to write patch to $targetFile.", ['app' => $this->appName]);
                        } else {
                            $logger->info("Successfully patched $appId to include AVIF support.", ['app' => $this->appName]);
                        }
                    }
                } else {
                    $logger->warning("Could not find a suitable place for IMAGE_MIMES constant in $targetFile for $appId. The app might have been updated.", ['app' => $this->appName]);
                }
            } catch (\Throwable $e) {
                $logger->error("An error occurred while trying to patch $appId: " . $e->getMessage(), ['app' => $this->appName]);
            }
        }
        */
    }
}
