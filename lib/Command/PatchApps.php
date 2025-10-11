<?php
namespace OCA\CameraRawPreviews\Command;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class PatchApps extends Command {

    private IAppManager $appManager;
    private LoggerInterface $logger;
    private string $appName;

    public function __construct(IAppManager $appManager, LoggerInterface $logger) {
        parent::__construct();
        $this->appManager = $appManager;
        $this->logger = $logger;
        $this->appName = 'camerarawpreviews';
    }

    protected function configure() {
        $this
            ->setName('camerarawpreviews:patch')
            ->setDescription('Adds AVIF support to the Photos and Memories apps by patching their config.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $appsToPatch = ['photos', 'memories'];
        $patchApplied = false;

        foreach ($appsToPatch as $appId) {
            if (!$this->appManager->isInstalled($appId)) {
                $output->writeln("<comment>Skipping '$appId': App is not enabled.</comment>");
                continue;
            }

            try {
                $appPath = $this->appManager->getAppPath($appId);
                $targetFile = $appPath . '/lib/AppInfo/Application.php';

                if (!file_exists($targetFile)) {
                    $output->writeln("<comment>Skipping '$appId': Target file not found at $targetFile.</comment>");
                    continue;
                }
                
                if (!is_writable($targetFile)) {
                    $output->writeln("<error>Error for '$appId': Target file is not writable. Please check permissions for: $targetFile</error>");
                    continue;
                }

                $content = file_get_contents($targetFile);

                if (strpos($content, "'image/avif'") !== false) {
                    $output->writeln("<info>Patch for '$appId' already applied. Nothing to do.</info>");
                    continue;
                }

                $pattern = '/(public const IMAGE_MIMES\s*=\s*)(\[.*?\];)/s';
                if (preg_match($pattern, $content, $matches)) {
                    $constDeclaration = $matches[1];
                    $arrayString = $matches[2];

                    $arrayStringClean = preg_replace('/\s*\/\/.*$/m', '', $arrayString);
                    preg_match_all("/'([^']+)'/", $arrayStringClean, $mimeMatches);
                    $mimes = $mimeMatches[1] ?? [];

                    if (!in_array('image/avif', $mimes)) {
                        array_unshift($mimes, 'image/avif');
                    }
                    
                    $newMimesString = "[\n";
                    foreach ($mimes as $mime) {
                        $newMimesString .= "        '$mime',\n";
                    }
                    $newMimesString .= "    ];";
                    
                    $newContent = str_replace($arrayString, $newMimesString, $content);

                    if ($newContent !== $content) {
                        if (file_put_contents($targetFile, $newContent) === false) {
                            $output->writeln("<error>Failed to write patch to '$targetFile'.</error>");
                        } else {
                            $output->writeln("<info>Successfully patched '$appId' to include AVIF support.</info>");
                            $patchApplied = true;
                        }
                    }
                } else {
                    $output->writeln("<warning>Could not find IMAGE_MIMES constant in '$targetFile' for '$appId'.</warning>");
                }
            } catch (\Throwable $e) {
                $output->writeln("<error>An error occurred while trying to patch '$appId': " . $e->getMessage() . "</error>");
                $this->logger->error("An error occurred while trying to patch '$appId': " . $e->getMessage(), ['app' => $this->appName]);
            }
        }
        
        if ($patchApplied) {
             $output->writeln("\n<info>Patching complete. It is recommended to run 'occ maintenance:repair'.</info>");
        }

        return Command::SUCCESS;
    }
}