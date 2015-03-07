<?php
/**
 * @author Ross Perkins <ross@vubeology.com>
 */

namespace Vube\VagrantBoxer;

use Vube\VagrantBoxer\Exception;
use Vube\VagrantBoxer\Exception\MissingArgumentException;
use Vube\FileSystem\PathTranslator;


/**
 * Boxer class
 *
 * @author Ross Perkins <ross@vubeology.com>
 */
class Boxer {

	const DEFAULT_CONFIG = 'default';

	private $stderr;
	private $verbose = false;

	private $uploadEnabled = true;
	private $uploadBaseUri = null;
    private $uploadMethod = 'rsync -av --rsh=ssh --progress';

	private $majorVersion;
	private $urlTemplate;
	private $forceWriteMetadata = false;

	private $name;
	private $vagrantFile;
	private $boxerId;
	private $version = null;
	private $url;

	private $provider = 'virtualbox';
	private $updateVersion;
	private $repackage;

	private $pathToVagrant = 'vagrant';

	private $baseName = null;
	private $defaultUrlTemplatePrefix = '{{download_url_prefix}}{{path_info}}/'; // see https://github.com/vube/vagrant-catalog
	private $defaultUrlTemplateSuffix = '{{name}}-{{version}}-{{provider}}.box';
	private $defaultMajorVersion = 0;
    private $bIsNewMajorVersion = false;
	private $boxerConfigFilename = 'boxer.json'; // in current directory
	private $metadataJsonFilename = 'metadata.json'; // in current directory
    private $vagrantBoxOutputFilename = 'package.box'; // in $vagrantBoxOutputDirectory directory
	private $vagrantBoxOutputDirectory = '.'; // in current directory

    private $testCallback = null;

    private $bIsTestMode = false;

	private $metadata = null;

	private $createdFiles = array();

	public function __construct()
	{
		$this->stderr = STDERR;
		$this->updateVersion = true;
		$this->repackage = true;
	}

	public function getName()
	{
		return $this->name;
	}

	public function getVagrantFile()
	{
		return $this->vagrantFile;
	}

	public function getBoxerId()
	{
		return $this->boxerId;
	}

	public function getProvider()
	{
		return $this->provider;
	}

	public function getMetaData()
	{
		return $this->metadata;
	}

	public function getVersion()
	{
		return $this->version;
	}

    public function getUrl()
    {
        return $this->url;
    }

    public function isNewMajorVersion()
    {
        return $this->bIsNewMajorVersion;
    }

    public function isTestMode()
    {
        return $this->bIsTestMode;
    }

    public function silenceStderr()
	{
		$this->stderr = null;
	}

    public function setTestCallback($callback)
    {
        $this->testCallback = $callback;
    }

    public function makeTestCallback($message)
    {
        if(is_callable($this->testCallback))
            call_user_func($this->testCallback, $message);
        return 0;
    }

	public function write()
	{
		if(! $this->verbose)
			return;

		$msg = implode("", func_get_args());
		echo $msg;
	}

	public function writeStderr()
	{
		if($this->stderr === null)
			return;

		$msg = implode("", func_get_args());
		fwrite($this->stderr, $msg);
	}

	/**
	 * @param array $args List of arguments
	 * @param int $i Current index of $args list
	 * @return string
	 * @throws Exception\MissingArgumentException
	 */
	public function getNextArg($args, $i)
	{
		if($i+1 < count($args))
		{
			$value = $args[$i+1];

			// Only return this if it doesn't look like another parameter
			if(substr($value, 0, 2) !== '--')
				return $value;
		}

		throw new MissingArgumentException($args[$i]);
	}

	/**
	 * @param array $args Value of $_SERVER['argv']
	 * @throws Exception
	 */
	public function readCommandLine($args=array())
	{
		array_shift($args); // Remove program name from the front

		$n = count($args);
		for($i=0; $i<$n; $i++)
		{
			$arg = $args[$i];
			switch($arg)
			{
                case '--test':
                    $this->bIsTestMode = true;
                    break;

                case '--verbose':
					$this->verbose = true;
					break;

                case '--vagrant':
					$this->pathToVagrant = $this->getNextArg($args, $i);
                    $i++;
					break;

				case '--vagrant-output-file':
					$this->vagrantBoxOutputFilename = $this->getNextArg($args, $i);
					$i++;
					break;

                case '--vagrant-output-dir':
                    $this->vagrantBoxOutputDirectory = $this->getNextArg($args, $i);
                    $i++;
                    break;

                case '--no-upload':
					$this->uploadEnabled = false;
					break;

                case '--upload-method':
                    $this->uploadMethod = $this->getNextArg($args, $i);
                    $i++;
                    break;

				case '--no-bump-version':
					$this->updateVersion = false;
					break;

				case '--keep-package':
					$this->repackage = false;
					break;

				case '--config-file':
					$this->boxerConfigFilename = $this->getNextArg($args, $i);
					$i++;
					break;

				case '--metadata-file':
					$this->metadataJsonFilename = $this->getNextArg($args, $i);
					$i++;
					break;

				case '--force-write-metadata':
					$this->forceWriteMetadata = true;
					break;

				case '--base':
					$this->baseName = $this->getNextArg($args, $i);
					$i++;
					break;

				case '--vagrantfile':
					$this->vagrantFile = $this->getNextArg($args, $i);
					$i ++;
					break;

				case '--boxer-id':
					$this->boxerId = $this->getNextArg($args, $i);
					$i++;
					break;

				case '--url':
					$this->defaultUrlTemplatePrefix = '';
					$this->defaultUrlTemplateSuffix = $this->getNextArg($args, $i);
					$i++;
					break;

				case '--url-prefix':
					$this->defaultUrlTemplatePrefix = $this->getNextArg($args, $i);
					$i++;
					break;

				case '--url-suffix':
					$this->defaultUrlTemplateSuffix = $this->getNextArg($args, $i);
					$i++;
					break;

				case '--upload-base-uri':
					$this->uploadBaseUri = $this->getNextArg($args, $i);
					$i++;
					break;

				case '--major-version':
					$this->defaultMajorVersion = $this->getNextArg($args, $i);
					$i++;
					break;

				default:
					throw new Exception("Unrecognized command-line argument: $arg");
					break;
			}
		}
	}

	public function getDefaultBoxerConfig()
	{
		//if($this->baseName === null)
		//	throw new Exception("Must set --base parameter when not using boxer.json configuration");

		return array(
			'vm-name' => $this->baseName,
			'version' => $this->defaultMajorVersion,
			'url-template' => $this->defaultUrlTemplatePrefix . $this->defaultUrlTemplateSuffix,
		);
	}

	public function readBoxerConfig()
	{
		// If they decided to use default values instead of a config, do so
		if($this->boxerConfigFilename === self::DEFAULT_CONFIG)
			return null;

		$json = @file_get_contents($this->boxerConfigFilename);

		if($json === false)
		{
			$cwd = getcwd();
			$this->writeStderr("Warning: No {$this->boxerConfigFilename} (current dir: $cwd), using default\n");
			return null;
		}

		$obj = json_decode($json, true);

		if(! is_array($obj))
			throw new Exception\InvalidInputException("{$this->boxerConfigFilename} must contain an associative array configuration");

		if(! isset($obj['vm-name']))
			throw new Exception\InvalidInputException("{$this->boxerConfigFilename} does not define a vm-name");

		if(! isset($obj['url-template']))
		{
			if(! isset($obj['download-url-prefix']))
			{
				// Set the default download-url-prefix, which requires vagrant-catalog to
				// be serving up the metadata.json file, and allows vagrant-catalog to compute
				// the download url based on its install location.
				//
				// See https://github.com/vube/vagrant-catalog

				$obj['download-url-prefix'] = $this->defaultUrlTemplatePrefix;
			}

			$obj['url-template'] = $obj['download-url-prefix'] . $this->defaultUrlTemplateSuffix;
		}

		return $obj;
	}

	public function loadBoxerConfig()
	{
		$obj = $this->readBoxerConfig();

		if(! $obj)
			$obj = $this->getDefaultBoxerConfig();

		$this->name = $obj['vm-name'];
		$this->majorVersion = isset($obj['version']) ? $obj['version'] : 0;
		$this->urlTemplate = $obj['url-template'];

		// Unless we specifically configured it, the default boxer-id
		// is the same as the vm-name
		if(! isset($obj['boxer-id']))
			$obj['boxer-id'] = empty($this->boxerId) ? $obj['vm-name'] : $this->boxerId;

		$this->boxerId = $obj['boxer-id'];

		if(isset($obj['upload-base-uri']))
			$this->uploadBaseUri = $obj['upload-base-uri'];
	}

	public function loadMetaData()
	{
		$this->metadata = new MetaData($this->boxerId);
		return $this->metadata->loadFromFile($this->metadataJsonFilename);
	}

	public function computeUrl($template=null)
	{
        $urlName = basename($this->boxerId);

		$url = ($template===null) ? $this->urlTemplate : $template;
		$url = str_replace('{{name}}', $urlName, $url);
		$url = str_replace('{{version}}', $this->version, $url);
		$url = str_replace('{{provider}}', $this->provider, $url);
		return $url;
	}

	public function calculateCurrentVersionNumber()
	{
        $this->bIsNewMajorVersion = false;
		$activeVersionNumber = $this->metadata->getActiveVersionNumber();

		// If there is an active version
		if($activeVersionNumber !== null)
		{
			// Find out the length of majorVersion, e.g. "1.0" == 3
            $a = strlen($activeVersionNumber);
			$n = strlen($this->majorVersion);

			// If active version looks like "1.0.x" then it's still part
			// of the current major version
			if($a > $n)
            {
                $aSub = substr($activeVersionNumber, 0, $n+1);
                $nSub = $this->majorVersion . '.';

                if($aSub === $nSub)
                	return $activeVersionNumber;
			}

			// else the major version has changed so start the patch numbers
			// over at zero
		}

        $this->bIsNewMajorVersion = true;
		$defaultVersionNumber = $this->majorVersion . '.0';
		return $defaultVersionNumber;
	}

	public function postConfigure()
	{
		$this->version = $this->calculateCurrentVersionNumber();

		// AFTER computing the version, compute url
		$this->url = $this->computeUrl();
	}

	public function bumpVersionNumber()
	{
        if(! $this->bIsNewMajorVersion)
        {
            $version = explode(".", $this->version);
            $version[count($version)-1]++;
            $version = implode(".", $version);

            $this->version = $version;
        }

		// We changed the version number, recompute the URL
		$this->url = $this->computeUrl();
	}

	public function writeMetaData()
	{
        $written = false;

        // Don't write metadata in test mode
        if(! $this->isTestMode())
        {
            if($this->metadata->saveToFile($this->metadataJsonFilename, $this->forceWriteMetadata))
            {
                $written = true;
            }
        }

        // AFTER creating the file, then we can use realpath() to find out where
        // the file got written.

        $file = $this->metadataJsonFilename;

        // Even though we didn't necessarily WRITE this file, it is a file that is
        // created/updated by us, we want to know its location
        $this->createdFiles[] = $file;

        $this->write("METADATA LOCATION: $file\n");

		return $written;
	}

	public function getVersionedFilename()
	{
        $filename = basename($this->url);
		return $this->vagrantBoxOutputDirectory . '/' . $filename;
	}

	public function package()
	{
		$boxname = $this->vagrantBoxOutputDirectory . '/' . $this->vagrantBoxOutputFilename;

		$versionedFilename = $this->getVersionedFilename();

		if($this->repackage || ! file_exists($boxname))
		{
			// Remove existing file (if any) to prevent vagrant errors
            if(file_exists($boxname))
            {
                if(! $this->isTestMode() && ! unlink($boxname))
                    throw new Exception("Cannot remove temporary file: $boxname");
            }

			$command = array(
				$this->pathToVagrant, 'package',
				'--output', escapeshellarg($boxname),
			);
			if($this->name)
			{
				array_push($command, '--base', escapeshellarg($this->name));
			}
			if($this->vagrantFile)
			{
				array_push($command, '--vagrantfile', escapeshellarg($this->vagrantFile));
			}

			$command = implode(" ", $command);

			$this->write("EXEC: $command\n");

            if($this->isTestMode())
                $r = $this->makeTestCallback("EXEC: $command");
            else
                passthru($command, $r);

			if($r !== 0)
				throw new Exception("vagrant package failed, exit code=$r from command: $command");

			if(! $this->isTestMode() && ! file_exists($boxname))
				throw new Exception("vagrant package seems to have failed; its expected output file ($boxname) does not exist. Make sure the vm-name ({$this->name}) corresponds to the name of your VM in VirtualBox and that this VM exists in VirtualBox");
		}

		// Copy the output file to the final location
		// Why copy?  Mainly for testing so I don't have to keep repackaging,
		// which consumes a ton of time.
        if(! $this->isTestMode())
        {
            @unlink($versionedFilename); // remove any existing file before trying to copy
            if(! copy($boxname, $versionedFilename))
                throw new Exception("Unable to copy vagrant package to $versionedFilename");

            // Need to remember the new sha1 of this file
            $sha1 = sha1_file($versionedFilename);
            if($sha1 === false)
                throw new Exception("Unable to compute sha1 checksum of file $versionedFilename");
        }
        else
            $sha1 = 'test';

		// Add new version to metadata
		$provider = array(
			'name' => $this->provider,
			'url' => $this->url,
			'checksum_type' => 'sha1',
			'checksum' => $sha1,
		);

		$this->metadata->addVersionProvider($this->version, $provider);

		$file = $versionedFilename;

		$this->createdFiles[] = $file;
		$this->write("PACKAGE LOCATION: $file\n");
	}

	public function uploadFiles()
	{
		// If there is no uploadBaseUri configured, we cannot upload
		if(! $this->uploadBaseUri)
		{
			$this->writeStderr("Notice: Uploading is enabled but there is no upload uri defined, skipping upload.\n");
			return;
		}

		// Figure out the full path to the upload server
		$uri = $this->uploadBaseUri .
			(substr($this->uploadBaseUri,-1) == '/' ? '' : '/') . // add trailing slash if none exists
			$this->metadata->get('name') . '/'; // append name of the project (pathinfo) and trailing slash

        // Translate local filenames (required for Windows+MSYS)
        $fileNames = array();
        $files = array();
        $translator = new PathTranslator();
        foreach($this->createdFiles as $file)
        {
            $fileNames[] = basename($file);
            $posixPath = $translator->translate($file);
            $files[] = $posixPath;
        }

        $chmodCommand = null;

        if(preg_match("%^((?:(?:[@:]+)@)?[^:]+):(.*)%", $uri, $matches))
        {
            $host = $matches[1];
            $path = $matches[2];

            // Command to create the directory on the remote server
            $command = array(
                'ssh',
                escapeshellarg($host),
                escapeshellarg("mkdir -p -m 0775 $path"),
            );
            $command = implode(' ', $command);

            $this->write("EXEC: $command\n");

            if($this->isTestMode())
                $r = $this->makeTestCallback("EXEC: $command");
            else
                passthru($command, $r);

            if($r !== 0)
                throw new Exception("Failed to create upload directory, exit code=$r");

            $remoteFiles = array();
            foreach($fileNames as $file)
                $remoteFiles[] = $path . $file;

            $command = array(
                'ssh',
                escapeshellarg($host),
                escapeshellarg("chmod g+w ".implode(' ', $remoteFiles)),
            );
            $chmodCommand = implode(' ', $command);
        }

		// Command to copy stuff up to the vagrant-catalog server
		$command = array(
            $this->uploadMethod,
			implode(' ', array_map('escapeshellarg', $files)),
			escapeshellarg($uri),
		);
		$command = implode(' ', $command);

		$this->write("EXEC: $command\n");

        if($this->isTestMode())
            $r = $this->makeTestCallback("EXEC: $command");
        else
            passthru($command, $r);

		if($r !== 0)
            throw new Exception("Failed to upload files, exit code=$r from method='{$this->uploadMethod}'");

        if($chmodCommand !== null)
        {
            $command = $chmodCommand;
            $this->write("EXEC: $command\n");

            if($this->isTestMode())
                $r = $this->makeTestCallback("EXEC: $command");
            else
                passthru($command, $r);

            if($r !== 0)
                throw new Exception("Failed to chmod files, exit code=$r");
        }
	}

	public function init($args=array())
	{
		$this->readCommandLine($args);

		// 1) Read/parse boxer.json
		$this->loadBoxerConfig();

		// 2) Read/parse metadata.json
		$this->loadMetaData();

		// 3) Configure some things that require both boxer.json and metadata.json
		$this->postConfigure();
	}

	public function exec()
	{
        if($this->isTestMode())
            $this->write("NOTICE: Running in test mode, files won't be generated or uploaded.\n");

		// 1) IFF we are updating the metadata, bump the version number
		if($this->updateVersion && ! $this->metadata->isDefault())
			$this->bumpVersionNumber();

		// 2) Package up the box
		$this->package();

		// 3) IFF something changed, write the metadata.json
		$this->writeMetaData();

		// 4) If upload is enabled, scp these files up to a vagrant-catalog server
		if($this->uploadEnabled)
			$this->uploadFiles();
	}
}
