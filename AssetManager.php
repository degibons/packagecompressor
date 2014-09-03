<?php

class AssetManager extends CAssetManager {

    /**
     * @var array published assets
     */
    private $_published=array();

    /**
     * Attempt to delete published assets folder and remove cached path
     * @param $path
     * @param bool $hashByName
     * @return bool
     */
    public function unPublish($path, $hashByName=false)
    {
        if (isset($this->_published[$path])) {
            if ($assetsPath = $this->getPublishedPath($path, $hashByName)) {
                $this->deleteDir($assetsPath);
                unset($this->_published[$path]);
                return true;
            }
        }
        return false;
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

    /**
     * Copy of CAssetManager->publish()
     * in order to work correctly with $this->_published
     */
    public function publish($path,$hashByName=false,$level=-1,$forceCopy=null)
    {
        if($forceCopy===null)
            $forceCopy=$this->forceCopy;
        if($forceCopy && $this->linkAssets)
            throw new CException(Yii::t('yii','The "forceCopy" and "linkAssets" cannot be both true.'));
        if(isset($this->_published[$path]))
            return $this->_published[$path];
        elseif(($src=realpath($path))!==false)
        {
            $dir=$this->generatePath($src,$hashByName);
            $dstDir=$this->getBasePath().DIRECTORY_SEPARATOR.$dir;
            if(is_file($src))
            {
                $fileName=basename($src);
                $dstFile=$dstDir.DIRECTORY_SEPARATOR.$fileName;
                if(!is_dir($dstDir))
                {
                    mkdir($dstDir,$this->newDirMode,true);
                    @chmod($dstDir,$this->newDirMode);
                }
                if($this->linkAssets && !is_file($dstFile)) symlink($src,$dstFile);
                elseif(@filemtime($dstFile)<@filemtime($src))
                {
                    copy($src,$dstFile);
                    @chmod($dstFile,$this->newFileMode);
                }
                return $this->_published[$path]=$this->getBaseUrl()."/$dir/$fileName";
            }
            elseif(is_dir($src))
            {
                if($this->linkAssets && !is_dir($dstDir))
                {
                    symlink($src,$dstDir);
                }
                elseif(!is_dir($dstDir) || $forceCopy)
                {
                    CFileHelper::copyDirectory($src,$dstDir,array(
                        'exclude'=>$this->excludeFiles,
                        'level'=>$level,
                        'newDirMode'=>$this->newDirMode,
                        'newFileMode'=>$this->newFileMode,
                    ));
                }
                return $this->_published[$path]=$this->getBaseUrl().'/'.$dir;
            }
        }
        throw new CException(Yii::t('yii','The asset "{asset}" to be published does not exist.',
            array('{asset}'=>$path)));
    }

    /**
     * Copy of CAssetManager->getPublishedUrl()
     * in order to work correctly with $this->_published
     */
    public function getPublishedUrl($path,$hashByName=false)
    {
        if(isset($this->_published[$path]))
            return $this->_published[$path];
        if(($path=realpath($path))!==false)
        {
            $base=$this->getBaseUrl().'/'.$this->generatePath($path,$hashByName);
            return is_file($path) ? $base.'/'.basename($path) : $base;
        }
        else
            return false;
    }

}
