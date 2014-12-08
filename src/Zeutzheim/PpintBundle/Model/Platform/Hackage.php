<?php

namespace Zeutzheim\PpintBundle\Model\Platform;

use Doctrine\ORM\EntityManager;

use Zeutzheim\PpintBundle\Exception\PackageNotFoundException;
use Zeutzheim\PpintBundle\Exception\VersionNotFoundException;
use Zeutzheim\PpintBundle\Exception\DownloadErrorException;

use Zeutzheim\PpintBundle\Model\Platform;

use Zeutzheim\PpintBundle\Entity\Platform as PlatformEntity;
use Zeutzheim\PpintBundle\Entity\Package;
use Zeutzheim\PpintBundle\Entity\Version;

class Hackage extends Platform {

	public function doDownloadVersion(Version $version, $path) {
		$fn = $version->getPackage()->getUrl() . '-' . $version->getName() . '.tar.gz';
		$url = 'http://hackage.haskell.org/package/' . $version->getPackage()->getUrl() . '-' . $version->getName() . '/' . $fn;

		if (!$this->downloadFile($url, $path . $fn))
			throw new DownloadErrorException($version);
		
		// Extract files
		exec('tar -zxf ' . escapeshellarg($path . $fn) . ' --strip-components=1 -C ' . escapeshellarg($path) . ' && rm ' . escapeshellarg($path . $fn), $output, $success);
		if ($success !== 0)
			throw new DownloadErrorException($version);
		
		return true;
	}

	//*******************************************************************

	public function getName() {
		return 'hackage';
	}

	public function getBaseUrl() {
		return 'https://hackage.haskell.org/package/';
	}

	public function getCrawlUrl() {
		return 'https://hackage.haskell.org/packages/recent.rss';
	}

	public function getMasterVersion() {
		return null;
	}

	public function getPackageRegex() {
		return '@<link>http://hackage.haskell.org/package/([^<]*)-[\d\.]*</link>@i';
	}

	public function getPackageData(Package $package) {
        $src = $this->httpGet($this->getBaseUrl() . $package->getName());
        if ($src === false) {
            return false;
        }
        
        $pkgData = array();
        
        // Get versions
        preg_match_all('@href="/package/[^"]*-([\d\.]*\d)@i', $src, $matches);
        $pkgData['versions'] = array_unique($matches[1]);
        
        // Get description
        preg_match('@id="content"[\s\S]*<div[\s\S]*</div>([\s\S]*)<hr@imx', $src, $matches);
        if (isset($matches[1])) {
            $pkgData['description'] = strip_tags($matches[1]);
        }
        
        return $pkgData;
	}

}
