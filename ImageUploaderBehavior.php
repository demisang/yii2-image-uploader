<?php
/**
 * @copyright Copyright (c) 2018 Ivan Orlov
 * @license   https://github.com/demisang/yii2-image-uploader/blob/master/LICENSE
 * @link      https://github.com/demisang/yii2-image-uploader#readme
 * @author    Ivan Orlov <gnasimed@gmail.com>
 */

namespace demi\image;

use Yii;
use yii\base\Behavior;
use yii\db\ActiveRecord;
use yii\image\drivers\Image_GD;
use yii\image\drivers\Image_Imagick;
use yii\validators\ImageValidator;
use yii\validators\RequiredValidator;
use yii\validators\Validator;
use yii\web\UploadedFile;

/**
 * Model image behavior
 *
 * @package demi\image
 *
 * @property ActiveRecord $owner
 */
class ImageUploaderBehavior extends Behavior
{
    /**
     * Name of image attribute where the image will be stored
     *
     * @var string
     */
    protected $_imageAttribute = 'image';
    /**
     * Yii-alias to dir where will be stored subdirectories with images
     *
     * @var string
     */
    protected $_savePathAlias = '@frontend/web/images';
    /**
     * Yii-alias to root project dir, relative path to the image will exclude this part of the full path
     *
     * @var string
     */
    protected $_rootPathAlias = '@frontend/web';
    /**
     * Allowed filetypes
     *
     * @var string
     */
    protected $_fileTypes = 'jpg,jpeg,gif,png';
    /**
     * Max image size (bytes)
     *
     * @var integer
     */
    protected $_maxFileSize = 10485760; // 10mb
    /**
     * List of thumbnails sizes.
     * Format: [prefix=>max_width]
     * Thumbnails height calculated proportionally automatically
     * Prefix '' is special, it determines the max width of the main image
     *
     * @var array
     */
    protected $_imageSizes = [];
    /**
     * Name of default image. Image placed to: webrooot/images/{noImageBaseName}
     * You must create all noimage files: noimage.jpg, medium_noimage.jpg, small_noimage.jpg, etc.
     *
     * @var string
     */
    protected $_noImageBaseName = 'noimage.png';
    /**
     * Is image field required
     *
     * @var boolean
     */
    protected $_imageRequire = false;
    /**
     * This params will be passed to \yii\validators\ImageValidator
     *
     * @var array
     */
    protected $_imageValidatorParams = [];
    /**
     * If backend is located on a subdomain 'admin.', and images are uploaded to a directory
     * located in the frontend, you can set this param and then getImageSrc() will be return
     * path to image without subdomain part even in backend part
     *
     * @var string
     */
    protected $_backendSubdomain = 'admin.';
    /**
     * If backend is located on a route '/admin', and images are uploaded to a directory
     * located in the frontend, you can set this param and then getImageSrc() will be return
     * path to image without route part even in backend part
     *
     * @var string
     */
    protected $_backendRoute = '/admin';
    /**
     * Tmp field stored already uploaded image
     *
     * @var string
     */
    protected $_oldImage = null;
    /**
     * Cropper config.
     * 4/3 or 16/9(wide) or 1/1(square) or any other ratio. Null - free ratio
     *
     * @var float|null
     */
    protected $_aspectRatio = null;
    /**
     * Custom config array
     *
     * @var array
     */
    public $imageConfig = [];
    /**
     * Inner: image component (eg.: yii\image\ImageDriver)
     *
     * @var object
     */
    protected static $_imageComponent;

    /**
     * @param ActiveRecord $owner
     */
    public function attach($owner)
    {
        parent::attach($owner);

        // Apply custom config
        foreach ($this->imageConfig as $key => $value) {
            $var = '_' . $key;
            $this->$var = $value;
        }

        // Get webroot path
        if (empty($this->_rootPathAlias)) {
            $savePathParts = explode('/', $this->_savePathAlias);
            // Remove last part
            unset($savePathParts[count($savePathParts - 1)]);
            // Join parts
            $this->_rootPathAlias = implode('/', $savePathParts);
        }

        // Attach "required" validator if needed
        if ($this->_imageRequire) {
            $owner->validators->append(Validator::createValidator(RequiredValidator::className(), $owner,
                $this->_imageAttribute));
        }

        // Attach "image" validator
        $validatorParams = array_merge([
            'extensions' => $this->_fileTypes,
            'maxSize' => $this->_maxFileSize,
            'skipOnEmpty' => true,
        ], $this->_imageValidatorParams);

        $validator = Validator::createValidator(ImageValidator::className(), $owner, $this->_imageAttribute,
            $validatorParams);
        $owner->validators->append($validator);
    }

    /**
     * Get image config param value
     *
     * @param string $paramName
     *
     * @return mixed
     */
    public function getImageConfigParam($paramName)
    {
        $name = '_' . $paramName;

        return $this->$name;
    }

    /**
     * Set new config param value
     *
     * @param string $paramName
     * @param mixed $value
     */
    public function setImageConfigParam($paramName, $value)
    {
        $name = '_' . $paramName;

        $this->$name = $value;
    }

    public function events()
    {
        return [
            ActiveRecord::EVENT_BEFORE_INSERT => 'beforeSave',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeSave',
            ActiveRecord::EVENT_AFTER_DELETE => 'deleteImage',
            ActiveRecord::EVENT_AFTER_FIND => 'afterFind',
        ];
    }

    public function beforeSave()
    {
        $owner = $this->owner;

        $image = $owner->{$this->_imageAttribute};
        $image = ($image instanceof UploadedFile) ? $image :
            UploadedFile::getInstance($owner, $this->_imageAttribute);

        if ($image instanceof UploadedFile) {
            // If new image uploaded - process it, remove old
            $new_image = static::uploadImage($image);
            if (!empty($new_image)) {
                $this->deleteImage();
                $owner->{$this->_imageAttribute} = $new_image;
            } else {
                // If errors - revert old image
                $owner->{$this->_imageAttribute} = $this->_oldImage;
            }
        } else {
            // Save model without image uploading - return old image to the field value
            $owner->{$this->_imageAttribute} = $this->_oldImage;
        }
    }

    public function afterFind()
    {
        $owner = $this->owner;
        // Remember old image value
        $this->_oldImage = $owner->{$this->_imageAttribute};
    }

    /**
     * Delete image file and set|save model image field to null
     *
     * @param bool $updateDb need to update image field in DB
     */
    public function deleteImage($updateDb = false)
    {
        $owner = $this->owner;
        $DS = DIRECTORY_SEPARATOR;

        $image = str_replace('/', $DS, $this->_oldImage);

        if (!empty($image)) {
            $dirName = Yii::getAlias($this->_savePathAlias);
            // Remove all resized images
            foreach ($this->getImageSizes() as $prefix => $size) {
                $file_name = $dirName . $DS . static::addPrefixToFile($image, $prefix);
                @unlink($file_name);
            }
            // Remove original image
            @unlink($this->getOriginalImagePath());
        }

        // Clear field value
        $owner->{$this->_imageAttribute} = null;
        $this->_oldImage = null;

        if ($updateDb) {
            $owner->update(false, [$this->_imageAttribute]);
        }
    }

    /**
     * Get image sizes
     *
     * @return array
     */
    protected function getImageSizes()
    {
        if (is_callable($this->_imageSizes)) {
            return call_user_func($this->_imageSizes, $this->owner);
        } else {
            return $this->_imageSizes;
        }
    }

    /**
     * Save uploaded image
     *
     * @param UploadedFile $image
     *
     * @return string Image field value or NULL if error
     */
    protected function uploadImage(UploadedFile $image)
    {
        $DS = DIRECTORY_SEPARATOR;
        // Max width for uploaded original image
        $maxWidth = 1500;
        $namePart = uniqid();
        $name = $namePart . '.' . $image->extension; // New filename
        $imageFolder = Yii::getAlias($this->_savePathAlias); // Куда загружать изображение
        // Creates new random subdir for images
        $rnddir = static::getRandomDir($imageFolder);
        $fullImagePath = $imageFolder . $DS . $rnddir . $DS . $name; // Full image path
        if ($image->saveAs($fullImagePath)) {
            // Reduce image if image is very large
            $imageComponent = static::getImageComponent();
            $imageInfo = getimagesize($fullImagePath);
            $img_width = $imageInfo[0];
            if ($img_width > $maxWidth) {
                /* @var $image_o Image_GD|Image_Imagick */
                $image_o = $imageComponent->load($fullImagePath);
                $image_o->resize($maxWidth, static::getMaxHeight($maxWidth));
                $image_o->save($fullImagePath);
            }

            // Save original file
            $originalImage = $imageFolder . $DS . $rnddir . $DS . $namePart . '_original.' . $image->extension;
            @copy($fullImagePath, $originalImage);
            // If image successfully saved - make resized copies
            $sizes = $this->getImageSizes();
            $imageInfo = getimagesize($fullImagePath);
            $img_width = $imageInfo[0];
            $img_height = $imageInfo[1];

            // Crop image if set aspectRatio value
            if ($this->_aspectRatio) {
                $isVertical = $img_width < $img_height;

                if (!$isVertical) {
                    $width = $img_height * $this->_aspectRatio;
                    $height = $width / $this->_aspectRatio;
                } else {
                    $height = $img_width / $this->_aspectRatio;
                    $width = $height * $this->_aspectRatio;
                }
                /* @var $image_c Image_GD|Image_Imagick */
                $image_c = $imageComponent->load($fullImagePath);
                $image_c->crop($width, $height);
                $img_width = $width;
                $image_c->save($fullImagePath);
            }

            // If the image is NOT wider than it should be - remove main size from the list of resize sizes
            if ($img_width <= $sizes['']) {
                unset($sizes['']);
            }

            // Do resize
            $rez = static::resizeAndSave($imageFolder . $DS . $rnddir, $name, $sizes);
            // Successfull
            if ($rez === true) {
                return $rnddir . '/' . $name;
            } else {
                // Error - remove resized copies
                foreach ($this->getImageSizes() as $size) {
                    $file_name = $imageFolder . $DS . $rnddir . $DS . static::addPrefixToFile($name, $size);
                    @unlink($file_name);
                }

                return null;
            }
        }

        return null;
    }

    /**
     * Add file prefix
     * For example addPrefixToFile("dirname/50b3d1ad130d0.png", "normal_") returned "dirname/normal_50b3d1ad130d0.png"
     *
     * @param string $path   Main image path
     * @param string $prefix Size prefix
     *
     * @return string Main image path with size prefix
     */
    public static function addPrefixToFile($path, $prefix = null)
    {
        if ($prefix === null || $prefix == '') {
            return $path;
        }

        $path = str_replace('\\', '/', $path);
        $dir = explode('/', $path);
        $lastIndex = count($dir) - 1;
        $dir[$lastIndex] = $prefix . $dir[$lastIndex];

        return implode('/', $dir);
    }

    /**
     * Add file postfix
     * For example addPrefixToFile("dirname/50b3d1ad130d0.png", "_normal") returned "dirname/50b3d1ad130d0_normal.png"
     *
     * @param string $path    Main image path
     * @param string $postfix Size postfix
     *
     * @return string Main image path with size postfix
     */
    public static function addPostfixToFile($path, $postfix = null)
    {
        if ($postfix === null || $postfix == '') {
            return $path;
        }

        $parts = explode('.', $path);

        if (count($parts) === 1) {
            return $postfix . $path;
        }

        $parts[count($parts) - 2] .= $postfix;

        return implode('.', $parts);
    }

    /**
     * Return image path for web
     *
     * @param string $size Size prefix
     *
     * @return string Image src path, can be value for Html::image()
     */
    public function getImageSrc($size = null)
    {
        $owner = $this->owner;

        $prefix = '';
        if (Yii::$app->request instanceof \yii\web\Request) {
            $prefix = Yii::$app->request->baseUrl;
            $host = Yii::$app->request->hostInfo;
            // If current application is backend - return absolute frontend image path
            if (!empty($this->_backendSubdomain) && strpos($host, $this->_backendSubdomain) !== false) {
                $prefix = str_replace($this->_backendSubdomain, '', $host) . $prefix;
            }
            if (!empty($this->_backendRoute) && strpos($prefix, $this->_backendRoute) !== false) {
                $prefix = str_replace($this->_backendRoute, '', $prefix);
            }
        }

        $image = $owner->{$this->_imageAttribute};
        if (empty($image)) {
            if (isset($this->getImageSizes()[$size])) {
                return $prefix . '/images/' . static::addPrefixToFile($this->_noImageBaseName, $size);
            }

            return $prefix . '/images/' . $this->_noImageBaseName;
        }

        $root = Yii::getAlias($this->_rootPathAlias); // Webroot
        $path = Yii::getAlias($this->_savePathAlias); // Uploading dir
        $path = str_replace($root, '', $path); // Remove server path
        $path = str_replace('\\', '/', $path); // Replace "\" to "/"
        $folder = $prefix . '/' . trim($path, '/') . '/';

        if (!empty($size)) {
            return $folder . static::addPrefixToFile($image, $size);
        } else {
            return $folder . $image;
        }
    }

    /**
     * Creates new directory by path (if doesn't exists)
     *
     * @param string $path Primary uploading directory path
     *
     * @return string Path to created random dir
     */
    public static function getRandomDir($path)
    {
        $DS = DIRECTORY_SEPARATOR;
        $max_scatter = 9; // Directory names max range 0..$max_scatter
        $levels = 3; // Nested level
        $dirs = [];
        for ($i = 1; $i <= $levels; $i++) {
            $dirs[] = (string)rand(0, $max_scatter);
        }
        $dir_path = implode($DS, $dirs);
        $full_path = rtrim($path, '/\\') . $DS . $dir_path;

        if (!is_dir($full_path)) {
            if (YII_DEBUG) {
                mkdir($full_path, 0777, true);
            } else {
                shell_exec('mkdir -m 0777 -p ' . $full_path);
            }
            if (!YII_DEBUG && isset($dirs[0])) {
                shell_exec('chmod -R 0777 ' . $path . $DS . $dirs[0] . $DS . '*');
            }
        }

        $dir_path = str_replace('\\', '/', $dir_path);

        return ltrim($dir_path, '/');
    }

    /**
     * Make resized images
     *
     * @param string $dir        Random directory path
     * @param string $fileName   Primary filename
     * @param mixed $resizeWidth integer(wide): one resize - rewrite primary file.<br />
     *                           array: resize for each item.<br />
     *                           array format:
     *                           <pre>
     *                           array('prefix1'=>sizeWidth1, 'prefix2'=>sizeWidth2)
     *                           </pre>
     *
     * @return boolean TRUE if successfull, FALSE otherwise
     * @todo Crop max height instead validaton error message?
     */
    public static function resizeAndSave($dir, $fileName, $resizeWidth)
    {
        $DS = DIRECTORY_SEPARATOR;

        // Full original image path
        $fullPath = $dir . $DS . $fileName;

        if (!@file_exists($fullPath)) {
            // if original file doesn't exists - breack
            return false;
        }

        try {
            $imageComponent = static::getImageComponent();
            $image_r = $imageComponent->load($fullPath);
            /* @var $image_r Image_GD|Image_Imagick */
            if (is_array($resizeWidth)) {
                foreach ($resizeWidth as $prefix => $width) {
                    $image_r->resize($width, static::getMaxHeight($width));
                    $image_r->save($dir . $DS . $prefix . $fileName);
                }
            } else {
                $image_r->resize($resizeWidth, static::getMaxHeight($resizeWidth));
                $image_r->save($fullPath);
            }
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Get image component
     *
     * @return \yii\image\ImageDriver
     */
    public static function getImageComponent()
    {
        // Get image component
        if (is_object(static::$_imageComponent)) {
            $imageComponent = static::$_imageComponent;
        } else {
            $imageComponent = Yii::createObject([
                'class' => 'yii\image\ImageDriver',
            ]);
            // Store component for future using
            static::$_imageComponent = $imageComponent;
        }

        return $imageComponent;
    }

    /**
     * Returns the maximum height of the image relative to the specified width
     *
     * @param integer $width
     *
     * @return integer
     */
    public static function getMaxHeight($width)
    {
        return $width * 2;
    }

    /**
     * Return instance of current behavior
     *
     * @return self $this
     */
    public function getImageBehavior()
    {
        return $this;
    }

    /**
     * Return server path to original image src
     *
     * @return string
     */
    public function getOriginalImagePath()
    {
        $savePath = Yii::getAlias($this->_savePathAlias);
        $image = $this->_oldImage;

        return static::addPostfixToFile($savePath . DIRECTORY_SEPARATOR . $image, '_original');
    }

    /**
     * Crop original image and resave resized images
     *
     * @param int $x
     * @param int $y
     * @param int $width
     * @param int $height
     * @param int $rotate
     *
     * @return bool
     */
    public function cropImage($x, $y, $width, $height, $rotate)
    {
        $DS = DIRECTORY_SEPARATOR;
        $savePath = Yii::getAlias($this->_savePathAlias);
        $imageSrc = $this->owner->{$this->_imageAttribute};
        $fullImagePath = $savePath . $DS . $imageSrc;

        $imageComponent = static::getImageComponent();
        /* @var $image Image_GD|Image_Imagick */
        $image = $imageComponent->load($this->getOriginalImagePath());

        $image->crop($width, $height, $x, $y);
        $image->rotate($rotate);

        $image->save($fullImagePath);

        $sizes = $this->getImageSizes();
        unset($sizes['']);

        $pathParts = explode('/', str_replace('\\', '/', $imageSrc));
        $filename = array_pop($pathParts);

        // Make resize
        return static::resizeAndSave($savePath . $DS . implode($DS, $pathParts), $filename, $sizes);
    }
}
