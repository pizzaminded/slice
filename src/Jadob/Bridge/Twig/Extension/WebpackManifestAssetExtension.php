<?php

namespace Jadob\Bridge\Twig\Extension;

/**
 * @see https://www.npmjs.com/package/webpack-manifest-plugin
 * @author pizzaminded <miki@appvende.net>
 * @license MIT
 */
class WebpackManifestAssetExtension extends  \Twig_Extension
{
    /**
     * @var string[]
     */
    protected $manifest;

    /**
     * WebpackManifestAssetExtension constructor.
     * @param string[] $manifest
     */
    public function __construct(array $manifest)
    {
        $this->manifest = $manifest;
    }

    /**
     * @return \Twig\TwigFunction[]
     */
    public function getFunctions()
    {
        return [
            new \Twig_SimpleFunction('asset_from_manifest', [$this, 'getAssetFromManifest'])
        ];
    }

    /**
     * @param string $assetName
     * @return string
     */
    public function getAssetFromManifest(string $assetName): string
    {

        if(isset($this->manifest[$assetName])) {
            return $this->manifest[$assetName];
        }

        throw new \RuntimeException('Could not find "'.$assetName.'" in webpack manifest file');
    }
}