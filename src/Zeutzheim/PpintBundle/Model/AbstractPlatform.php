<?php

namespace Zeutzheim\PpintBundle\Model;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Monolog\Logger;

use Zeutzheim\PpintBundle\Exception\PackageNotFoundException;
use Zeutzheim\PpintBundle\Exception\VersionNotFoundException;
use Zeutzheim\PpintBundle\Exception\DownloadErrorException;

use Zeutzheim\PpintBundle\Model\LanguageManager;
use Zeutzheim\PpintBundle\Model\Language;

use Zeutzheim\PpintBundle\Entity\Platform as Platform;
use Zeutzheim\PpintBundle\Entity\Package;
use Zeutzheim\PpintBundle\Entity\CodeTag;

use Zeutzheim\PpintBundle\Util\Utils;

abstract class AbstractPlatform {

	const PLATFORM_STR_LEN = 12;
	const PACKAGE_STR_LEN = 46;
	const VERSION_STR_LEN = 16;

	const ERR_PACKAGE_NOT_FOUND = 1;
	const ERR_VERSION_NOT_FOUND = 2;
	const ERR_DOWNLOAD_ERROR = 3;
	const ERR_OTHER_ERROR = 4;
	const ERR_DOWNLOAD_NOT_FOUND = 5;
	const ERR_NEEDS_REINDEXING = 6;
	
	public $ERROR_MESSAGES = array(
		AbstractPlatform::ERR_PACKAGE_NOT_FOUND => 'Package not found',
		AbstractPlatform::ERR_VERSION_NOT_FOUND => 'Version not found',
		AbstractPlatform::ERR_DOWNLOAD_ERROR => 'Download failed',
		AbstractPlatform::ERR_OTHER_ERROR => 'Unknown error',
		AbstractPlatform::ERR_DOWNLOAD_NOT_FOUND => 'Download not found',
		AbstractPlatform::ERR_NEEDS_REINDEXING => 'Needs reindexing',
	);
	
	/**
	 * @var EntityManagerInterface
	 */
	private $em;

	/**
	 * @var Logger
	 */
	private $logger;

	/**
	 * @var LanguageManager
	 */
	private $languageManager;

	/**
	 * @var Platform
	 */
	protected $platformEntity;

	/**
	 * @var Platform
	 */
	protected $platformReference;

	/**
	 * @var EntityRepository
	 */
	protected $platformRepo;

	/**
	 * @var EntityRepository
	 */
	protected $packageRepo;

	/**
	 * @var QueryBuilder
	 */
	protected $packageQb;

	/**
	 * constructor
	 */
	public function __construct(EntityManager $em, Logger $logger, LanguageManager $languageManager) {
		$this->em = $em;
		$this->logger = $logger;
		$this->languageManager = $languageManager;
		$this->platformRepo = $this->getEntityManager()->getRepository('ZeutzheimPpintBundle:Platform');
		$this->packageRepo = $this->getEntityManager()->getRepository('ZeutzheimPpintBundle:Package');
	}

	//*******************************************************************

	public function crawlPlatform() {
		$this->getLogger()->notice('> started crawling platform ' . $this->getName());

		$src = $this->httpGet($this->getCrawlUrl());
		if (!$src)
			return false;

		preg_match_all($this->getPackageRegex(), $src, $matches);
		$packages = array_unique($matches[1]);

		foreach ($packages as $packageUri) {
			$package = $this->getPackage($packageUri);
			if (!$package) {
				$package = new Package();
				$package->setName($packageUri);
				$package->setPlatform($this->getPlatformReference());
				$this->getEntityManager()->persist($package);

				$this->log('Package added', $package);
			} else {
				$package->setNew(true);
				$this->log('Package updated', $package);
			}
		}
		$this->getEntityManager()->flush();
		// $this->packageRepo->clear();
		$this->getLogger()->notice('> finished crawling platform ' . $this->getName());
	}

	public function getCachePath(Package $package) {
		$path = WEB_DIRECTORY . '../tmp/' . $package->getPlatform()->getName() . '/' . $package->getName() . '/' . $package->getVersion() . '/';
		if (!file_exists($path . 'ppint_repository'))
			$path = WEB_DIRECTORY . '../tmp/' . $package->getPlatform()->getName() . '/' . $package->getName() . '/';
		return $path;
	}

	public function index(Package $package, $quick = false) {
		if ($quick) {
			if (!$package->getVersion() || !file_exists($this->getCachePath($package) . 'ppint_repository'))
				$quick = false;
		}
		if (!$quick) {
			// Load package-data
			$this->log('Checking package data', $package, Logger::INFO);
			if (!$this->getPackageData($package)) {
				$package->setError(true);
				$this->log('Failed to get package data', $package, Logger::ERROR);
				return false;
			}
	
			// Get description
			if (array_key_exists('description', $package->data))
				$package->setDescription($package->data['description']);
	
			// Get package name if it's case-sensitive
			if (array_key_exists('packagename', $package->data))
				$package->setName($package->data['packagename']);
	
			// Check if a version was found
			if (!array_key_exists('version', $package->data) || !$package->data['version']) {
				$this->log('No version found', $package, Logger::WARNING);
				$package->setError(AbstractPlatform::ERR_VERSION_NOT_FOUND);
				return;
			}
			
			// Check, if version is the same as the last one indexed (master-versions)
			if ($package->getVersion() == $package->data['version'] && array_key_exists('version-ref', $package->data)) {
				if ($package->data['version-ref'] == $package->getVersionReference()) {
					$package->setNew(0);
					return true;
				}
			}
			$package->setVersion($package->data['version']);
		}

		$cachePath = $this->getCachePath($package);
		$cacheIdFile = $cachePath . 'ppint_repository';
		$cacheVersion = file_exists($cacheIdFile) ? file_get_contents($cacheIdFile) : false;
		
		// if ($cacheVersion != $package->getVersion()) {
		if ($cacheVersion === false) {
			// Prepare download directory
			exec('rm -rf ' . escapeshellarg($cachePath));
			mkdir($cachePath, 0777, true);

			// Download package source
			$this->log('Downloading package', $package, Logger::INFO);
			if (!$this->downloadPackage($package, $cachePath)) {
				return false;
			}
			// Delete .git cache
			if (file_exists($cachePath . '.git/'))
				exec('rm -rf ' . escapeshellarg($cachePath . '.git/'));

			// Create cache-file
			file_put_contents($cachePath . 'ppint_repository', $package->getVersion());
		} else {
			if ($cachePath == WEB_DIRECTORY . '../tmp/' . $package->getPlatform()->getName() . '/' . $package->getName() . '/' . $package->getVersion() . '/') {
				$newCachePath = WEB_DIRECTORY . '../tmp/' . $package->getPlatform()->getName() . '/' . $package->getName() . '/';
				$this->log('Moving cache to ' . $newCachePath, $package, Logger::INFO);
				exec('mv ' . escapeshellarg($cachePath) . '* ' . escapeshellarg($newCachePath));
				$cachePath = $newCachePath;
			}
			file_put_contents($cachePath . 'ppint_repository', $package->getVersion());
		}

		// Index files
		$this->log('Indexing package', $package, Logger::INFO);
		$i = 0;
		$index = array();
		$files = $this->recursiveScandir($cachePath);
		foreach ($files as $file) {
			foreach ($this->getLanguageManager()->getLanguages() as $lang) {
				if ($lang->checkFilename($file)) {
					$this->log('Indexing ' . $file, $package, Logger::DEBUG);
					$fileIndex = array($lang->getName() => $lang->analyzeProvide(file_get_contents($file)));
					PackageManager::mergeIndex($index, $fileIndex);
				}
			}
		}
		// echo "\n\n"; print_r($index); exit;
		$index = PackageManager::collapseIndex($index);
		// echo "\n\n"; print_r($index); exit;

		$package->setClasses(array_get($index, 'class'));
		$package->setNamespaces(array_get($index, 'namespace'));
		$package->setLanguages(array_get($index, 'language'));
		if (array_key_exists('tag', $index)) {
			$tags = $index['tag'];
			
			$max = 0;
			foreach ($tags as $count) $max = max($count, $max);
			foreach ($tags as &$count) $count /= $max;
			$package->setCodeTagsMaximum($max);

			foreach ($package->getCodeTags() as $tag) {
				if (array_key_exists($tag->getName(), $tags)) {
					$tag->setCount($tags[$tag->getName()]);
					unset($tags[$tag->getName()]);
				} else {
					$package->removeCodeTag($tag);
					// $this->getEntityManager()->remove($tag);
				}
			}
			foreach ($tags as $name => $count) {
				$package->addCodeTag(new CodeTag($package, $name, $count));
				// $this->getEntityManager()->persist(new CodeTag($package, $name, $count));
			}
		}
		$package->setError(null);
		$package->setNew(false);
		$package->setIndexedDate(new \DateTime());
		$this->getEntityManager()->flush();

		$this->log('indexed', $package, Logger::DEBUG);
		return true;
	}

	public function clearIndex(Package $package) {
		$package->setClasses(null);
		$package->setNamespaces(null);
		$package->setIndexed(null);
	}

	public function downloadPackage(Package $package, $path, $version = null) {
		$err = $this->doDownload($package, $path, $version ? $version : $package->getVersion());
		if (!$err)
			return true;
		$this->log('download error: ' . $this->ERROR_MESSAGES[$err], $package, Logger::ERROR);
		$package->setError($err);
		$this->getEntityManager()->flush();
		return false;
	}

	//*******************************************************************

	public abstract function doDownload(Package $package, $path, $version);

	//*******************************************************************

	public abstract function getName();

	public abstract function getBaseUrl();

	public abstract function getCrawlUrl();

	public abstract function getPackageRegex();

	public abstract function getMasterVersion();

	public abstract function getPackageData(Package $package);

	//*******************************************************************

	/**
	 * @return array (integer)
	 */
	public function selectCrawlPackages() {
		$qb = $this->packageRepo->createQueryBuilder('pkg');
		$qb->select('pkg.id')->where('pkg.crawled = FALSE')->andWhere('pkg.platform = ' . $this->getPlatformEntity()->getId())->orderBy('pkg.addedDate');
		return $qb->getQuery()->getResult();
	}

	/**
	 * @return int
	 */
	public function getManagedEntityCount() {
		$count = 0;
		foreach ($this->getEntityManager()->getUnitOfWork()->getIdentityMap() as $entities)
			$count += count($entities);
		return $count;
	}

	/**
	 * @return Package
	 */
	public function getPackage($name) {
		if (!$this->packageQb) {
			$this->packageQb = $this->packageRepo->createQueryBuilder('e');
			$this->packageQb->where('e.platform = ?1');
			$this->packageQb->andWhere('e.name = ?2');
		}
		return $this->packageQb->setParameters(array(1 => $this->getPlatformEntity()->getId(), 2 => $name))->getQuery()->getOneOrNullResult();
	}

	//*******************************************************************

	public function httpGet($url) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_USERAGENT, 'github-olee');
		//curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		//curl_setopt($ch, CURLOPT_USERPWD, 'USERNAME:PASSWORD');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_URL, $url);
		$result = curl_exec($ch);
		$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($statusCode != 200)
			return false;
		return $result;
	}

	public function downloadFile($url, $path) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_FILE, fopen($path, 'w'));
		curl_setopt($ch, CURLOPT_URL, $url);
		$result = curl_exec($ch);
		curl_close($ch);
		return $result;
	}

	public function log($msg, $obj = null, $logLevel = Logger::INFO) {
		if ($obj) {
			if ($obj instanceof Platform)
				$msg = str_pad($obj->getName(), AbstractPlatform::PLATFORM_STR_LEN) . ' ' . $msg;
			elseif ($obj instanceof Package)
				$msg = str_pad($obj->getPlatform()->getName(), AbstractPlatform::PLATFORM_STR_LEN) . ' ' . 
					str_pad($obj->getName(), AbstractPlatform::PACKAGE_STR_LEN) . ' ' . 
					str_pad($obj->getVersion(), AbstractPlatform::VERSION_STR_LEN) . ' ' . $msg;
			elseif (is_string($obj))
				$msg = $obj . ' ' . $msg;
		}
		$this->getLogger()->log($logLevel, $msg);
	}

	public function recursiveScandir($path, $excludeHidden = true) {
		if ($path[strlen($path) - 1] != '/')
			$path .= '/';
		$files = array();
		foreach (scandir($path) as $file) {
			if ($excludeHidden && $file[0] == '.')
				continue;
			if ($file == '.' || $file == '..')
				continue;
			$fn = $path . $file;
			if (is_link($fn))
				continue;
			if (is_dir($fn)) {
				$files = array_merge($files, $this->recursiveScandir($fn . '/'));
			} else if (is_file($fn)) {
				$files[] = $fn;
			}
		}
		return $files;
	}

	//*******************************************************************

	/**
	 * @return EntityManager
	 */
	public function getEntityManager() {
		return $this->em;
	}

	/**
	 * @return Logger
	 */
	public function getLogger() {
		return $this->logger;
	}

	/**
	 * @return LanguageManager
	 */
	public function getLanguageManager() {
		return $this->languageManager;
	}

	/**
	 * @return Platform
	 */
	public function getPlatformEntity() {
		if (!$this->platformEntity) {
			$this->platformEntity = $this->platformRepo->findOneByName($this->getName());
			if (!$this->platformEntity) {
				$this->platformEntity = new Platform();
				$this->platformEntity->setName($this->getName());
				$this->platformEntity->setBaseUrl($this->getBaseUrl());
				$this->getEntityManager()->persist($this->platformEntity);
				$this->getEntityManager()->flush();
			}
		}
		return $this->platformEntity;
	}

	/**
	 * @return Platform
	 */
	public function getPlatformReference() {
		if (!$this->platformReference) {
			$this->platformReference = $this->getEntityManager()->getReference('ZeutzheimPpintBundle:Platform', $this->getPlatformEntity()->getId());
		}
		return $this->platformReference;
	}

	//*******************************************************************
}

function array_get($array, $index) {
	if (array_key_exists($index, $array))
		return $array[$index];
	else
		return null;
}