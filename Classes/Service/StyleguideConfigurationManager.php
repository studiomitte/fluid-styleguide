<?php
declare(strict_types=1);

namespace Sitegeist\FluidStyleguide\Service;

use Sitegeist\FluidStyleguide\Domain\Model\Package;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

class StyleguideConfigurationManager
{
    /**
     * @var YamlFileLoader
     */
    protected $yamlFileLoader;

    /**
     * @var PackageManager
     */
    protected $packageManager;

    /**
     * @var string
     */
    protected $defaultConfigurationFile = 'EXT:fluid_styleguide/Configuration/Yaml/FluidStyleguide.yaml';

    /**
     * @var array
     */
    protected $mergedConfiguration;

    public function __construct(YamlFileLoader $yamlFileLoader, PackageManager $packageManager)
    {
        $this->yamlFileLoader = $yamlFileLoader;
        $this->packageManager = $packageManager;
        $this->loadConfiguration();
    }

    public function loadConfiguration()
    {
        $this->mergedConfiguration = $this->yamlFileLoader->load($this->defaultConfigurationFile)['FluidStyleguide'];

        // Merge default configuration with custom configuration
        $activeExtensions = $this->packageManager->getActivePackages();
        foreach ($activeExtensions as $package) {
            // Skip default configuration
            if ($package->getPackageKey() === 'fluid_styleguide') {
                continue;
            }

            $packageConfiguration = $package->getPackagePath() . 'Configuration/Yaml/FluidStyleguide.yaml';
            if (file_exists($packageConfiguration)) {
                ArrayUtility::mergeRecursiveWithOverrule(
                    $this->mergedConfiguration,
                    $this->yamlFileLoader->load($packageConfiguration)['FluidStyleguide'] ?? []
                );
            }
        }

        // Sanitize component assets
        $this->mergedConfiguration['ComponentAssets']['Global']['Css'] = $this->sanitizeComponentAssets(
            $this->mergedConfiguration['ComponentAssets']['Global']['Css'] ?? []
        );
        $this->mergedConfiguration['ComponentAssets']['Global']['Javascript'] = $this->sanitizeComponentAssets(
            $this->mergedConfiguration['ComponentAssets']['Global']['Javascript'] ?? []
        );
        foreach ($this->mergedConfiguration['ComponentAssets']['Packages'] as &$assets) {
            $assets['Css'] = $this->sanitizeComponentAssets($assets['Css'] ?? []);
            $assets['Javascript'] = $this->sanitizeComponentAssets($assets['Javascript'] ?? []);
        }

        $this->mergedConfiguration['ResponsiveBreakpoints'] = array_filter($this->mergedConfiguration['ResponsiveBreakpoints']);
    }

    public function getFeatures(): array
    {
        return $this->mergedConfiguration['Features'];
    }

    public function isFeatureEnabled(string $feature): bool
    {
        return !empty($this->mergedConfiguration['Features'][$feature]);
    }

    public function getComponentContext(): string
    {
        return $this->mergedConfiguration['ComponentContext'] ?? '|';
    }

    public function getGlobalCss(): array
    {
        return $this->mergedConfiguration['ComponentAssets']['Global']['Css'] ?? [];
    }

    public function getGlobalJavascript(): array
    {
        return $this->mergedConfiguration['ComponentAssets']['Global']['Javascript'] ?? [];
    }

    public function getCssForPackage(Package $package): array
    {
        return $this->mergedConfiguration['ComponentAssets']['Packages'][$package->getNamespace()]['Css'] ?? [];
    }

    public function getJavascriptForPackage(Package $package): array
    {
        return $this->mergedConfiguration['ComponentAssets']['Packages'][$package->getNamespace()]['Javascript'] ?? [];
    }

    public function getResponsiveBreakpoints(): array
    {
        return $this->mergedConfiguration['ResponsiveBreakpoints'] ?? [];
    }

    public function getTemplateRootPaths(): array
    {
        return $this->mergedConfiguration['Fluid']['TemplateRootPaths'] ?? [];
    }

    public function getPartialRootPaths(): array
    {
        return $this->mergedConfiguration['Fluid']['PartialRootPaths'] ?? [];
    }

    public function getLayoutRootPaths(): array
    {
        return $this->mergedConfiguration['Fluid']['LayoutRootPaths'] ?? [];
    }

    public function getBrandingLogo(): string
    {
        $logo = $this->mergedConfiguration['Branding']['Logo'] ?? '';
        if (!empty($logo)) {
            $logo = GeneralUtility::getFileAbsFileName($logo);
            $logo = substr(
                $logo,
                strlen(GeneralUtility::getIndpEnv('TYPO3_DOCUMENT_ROOT'))
            );
        }

        return $logo;
    }

    public function getBrandingBodyBackground(): string
    {
        return $this->mergedConfiguration['Branding']['BodyBackground'] ?? '';
    }

    public function getBrandingHeaderBackground(): string
    {
        return $this->mergedConfiguration['Branding']['HeaderBackground'] ?? '';
    }

    protected function sanitizeComponentAssets($assets)
    {
        if (is_string($assets)) {
            $assets = [$assets];
        } elseif (!is_array($assets)) {
            return [];
        }

        $baseUrl = $GLOBALS['TYPO3_REQUEST']->getAttribute('site')->getBase();
        foreach ($assets as $key => &$asset) {
            if (!static::isRemoteUri($asset)) {
                // TODO generate relative urls
                $asset = GeneralUtility::getFileAbsFileName($asset);
                if ($asset) {
                    $asset = $baseUrl->withPath(
                        $baseUrl->getPath() .
                        PathUtility::stripPathSitePrefix(GeneralUtility::getFileAbsFileName($asset))
                    );
                } else {
                    unset($assets[$key]);
                }
            }
        }
        return $assets;
    }

    /**
     * Checks if the provided uri is a valid remote uri
     *
     * @param string $uri
     * @return boolean
     */
    protected static function isRemoteUri(string $uri): bool
    {
        $scheme = parse_url($uri, PHP_URL_SCHEME);
        return ($scheme && in_array(strtolower($scheme), ['http', 'https']));
    }
}
