<?php
/**
 * PackageCompressor
 *
 * A Javascript and CSS compressor based on Yii's package system.
 *
 * @author Michael Härtl <haertl.mike@gmail.com>
 * @version 1.0.4
 */

Yii::setPathOfAlias('_packagecompressor', dirname(__FILE__));

class PackageCompressor extends CClientScript
{
    /**
     * @var bool wether to enable package compression
     */
    public $enableCompression = true;

    /**
     * If this is enabled, during compression all other requests will wait until the compressing
     * process has completed. If disabled, the uncompressed files will be delivered for these
     * requests. This should prevent the thundering herd problem.
     *
     * @var bool whether other requests should pause during compression. On by default.
     */
    public $blockDuringCompression = true;

    /**
     * @var bool whether to add a fingerprint to CSS image urls.
     */
    public $enableCssImageFingerPrinting = true;

    /**
     * @var string name or path of/to the JAVA executable. Default is 'java'.
     */
    public $javaBin = 'java';

    /**
     * @var array names of packages that where registered on current page
     */
    private $_registeredPackages = array();

    /**
     * @var mixed meta information about the compressed packages
     */
    private $_pd;

    /**
     * @var mixed the EMutex component
     */
    private $_mutex;

    // Locking parameter to prevent parallel compression
    const LOCK_ID       = '_PackageCompressor';
    const LOCK_TIMEOUT  = 15;

    // YUI compressor jar name
    const YUI_COMPRESSOR_JAR = 'yuicompressor-2.4.7.jar';

    /**
     * Used internally: Create a compressed version of all package files, publish the
     * compressed file through asset manager and store compressedInfo. Js and CSS will
     * be processed independently.
     *
     * @param string $name of package
     * @return bool package compressed or not
     */
    public function compressPackage($name)
    {
        // compress from Clientscript->packages only
        if (!isset($this->packages[$name]))
            return false;

        // Backup registered scripts, css and core scripts, as we only want to
        // catch the files contained in the package, not those registered from elsewhere
        $coreScripts        = $this->coreScripts;
        $scriptFiles        = $this->scriptFiles;
        $cssFiles           = $this->cssFiles;

        $this->coreScripts = $this->cssFiles = $this->scriptFiles = array();

        $this->coreScripts[$name] = $this->packages[$name];
        // Now we expand coreScripts into scriptFiles (usually happens during rendering)
        $this->renderCoreScripts();

        // Copied from CClientScript: process the scriptMap and remove duplicates
        if(!empty($this->scriptMap))
            $this->remapScripts();
        $this->unifyScripts();

        $info       = array();
        $am         = Yii::app()->assetManager;
        $basePath   = Yii::getPathOfAlias('webroot');

        // /www/root/sub -> /www/root   (baseUrl=/sub)
        if(($baseUrl = Yii::app()->request->baseUrl)!=='')
            $basePath = substr($basePath,0,-strlen($baseUrl));

        // Process all JS files from the package (if any)
        if(isset($this->scriptFiles[$this->coreScriptPosition]))
        {
            $scripts    = array();
            $urls       = array();

            foreach($this->scriptFiles[$this->coreScriptPosition] as $script)
                if (strtolower(substr($script,0,4))==='http' || substr($script,0,2)==='//') // external script
                    $urls[] = $script;
                else
                    $scripts[] = $basePath.$script;   // '/www/root'.'/sub/js/some.js'

            if ($scripts!==array())
            {
                $fileName = $this->compressFiles($name,'js',$scripts);
                $urls[] = $am->publish($fileName,true);    // URL to compressed file

                $info['js'] = array(
                    'file'          => $am->getPublishedPath($fileName,true), // path to compressed file
                    'files'         => $scripts,
                    'urls'          => $urls,
                );
                unlink($fileName);
            }
            elseif ($urls!==array()) {
                $info['js'] = array(
                    'urls'          => $urls,
                );
            }
        }

        // Process all CSS files from the package (if any)
        if ($this->cssFiles!==array())
        {
            $files  = array();
            $urls   = array();

            foreach(array_keys($this->cssFiles) as $file)
                if (strtolower(substr($file,0,4))==='http' || substr($file,0,2)==='//') // external file
                    $urls[] = $file;
                else
                    $files[] = $basePath.$file;

            if ($files!==array())
            {
                $fileName = $this->compressFiles($name,'css',$files);

                if(isset($this->packages[$name]['baseUrl']))
                {
                    // If a CSS package uses 'baseUrl' we do not use the asset publisher
                    // because this could break CSS URLs. Instead we copy to baseUrl:
                    $url = '/'.trim($this->packages[$name]['baseUrl'],'/').'/'.basename($fileName);

                    // '/www/root'.'/sub/'.'/css/some.css'
                    $destFile = $basePath.$baseUrl.$url;

                    copy($fileName, $destFile);
                    $urls[] = $baseUrl.$url;  // '/sub'.'/css/some.css'
                }
                else
                {
                    $urls[] = $am->publish($fileName,true);    // URL to compressed file
                    $destFile = $am->getPublishedPath($fileName,true);
                }

                $info['css'] = array(
                    'file'  => $destFile,
                    'files' => $files,
                    'urls'  => $urls,
                    'media' => isset($this->packages[$name]['media']) ? $this->packages[$name]['media'] : '',
                );
                unlink($fileName);
            }
            elseif ($urls!==array()) {
                $info['css'] = array(
                    'urls'  => $urls,
                    'media' => isset($this->packages[$name]['media']) ? $this->packages[$name]['media'] : '',
                );
            }
        }

        // delete published package original assets
        if (isset($this->packages[$name]['basePath']))
            $am->unPublish(Yii::getPathOfAlias($this->packages[$name]['basePath']));

        // Restore original coreScripts, scriptFiles and cssFiles
        $this->coreScripts  = $coreScripts;
        $this->scriptFiles  = $scriptFiles;
        $this->cssFiles     = $cssFiles;

        // Store package meta info
        if($info!==array()) {
            $this->setCompressedInfo($name,$info);
            return true;
        }
        else
            return false;
    }

    /**
     * Override CClientScript::registerPackage() to initialize the compression algorithm
     *
     * @param string $name Name of Package to register
     * @return CClientScript the CClientScript object itself
     */
    public function registerPackage($name)
    {
        if ($this->enableCompression && !in_array($name, $this->_registeredPackages))
        {
            if(isset($this->packages[$name]))
            {
                $package=$this->packages[$name];
                if(!empty($package['depends']))
                {
                    foreach($package['depends'] as $p)
                        $this->registerPackage($p);
                }
                $this->hasScripts = true;
                $this->_registeredPackages[$name] = $name;

                // Create compressed package if not done so yet
                if(($info = $this->getCompressedInfo($name))===null)
                {
                    $mutex = $this->getMutex();

                    // Compresssion must only be performed once, even for several parallel requests
                    while(!$mutex->lock(self::LOCK_ID,self::LOCK_TIMEOUT))
                        if($this->blockDuringCompression)
                            sleep(1);
                        else
                            return parent::registerPackage($name);

                    // We have a Mutex lock, now check if another process already compressed this package
                    if ($this->getCompressedInfo($name,true)!==null) {
                        $mutex->unlock();
                        return $this;
                    }

                    $this->compressPackage($name);

                    $mutex->unlock();

                }
            }
            return $this;
        } else
            return parent::registerPackage($name);
    }

    /**
     * Override CClientScript::getPackageBaseUrl() to avoid Yii::app()->getAssetManager call
     * In order to console command "compress" works correct
     * Yii::app()->getAssetManager() is not available in console apps (should be Yii::app()->assetManager)
     * @param string $name
     * @return bool|string
     */
    public function getPackageBaseUrl($name)
    {
        if(!isset($this->coreScripts[$name]))
            return false;
        $package=$this->coreScripts[$name];
        if(isset($package['baseUrl']))
        {
            $baseUrl=$package['baseUrl'];
            if($baseUrl==='' || $baseUrl[0]!=='/' && strpos($baseUrl,'://')===false)
                $baseUrl=Yii::app()->getRequest()->getBaseUrl().'/'.$baseUrl;
            $baseUrl=rtrim($baseUrl,'/');
        }
        elseif(isset($package['basePath']))
            $baseUrl=Yii::app()->assetManager->publish(Yii::getPathOfAlias($package['basePath']));
        else
            $baseUrl=$this->getCoreScriptUrl();

        return $this->coreScripts[$name]['baseUrl']=$baseUrl;
    }

    /**
     * Override CClientScript::render() to add compressed package files if available.
     *
     * @param string $output the existing output that needs to be inserted with script tags
     */
    public function render(&$output)
    {
        if(!$this->hasScripts)
            return;

        $packages = $this->_registeredPackages;
        if($this->enableCompression)
            foreach($packages as $package)
                $this->unregisterPackagedCoreScripts($package);

        $this->renderCoreScripts();

        if(!empty($this->scriptMap))
            $this->remapScripts();

        // Register package files as *first* files always
        if($this->enableCompression)
            foreach(array_reverse($this->_registeredPackages) as $package)
                $this->renderCompressedPackage($package);

        $this->unifyScripts();

        $this->renderHead($output);
        if($this->enableJavaScript)
        {
            $this->renderBodyBegin($output);
            $this->renderBodyEnd($output);
        }
    }

    /**
     * Delete cached package file
     *
     * @param string (optional) $name of package
     */
    public function resetCompressedPackage($name=null)
    {
        if($this->_pd===null)
            $this->loadPackageData();

        if($name===null)
            $packages = $this->_pd;
        elseif(isset($this->_pd[$name]))
            $packages = array($name=>$this->_pd[$name]);
        else
            $packages = array();

        if($packages===array())
            return false;

        foreach($packages as $package => $info)
        {
            if(isset($info['js']['file']))
                $this->deleteDir(dirname($info['js']['file']));

            if(isset($info['css']['file'])) {
                if (isset($this->packages[$package]['basePath']))
                    $this->deleteDir(dirname($info['css']['file']));
                else
                    @unlink($info['css']['file']);
            }

            unset($this->_pd[$package]);
        }

        $this->savePackageData();

        return true;
    }

    /**
     * If a compressed package is available, will return an array of this format:
     *
     *  array(
     *      'js'=>array(
     *          'file'          =>'/path/to/compressed/file',
     *          'files'         => <list of original file names>
     *          'urls'          => <list of script URLs (incl. external)>
     *      ),
     *      'css'=>array(
     *          'file'          =>'/path/to/compressed/file',
     *          'urls'          => <list of script URLs (incl. external)>
     *      ),
     *
     * @param string name of package to load
     * @param bool wether to enforce that package data is read again from global state
     * @return mixed array with compressed package information or null if none
     */
    public function getCompressedInfo($name, $forceRefresh=false)
    {
        if ($this->_pd===null || $forceRefresh)
            $this->loadPackageData($forceRefresh);

        $i=isset($this->_pd[$name]) ? $this->_pd[$name] : null;

        // Safety check: Verify that compressed files exist
        if( isset($i['js']['file']) && !file_exists($i['js']['file']) ||
            isset($i['css']['file']) && !file_exists($i['css']['file']))
        {
            $this->setCompressedInfo($name,null);
            YII_DEBUG && Yii::trace(
                sprintf(
                    "Remove %s:\n%s\n%s",
                    $name,
                    isset($i['js']) ? $i['js']['file'] : '-',
                    isset($i['css']) ? $i['css']['file'] : '-'
                ),
                'application.components.packagecompressor'
            );
            $i=null;
        }

        return $i;
    }

    /**
     * @return array list of compressed package names
     */
    public function getCompressedPackageNames()
    {
        if ($this->_pd===null)
            $this->loadPackageData();

        return array_keys($this->_pd);
    }

    /**
     * @return EMutex the mutex component from the bundled EMutext extension
     */
    public function getMutex()
    {
        if($this->_mutex===null)
            $this->_mutex = Yii::createComponent(array(
                'class'     => '_packagecompressor.EMutex',
                'mutexFile' => Yii::app()->runtimePath.'/packagecompressor_mutex.bin',
            ));

        return $this->_mutex;
    }

    /**
     * @return string unique key per application
     */
    public function getStateKey()
    {
        return '__packageCompressor:'.Yii::app()->getId();
    }

    /**
     * Combine the set of given text files into one file
     *
     * For js files, we add a semicolon to the end, in case the file forgot it, e.g. jquery.history.js in the yii repo.
     *
     * @param string $name of package
     * @param string $type of package, either js or css
     * @param array  $files List of files to combine (full path)
     * @return string full path name of combined file
     */
    private function combineFiles($name,$type,$files)
    {
        $fileName = tempnam(Yii::app()->runtimePath,'combined_'.$name);

        foreach($files as $f) {
            if($type == 'css' && $this->enableCssImageFingerPrinting)
                $fileContents = $this->addCssImageFingerPrints($f);
            else
                $fileContents = file_get_contents($f);

            if(!file_put_contents($fileName, $fileContents.($type==='js'?';':'')."\n", FILE_APPEND))
                throw new CException(sprintf(
                    'Could not combine file "%s" into "%s"',
                    $f,
                    $fileName
                ));
        }

        return $fileName;
    }

    /**
     * Create a compressed file using YUI compressor (requires JRE!)
     *
     * @param string $name of package
     * @param string $type of package, either js or css
     * @param array $files list of full file paths to files
     * @return string file name of compressed file
     */
    private function compressFiles($name,$type,$files)
    {
        YII_DEBUG && Yii::trace(sprintf(
            "Compressing %s package %s:\n%s",
            $type,
            $name,
            implode(",\n",$files)
        ),'application.components.packagecompressor');

        $inFile = $this->combineFiles($name,$type,$files);
        $outFile = sprintf(
            '%s/%s_%s.%s',
            Yii::app()->runtimePath,
            $name,
            substr(md5_file($inFile), 0, 16),
            $type
        );

        if (isset($this->packages[$name]['compress']) && $this->packages[$name]['compress'] === false)
            copy($inFile,$outFile);
        else
        {
            $jar = Yii::getPathOfAlias('_packagecompressor.yuicompressor').DIRECTORY_SEPARATOR.self::YUI_COMPRESSOR_JAR;
            // See http://developer.yahoo.com/yui/compressor/
            $command = sprintf("%s -jar %s --type %s -o %s %s",escapeshellarg($this->javaBin),escapeshellarg($jar),$type,escapeshellarg($outFile),escapeshellarg($inFile));
            exec($command,$output,$result);

            if ($result!==0)
                throw new CException(sprintf(
                    "Could not create compressed $type file. Maybe missing a JRE?\nCommand was:\n%s",
                    $command
                ));
        }

        unlink($inFile);
        return $outFile;
    }

    /**
     * Load meta information about compressed packages from global state
     *
     * @param bool wether to enforce that global state is refreshed
     */
    private function loadPackageData($forceRefresh=false)
    {
        // Make sure, statefile is read in again. It could have been changed from
        // another request, while we were waiting for the mutex lock
        if ($forceRefresh)
            Yii::app()->loadGlobalState();

        $this->_pd = Yii::app()->getGlobalState($this->getStateKey(),array());
    }

    /**
     * Replace all sripts registered at $coreScriptPosition with compressed file
     */
    private function renderCompressedPackage($name)
    {
        if(($package = $this->getCompressedInfo($name))===null)
            return;

        if(isset($package['js']))
        {
            $p = $this->coreScriptPosition;

            // Keys in scriptFiles must be equal to value to make unifyScripts work:
            $packageFiles = array_combine($package['js']['urls'], $package['js']['urls']);

            $this->scriptFiles[$p] = isset($this->scriptFiles[$p]) ?
                array_merge($packageFiles, $this->scriptFiles[$p]) : $packageFiles;
        }

        if(isset($package['css']))
        {
            $cssFiles = $this->cssFiles;

            $this->cssFiles = array();

            foreach($package['css']['urls'] as $url)
                $this->cssFiles[$url] = $package['css']['media'];

            foreach($cssFiles as $url => $media)
                $this->cssFiles[$url] = $media;
        }
    }

    /**
     * Remove any registered core scripts and packages if we have it in the package to prevent publishing
     *
     * @param string $name of package
     */
    private function unregisterPackagedCoreScripts($package)
    {
        if (($info = $this->getCompressedInfo($package))===null)
            return;

        // Remove the package itself from the coreScripts or it would
        // still render the uncompressed script files
        unset($this->coreScripts[$package]);
    }

    /**
     * Save meta information about compressed packages to global state
     */
    private function savePackageData()
    {
        Yii::app()->setGlobalState($this->getStateKey(),$this->_pd);

        // We want to be sure that global state is written immediately. Default would be onEndRequest.
        Yii::app()->saveGlobalState();
    }

    /**
     * Stores meta information about compressed package to cache and global state
     *
     * @param mixed Array of format array('file'=>...,'urls'=>...) or null to reset
     */
    private function setCompressedInfo($name,$value)
    {
        if($this->_pd===null)
            $this->loadPackageData();

        if($value!==null)
            $this->_pd[$name]=$value;
        elseif(isset($this->_pd[$name]))
            unset($this->_pd[$name]);

        $this->savePackageData();
    }

    /**
     * Attempt to add a fingerprint to any image url found in the input file
     *
     * Example CSS that we want to match:
     *   background: url('/images/stars/star.png') no-repeat 0px 0px;
     *
     * Resulting image file that we want to get a fingerprint for: /images/stars/star.png;
     *
     * Notes:
     *  A) Cope with ' or " or no string encloser.
     *  B) Only modify local assets, i.e. not apsolute urls.
     *  C) Only modify jpg|jpeg|gif|png assets.
     *  D) Don't modify assets that already have a fingerprint
     *
     * @param String $fileName Full file name of a CSS file
     * @return string Modified CSS text
     */
    private function addCssImageFingerPrints($fileName)
    {
        $webRoot = realpath(Yii::getPathOfAlias('webroot'));

        return preg_replace_callback(
            '/url\([\'\"]?(.*?\.(jpg|jpeg|gif|png))[\'\"]?\)/',
            function($matches) use ($fileName,$webRoot)
            {
                $imageURL = $matches[1];

                if (preg_match('/^(https?:)?\/\//', $imageURL))
                    return $matches[0]; // We don't fingerprint absolute urls

                // image urls that start with a / are relative to the webroot, otherwise they are relative to the url of the CSS file.
                $imageFile = ( $imageURL[0]==='/' ? $webRoot : dirname($fileName).'/' ) . $imageURL;

                if (file_exists($imageFile))
                    return 'url(\''.$imageURL.'?'.substr(md5_file($imageFile),0,8).'\')';

                // We won't always find the image, e.g. there might be a mod_rewrite rule in play.
                YII_DEBUG && Yii::trace(
                    sprintf("Unable to find css image '%s' on disk. Css File: '%s'. CSS: %s.",
                        $imageFile,
                        $fileName,
                        $matches[0]),
                    'application.components.packagecompressor'
                );
                return $matches[0];
            },
            file_get_contents($fileName)
        );
    }

    /**
     * Delete folder recursively
     * @param $dirPath
     * @return bool
     */
    private function deleteDir($dirPath)
    {
        try {
            foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirPath, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $path) {
                $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
            }
            @rmdir($dirPath);
            return true;
        }
        catch (Exception $e) {
            return false;
        }
    }
}
